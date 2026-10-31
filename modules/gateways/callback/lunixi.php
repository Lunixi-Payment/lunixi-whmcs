<?php

declare(strict_types=1);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
$body = file_get_contents('php://input', false, null, 0, 1048577);
if ($body === false || strlen($body) > 1048576) {
    http_response_code(413);
    exit;
}
require_once __DIR__ . '/../../../init.php';
require_once ROOTDIR . '/includes/gatewayfunctions.php';
require_once ROOTDIR . '/includes/invoicefunctions.php';
require_once __DIR__ . '/../lunixi/autoload.php';
use Lunixi\WHMCS\{Access, Runtime};
try {
    $profileId = Access::id($_GET['profile'] ?? null);
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_') && is_string($value)) {
            $headers[str_replace('_', '-', substr($key, 5))] = $value;
        }
    }
    Runtime::boot()->webhook($profileId, $body, $headers);
    http_response_code(200);
    echo 'OK';
} catch (Throwable $error) {
    Runtime::failure($error);
    http_response_code(503);
    echo 'Retry delivery';
}
