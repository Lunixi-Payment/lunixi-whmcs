<?php

declare(strict_types=1);

require_once __DIR__ . '/autoload.php';
use Lunixi\WHMCS\{Access, Runtime};
// Informational return adapter deliberately runs before WHMCS session/CSRF
// bootstrap. It exposes no invoice data and performs no native/gateway write.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if ($raw === false || strlen($raw) > 1048576) {
        http_response_code(413);
        exit;
    }
    try {
        $id = Access::id($_GET['invoiceid'] ?? null);
    } catch (Throwable $error) {
        http_response_code(400);
        exit;
    }
    header('Cache-Control: no-store');
    header('Location: return.php?invoiceid=' . $id, true, 303);
    exit;
}
if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    exit;
}
define('CLIENTAREA', true);
require_once __DIR__ . '/../../../init.php';
try {
    $id = Access::id($_GET['invoiceid'] ?? null);
    $integration = Runtime::boot();
    Access::owner($integration->invoices->snapshot($id)['clientId']);
    // Browser status, transaction ID and amount are deliberately never payment evidence.
    header('Cache-Control: no-store');
    header('Location: ' . Runtime::baseUrl() . '/viewinvoice.php?id=' . $id, true, 303);
} catch (Throwable $error) {
    http_response_code(403);
    echo Runtime::escape(Runtime::failure($error));
}
