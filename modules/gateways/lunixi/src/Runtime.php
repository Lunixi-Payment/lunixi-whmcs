<?php

declare(strict_types=1);

namespace Lunixi\WHMCS;

use WHMCS\Database\Capsule;
use Lunixi\WHMCS\Vendor\Lunixi\Sdk\Commerce\SecretBox;

final class Runtime
{
    public static function boot(): Integration
    {
        if (!defined('WHMCS') || !class_exists(Capsule::class)) {
            throw new \RuntimeException('WHMCS is required.');
        }
        self::registerHooks();
        $raw = getenv('LUNIXI_WHMCS_ENCRYPTION_KEY');
        $key = $raw === false ? false : base64_decode($raw, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('Configure the separate 32-byte server encryption key.');
        }
        $db = Capsule::connection();
        Schema::assertTransactional($db);
        return new Integration($db, new SecretBox($key));
    }
    public static function registerHooks(): void
    {
        static $registered = false;
        if ($registered || !function_exists('add_hook')) {
            return;
        }
        $registered = true;
        add_hook('EmailPreSend', 1, static fn (array $vars): array => Outbox::email($vars));
        foreach (['PreModuleCreate' => 'create', 'PreModuleUnsuspend' => 'unsuspend', 'PreModuleRenew' => 'renew', 'PreModuleChangePackage' => 'changePackage'] as $hook => $action) {
            add_hook($hook, 1, static fn (array $vars): array => Outbox::provisioning($action, $vars));
        }
        add_hook('NotificationPreSend', 1, static function (array $vars): void {
            if (Outbox::isActive()) {
                throw new \WHMCS\Notification\Exception\AbortNotification();
            }
        });
    }
    public static function baseUrl(): string
    {
        global $CONFIG;
        $url = rtrim((string) ($CONFIG['SystemURL'] ?? ''), '/');
        $parsed = parse_url($url);
        if (($parsed['scheme'] ?? '') !== 'https' || !isset($parsed['host']) || (isset($parsed['user']) || isset($parsed['pass'])) || isset($parsed['query']) || isset($parsed['fragment'])) {
            throw new \RuntimeException('Configure an HTTPS WHMCS SystemURL.');
        }
        return $url;
    }
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    public static function language(): string
    {
        global $CONFIG;
        return str_starts_with(strtolower((string) ($_SESSION['Language'] ?? $CONFIG['Language'] ?? 'english')), 'turk') ? 'tr' : 'en';
    }
    public static function text(string $key): string
    {
        $strings = require __DIR__ . '/../lang/' . self::language() . '.php';
        return $strings[$key] ?? $key;
    }
    public static function failure(\Throwable $error): string
    {
        // Never include exception messages, gateway bodies, buyer data or credentials in native logs.
        $reference = bin2hex(random_bytes(8));
        if (function_exists('logActivity')) {
            logActivity('Lunixi error reference ' . $reference);
        }
        return self::text('error') . ' [' . $reference . ']';
    }
}
