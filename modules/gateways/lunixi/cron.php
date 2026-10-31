<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once __DIR__ . '/../../../init.php';
require_once ROOTDIR . '/includes/gatewayfunctions.php';
require_once ROOTDIR . '/includes/invoicefunctions.php';
require_once __DIR__ . '/autoload.php';
use Lunixi\WHMCS\Runtime;
$integration = Runtime::boot();
$failed = 0;
foreach ($integration->pendingInvoiceIds() as $invoiceId) {
    try {
        $integration->reconcile((int) $invoiceId);
    } catch (Throwable $error) {
        Runtime::failure($error);
        ++$failed;
    }
}
for ($n = 0; $n < 50; ++$n) {
    try {
        if (!$integration->outbox->dispatchOne(static fn (string $action, array $params): array => localAPI($action, $params))) {
            break;
        }
    } catch (Throwable $error) {
        Runtime::failure($error);
        ++$failed;
    }
}
echo json_encode(['reconciliationErrors' => $failed], JSON_THROW_ON_ERROR) . PHP_EOL;
exit($failed === 0 ? 0 : 1);
