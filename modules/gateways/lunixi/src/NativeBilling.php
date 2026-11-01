<?php

declare(strict_types=1);

namespace Lunixi\WHMCS;

use Illuminate\Database\Connection;
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\Money;
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\PaymentDecision;

/** Official callback helpers for payments; official local API + originalTransaction relation for refunds. */
final class NativeBilling
{
    public function __construct(private Connection $db, private Invoices $invoices, private Outbox $outbox)
    {
    }
    public function apply(int $invoiceId, PaymentDecision $decision): void
    {
        if ($this->db->transactionLevel() !== 1 || !$this->db->getPdo()->inTransaction()) {
            throw new \RuntimeException('Native changes require the ledger transaction.');
        }
        $binding = $this->db->table('mod_lunixi_invoices')->where('invoice_id', $invoiceId)->lockForUpdate()->first();
        if ($binding === null || ($binding->payment_id !== '' && $binding->payment_id !== $decision->paymentId)) {
            throw new \RuntimeException('Native payment identity mismatch.');
        }
        $saved = $this->invoices->assertUnchanged($invoiceId);
        if (Money::compare($decision->capturedAmount, Money::add((string) $binding->applied_base, (string) $binding->applied_fee)) < 0 || Money::compare($decision->refundedAmount, (string) $binding->applied_refund) < 0) {
            throw new \RuntimeException('Native balances cannot move backwards.');
        }
        if ((string) $binding->applied_base === '0' && $this->invoices->snapshot($invoiceId)['status'] !== 'Unpaid' && in_array($decision->state, ['paid', 'partially_refunded', 'refunded'], true)) {
            throw new \RuntimeException('The native invoice is no longer payable; manual review is required.');
        }
        $base = $saved['outstanding'];
        $exponent = Money::exponent($saved['currency']);
        $reference = 'lxw:' . hash('sha256', $decision->paymentId);
        $this->outbox->financial($reference . ':' . $decision->refundedAmount, function () use ($binding, $saved, $invoiceId, $decision, $base, $exponent, $reference): void {
            if ((string) $binding->applied_base === '0' && in_array($decision->state, ['paid', 'partially_refunded', 'refunded'], true)) {
                if ($decision->capturedAmount !== $decision->chargedAmount || Money::compare($decision->capturedAmount, $base) < 0) {
                    throw new \RuntimeException('Held partial capture requires accounting review before invoice application.');
                }
                // Never credit the buyer's installment fee against invoice principal.
                $this->payment($invoiceId, $reference, $base, $exponent);
                $fee = Money::subtract($decision->capturedAmount, $base);
                if (Money::compare($fee, '0') < 0) {
                    throw new \RuntimeException('Incomplete capture cannot pay an invoice.');
                }
                if ($fee !== '0') {
                    $this->transaction($saved, 0, $reference . ':fee', $fee, '0', $exponent);
                }
                $this->db->table('mod_lunixi_invoices')->where('invoice_id', $invoiceId)->update(['applied_base' => $base, 'applied_fee' => $fee]);
            }
            if (Money::compare($decision->refundedAmount, (string) $binding->applied_refund) > 0) {
                if ((string) $binding->applied_base === '0' && !in_array($decision->state, ['paid', 'partially_refunded', 'refunded'], true)) {
                    throw new \RuntimeException('Refund of a held partial capture requires accounting review.');
                }
                $oldBase = Money::minimum((string) $binding->applied_refund, $base);
                $newBase = Money::minimum($decision->refundedAmount, $base);
                $oldFee = Money::subtract((string) $binding->applied_refund, $oldBase);
                $newFee = Money::subtract($decision->refundedAmount, $newBase);
                // Explicit base-first refund allocation; installments are not put in WHMCS's gateway fee field.
                if (Money::compare($newBase, $oldBase) > 0) {
                    $this->transaction($saved, $invoiceId, $reference . ':r:' . $decision->refundedAmount, '0', Money::subtract($newBase, $oldBase), $exponent, $reference);
                }
                if (Money::compare($newFee, $oldFee) > 0) {
                    $this->transaction($saved, 0, $reference . ':fr:' . $decision->refundedAmount, '0', Money::subtract($newFee, $oldFee), $exponent, $reference . ':fee');
                }
                if ($decision->refundedAmount === $decision->capturedAmount) {
                    $result = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Refunded']);
                    if (($result['result'] ?? '') !== 'success') {
                        throw new \RuntimeException('Native refund status update requires review.');
                    }
                }
            }
        }, ['invoiceId' => $invoiceId, 'clientId' => $saved['clientId']]);
        if ($this->db->transactionLevel() !== 1 || !$this->db->getPdo()->inTransaction()) {
            throw new \RuntimeException('A native helper committed outside the ledger boundary.');
        }
        $this->db->table('mod_lunixi_invoices')->where('invoice_id', $invoiceId)->update(['payment_id' => $decision->paymentId, 'applied_refund' => $decision->refundedAmount, 'state' => $decision->state]);
    }
    private function payment(int $invoiceId, string $reference, string $amount, int $exponent): void
    {
        if ($amount === '0') {
            throw new \RuntimeException('A positive explicit invoice amount is mandatory.');
        }
        $old = $this->db->table('tblaccounts')->where('transid', $reference)->first();
        if ($old !== null) {
            if ((string) $old->gateway !== 'lunixi' || (int) $old->invoiceid !== $invoiceId || Money::minor($old->amountin, $exponent) !== $amount || Money::minor($old->amountout, $exponent) !== '0') {
                throw new \RuntimeException('Native transaction reference collision.');
            }
            return;
        }
        $checked = checkCbInvoiceID($invoiceId, 'Lunixi');
        if ((int) $checked !== $invoiceId) {
            throw new \RuntimeException('Native invoice validation failed.');
        }
        checkCbTransID($reference);
        addInvoicePayment($invoiceId, $reference, Money::decimal($amount, $exponent), '0.00', 'lunixi');
        $new = $this->db->table('tblaccounts')->where('gateway', 'lunixi')->where('transid', $reference)->first();
        if ($new === null || (int) $new->invoiceid !== $invoiceId || Money::minor($new->amountin, $exponent) !== $amount || Money::minor($new->amountout, $exponent) !== '0') {
            throw new \RuntimeException('Native payment did not match the confirmed principal.');
        }
    }
    /** @param array<string,mixed> $saved */
    private function transaction(array $saved, int $invoiceId, string $reference, string $in, string $out, int $exponent, ?string $original = null): void
    {
        $existing = $this->db->table('tblaccounts')->where('gateway', 'lunixi')->where('transid', $reference)->first();
        if ($existing === null) {
            $params = ['paymentmethod' => 'lunixi', 'userid' => $saved['clientId'], 'transid' => $reference, 'amountin' => Money::decimal($in, $exponent), 'amountout' => Money::decimal($out, $exponent), 'fees' => '0.00', 'credit' => false, 'description' => Runtime::text('settlement')];
            if ($invoiceId > 0) {
                $params['invoiceid'] = $invoiceId;
            }
            $result = localAPI('AddTransaction', $params);
            if (($result['result'] ?? '') !== 'success') {
                throw new \RuntimeException('Native transaction creation failed.');
            }
            $existing = $this->db->table('tblaccounts')->where('gateway', 'lunixi')->where('transid', $reference)->first();
        }
        if ($existing === null || (int) $existing->userid !== $saved['clientId'] || !in_array((int) $existing->currency, [0, (int) $saved['currencyId']], true) || (int) $existing->invoiceid !== $invoiceId || Money::minor($existing->amountin, $exponent) !== $in || Money::minor($existing->amountout, $exponent) !== $out) {
            throw new \RuntimeException('Native settlement transaction mismatch.');
        }
        if ($original !== null) {
            $type = '\\WHMCS\\Billing\\Payment\\Transaction';
            $parent = $type::lookup('lunixi', $original)->first();
            $refund = $type::lookup('lunixi', $reference)->first();
            if ($parent === null || $refund === null) {
                throw new \RuntimeException('Original native transaction is unavailable.');
            }
            if ($parent->getConnection()->getPdo() !== $this->db->getPdo() || $refund->getConnection()->getPdo() !== $this->db->getPdo()) {
                throw new \RuntimeException('Native transaction models use a different connection.');
            }
            $refund->originalTransaction()->associate($parent);
            $refund->save();
        }
    }
}
