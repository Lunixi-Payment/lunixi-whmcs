<?php
declare(strict_types=1);
$path = $argv[1] ?? '';
if ($path === '' || getenv('CI') !== 'true') { throw new RuntimeException('CI-only disposable connection file.'); }
file_put_contents($path, json_encode(['host' => '127.0.0.1', 'port' => 3306, 'database' => 'lunixi_whmcs', 'username' => 'lunixi_whmcs', 'password' => 'public-disposable-ci-only'], JSON_THROW_ON_ERROR));
chmod($path, 0600);
