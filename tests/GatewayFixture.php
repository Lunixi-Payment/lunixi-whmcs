<?php
declare(strict_types=1);
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Http\{HttpClientInterface, HttpResponse};

/** Local deterministic HTTP fixture; no Lunixi account or network payment is created. */
final class GatewayFixture implements HttpClientInterface
{
    public array $calls = [];
    public array $payments = [];
    public array $createKeys = [];
    public array $mutationKeys = [];
    public bool $loseCreate = false;
    public ?string $paymentPageUrl = "https://pay.lunixi.com/payment/backend-hosted-token-12345";
    public bool $loseMutation = false;
    public bool $async = false;
    public ?array $tamper = null;
    public ?array $split = null;
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        if (str_ends_with($url, '/auth/token')) { return $this->json(['access_token' => 'fixture-bearer', 'expires_in' => 3600]); }
        $data = $body === null ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (str_ends_with($url, '/payments/intents')) {
            $key = $headers['Idempotency-Key'];
            $id = $this->createKeys[$key] ?? 'pi-' . (count($this->payments) + 1);
            $this->createKeys[$key] = $id;
            $this->payments[$id] ??= ['data' => ['id' => $id, 'merchantId' => 'merchant-a', 'orderId' => $data['orderId'], 'currency' => $data['currency'], 'amount' => $data['amount'], 'metadata' => $data['metadata'], 'status' => 'INITIATED'], 'details' => ['capturedAmount' => '0', 'refundedAmount' => '0']];
            if ($this->loseCreate) { $this->loseCreate = false; throw new RuntimeException('Fixture lost create response.'); }
            return $this->json(['data' => ['paymentId' => $id, 'token' => 'fixture-browser-token'] + ($this->paymentPageUrl === null ? [] : ['paymentPageUrl' => $this->paymentPageUrl])]);
        }
        if (str_ends_with($url, '/payments/split/split-1')) { return $this->json(['data' => $this->split]); }
        if (!preg_match('~/payments/(pi-[0-9]+)(?:/(capture|refund|void))?$~D', $url, $m)) { throw new RuntimeException('Unexpected fixture endpoint: ' . $url); }
        $id = $m[1];
        if ($method === 'GET') { return $this->json($this->tamper ?? $this->payments[$id]); }
        $key = $headers['Idempotency-Key'];
        if (!isset($this->mutationKeys[$key])) {
            $this->mutationKeys[$key] = $body;
            if (!$this->async) {
                $p = &$this->payments[$id];
                if ($m[2] === 'refund') {
                    $p['details']['refundedAmount'] = \Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\Money::add($p['details']['refundedAmount'], $data['amount']);
                    $p['data']['status'] = $p['details']['refundedAmount'] === $p['details']['capturedAmount'] ? 'REFUNDED' : 'PARTIALLY_REFUNDED';
                } elseif ($m[2] === 'capture') {
                    $p['details']['capturedAmount'] = \Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\Money::add($p['details']['capturedAmount'], $data['amount']);
                    $p['data']['status'] = $p['details']['capturedAmount'] === $p['data']['amount'] ? 'CAPTURED' : 'PARTIALLY_CAPTURED';
                } else { $p['data']['status'] = 'VOIDED'; }
            }
        } elseif ($this->mutationKeys[$key] !== $body) { throw new RuntimeException('Mutation key changed payload.'); }
        if ($this->loseMutation) { $this->loseMutation = false; throw new RuntimeException('Fixture lost mutation response.'); }
        return $this->json(['data' => ['id' => $id, 'status' => $this->payments[$id]['data']['status']]]);
    }
    public function settle(string $status = 'CAPTURED', string $captured = '6500', string $refunded = '0', string $id = 'pi-1'): void
    {
        $this->payments[$id]['data']['status'] = $status;
        $this->payments[$id]['details'] = ['capturedAmount' => (string) $captured, 'refundedAmount' => (string) $refunded];
    }
    private function json(array $data): HttpResponse { return new HttpResponse(200, [], json_encode($data, JSON_THROW_ON_ERROR)); }
}
