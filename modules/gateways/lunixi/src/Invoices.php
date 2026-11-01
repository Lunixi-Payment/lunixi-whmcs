<?php

declare(strict_types=1);

namespace Lunixi\WHMCS;

use Illuminate\Database\Connection;
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\Money;

/** Native DECIMAL source, including credits and prior partial payments; never getBalanceAttribute(). */
final class Invoices
{
    public function __construct(private Connection $db)
    {
    }
    /** @return array<string,mixed> */
    public function snapshot(int $id, bool $lock = false): array
    {
        $query = $this->db->table('tblinvoices')->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $invoice = $query->first();
        if ($invoice === null) {
            throw new \RuntimeException('Invoice is unavailable.');
        }
        $client = $this->db->table('tblclients')->where('id', $invoice->userid)->first();
        $currency = $client === null ? null : $this->db->table('tblcurrencies')->where('id', $client->currency)->first();
        if ($currency === null) {
            throw new \RuntimeException('Invoice currency is unavailable.');
        }
        $code = (string) $currency->code;
        $exponent = Money::exponent($code);
        $total = Money::minor($invoice->total, $exponent);
        $credit = Money::minor($invoice->credit, $exponent);
        $incoming = '0';
        $outgoing = '0';
        $external = [];
        foreach ($this->db->table('tblaccounts')->where('invoiceid', $id)->orderBy('id')->get() as $row) {
            if (!in_array((int) $row->currency, [0, (int) $currency->id], true) || (int) $row->userid !== (int) $invoice->userid) {
                throw new \RuntimeException('Invoice transactions have a different owner or currency.');
            }
            $in = Money::minor($row->amountin, $exponent);
            $out = Money::minor($row->amountout, $exponent);
            $incoming = Money::add($incoming, $in);
            $outgoing = Money::add($outgoing, $out);
            if ((string) $row->gateway !== 'lunixi' || !str_starts_with((string) $row->transid, 'lxw:')) {
                $external[] = [(int) $row->id, $in, $out, (string) $row->gateway, (string) $row->transid];
            }
        }
        $baseline = ['clientId' => (int) $invoice->userid, 'currencyId' => (int) $currency->id, 'currency' => $code, 'total' => $total, 'credit' => $credit, 'external' => $external];
        return $baseline + ['invoiceId' => $id, 'status' => (string) $invoice->status, 'outstanding' => Money::difference(Money::add($total, $outgoing), Money::add($credit, $incoming)), 'fingerprint' => hash('sha256', json_encode($baseline, JSON_THROW_ON_ERROR))];
    }
    /** @return array<string,mixed> */
    public function bind(int $invoiceId, int $profileId): array
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new \RuntimeException('Checkout binding cannot nest transactions.');
        }
        return $this->db->transaction(function () use ($invoiceId, $profileId): array {
            $current = $this->snapshot($invoiceId, true);
            $row = $this->db->table('mod_lunixi_invoices')->where('invoice_id', $invoiceId)->lockForUpdate()->first();
            if ($row !== null) {
                $saved = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
                if ($current['fingerprint'] !== $saved['fingerprint'] || $profileId !== (int) $row->profile_id) {
                    throw new \RuntimeException('Invoice or merchant profile changed; reconcile the original attempt.');
                }
                return $saved;
            }
            if ($current['status'] !== 'Unpaid' || Money::compare($current['outstanding'], '0') <= 0) {
                throw new \RuntimeException('Only an unpaid invoice with a positive exact balance can start checkout.');
            }
            $this->db->table('mod_lunixi_invoices')->insert(['invoice_id' => $invoiceId, 'profile_id' => $profileId, 'snapshot' => json_encode($current, JSON_THROW_ON_ERROR)]);
            return $current;
        });
    }
    public function binding(int $id): \stdClass
    {
        $row = $this->db->table('mod_lunixi_invoices')->where('invoice_id', $id)->first();
        if (!$row instanceof \stdClass) {
            throw new \RuntimeException('No payment binding exists.');
        }
        return $row;
    }
    /** @return array<string,mixed> */
    public function assertUnchanged(int $id): array
    {
        $row = $this->binding($id);
        $saved = json_decode($row->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $now = $this->snapshot($id, true);
        if ($saved['fingerprint'] !== $now['fingerprint']) {
            throw new \RuntimeException('Invoice totals, currency, owner, credit or external payments changed; manual review is required.');
        }
        return $saved;
    }
}
