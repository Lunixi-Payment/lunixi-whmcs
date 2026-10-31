<?php
declare(strict_types=1);
if (getenv('LUNIXI_CONTRACT_TEST') !== '1') { throw new RuntimeException('Explicit disposable contract test opt-in is required.'); }
require dirname(__DIR__) . '/tools/vendor/autoload.php';
require dirname(__DIR__) . '/build/release/modules/gateways/lunixi/sdk/autoload.php';
spl_autoload_register(static function (string $name): void {
    $prefix = 'Lunixi\\WHMCS\\';
    if (str_starts_with($name, $prefix) && !str_starts_with($name, $prefix . 'Vendor\\')) {
        $file = dirname(__DIR__) . '/modules/gateways/lunixi/src/' . str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
        if (is_file($file)) { require_once $file; }
    }
});
require __DIR__ . '/native-contract.php';
require __DIR__ . '/GatewayFixture.php';
