<?php

declare(strict_types=1);

define('CLIENTAREA', true);
require_once __DIR__ . '/../../../init.php';
require_once ROOTDIR . '/includes/gatewayfunctions.php';
require_once __DIR__ . '/autoload.php';
use Lunixi\WHMCS\{Access, CheckoutView, Runtime};
try {
    Access::csrf();
    $id = Access::id($_POST['invoiceid'] ?? null);
    $gateway = getGatewayVariables('lunixi');
    if (empty($gateway['type'])) {
        throw new RuntimeException('Gateway is inactive.');
    }
    $integration = Runtime::boot();
    $snapshot = $integration->invoices->snapshot($id);
    Access::owner($snapshot['clientId']);
    $profileId = Access::id($gateway['profileId'] ?? null);
    $profile = $integration->profiles->get($profileId);
    $return = Runtime::baseUrl() . '/modules/gateways/lunixi/return.php?invoiceid=' . $id;
    $session = $integration->checkout($id, $profileId, $return, Runtime::language());
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    if ($profile['mode'] === 'hosted') {
        header('Location: ' . $session->hostedUrl(), true, 303);
        exit;
    }
    echo CheckoutView::render($session, Runtime::language(), ['pay' => Runtime::text('pay'), 'error' => Runtime::text('error'), 'invoice' => Runtime::text('invoice')], Runtime::baseUrl() . '/viewinvoice.php?id=' . $id);
} catch (Throwable $error) {
    http_response_code(403);
    echo Runtime::escape(Runtime::failure($error));
}
