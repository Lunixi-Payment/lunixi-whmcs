<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/lunixi/autoload.php';

/**
 * @return array<string,mixed>
 */
function lunixi_MetaData(): array
{
    return ['DisplayName' => 'Lunixi Payments', 'APIVersion' => '1.1', 'DisableLocalCreditCardInput' => true, 'TokenisedStorage' => false];
}
/**
 * @return array<string,mixed>
 */
function lunixi_config(): array
{
    return [
        'FriendlyName' => ['Type' => 'System', 'Value' => 'Lunixi Payments'],
        'profileId' => ['FriendlyName' => \Lunixi\WHMCS\Runtime::text('profile_id'), 'Type' => 'text', 'Size' => '8', 'Description' => \Lunixi\WHMCS\Runtime::text('configure_addon')],
    ];
}
/**
 * @param array<string,mixed> $params
 */
function lunixi_link(array $params): string
{
    try {
        $id = \Lunixi\WHMCS\Access::id($params['invoiceid'] ?? null);
        $base = \Lunixi\WHMCS\Runtime::baseUrl();
        $e = [\Lunixi\WHMCS\Runtime::class, 'escape'];
        return '<form method="post" action="' . $e($base . '/modules/gateways/lunixi/checkout.php') . '"><input type="hidden" name="invoiceid" value="' . $id . '"><input type="hidden" name="token" value="' . $e(\Lunixi\WHMCS\Access::token()) . '"><button class="btn btn-primary" type="submit">' . $e(\Lunixi\WHMCS\Runtime::text('pay')) . '</button></form>';
    } catch (\Throwable $error) {
        return \Lunixi\WHMCS\Runtime::escape(\Lunixi\WHMCS\Runtime::failure($error));
    }
}
/** WHMCS has no durable native refund request ID: all real refunds use the role/CSRF-gated addon. */
/**
 * @param array<string,mixed> $params
 * @return array<string,mixed>
 */
function lunixi_refund(array $params): array
{
    return ['status' => 'error', 'rawdata' => ['code' => 'LUNIXI_USE_DURABLE_ADDON_OPERATION'], 'error' => \Lunixi\WHMCS\Runtime::text('refund_addon')];
}
