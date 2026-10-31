<?php
/** Runs ONLY on an operator-provided licensed disposable WHMCS sandbox. No gateway charge is sent. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('LUNIXI_LICENSED_TEST') !== '1' || count($argv) !== 3) {
    fwrite(STDERR, "Usage: LUNIXI_LICENSED_TEST=1 php tests/licensed-acceptance.php /licensed/disposable/whmcs INVOICE_ID\n");
    exit(2);
}
$root = realpath($argv[1]);
if ($root === false || !is_file($root . '/init.php') || !preg_match('/^[1-9][0-9]*$/D', $argv[2])) { throw new RuntimeException('Licensed sandbox and positive disposable invoice ID required.'); }
require $root . '/init.php';
require ROOTDIR . '/includes/gatewayfunctions.php';
require ROOTDIR . '/includes/invoicefunctions.php';
require dirname(__DIR__) . '/build/release/modules/gateways/lunixi/autoload.php';
use Lunixi\WHMCS\{CapsuleLedger, Invoices, Outbox, Runtime, Schema};
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\{Money, SecretBox};
$db = WHMCS\Database\Capsule::connection();
Schema::install($db);
Schema::assertTransactional($db);
Runtime::registerHooks();
$invoiceId = (int) $argv[2];
$invoices = new Invoices($db);
$snapshot = $invoices->snapshot($invoiceId);
if ($snapshot['status'] !== 'Unpaid' || $snapshot['outstanding'] < 1) { throw new RuntimeException('A fresh unpaid disposable test invoice is required.'); }
$key = 'whmcs:invoice:' . $invoiceId;
if ($db->table('mod_lunixi_attempts')->where('order_key', $key)->exists()) { throw new RuntimeException('Use a fresh test invoice with no real payment attempt.'); }
$reference = 'lxw:acceptance:' . bin2hex(random_bytes(16));
$ledger = new CapsuleLedger($db);
$outbox = new Outbox($db, new SecretBox(random_bytes(32)));
$nativeChecks = 0;
try {
    $ledger->transaction($key, function () use ($db, $invoiceId, $reference, $snapshot, $ledger, $outbox, &$nativeChecks): void {
        if ((int) checkCbInvoiceID($invoiceId, 'Lunixi') !== $invoiceId) { throw new RuntimeException('Native invoice helper differs.'); }
        checkCbTransID($reference);
        $ledger->claimEvent('licensed-acceptance', $reference, hash('sha256', $reference));
        $outbox->financial($reference, function () use ($db, $invoiceId, $reference, $snapshot, &$nativeChecks): void {
            addInvoicePayment($invoiceId, $reference, Money::decimal($snapshot['outstanding'], Money::exponent($snapshot['currency'])), '0.00', 'lunixi');
            if ($db->transactionLevel() !== 1 || !$db->getPdo()->inTransaction()) { throw new RuntimeException('Native helper changed the transaction boundary.'); }
            $original = WHMCS\Billing\Payment\Transaction::lookup('lunixi', $reference)->first();
            if ($original === null || $original->getConnection()->getPdo() !== $db->getPdo()) { throw new RuntimeException('Native model/helper connection differs.'); }
            ++$nativeChecks;
            $params = ['paymentmethod' => 'lunixi', 'userid' => $snapshot['clientId'], 'invoiceid' => $invoiceId, 'transid' => $reference . ':refund', 'amountin' => '0.00', 'amountout' => Money::decimal($snapshot['outstanding'], Money::exponent($snapshot['currency'])), 'fees' => '0.00', 'credit' => false];
            $result = localAPI('AddTransaction', $params);
            if (($result['result'] ?? '') !== 'success') { throw new RuntimeException('Native AddTransaction refund differs.'); }
            $refund = WHMCS\Billing\Payment\Transaction::lookup('lunixi', $params['transid'])->first();
            if ($refund === null) { throw new RuntimeException('Native refund transaction missing.'); }
            $refund->originalTransaction()->associate($original);
            $refund->save();
            if ((int) $refund->fresh()->originalTransaction->id !== (int) $original->id) { throw new RuntimeException('Native original-transaction relationship differs.'); }
            ++$nativeChecks;
            $result = localAPI('UpdateInvoice', ['invoiceid' => $invoiceId, 'status' => 'Refunded']);
            if (($result['result'] ?? '') !== 'success') { throw new RuntimeException('Native Refunded status API differs; accounting adaptation required.'); }
            ++$nativeChecks;
        });
        throw new LogicException('LUNIXI_EXPECTED_ACCEPTANCE_ROLLBACK');
    });
} catch (LogicException $expected) {
    if ($expected->getMessage() !== 'LUNIXI_EXPECTED_ACCEPTANCE_ROLLBACK') { throw $expected; }
}
if ($nativeChecks !== 3 || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction() || $db->table('tblaccounts')->where('transid', 'like', $reference . '%')->exists() || $db->table('mod_lunixi_attempts')->where('order_key', $key)->exists() || $invoices->snapshot($invoiceId)['status'] !== 'Unpaid') { throw new RuntimeException('Licensed native rollback acceptance failed.'); }
echo json_encode(['licensedNativeHelperChecks' => $nativeChecks, 'atomicRollback' => true, 'note' => 'Complete the manual checkout/ACL/mail/provisioning matrix in docs/ACCEPTANCE.md before deployment.'], JSON_THROW_ON_ERROR) . PHP_EOL;
