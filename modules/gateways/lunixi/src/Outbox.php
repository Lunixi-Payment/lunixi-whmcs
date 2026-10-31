<?php

declare(strict_types=1);

namespace Lunixi\WHMCS;

use Illuminate\Database\Connection;
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\SecretBox;

/** Native effects are deferred until commit; uncertain delivery is held for operator review. */
final class Outbox
{
    private static ?self $active = null;
    private string $context = '';
    /** @var array<string,int> */
    private array $identity = [];
    public function __construct(private Connection $db, private SecretBox $box)
    {
    }
    /** @param array<string,int> $identity */
    public function financial(string $context, callable $work, array $identity = []): void
    {
        if (self::$active !== null || $this->db->transactionLevel() !== 1) {
            throw new \RuntimeException('Invalid native financial scope.');
        }
        self::$active = $this;
        $this->context = $context;
        $this->identity = $identity;
        try {
            $work();
        } finally {
            self::$active = null;
            $this->context = '';
            $this->identity = [];
        }
    }
    /**
     * @param array<string,mixed> $vars
     * @return array<string,bool>
     */
    public static function email(array $vars): array
    {
        if (self::$active === null) {
            return [];
        }
        self::$active->queue('email', ['messagename' => (string) ($vars['messagename'] ?? ''), 'id' => (int) ($vars['relid'] ?? 0)]);
        return ['abortsend' => true];
    }
    /**
     * @param array<string,mixed> $vars
     * @return array<string,bool>
     */
    public static function provisioning(string $action, array $vars): array
    {
        if (self::$active === null) {
            return [];
        }
        $params = $vars['params'] ?? [];
        self::$active->queue('provision', ['action' => $action, 'serviceid' => (int) ($params['serviceid'] ?? 0)] + self::$active->identity);
        return ['abortcmd' => true];
    }
    public static function isActive(): bool
    {
        return self::$active !== null;
    }
    /** @param array<string,mixed> $data */
    private function queue(string $kind, array $data): void
    {
        $id = hash('sha256', $this->context . "\0" . $kind . "\0" . json_encode($data, JSON_THROW_ON_ERROR));
        $this->db->table('mod_lunixi_outbox')->insertOrIgnore(['id' => $id, 'encrypted' => $this->box->encrypt(json_encode(['kind' => $kind, 'data' => $data], JSON_THROW_ON_ERROR))]);
    }
    /** @return list<array<string,mixed>> */
    public function attention(): array
    {
        $result = [];
        foreach ($this->db->table('mod_lunixi_outbox')->whereNotIn('state', ['sent', 'resolved'])->orderBy('created_at')->limit(100)->get() as $row) {
            $payload = json_decode($this->box->decrypt($row->encrypted), true, 512, JSON_THROW_ON_ERROR);
            $result[] = ['id' => $row->id, 'state' => $row->state, 'kind' => $payload['kind'], 'reference' => $payload['data']['serviceid'] ?? $payload['data']['id'] ?? 0];
        }
        return $result;
    }
    public function acknowledge(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid outbox job.');
        }
        $changed = $this->db->table('mod_lunixi_outbox')->where('id', $id)->whereIn('state', ['review', 'uncertain', 'sending'])->update(['state' => 'resolved']);
        if ($changed !== 1) {
            throw new \RuntimeException('Only a reviewed held or uncertain job can be acknowledged.');
        }
    }
    /** One durable job claim. A crash after external delivery is never blindly retried. */
    public function dispatchOne(callable $api): bool
    {
        if ($this->db->transactionLevel() !== 0) {
            throw new \RuntimeException('Outbox dispatch must occur after commit.');
        }
        $job = $this->db->transaction(function (): ?object {
            $row = $this->db->table('mod_lunixi_outbox')->where('state', 'ready')->orderBy('created_at')->lockForUpdate()->first();
            if ($row !== null) {
                $this->db->table('mod_lunixi_outbox')->where('id', $row->id)->update(['state' => 'sending']);
            }
            return $row;
        });
        if ($job === null) {
            return false;
        }
        $payload = json_decode($this->box->decrypt($job->encrypted), true, 512, JSON_THROW_ON_ERROR);
        try {
            if ($payload['kind'] === 'email') {
                $result = $api('SendEmail', $payload['data']);
            } else {
                $result = $this->provision($payload['data'], $api);
                if ($result === null) {
                    $this->db->table('mod_lunixi_outbox')->where('id', $job->id)->update(['state' => 'review']);
                    return true;
                }
            }
            $this->db->table('mod_lunixi_outbox')->where('id', $job->id)->update(['state' => ($result['result'] ?? '') === 'success' ? 'sent' : 'review']);
        } catch (\Throwable $e) {
            $this->db->table('mod_lunixi_outbox')->where('id', $job->id)->update(['state' => 'uncertain']);
            throw new \RuntimeException('Outbox delivery is uncertain; review before retry.');
        }
        return true;
    }
    /**
     * Execute only documented native create/unsuspend commands once after commit.
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private function provision(array $data, callable $api): ?array
    {
        $action = $data['action'] ?? '';
        if (!in_array($action, ['create', 'unsuspend'], true) || ($data['serviceid'] ?? 0) < 1 || ($data['invoiceId'] ?? 0) < 1 || ($data['clientId'] ?? 0) < 1) {
            return null;
        }
        $invoice = $this->db->table('tblinvoices')->where('id', $data['invoiceId'])->first();
        $binding = $this->db->table('mod_lunixi_invoices')->where('invoice_id', $data['invoiceId'])->first();
        if ($invoice === null || $binding === null || $invoice->status !== 'Paid' || $binding->state !== 'paid' || (int) $invoice->userid !== $data['clientId']) {
            return null;
        }
        $params = ['clientid' => $data['clientId'], 'serviceid' => $data['serviceid'], 'limitnum' => 1];
        $products = $api('GetClientsProducts', $params);
        $status = $this->serviceStatus($products, $data);
        if ($status === 'Active') {
            return ['result' => 'success'];
        }
        if ($status !== ($action === 'create' ? 'Pending' : 'Suspended')) {
            return null;
        }
        $product = array_values($products['products']['product'])[0];
        $orderId = (int) ($product['orderid'] ?? 0);
        if ($orderId < 1) {
            return null;
        }
        $orders = $api('GetOrders', ['id' => $orderId, 'userid' => $data['clientId'], 'limitnum' => 1]);
        $rows = $orders['orders']['order'] ?? null;
        if (($orders['result'] ?? '') !== 'success' || !is_array($rows) || count($rows) !== 1) {
            return null;
        }
        $order = array_values($rows)[0];
        if (!is_array($order) || (int) ($order['id'] ?? 0) !== $orderId || (int) ($order['userid'] ?? 0) !== $data['clientId'] || !in_array($order['status'] ?? null, ['Pending', 'Active'], true)) {
            return null;
        }
        // Fraud/cancelled/custom manual holds are never overridden by delayed provisioning.
        $result = $api($action === 'create' ? 'ModuleCreate' : 'ModuleUnsuspend', ['serviceid' => $data['serviceid']]);
        if (($result['result'] ?? '') !== 'success') {
            return null;
        }
        if ($this->serviceStatus($api('GetClientsProducts', $params), $data) !== 'Active') {
            throw new \RuntimeException('Provisioning state is uncertain.');
        }
        return ['result' => 'success'];
    }
    /**
     * @param array<string,mixed> $response
     * @param array<string,mixed> $data
     */
    private function serviceStatus(array $response, array $data): ?string
    {
        $rows = $response['products']['product'] ?? null;
        if (($response['result'] ?? '') !== 'success' || !is_array($rows) || count($rows) !== 1) {
            return null;
        }
        $row = array_values($rows)[0];
        if (!is_array($row) || (int) ($row['id'] ?? 0) !== $data['serviceid'] || (int) ($row['clientid'] ?? 0) !== $data['clientId'] || !is_string($row['status'] ?? null)) {
            return null;
        }
        return $row['status'];
    }
}
