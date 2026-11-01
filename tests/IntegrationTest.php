<?php
declare(strict_types=1);
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Lunixi\WHMCS\{Access, CapsuleLedger, Integration, Outbox, Runtime, Schema};
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Auth\Ed25519Signer;
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\SecretBox;
use PHPUnit\Framework\TestCase;

/** Official callback/API stand-ins over actual MariaDB/InnoDB and actual Illuminate transactions. */
final class IntegrationTest extends TestCase
{
    private $db;
    private Integration $integration;
    private GatewayFixture $http;
    private int $profile;
    private array $profileData;
    protected function setUp(): void
    {
        $file = getenv('LUNIXI_TEST_DB_CONFIG');
        if ($file === false || !is_file($file)) { self::markTestSkipped('Disposable MariaDB fixture is required.'); }
        $c = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        if ($c['database'] !== 'lunixi_whmcs' || getenv('LUNIXI_CONTRACT_TEST') !== '1') { throw new RuntimeException('Refusing non-disposable database.'); }
        $capsule = new Manager();
        $capsule->addConnection(['driver' => 'mysql', 'host' => $c['host'], 'port' => $c['port'], 'database' => $c['database'], 'username' => $c['username'], 'password' => $c['password'], 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();
        $this->db = $capsule->getConnection();
        $GLOBALS['fixture_db'] = $this->db;
        $schema = $this->db->getSchemaBuilder();
        foreach ($schema->getTableListing() as $table) { $schema->drop($table); }
        $schema->create('tblclients', static function (Blueprint $t): void { $t->engine = 'InnoDB'; $t->id(); $t->integer('currency'); });
        $schema->create('tblcurrencies', static function (Blueprint $t): void { $t->engine = 'InnoDB'; $t->id(); $t->string('code', 3); });
        $schema->create('tblinvoices', static function (Blueprint $t): void { $t->engine = 'InnoDB'; $t->id(); $t->integer('userid'); $t->decimal('total', 20, 4); $t->decimal('credit', 20, 4); $t->string('status'); });
        $schema->create('tblaccounts', static function (Blueprint $t): void { $t->engine = 'InnoDB'; $t->id(); $t->integer('userid'); $t->integer('invoiceid'); $t->integer('currency')->default(0); $t->string('gateway'); $t->string('transid')->unique(); $t->decimal('amountin', 20, 4); $t->decimal('amountout', 20, 4); $t->integer('fixture_original_id')->nullable(); });
        Schema::install($this->db);
        $this->db->table('tblcurrencies')->insert(['id' => 1, 'code' => 'TRY']);
        $this->db->table('tblclients')->insert(['id' => 7, 'currency' => 1]);
        $this->db->table('tblinvoices')->insert(['id' => 31, 'userid' => 7, 'total' => '100.0000', 'credit' => '10.0000', 'status' => 'Unpaid']);
        $this->db->table('tblaccounts')->insert(['userid' => 7, 'invoiceid' => 31, 'currency' => 0, 'gateway' => 'banktransfer', 'transid' => 'external-1', 'amountin' => '25.0000', 'amountout' => '0.0000']);
        $GLOBALS['fixture_sent'] = 0;
        $GLOBALS['fixture_service_status'] = 'Pending';
        $GLOBALS['fixture_order_status'] = 'Pending';
        $GLOBALS['fixture_provision_calls'] = 0;
        $GLOBALS['fixture_lose_provision'] = false;
        $GLOBALS['fixture_fail_native'] = false;
        $GLOBALS['fixture_logs'] = [];
        WHMCS\Authentication\CurrentUser::$client = null;
        WHMCS\Authentication\CurrentUser::$user = null;
        WHMCS\Authentication\CurrentUser::$admin = null;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['token' => 'native-csrf-fixture'];
        $this->http = new GatewayFixture();
        $this->integration = new Integration($this->db, new SecretBox(str_repeat('K', 32)), $this->http);
        $this->profileData = ['merchantId' => 'merchant-a', 'keyId' => 'fixture-key', 'privateKey' => Ed25519Signer::generateKeyPair()['privateKey'], 'webhookSecret' => 'fixture-webhook-secret', 'mode' => 'hosted', 'preauth' => false, 'environment' => 'TEST'];
        $this->profile = $this->integration->profiles->create('Fixture profile', $this->profileData);
        Runtime::registerHooks();
    }
    private function start(): void { $this->integration->checkout(31, $this->profile, 'https://billing.example/modules/gateways/lunixi/return.php?invoiceid=31', 'en'); }
    private function event(string $id = 'evt-1', array $extra = []): array
    {
        $body = json_encode(['id' => $id, 'type' => 'payment.captured', 'environment' => 'TEST', 'data' => ['intentId' => 'pi-1', 'orderId' => 'whmcs:invoice:31'] + $extra], JSON_THROW_ON_ERROR);
        $timestamp = gmdate('c');
        return [$body, ['x-lunixi-event-id' => $id, 'x-lunixi-signature-timestamp' => $timestamp, 'x-lunixi-signature' => 'v2=' . hash_hmac('sha256', $id . '.' . $timestamp . '.' . $body, 'fixture-webhook-secret')]];
    }
    public function testNewProfileDefaultsToEmbeddedAndStoresIndependentBackendUrlEncrypted(): void
    {
        $data = $this->profileData; unset($data['mode']);
        $profile = $this->integration->profiles->create('Embedded default', $data);
        self::assertSame('embedded', $this->integration->profiles->get($profile)['mode']);
        $session = $this->integration->checkout(31, $profile, 'https://billing.example/return?invoiceid=31', 'en');
        self::assertSame('https://pay.lunixi.com/payment/backend-hosted-token-12345', $session->hostedUrl());
        self::assertStringContainsString('fixture-browser-token', $session->browserConfig());
        $create = array_values(array_filter($this->http->calls, fn ($c) => str_ends_with($c['url'], '/payments/intents')))[0];
        self::assertArrayNotHasKey('allowedOrigin', json_decode($create['body'], true)['metadata']);
        $record = $this->db->table('mod_lunixi_attempts')->first()->record;
        self::assertStringNotContainsString('backend-hosted-token', $record);
        $again = $this->integration->checkout(31, $profile, 'https://billing.example/return?invoiceid=31', 'en');
        self::assertSame($session->hostedUrl(), $again->hostedUrl()); self::assertCount(1, $this->http->createKeys);
    }
    public function testMissingAndLegacyBackendUrlKeepEmbeddedTokenWithoutNewIntent(): void
    {
        $this->http->paymentPageUrl = null;
        $session = $this->integration->checkout(31, $this->profile, 'https://billing.example/return?invoiceid=31', 'en');
        self::assertStringContainsString('fixture-browser-token', $session->browserConfig());
        try { $session->hostedUrl(); self::fail('Missing backend URL must not be built from token.'); } catch (RuntimeException) { self::assertTrue(true); }
        $row = $this->db->table('mod_lunixi_attempts')->first(); $record = json_decode($row->record, true); unset($record['paymentPageUrl']);
        $this->db->table('mod_lunixi_attempts')->where('order_key', $row->order_key)->update(['record' => json_encode($record, JSON_THROW_ON_ERROR)]);
        $legacy = $this->integration->checkout(31, $this->profile, 'https://billing.example/return?invoiceid=31', 'en');
        self::assertStringContainsString('fixture-browser-token', $legacy->browserConfig());
        self::assertSame('', $legacy->paymentPageUrl); self::assertCount(1, $this->http->createKeys);
    }
    public function testOutstandingUsesNativeExactCreditAndPartialPayments(): void
    {
        $this->start();
        self::assertSame('6500', $this->http->payments['pi-1']['data']['amount']);
        $create = array_values(array_filter($this->http->calls, fn ($c) => str_ends_with($c['url'], '/payments/intents')))[0];
        $body = json_decode($create['body'], true);
        self::assertSame('https://pay.lunixi.com', $body['metadata']['allowedOrigin']);
        self::assertArrayNotHasKey('methods', $body);
        self::assertArrayNotHasKey('environment', $body);
        $record = $this->db->table('mod_lunixi_attempts')->first()->record;
        self::assertStringNotContainsString('fixture-browser-token', $record);
        self::assertStringNotContainsString('fixture-webhook-secret', $this->db->table('mod_lunixi_profiles')->first()->encrypted);
    }
    public function testVerifiedCapturePaysPrincipalExactlyOnceAndDefersEmail(): void
    {
        $this->start(); $this->http->settle(); [$body, $headers] = $this->event();
        $this->integration->webhook($this->profile, $body, $headers);
        $this->integration->webhook($this->profile, $body, $headers);
        self::assertSame('Paid', $this->db->table('tblinvoices')->first()->status);
        self::assertSame(2, $this->db->table('tblaccounts')->count());
        self::assertSame('65.0000', $this->db->table('tblaccounts')->where('gateway', 'lunixi')->first()->amountin);
        self::assertSame(1, $this->db->table('mod_lunixi_events')->count());
        self::assertSame(0, $GLOBALS['fixture_sent']);
        self::assertSame(2, $this->db->table('mod_lunixi_outbox')->count());
        while ($this->integration->outbox->dispatchOne(fn ($a, $p) => localAPI($a, $p))) {}
        self::assertSame(1, $GLOBALS['fixture_sent']);
        self::assertSame(1, $GLOBALS['fixture_provision_calls']);
        self::assertSame('Active', $GLOBALS['fixture_service_status']);
    }
    public function testNativeHelperFailureRollsBackInvoiceEventAndOutboxTogether(): void
    {
        $this->start(); $this->http->settle(); [$body, $headers] = $this->event(); $GLOBALS['fixture_fail_native'] = true;
        try { $this->integration->webhook($this->profile, $body, $headers); self::fail('Expected native rollback.'); } catch (RuntimeException $expected) {}
        self::assertSame('Unpaid', $this->db->table('tblinvoices')->first()->status);
        self::assertSame(1, $this->db->table('tblaccounts')->count());
        self::assertSame(0, $this->db->table('mod_lunixi_events')->count());
        self::assertSame(0, $this->db->table('mod_lunixi_outbox')->count());
        self::assertSame(0, $GLOBALS['fixture_sent']);
        $GLOBALS['fixture_fail_native'] = false;
        $this->integration->webhook($this->profile, $body, $headers);
        self::assertSame('Paid', $this->db->table('tblinvoices')->first()->status);
    }
    public function testLostCreateResponseRecoversOnlySignedOpaqueBoundAttempt(): void
    {
        $this->http->loseCreate = true;
        try { $this->start(); self::fail('Expected uncertainty.'); } catch (RuntimeException $expected) {}
        $this->http->settle(); [$body, $headers] = $this->event();
        $this->integration->webhook($this->profile, $body, $headers);
        self::assertSame('Paid', $this->db->table('tblinvoices')->first()->status);
        self::assertSame(1, count($this->http->createKeys));
    }
    public function testTimeoutRetryPreservesExactCreateKeyAndBody(): void
    {
        $this->http->loseCreate = true;
        try { $this->start(); } catch (RuntimeException $expected) {}
        $this->start();
        $calls = array_values(array_filter($this->http->calls, fn ($c) => str_ends_with($c['url'], '/payments/intents')));
        self::assertCount(2, $calls);
        self::assertSame($calls[0]['body'], $calls[1]['body']);
        self::assertSame($calls[0]['headers']['Idempotency-Key'], $calls[1]['headers']['Idempotency-Key']);
    }
    public function testInstallmentFeeIsSeparateFromPrincipalAndRefundsUseOriginalRelation(): void
    {
        $this->start(); $this->http->payments['pi-1']['data']['amount'] = '7000'; $this->http->settle('CAPTURED', '7000');
        $this->integration->reconcile(31);
        $rows = $this->db->table('tblaccounts')->where('gateway', 'lunixi')->get();
        self::assertCount(2, $rows);
        self::assertSame('65.0000', $rows[0]->amountin);
        self::assertSame('5.0000', $rows[1]->amountin);
        self::assertSame(0, (int) $rows[1]->invoiceid);
        $op = $this->integration->prepare(31, 1, 'refund', '70.00');
        self::assertSame('confirmed', $this->integration->execute($op));
        self::assertSame('confirmed', $this->integration->execute($op));
        self::assertSame('Refunded', $this->db->table('tblinvoices')->first()->status);
        self::assertSame(2, $this->db->table('tblaccounts')->whereNotNull('fixture_original_id')->count());
        self::assertSame(1, count($this->http->mutationKeys));
    }
    public function testLostRefundResponseUsesSameDurableOperationAndDoesNotDoubleCredit(): void
    {
        $this->start(); $this->http->settle(); $this->integration->reconcile(31);
        $op = $this->integration->prepare(31, 2, 'refund', '10.00');
        $this->http->loseMutation = true;
        try { $this->integration->execute($op); self::fail('Expected lost response.'); } catch (RuntimeException $expected) {}
        self::assertSame('uncertain', $this->integration->operations(31)[0]->state);
        try { $this->integration->prepare(31, 2, 'refund', '10.00'); self::fail('Created a new key while uncertain.'); } catch (RuntimeException $expected) {}
        self::assertSame('confirmed', $this->integration->execute($op));
        self::assertSame('confirmed', $this->integration->execute($op));
        self::assertCount(1, $this->http->mutationKeys);
        self::assertSame(3, $this->db->table('tblaccounts')->count());
        self::assertSame('10.0000', $this->db->table('tblaccounts')->where('amountout', '>', '0')->first()->amountout);
    }
    public function testAsyncRefundRemainsPendingUntilAuthenticatedBalanceConfirms(): void
    {
        $this->start(); $this->http->settle(); $this->integration->reconcile(31);
        $op = $this->integration->prepare(31, 2, 'refund', '10.00'); $this->http->async = true;
        self::assertSame('pending', $this->integration->execute($op));
        self::assertSame(2, $this->db->table('tblaccounts')->count());
        $this->http->settle('PARTIALLY_REFUNDED', '6500', '1000');
        $this->integration->reconcile(31);
        self::assertSame('confirmed', $this->integration->operations(31)[0]->state);
        self::assertSame(3, $this->db->table('tblaccounts')->count());
    }
    public function testPartialCaptureStaysUnpaidUntilRemainingCapture(): void
    {
        $this->start(); $this->http->settle('AUTHORIZED', '0'); $this->integration->reconcile(31);
        $op = $this->integration->prepare(31, 1, 'capture', '20.00');
        self::assertSame('confirmed', $this->integration->execute($op));
        self::assertSame('Unpaid', $this->db->table('tblinvoices')->first()->status);
        self::assertSame(1, $this->db->table('tblaccounts')->count());
        try { $this->integration->prepare(31, 1, 'refund', '5.00'); self::fail('Held capture refund needs accounting review.'); } catch (RuntimeException $expected) {}
        $op = $this->integration->prepare(31, 1, 'capture', '45.00');
        self::assertSame('confirmed', $this->integration->execute($op));
        self::assertSame('Paid', $this->db->table('tblinvoices')->first()->status);
    }
    public function testVoidNeverCreatesPaymentOrMarksInvoicePaid(): void
    {
        $this->start(); $this->http->settle('AUTHORIZED', '0'); $this->integration->reconcile(31);
        $op = $this->integration->prepare(31, 1, 'void', null);
        self::assertSame('confirmed', $this->integration->execute($op));
        self::assertSame('Unpaid', $this->db->table('tblinvoices')->first()->status);
        self::assertSame('failed', $this->integration->invoices->binding(31)->state);
    }
    public function testChangedInvoiceCannotBePaidOrConsumeWebhook(): void
    {
        $this->start(); $this->http->settle(); $this->db->table('tblinvoices')->where('id', 31)->update(['credit' => '20.00']);
        [$body, $headers] = $this->event();
        try { $this->integration->webhook($this->profile, $body, $headers); self::fail('Accepted changed invoice.'); } catch (RuntimeException $expected) {}
        self::assertSame(0, $this->db->table('mod_lunixi_events')->count());
        self::assertSame('Unpaid', $this->db->table('tblinvoices')->first()->status);
    }
    public function testCancelledNativeInvoiceIsNeverAutomaticallyPaid(): void
    {
        $this->start(); $this->http->settle(); $this->db->table('tblinvoices')->where('id', 31)->update(['status' => 'Cancelled']);
        $this->expectException(RuntimeException::class); $this->integration->reconcile(31);
    }
    public function testSignedWebhookCannotReplacePaymentOrMerchantIdentity(): void
    {
        $this->start(); $this->http->settle(); $this->http->tamper = $this->http->payments['pi-1']; $this->http->tamper['data']['merchantId'] = 'merchant-b';
        [$body, $headers] = $this->event();
        $this->expectException(RuntimeException::class); $this->integration->webhook($this->profile, $body, $headers);
    }
    public function testRawByteTamperStaleSignatureAndEnvironmentAreRejected(): void
    {
        $this->start(); $this->http->settle(); [$body, $headers] = $this->event();
        foreach ([$body . ' ', str_replace('TEST', 'LIVE', $body)] as $bad) {
            try { $this->integration->webhook($this->profile, $bad, $headers); self::fail('Tamper accepted.'); } catch (RuntimeException $expected) { self::assertSame(0, $this->db->table('mod_lunixi_events')->count()); }
        }
        $bad = str_replace('TEST', 'LIVE', $body); $ts = $headers['x-lunixi-signature-timestamp']; $headers['x-lunixi-signature'] = 'v2=' . hash_hmac('sha256', 'evt-1.' . $ts . '.' . $bad, 'fixture-webhook-secret');
        try { $this->integration->webhook($this->profile, $bad, $headers); self::fail('Wrong signed environment accepted.'); } catch (RuntimeException $expected) { self::assertSame(0, $this->db->table('mod_lunixi_events')->count()); }
        $ts = gmdate('c', time() - 900); $headers['x-lunixi-signature-timestamp'] = $ts; $headers['x-lunixi-signature'] = 'v2=' . hash_hmac('sha256', 'evt-1.' . $ts . '.' . $body, 'fixture-webhook-secret');
        $this->expectException(RuntimeException::class); $this->integration->webhook($this->profile, $body, $headers);
    }
    public function testEventHashCollisionRejectsDifferentSignedPayload(): void
    {
        $this->start(); $this->http->settle(); [$body, $headers] = $this->event(); $this->integration->webhook($this->profile, $body, $headers);
        [$body, $headers] = $this->event('evt-1', ['other' => 'different']);
        $this->expectException(RuntimeException::class); $this->integration->webhook($this->profile, $body, $headers);
    }
    public function testRotationCannotCrossMerchantOrTestLiveScope(): void
    {
        $this->start();
        $new = $this->profileData; $new['keyId'] = 'rotated-test'; $new['webhookSecret'] = 'rotated-secret';
        $this->integration->profiles->create('Rotated test', $new);
        $live = $new; $live['environment'] = 'LIVE'; $live['keyId'] = 'live-key';
        $this->integration->profiles->create('Live', $live);
        $other = $new; $other['merchantId'] = 'merchant-b'; $other['keyId'] = 'foreign-key';
        $this->integration->profiles->create('Other merchant', $other);
        self::assertSame('rotated-test', $this->integration->profiles->credentials($this->profile)['keyId']);
        self::assertSame(['fixture-webhook-secret', 'rotated-secret'], $this->integration->profiles->verificationSecrets($this->profile));
        $this->http->settle(); [$body, $headers] = $this->event();
        $ts = $headers['x-lunixi-signature-timestamp']; $headers['x-lunixi-signature'] = 'v2=' . hash_hmac('sha256', 'evt-1.' . $ts . '.' . $body, 'rotated-secret');
        $this->integration->webhook($this->profile, $body, $headers);
        self::assertSame('Paid', $this->db->table('tblinvoices')->first()->status);
    }
    public function testNativeCsrfAndOwnerAreRequired(): void
    {
        $client = new class { public int $id = 7; public function isOwnedBy($user) { return $user->id === 8; } };
        WHMCS\Authentication\CurrentUser::$client = $client;
        WHMCS\Authentication\CurrentUser::$user = (object) ['id' => 8];
        Access::owner(7); Access::csrf(); self::assertSame('WHMCS.default', $GLOBALS['fixture_csrf_scope']);
        try { Access::owner(9); self::fail('IDOR accepted.'); } catch (RuntimeException $expected) {}
        $_POST['token'] = 'forged';
        $this->expectException(RuntimeException::class); Access::csrf(true);
    }
    public function testLedgerRefusesNestedIlluminateOrPdoTransactions(): void
    {
        $ledger = new CapsuleLedger($this->db);
        $this->db->beginTransaction();
        try { $ledger->transaction('whmcs:invoice:31', fn () => null); self::fail('Outer Capsule transaction accepted.'); } catch (RuntimeException $expected) {}
        finally { $this->db->rollBack(); }
        $this->db->getPdo()->beginTransaction();
        try { $ledger->transaction('whmcs:invoice:31', fn () => null); self::fail('Outer PDO transaction accepted.'); } catch (RuntimeException $expected) {}
        finally { $this->db->getPdo()->rollBack(); }
        self::assertSame(0, $this->db->transactionLevel());
    }
    public function testNativeNonTransactionalTablesRejectBeforeNetwork(): void
    {
        $this->db->statement('ALTER TABLE tblaccounts ENGINE=MyISAM');
        try { $this->start(); self::fail('Nontransactional native table accepted.'); } catch (RuntimeException $expected) {}
        self::assertSame([], $this->http->calls);
    }
    public function testFractionalMinorAndForeignCurrencyAreRejectedBeforeNetwork(): void
    {
        $this->db->table('tblinvoices')->where('id', 31)->update(['total' => '100.0001']);
        try { $this->start(); self::fail('Fractional cent accepted.'); } catch (InvalidArgumentException $expected) {}
        $this->db->table('tblinvoices')->where('id', 31)->update(['total' => '100.0000']);
        $this->db->table('tblaccounts')->update(['currency' => 99]);
        try { $this->start(); self::fail('Foreign currency transaction accepted.'); } catch (RuntimeException $expected) {}
        self::assertSame([], $this->http->calls);
    }
    public function testSafeErrorLogNeverContainsExceptionSecrets(): void
    {
        $message = Runtime::failure(new RuntimeException('PRIVATEKEY buyer@example.com token-secret'));
        self::assertStringNotContainsString('PRIVATEKEY', $message);
        self::assertStringNotContainsString('buyer@example.com', json_encode($GLOBALS['fixture_logs']));
    }
    public function testConcurrentProcessesSerializeDurableLedgerAndDeduplicateInbox(): void
    {
        // Existing rows caused INSERT IGNORE shared-to-exclusive lock-upgrade deadlocks.
        $this->db->table('mod_lunixi_attempts')->insert(['order_key' => 'whmcs:invoice:31', 'record' => '{"count":0}']);
        $workers = [];
        for ($n = 0; $n < 4; ++$n) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, __DIR__ . '/ledger-worker.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $workers[] = [$process, $pipes];
        }
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $error);
            self::assertSame("committed\n", $output);
        }
        $r = json_decode($this->db->table('mod_lunixi_attempts')->first()->record, true);
        self::assertSame(4, $r['count']);
        self::assertSame(1, $this->db->table('mod_lunixi_events')->count());
    }
    public function testSignedEventStatusAloneCannotPayInvoice(): void
    {
        $this->start(); [$body, $headers] = $this->event('evt-pending', ['status' => 'CAPTURED', 'capturedAmountMinor' => '6500']);
        $this->integration->webhook($this->profile, $body, $headers);
        self::assertSame('Unpaid', $this->db->table('tblinvoices')->first()->status);
        self::assertSame(1, $this->db->table('tblaccounts')->count());
    }
    public function testUncertainOutboxDeliveryIsNeverBlindlyRepeated(): void
    {
        $this->start(); $this->http->settle(); $this->integration->reconcile(31);
        try { while ($this->integration->outbox->dispatchOne(function ($action, $params) { if ($action === 'SendEmail') { throw new RuntimeException('Lost mail response'); } return localAPI($action, $params); })) {} self::fail('Expected uncertain outbox delivery.'); } catch (RuntimeException $expected) {}
        self::assertSame(1, $this->db->table('mod_lunixi_outbox')->where('state', 'uncertain')->count());
        while ($this->integration->outbox->dispatchOne(fn ($a, $p) => localAPI($a, $p))) {}
        self::assertSame(0, $GLOBALS['fixture_sent']);
        foreach ($this->integration->outbox->attention() as $job) { $this->integration->outbox->acknowledge($job['id']); }
        self::assertSame([], $this->integration->outbox->attention());
    }

    public function testNewAttemptOnlyAfterAuthenticatedTerminalFailure(): void
    {
        $this->start();
        try { $this->integration->retryFailed(31); self::fail('Reset pending payment.'); } catch (RuntimeException $expected) {}
        $this->http->settle('FAILED', '0');
        try { $this->integration->retryFailed(31); self::fail('FAILED alone does not prove definitive provider failure.'); } catch (RuntimeException $expected) {}
        $this->http->settle('VOIDED', '0');
        $this->integration->retryFailed(31);
        $this->start();
        self::assertCount(2, $this->http->createKeys);
        self::assertCount(2, $this->http->payments);
        $record = json_decode($this->db->table('mod_lunixi_attempts')->first()->record, true);
        self::assertSame('pi-1', $record['history'][0]['paymentId']);
        self::assertSame('pi-2', $record['paymentId']);
    }

    public function testLostProvisioningResponseNeverRepeatsRemoteCommand(): void
    {
        $this->start(); $this->http->settle(); $this->integration->reconcile(31);
        $GLOBALS['fixture_lose_provision'] = true;
        try { while ($this->integration->outbox->dispatchOne(fn ($a, $p) => localAPI($a, $p))) {} } catch (RuntimeException $expected) {}
        self::assertSame(1, $GLOBALS['fixture_provision_calls']);
        self::assertSame('Active', $GLOBALS['fixture_service_status']);
        self::assertSame(1, $this->db->table('mod_lunixi_outbox')->where('state', 'uncertain')->count());
        while ($this->integration->outbox->dispatchOne(fn ($a, $p) => localAPI($a, $p))) {}
        self::assertSame(1, $GLOBALS['fixture_provision_calls']);
    }
    public function testAlreadyActiveOrRefundedServiceCannotBeProvisionedAgain(): void
    {
        $this->start(); $this->http->settle(); $this->integration->reconcile(31);
        $GLOBALS['fixture_service_status'] = 'Active';
        while ($this->integration->outbox->dispatchOne(fn ($a, $p) => localAPI($a, $p))) {}
        self::assertSame(0, $GLOBALS['fixture_provision_calls']);
        self::assertSame([], $this->integration->outbox->attention());
    }

    public function testSplitParentMustBeCompleteBalancedAndUncompensated(): void
    {
        $this->start();
        $this->http->payments['pi-1']['data']['status'] = 'SETTLED_BY_SPLIT';
        $this->http->payments['pi-1']['data']['split'] = ['role' => 'PARENT', 'splitPaymentId' => 'split-1'];
        $this->http->split = ['id' => 'split-1', 'currency' => 'TRY', 'orderId' => 'whmcs:invoice:31', 'status' => 'PROCESSING', 'needsReconciliation' => false, 'aggregate' => ['amountsBalanced' => true, 'totalAmount' => '6500', 'legsAmount' => '6500', 'capturedAmount' => '2000', 'compensatedAmount' => '0']];
        $this->integration->reconcile(31);
        self::assertSame('Unpaid', $this->db->table('tblinvoices')->first()->status);
        $this->http->split['status'] = 'COMPLETED';
        $this->http->split['aggregate']['capturedAmount'] = '6500';
        $this->http->split['aggregate']['compensatedAmount'] = '1';
        $this->integration->reconcile(31);
        self::assertSame('Unpaid', $this->db->table('tblinvoices')->first()->status);
        $this->http->split['aggregate']['compensatedAmount'] = '0';
        $this->integration->reconcile(31);
        self::assertSame('Paid', $this->db->table('tblinvoices')->first()->status);
    }
    public function testPartialRefundOfHeldCaptureNeverCreatesPrincipalTransaction(): void
    {
        $this->start(); $this->http->settle('PARTIALLY_REFUNDED', '2000', '500');
        try { $this->integration->reconcile(31); self::fail('Held partial capture paid invoice.'); } catch (RuntimeException $expected) {}
        self::assertSame('Unpaid', $this->db->table('tblinvoices')->first()->status);
        self::assertSame(1, $this->db->table('tblaccounts')->count());
        self::assertSame(0, $this->db->table('mod_lunixi_outbox')->count());
    }
    public function testUnexplainedInstallmentOverchargeIsRejected(): void
    {
        $this->start(); $this->http->payments['pi-1']['data']['amount'] = '7000';
        $this->http->payments['pi-1']['data']['metadata'] = [];
        $this->http->settle('CAPTURED', '7000');
        $this->expectException(RuntimeException::class); $this->integration->reconcile(31);
    }

    public function testNativeAdminPermissionAndMasqueradeRestrictions(): void
    {
        if (!defined('ADMINAREA')) { define('ADMINAREA', true); }
        $admin = new class { public int $id = 19; public bool $permitted = false; public function hasPermission($permission): bool { return $this->permitted && $permission === 'Manage Invoice'; } };
        WHMCS\Authentication\CurrentUser::$admin = $admin;
        try { Access::admin('Manage Invoice'); self::fail('Denied native permission accepted.'); } catch (RuntimeException $expected) {}
        $admin->permitted = true;
        self::assertSame(19, Access::admin('Manage Invoice'));
        try { Access::admin('Configure Payment Gateways'); self::fail('Invoice permission allowed credential editing.'); } catch (RuntimeException $expected) {}
        WHMCS\Authentication\CurrentUser::$client = new class { public int $id = 7; public function isOwnedBy($user): bool { return true; } };
        WHMCS\Authentication\CurrentUser::$user = (object) ['id' => 8];
        $this->expectException(RuntimeException::class); Access::owner(7);
    }

    public function testNativeFraudOrCustomHoldPreventsDelayedProvisioning(): void
    {
        $this->start(); $this->http->settle(); $this->integration->reconcile(31);
        $GLOBALS['fixture_order_status'] = 'Fraud';
        while ($this->integration->outbox->dispatchOne(fn ($a, $p) => localAPI($a, $p))) {}
        self::assertSame(0, $GLOBALS['fixture_provision_calls']);
        self::assertSame('Pending', $GLOBALS['fixture_service_status']);
        self::assertSame(1, $this->db->table('mod_lunixi_outbox')->where('state', 'review')->count());
    }

}
