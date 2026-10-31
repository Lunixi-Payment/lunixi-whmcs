<?php

declare(strict_types=1);

namespace Lunixi\WHMCS;

use Illuminate\Database\Connection;
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\LedgerStore;

/** All ledger and native invoice writes share Illuminate's transaction counter and PDO. */
final class CapsuleLedger implements LedgerStore
{
    private ?string $active = null;
    public function __construct(private Connection $db)
    {
    }

    public function transaction(string $orderKey, callable $operation)
    {
        if ($this->active !== null || $this->db->transactionLevel() !== 0 || $this->db->getPdo()->inTransaction()) {
            throw new \RuntimeException('An outer or nested financial transaction is forbidden.');
        }
        if (!preg_match('/^whmcs:invoice:[1-9][0-9]{0,17}$/D', $orderKey)) {
            throw new \InvalidArgumentException('Invalid invoice ledger key.');
        }
        return $this->db->transaction(function () use ($orderKey, $operation) {
            // An exclusive duplicate-key update avoids INSERT IGNORE shared-lock upgrade deadlocks.
            $this->db->statement('INSERT INTO mod_lunixi_attempts (order_key) VALUES (?) ON DUPLICATE KEY UPDATE order_key = VALUES(order_key)', [$orderKey]);
            $this->db->table('mod_lunixi_attempts')->where('order_key', $orderKey)->lockForUpdate()->first();
            $this->active = $orderKey;
            try {
                $result = $operation();
                if ($this->db->transactionLevel() !== 1 || !$this->db->getPdo()->inTransaction()) {
                    throw new \RuntimeException('The native helper changed the transaction boundary.');
                }
                return $result;
            } finally {
                $this->active = null;
            }
        }, 1);
    }
    public function load(string $orderKey): ?array
    {
        $this->guard($orderKey);
        $row = $this->db->table('mod_lunixi_attempts')->where('order_key', $orderKey)->first();
        return $row === null || $row->record === null ? null : json_decode($row->record, true, 512, JSON_THROW_ON_ERROR);
    }
    public function save(string $orderKey, array $record): void
    {
        $this->guard($orderKey);
        $this->db->table('mod_lunixi_attempts')->where('order_key', $orderKey)->update(['record' => json_encode($record, JSON_THROW_ON_ERROR)]);
    }
    public function claimEvent(string $merchantId, string $eventId, string $payloadHash): bool
    {
        if ($this->active === null || !preg_match('/^[a-f0-9]{64}$/D', $payloadHash)) {
            throw new \RuntimeException('Event claim requires a financial transaction and payload hash.');
        }
        $key = hash('sha256', $merchantId . "\0" . $eventId);
        if ($this->db->table('mod_lunixi_events')->insertOrIgnore(['event_key' => $key, 'payload_hash' => $payloadHash]) === 1) {
            return true;
        }
        $row = $this->db->table('mod_lunixi_events')->where('event_key', $key)->first();
        if ($row === null || !hash_equals($row->payload_hash, $payloadHash)) {
            throw new \RuntimeException('Event identity collision.');
        }
        return false;
    }
    private function guard(string $key): void
    {
        if ($this->active !== $key || $this->db->transactionLevel() !== 1) {
            throw new \RuntimeException('Ledger access requires its serialized transaction.');
        }
    }
}
