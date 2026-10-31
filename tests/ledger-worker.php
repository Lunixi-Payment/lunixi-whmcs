<?php
declare(strict_types=1);
if (getenv('LUNIXI_CONTRACT_TEST') !== '1') { exit(2); }
require __DIR__ . '/bootstrap.php';
$c = json_decode(file_get_contents(getenv('LUNIXI_TEST_DB_CONFIG')), true, 512, JSON_THROW_ON_ERROR);
if ($c['database'] !== 'lunixi_whmcs') { exit(2); }
$manager = new Illuminate\Database\Capsule\Manager();
$manager->addConnection(['driver' => 'mysql', 'host' => $c['host'], 'port' => $c['port'], 'database' => $c['database'], 'username' => $c['username'], 'password' => $c['password'], 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '']);
$db = $manager->getConnection();
$ledger = new Lunixi\WHMCS\CapsuleLedger($db);
$ledger->transaction('whmcs:invoice:31', function () use ($ledger): void {
    $r = $ledger->load('whmcs:invoice:31') ?? ['count' => 0];
    usleep(100000);
    ++$r['count'];
    $ledger->save('whmcs:invoice:31', $r);
    $ledger->claimEvent('fixture-merchant', 'concurrent-event', hash('sha256', 'identical-raw-bytes'));
});
echo "committed\n";
