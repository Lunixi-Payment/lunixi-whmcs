<?php

declare(strict_types=1);

require_once __DIR__ . '/sdk/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'Lunixi\\WHMCS\\';
    if (str_starts_with($class, $prefix) && !str_starts_with($class, $prefix . 'Vendor\\')) {
        $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});
