<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * First name and last name, and the Paystack dedicated-account identifier.
 *
 * ## Why the name is split now
 *
 * Paystack builds a dedicated virtual account's *account name* from the
 * customer's `first_name` and `last_name`, and the Customer API documentation
 * makes both fields **compulsory** — not optional — when the customer is going
 * to be assigned a virtual account and the business category is Betting,
 * Financial services or General Service. ReUp is a wallet, so it is in that set.
 *
 * Until now the application stored one `name` column and guessed the two halves
 * by splitting on the first space, which is wrong for every customer whose name
 * is not "First Last": "Ada Obi Nwosu" became last name "Obi Nwosu" (fine) but
 * "Al-Amin Yusuf" or a single-word name did not survive the round trip, and the
 * name embossed on the account is the one the payer sees in their banking app
 * before sending money. Collecting the two parts at sign-up is the only way to
 * know them rather than infer them.
 *
 * ## `name` is kept, and stays authoritative for display
 *
 * The column is deliberately **not** dropped and not renamed. It is read by the
 * admin console, the dashboard greeting, the chat widgets and dozens of views,
 * and a rename would be a wide, risky change for no benefit. Instead it is
 * derived: `User::syncNameFromParts()` rebuilds it from the two halves on save,
 * and the two halves are derived from it when they are empty (accounts that
 * predate this migration, and rows created by factories or seeders that set
 * only `name`). Every reader keeps working, unchanged, and there is one place
 * that decides what a user's name is.
 *
 * ## Backfill
 *
 * Done in PHP rather than SQL string functions: `SUBSTRING_INDEX` does not exist
 * on SQLite, and the test suite and local installs are not required to be MySQL.
 * A row with a single-word name keeps a null last name, which is honest — the
 * splitter's placeholder ("Customer") is for the *gateway payload*, where an
 * empty value is rejected outright, and has no business being written to the
 * database as somebody's surname.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'first_name')) {
                $table->string('first_name')->nullable()->after('name');
            }

            if (! Schema::hasColumn('users', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }

            /*
             * Paystack's own integer id for the assigned dedicated account.
             *
             * `dva_account_number` is what credits a transfer to a customer, but
             * it is not what the account is *addressed* by: the Fetch Dedicated
             * Account and Requery Dedicated Account endpoints take this id, and
             * the Create response returns it as `data.id`. Storing it is what
             * makes a support question answerable ("re-query this account") and
             * makes the create response's `data.accounts[]` shape readable back.
             */
            if (! Schema::hasColumn('users', 'dva_account_id')) {
                $table->unsignedBigInteger('dva_account_id')->nullable()->after('dva_account_name');
            }

            if (! Schema::hasColumn('users', 'dva_bank_slug')) {
                $table->string('dva_bank_slug')->nullable()->after('dva_bank_name');
            }

            /*
             * When the customer's name and phone were last pushed to Paystack.
             *
             * Not a cache: it is what stops the virtual-account page paying for
             * the same `PUT /customer/{code}` on every single load. A customer
             * record created during card funding has no name or phone on
             * Paystack's side, and the record is never revisited by
             * `ensureCustomer()` once its code is stored — so without a sync the
             * gap is permanent. With one that runs on *every* load, the page
             * makes a pointless write for every returning visitor.
             *
             * It is a column rather than a cache key because the condition it
             * records is a fact about a remote record, and a cache that expired
             * would simply cause one redundant, idempotent update.
             */
            if (! Schema::hasColumn('users', 'paystack_details_synced_at')) {
                $table->timestamp('paystack_details_synced_at')->nullable()->after('dva_bank_slug');
            }
        });

        $this->backfillNames();
    }

    /**
     * Split every existing single-column name into the two new halves.
     *
     * The remainder after the first word is the last name, so "Ada Obi Nwosu"
     * yields first "Ada", last "Obi Nwosu" — which recombines to exactly the
     * stored `name`. Nothing is written to `name` here: it already holds the
     * value, and rewriting it during a migration is how a display name quietly
     * changes under a customer.
     */
    private function backfillNames(): void
    {
        DB::table('users')
            ->select('id', 'name')
            ->whereNull('first_name')
            ->orderBy('id')
            ->chunk(500, function ($users): void {
                foreach ($users as $user) {
                    $name = trim((string) ($user->name ?? ''));

                    if ($name === '') {
                        continue;
                    }

                    $parts = preg_split('/\s+/', $name) ?: [];
                    $first = (string) array_shift($parts);
                    $last = implode(' ', $parts);

                    DB::table('users')->where('id', $user->id)->update([
                        'first_name' => $first,
                        'last_name' => $last === '' ? null : $last,
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['first_name', 'last_name', 'dva_account_id', 'dva_bank_slug', 'paystack_details_synced_at'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
