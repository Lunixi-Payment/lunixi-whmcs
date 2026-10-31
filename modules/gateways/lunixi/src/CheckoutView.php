<?php

declare(strict_types=1);

namespace Lunixi\WHMCS;

use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\CheckoutSession;

/** Owned view only; its caller must authorize the invoice and validate native CSRF. */
final class CheckoutView
{
    /** @param array{pay:string,error:string,invoice:string} $labels */
    public static function render(CheckoutSession $session, string $locale, array $labels, string $invoiceUrl): string
    {
        $e = [Runtime::class, 'escape'];
        // browserConfig already returns JSON_HEX-safe JSON. Encoding it again
        // makes the browser receive a string instead of the checkout object.
        $json = $session->browserConfig();
        return '<!doctype html><html lang="' . $e($locale) . '"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lunixi Payments</title><link rel="stylesheet" href="assets/checkout.css"><main><img src="assets/lunixi.svg" width="160" alt="Lunixi"><h1>' . $e($labels['pay']) . '</h1><div id="lunixi-checkout-form"></div><p id="lunixi-error" hidden>' . $e($labels['error']) . '</p><a href="' . $e($invoiceUrl) . '">' . $e($labels['invoice']) . '</a></main><script id="lunixi-config" type="application/json">' . $json . '</script><script src="https://cdn.lunixi.com/lunixi-checkout.min.js" defer></script><script src="assets/checkout.js" defer></script></html>';
    }
}
