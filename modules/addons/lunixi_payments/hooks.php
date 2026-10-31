<?php

declare(strict_types=1);

if (!defined('WHMCS')) {
    exit;
}
require_once __DIR__ . '/../../gateways/lunixi/autoload.php';
\Lunixi\WHMCS\Runtime::registerHooks();
