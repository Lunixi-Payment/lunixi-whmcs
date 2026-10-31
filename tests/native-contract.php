<?php
/** Explicit stand-ins for official WHMCS contracts. This is NOT the licensed WHMCS runtime. */
declare(strict_types=1);
namespace WHMCS\Billing\Payment {
    class Transaction extends \Illuminate\Database\Eloquent\Model {
        protected $table = 'tblaccounts';
        public $timestamps = false;
        protected $guarded = [];
        public function scopeLookup($query, $gateway, $transactionId) { return $query->where('gateway', $gateway)->where('transid', $transactionId); }
        public function originalTransaction() { return $this->belongsTo(self::class, 'fixture_original_id'); }
    }
}
namespace WHMCS\Authentication {
    class CurrentUser {
        public static $client;
        public static $user;
        public static $admin;
        public function client() { return self::$client; }
        public function user() { return self::$user; }
        public function admin() { return self::$admin; }
        public function isAuthenticatedUser() { return self::$user !== null; }
        public function isAuthenticatedAdmin() { return self::$admin !== null; }
        public function isMasqueradingAdmin() { return self::$admin !== null && self::$client !== null; }
    }
}
namespace {
    function add_hook($name, $priority, $callback) { $GLOBALS['fixture_hooks'][$name][] = $callback; }
    function fixture_hook($name, array $vars): array {
        $result = [];
        foreach ($GLOBALS['fixture_hooks'][$name] ?? [] as $callback) { $result = array_merge($result, $callback($vars) ?? []); }
        return $result;
    }
    function checkCbInvoiceID($id, $gateway) {
        if (!$GLOBALS['fixture_db']->table('tblinvoices')->where('id', $id)->exists()) { throw new \RuntimeException('Invalid native invoice.'); }
        return $id;
    }
    function checkCbTransID($id) {
        if ($GLOBALS['fixture_db']->table('tblaccounts')->where('transid', $id)->exists()) { throw new \RuntimeException('Duplicate native transaction.'); }
    }
    function addInvoicePayment($invoiceId, $transactionId, $amount, $fee, $gateway) {
        if (!is_string($amount) || !preg_match('/^[0-9]+\.[0-9]{2}$/D', $amount) || $amount === '0.00' || $fee !== '0.00' || $gateway !== 'lunixi') { throw new \RuntimeException('Invalid official helper arguments.'); }
        $db = $GLOBALS['fixture_db'];
        $invoice = $db->table('tblinvoices')->where('id', $invoiceId)->first();
        $db->table('tblaccounts')->insert(['userid' => $invoice->userid, 'invoiceid' => $invoiceId, 'currency' => 0, 'gateway' => $gateway, 'transid' => $transactionId, 'amountin' => $amount, 'amountout' => '0.00']);
        $db->table('tblinvoices')->where('id', $invoiceId)->update(['status' => 'Paid']);
        if (!(fixture_hook('EmailPreSend', ['messagename' => 'Invoice Payment Confirmation', 'relid' => $invoiceId])['abortsend'] ?? false)) { ++$GLOBALS['fixture_sent']; }
        fixture_hook('PreModuleCreate', ['params' => ['serviceid' => 42]]);
        if ($GLOBALS['fixture_fail_native'] ?? false) { throw new \RuntimeException('Native helper failed after its writes.'); }
    }
    function localAPI($action, $params) {
        $db = $GLOBALS['fixture_db'];
        if ($action === 'AddTransaction') {
            if (($params['paymentmethod'] ?? '') !== 'lunixi' || isset($params['gateway']) || !is_string($params['amountin']) || !is_string($params['amountout']) || $params['credit'] !== false) { throw new \RuntimeException('Invalid official AddTransaction parameters.'); }
            checkCbTransID($params['transid']);
            $db->table('tblaccounts')->insert(['userid' => $params['userid'], 'invoiceid' => $params['invoiceid'] ?? 0, 'currency' => 0, 'gateway' => $params['paymentmethod'], 'transid' => $params['transid'], 'amountin' => $params['amountin'], 'amountout' => $params['amountout']]);
            return ['result' => 'success']; // Official API returns no transactionid.
        }
        if ($action === 'UpdateInvoice') {
            $db->table('tblinvoices')->where('id', $params['invoiceid'])->update(['status' => $params['status']]);
            return ['result' => 'success'];
        }
        if ($action === 'GetClientsProducts') {
            if ($db->transactionLevel() !== 0) { throw new \RuntimeException('Precommit provisioning lookup forbidden.'); }
            return ['result' => 'success', 'products' => ['product' => [['id' => 42, 'clientid' => 7, 'orderid' => 52, 'status' => $GLOBALS['fixture_service_status'] ?? 'Pending']]]];
        }
        if ($action === 'GetOrders') {
            if ($params !== ['id' => 52, 'userid' => 7, 'limitnum' => 1]) { throw new \RuntimeException('Invalid official GetOrders parameters.'); }
            return ['result' => 'success', 'orders' => ['order' => [['id' => 52, 'userid' => 7, 'status' => $GLOBALS['fixture_order_status'] ?? 'Pending']]]];
        }
        if (in_array($action, ['ModuleCreate', 'ModuleUnsuspend'], true)) {
            if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction() || $params !== ['serviceid' => 42]) { throw new \RuntimeException('Invalid/precommit provisioning command.'); }
            ++$GLOBALS['fixture_provision_calls'];
            $GLOBALS['fixture_service_status'] = 'Active';
            if ($GLOBALS['fixture_lose_provision'] ?? false) { throw new \RuntimeException('Lost provisioning response.'); }
            return ['result' => 'success'];
        }
        if ($action === 'SendEmail') {
            if ($db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) { throw new \RuntimeException('Precommit email is forbidden.'); }
            ++$GLOBALS['fixture_sent'];
            return ['result' => 'success'];
        }
        throw new \RuntimeException('Unsupported contract API.');
    }
    function generate_token($mode) { return 'native-csrf-fixture'; }
    function check_token($scope) {
        $GLOBALS['fixture_csrf_scope'] = $scope;
        if (($_POST['token'] ?? '') !== 'native-csrf-fixture') { throw new \RuntimeException('Invalid native CSRF.'); }
    }
    function logActivity($message) { $GLOBALS['fixture_logs'][] = $message; }
}
