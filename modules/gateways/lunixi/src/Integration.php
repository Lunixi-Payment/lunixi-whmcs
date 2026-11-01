<?php

declare(strict_types=1);

namespace Lunixi\WHMCS;

use Illuminate\Database\Connection;
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\LunixiClient;
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\{CheckoutService, CheckoutSession, Money, OrderSnapshot, SecretBox};
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Http\HttpClientInterface;

/** Thin native adapter; payment authentication and settlement rules live in the scoped SDK. */
final class Integration
{
    public readonly Profiles $profiles;
    public readonly Invoices $invoices;
    public readonly Outbox $outbox;
    private CapsuleLedger $ledger;
    private NativeBilling $billing;
    public function __construct(private Connection $db, private SecretBox $box, private ?HttpClientInterface $http = null)
    {
        $this->profiles = new Profiles($db, $box);
        $this->invoices = new Invoices($db);
        $this->outbox = new Outbox($db, $box);
        $this->ledger = new CapsuleLedger($db);
        $this->billing = new NativeBilling($db, $this->invoices, $this->outbox);
    }
    public function checkout(int $id, int $profileId, string $returnUrl, string $locale): CheckoutSession
    {
        Schema::assertTransactional($this->db);
        $profile = $this->profiles->get($profileId);
        $saved = $this->invoices->bind($id, $profileId);
        $snapshot = new OrderSnapshot($profile['merchantId'], self::key($id), $saved['outstanding'], $saved['currency']);
        return $this->service($profileId)->start(self::key($id), $snapshot, $returnUrl, $profile['mode'] === 'hosted', $profile['preauth'], $locale);
    }
    public function reconcile(int $id): void
    {
        $binding = $this->invoices->binding($id);
        try {
            $this->service((int) $binding->profile_id)->reconcile(self::key($id), fn ($decision) => $this->billing->apply($id, $decision));
            $this->syncOperations($id);
        } finally {
            $this->db->table('mod_lunixi_invoices')->where('invoice_id', $id)->update(['checked_at' => gmdate('Y-m-d H:i:s')]);
        }
    }
    /** @param array<string,string> $headers */
    public function webhook(int $profileId, string $body, array $headers): void
    {
        $profile = $this->profiles->get($profileId);
        $service = $this->service($profileId);
        $event = null;
        foreach ($this->profiles->verificationSecrets($profileId) as $secret) {
            try {
                $event = $service->verifyWebhook($body, $headers, $secret);
                break;
            } catch (\Throwable $error) {
            /* No payload, header or exception text is logged. */
            }
        }
        if ($event === null) {
            throw new \RuntimeException('Webhook verification failed.');
        }
        if (($event->envelope()['environment'] ?? null) !== $profile['environment']) {
            throw new \RuntimeException('Webhook environment mismatch.');
        }
        $paymentId = CheckoutService::eventPaymentId($event);
        $data = $event->data();
        $candidate = (string) ($data['orderId'] ?? '');
        $bound = $this->db->table('mod_lunixi_invoices')->where('profile_id', $profileId)->where('payment_id', $paymentId)->first();
        if ($bound === null) {
            $attempt = $this->db->table('mod_lunixi_attempts')->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(record, '$.paymentId')) = ?", [$paymentId])->first();
            if ($attempt !== null && preg_match('/^whmcs:invoice:([1-9][0-9]{0,17})$/D', $attempt->order_key, $match)) {
                $bound = $this->db->table('mod_lunixi_invoices')->where('invoice_id', (int) $match[1])->where('profile_id', $profileId)->first();
            }
        }
        if ($bound !== null) {
            $id = (int) $bound->invoice_id;
        } elseif (preg_match('/^whmcs:invoice:([1-9][0-9]{0,17})$/D', $candidate, $m)) {
            $id = (int) $m[1];
        } else {
            throw new \RuntimeException('Payment is not associated with a reserved invoice.');
        }
        $binding = $this->invoices->binding($id);
        if ((int) $binding->profile_id !== $profileId) {
            throw new \RuntimeException('Historical merchant profile mismatch.');
        }
        $service->reconcile(self::key($id), fn ($decision) => $this->billing->apply($id, $decision), $event);
        $this->syncOperations($id);
    }
    /** Persist an operation before any gateway request. Caller must display/reuse this ID after uncertainty. */
    public function prepare(int $id, int $adminId, string $action, ?string $decimal): string
    {
        if (!in_array($action, ['capture', 'refund', 'void'], true)) {
            throw new \InvalidArgumentException('Invalid financial action.');
        }
        $snapshot = json_decode($this->invoices->binding($id)->snapshot, true, 512, JSON_THROW_ON_ERROR);
        $amount = $action === 'void' ? null : Money::minor($decimal ?? '', Money::exponent($snapshot['currency']));
        if ($amount !== null && $amount === '0') {
            throw new \InvalidArgumentException('A positive amount is required.');
        }
        return $this->db->transaction(function () use ($id, $adminId, $action, $amount): string {
            $binding = $this->db->table('mod_lunixi_invoices')->where('invoice_id', $id)->lockForUpdate()->first();
            if ($binding === null) {
                throw new \RuntimeException('No native payment binding.');
            }
            if ($action === 'refund' && (int) $binding->applied_base < 1) {
                throw new \RuntimeException('Held partial captures require accounting review before a refund.');
            }
            if ($this->db->table('mod_lunixi_operations')->where('invoice_id', $id)->where('state', '!=', 'confirmed')->exists()) {
                throw new \RuntimeException('Retry or reconcile the existing operation first.');
            }
            $operation = bin2hex(random_bytes(24));
            $this->db->table('mod_lunixi_operations')->insert(['id' => $operation, 'invoice_id' => $id, 'admin_id' => $adminId, 'action' => $action, 'amount' => $amount]);
            return $operation;
        });
    }
    public function execute(string $operationId): string
    {
        if (!preg_match('/^[a-f0-9]{48}$/D', $operationId)) {
            throw new \InvalidArgumentException('Invalid operation ID.');
        }
        $op = $this->db->table('mod_lunixi_operations')->where('id', $operationId)->first();
        if ($op === null) {
            throw new \RuntimeException('Prepare the operation before executing it.');
        }
        if ($op->state === 'confirmed') {
            return 'confirmed';
        }
        $id = (int) $op->invoice_id;
        $binding = $this->invoices->binding($id);
        $service = $this->service((int) $binding->profile_id);
        try {
            $service->mutate(self::key($id), $operationId, $op->action, $op->amount === null ? null : (string) $op->amount, fn ($decision) => $this->billing->apply($id, $decision));
        } catch (\Throwable $error) {
            $this->db->table('mod_lunixi_operations')->where('id', $operationId)->update(['state' => 'uncertain']);
            throw $error;
        }
        $state = $service->operationStatus(self::key($id), $operationId)['state'] ?? 'pending';
        $this->db->table('mod_lunixi_operations')->where('id', $operationId)->update(['state' => $state]);
        return $state;
    }
    /** @return array<int,\stdClass> */
    public function operations(int $invoiceId): array
    {
        return $this->db->table('mod_lunixi_operations')->where('invoice_id', $invoiceId)->orderBy('created_at', 'desc')->get(['id', 'action', 'amount', 'state'])->all();
    }
    /** @return array<int,int> */
    public function pendingInvoiceIds(int $limit = 100): array
    {
        return $this->db->table('mod_lunixi_invoices')->whereNotIn('state', ['refunded', 'failed'])->orderBy('checked_at')->orderBy('invoice_id')->limit($limit)->pluck('invoice_id')->all();
    }
    public function retryFailed(int $id): void
    {
        $binding = $this->invoices->binding($id);
        $saved = $this->invoices->snapshot($id);
        if ($saved['status'] !== 'Unpaid' || $saved['outstanding'] < 1) {
            throw new \RuntimeException('Invoice is no longer payable.');
        }
        $this->service((int) $binding->profile_id)->resetFailedAttempt(self::key($id));
        $this->db->table('mod_lunixi_invoices')->where('invoice_id', $id)->update(['payment_id' => '', 'state' => 'reserved']);
    }
    private function syncOperations(int $id): void
    {
        $binding = $this->invoices->binding($id);
        $service = $this->service((int) $binding->profile_id);
        foreach ($this->operations($id) as $op) {
            if ($op->state === 'confirmed') {
                continue;
            }
            $state = $service->operationStatus(self::key($id), $op->id)['state'] ?? null;
            if ($state !== null) {
                $this->db->table('mod_lunixi_operations')->where('id', $op->id)->update(['state' => $state]);
            }
        }
    }
    private function service(int $profileId): CheckoutService
    {
        $profile = $this->profiles->credentials($profileId);
        $client = LunixiClient::create(['baseUrl' => 'https://api-gateway.lunixi.com', 'keyId' => $profile['keyId'], 'privateKey' => $profile['privateKey']], $this->http);
        return new CheckoutService($client, $this->ledger, $this->box);
    }
    public static function key(int $id): string
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Positive invoice ID required.');
        }
        return 'whmcs:invoice:' . $id;
    }
}
