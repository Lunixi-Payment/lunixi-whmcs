<?php

declare(strict_types=1);

define('CLIENTAREA', true);
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/autoload.php';
use Lunixi\WHMCS\{Access, Runtime};
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
