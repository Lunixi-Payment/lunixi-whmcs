<?php

declare(strict_types=1);

namespace Lunixi\WHMCS;

/** Native session identity and native CSRF validation, with no session-ID shortcuts. */
final class Access
{
    public static function owner(int $clientId): void
    {
        $current = new \WHMCS\Authentication\CurrentUser();
        $client = $current->client();
        $user = $current->user();
        if ($current->isAuthenticatedAdmin() || $current->isMasqueradingAdmin() || !$current->isAuthenticatedUser() || $client === null || $user === null || (int) $client->id !== $clientId || !$client->isOwnedBy($user)) {
            throw new \RuntimeException('Access denied.');
        }
    }
    /** Called only inside WHMCS's role-gated addon output route, never from a standalone admin endpoint. */
    public static function admin(string $permission): int
    {
        if (!defined('ADMINAREA') || !ADMINAREA) {
            throw new \RuntimeException('Access denied.');
        }
        $current = new \WHMCS\Authentication\CurrentUser();
        $admin = $current->admin();
        if (!$current->isAuthenticatedAdmin() || $admin === null || !$admin->hasPermission($permission)) {
            throw new \RuntimeException('Access denied.');
        }
        return (int) $admin->id;
    }
    public static function csrf(bool $admin = false): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !function_exists('check_token')) {
            throw new \RuntimeException('A native CSRF-protected POST is required.');
        }
        check_token($admin ? 'WHMCS.admin.default' : 'WHMCS.default');
    }
    public static function token(): string
    {
        if (!function_exists('generate_token')) {
            throw new \RuntimeException('Native CSRF service is unavailable.');
        }
        return (string) generate_token('plain');
    }
    public static function id(mixed $value): int
    {
        if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,17}$/D', (string) $value)) {
            throw new \InvalidArgumentException('Invalid identifier.');
        }
        return (int) $value;
    }
}
