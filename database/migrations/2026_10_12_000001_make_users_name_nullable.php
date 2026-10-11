<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `users.name` becomes nullable.
 *
 * The whole name is now *derived* from `first_name` + `last_name` by
 * `User::booted()`'s `saving` hook, and a sign-up form no longer sends it. That
 * creates one honest gap: a `User::create()` that supplies neither the parts nor
 * a whole name would try to insert NULL into a NOT NULL column and fail with a
 * database error that says nothing about names.
 *
 * Allowing NULL closes it. The column is still populated on every real path (the
 * hook builds it, and anything that sets `name` directly still stores it), so
 * this changes no behaviour — it only changes what happens to a row that has no
 * name, from "the insert is rejected" to "the model's placeholder name is
 * stored".
 *
 * `doctrine/dbal` is not installed, so the change is made with raw DDL. That is
 * safe in both directions: MySQL and SQLite accept `MODIFY` / no-op respectively,
 * and the column is left exactly as the original migration declared it — a
 * `varchar(255)` with no default — minus the NOT NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = Schema::getConnection();
        $table = $connection->getTablePrefix() . 'users';

        if ($connection->getDriverName() === 'mysql') {
            $connection->statement("ALTER TABLE `{$table}` MODIFY `name` VARCHAR(255) NULL");
        }
        // SQLite (and every other driver) has no `MODIFY`, and the column there
        // was created without a NOT NULL constraint to begin with — so there is
        // nothing to do and nothing to fail on.
    }

    public function down(): void
    {
        $connection = Schema::getConnection();
        $table = $connection->getTablePrefix() . 'users';

        if ($connection->getDriverName() === 'mysql') {
            // Re-nullable only after every nameless row has something to fall
            // back to, or the ALTER would fail on the offending rows.
            $connection->table('users')->whereNull('name')->update(['name' => 'ReUp Customer']);
            $connection->statement("ALTER TABLE `{$table}` MODIFY `name` VARCHAR(255) NOT NULL");
        }    }
};
