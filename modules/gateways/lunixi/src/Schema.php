<?php

declare(strict_types=1);

namespace Lunixi\WHMCS;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/** Additive schema only. Financial history and encrypted profiles survive deactivation. */
final class Schema
{
    public static function install(Connection $db): void
    {
        if ($db->transactionLevel() !== 0) {
            throw new \RuntimeException('Schema migration requires no transaction.');
        }
        $schema = $db->getSchemaBuilder();
        if (!$schema->hasTable('mod_lunixi_attempts')) {
            $schema->create('mod_lunixi_attempts', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->string('order_key', 128)->primary();
                $t->longText('record')->nullable();
            });
        }
        if (!$schema->hasTable('mod_lunixi_events')) {
            $schema->create('mod_lunixi_events', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('event_key', 64)->primary();
                $t->char('payload_hash', 64);
            });
        }
        if (!$schema->hasTable('mod_lunixi_profiles')) {
            $schema->create('mod_lunixi_profiles', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->bigIncrements('id');
                $t->string('label', 100);
                $t->longText('encrypted');
                $t->timestamp('created_at')->useCurrent();
            });
        }
        if (!$schema->hasTable('mod_lunixi_invoices')) {
            $schema->create('mod_lunixi_invoices', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->unsignedBigInteger('invoice_id')->primary();
                $t->unsignedBigInteger('profile_id');
                $t->longText('snapshot');
                $t->bigInteger('applied_base')->default(0);
                $t->bigInteger('applied_fee')->default(0);
                $t->bigInteger('applied_refund')->default(0);
                $t->string('payment_id', 191)->default('');
                $t->string('state', 32)->default('reserved');
                $t->timestamp('checked_at')->nullable()->index();
            });
        }
        if (!$schema->hasTable('mod_lunixi_operations')) {
            $schema->create('mod_lunixi_operations', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 48)->primary();
                $t->unsignedBigInteger('invoice_id')->index();
                $t->unsignedBigInteger('admin_id');
                $t->string('action', 12);
                $t->bigInteger('amount')->nullable();
                $t->string('state', 16)->default('prepared');
                $t->timestamp('created_at')->useCurrent();
            });
        }
        if (!$schema->hasTable('mod_lunixi_outbox')) {
            $schema->create('mod_lunixi_outbox', static function (Blueprint $t): void {
                $t->engine = 'InnoDB';
                $t->char('id', 64)->primary();
                $t->longText('encrypted');
                $t->string('state', 16)->default('ready');
                $t->timestamp('created_at')->useCurrent();
            });
        }
    }

    /** Reject nontransactional native tables before any financial network call. */
    public static function assertTransactional(Connection $db): void
    {
        if ($db->getDriverName() !== 'mysql') {
            throw new \RuntimeException('A MySQL/MariaDB InnoDB database is required.');
        }
        foreach (['tblinvoices', 'tblaccounts', 'tblclients', 'mod_lunixi_attempts', 'mod_lunixi_invoices', 'mod_lunixi_events'] as $table) {
            $row = $db->selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            if ($row === null || strtolower((string) $row->engine) !== 'innodb') {
                throw new \RuntimeException('Transactional native and Lunixi tables are required.');
            }
        }
    }
}
