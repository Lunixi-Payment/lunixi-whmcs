<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../../gateways/lunixi/autoload.php';

/**
 * @return array<string,mixed>
 */
function lunixi_payments_config(): array
{
    return ['name' => 'Lunixi Payments', 'description' => \Lunixi\WHMCS\Runtime::text('help'), 'version' => '0.1.3', 'author' => 'Lunixi', 'language' => 'english', 'fields' => []];
}
/**
 * @return array<string,mixed>
 */
function lunixi_payments_activate(): array
{
    try {
        \Lunixi\WHMCS\Schema::install(\WHMCS\Database\Capsule::connection());
        \Lunixi\WHMCS\Schema::assertTransactional(\WHMCS\Database\Capsule::connection());
        return ['status' => 'success', 'description' => 'Lunixi'];
    } catch (Throwable $error) {
        return ['status' => 'error', 'description' => \Lunixi\WHMCS\Runtime::failure($error)];
    }
}
/**
 * @return array<string,mixed>
 */
function lunixi_payments_deactivate(): array
{
    // Deliberately retain immutable profiles, operation IDs, ledger, inbox and outbox.
    return ['status' => 'success', 'description' => 'Lunixi'];
}
/**
 * @param array<string,mixed> $vars
 */
function lunixi_payments_output(array $vars): void
{
    try {
        (new \Lunixi\WHMCS\AdminPage())->render($vars);
    } catch (Throwable $error) {
        echo \Lunixi\WHMCS\Runtime::escape(\Lunixi\WHMCS\Runtime::failure($error));
    }
}
