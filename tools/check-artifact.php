<?php
/** Inspect the actual merchant artifact, including its standalone scoped autoloader. */
declare(strict_types=1);
$root = dirname(__DIR__);
$path = $root . '/dist/lunixi-whmcs-0.1.0.zip';
if (!is_file($path)) { throw new RuntimeException('Build the release ZIP first.'); }
$zip = new ZipArchive();
if ($zip->open($path) !== true) { throw new RuntimeException('Invalid release ZIP.'); }
$count = 0;
for ($index = 0; $index < $zip->numFiles; ++$index) {
    $name = $zip->getNameIndex($index);
    if (!is_string($name) || str_starts_with($name, '/') || str_contains($name, '..') || preg_match('~/(?:tests|vendor|\.git|\.env|build|tmp)(?:/|$)~', $name)) {
        throw new RuntimeException('Unsafe or development-only ZIP entry.');
    }
    $value = $zip->getFromIndex($index);
    if (!is_string($value) || preg_match('~/(?:Users|home)/|git[.][A-Za-z0-9.-]+|-----BEGIN [A-Z ]*PRIVATE KEY-----\R[A-Za-z0-9+/=]{20,}~', $value)) {
        throw new RuntimeException('Internal path or private key material in ZIP.');
    }
    if (str_ends_with($name, '.php') && preg_match('/namespace\s+Lunixi\\\\Sdk(?:[;\\\\])/', $value)) {
        throw new RuntimeException('Unscoped SDK namespace in ZIP.');
    }
    ++$count;
}
foreach (['modules/gateways/lunixi.php', 'modules/gateways/callback/lunixi.php', 'modules/gateways/lunixi/autoload.php', 'modules/gateways/lunixi/sdk/autoload.php', 'modules/gateways/lunixi/sdk/Commerce/CheckoutService.php', 'modules/addons/lunixi_payments/lunixi_payments.php', 'modules/addons/lunixi_payments/hooks.php', 'SECURITY.md', 'LICENSE', 'README.tr.md', 'module-version.json'] as $required) {
    if ($zip->locateName($required) === false) { throw new RuntimeException('Required merchant file is missing.'); }
}
$zip->close();
require $root . '/build/release/modules/gateways/lunixi/autoload.php';
if (!class_exists('Lunixi\\WHMCS\\Vendor\\Lunixi\\Sdk\\Commerce\\CheckoutService') || class_exists('Lunixi\\Sdk\\Commerce\\CheckoutService', false)) {
    throw new RuntimeException('Standalone SDK isolation failed.');
}
$en = require $root . '/modules/gateways/lunixi/lang/en.php';
$tr = require $root . '/modules/gateways/lunixi/lang/tr.php';
if (array_diff_key($en, $tr) !== [] || array_diff_key($tr, $en) !== []) { throw new RuntimeException('EN/TR translation drift.'); }
echo 'Artifact checks passed: ' . $count . ' files; isolated standalone SDK; EN/TR key parity; no private key/internal path/development tree. SHA256 ' . hash_file('sha256', $path) . "\n";
