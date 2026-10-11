<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a name that needs a second look is recorded.
 *
 * ## Why a column rather than a block or a suspension
 *
 * The application already has three ways to act on a customer — `status`,
 * `is_blocked` and the wallet controls — and every one of them is a *decision* with
 * a consequence: a suspended account cannot sign in, a blocked one cannot
 * transact. A suspicious-looking name is not a decision. It is a question, and the
 * overwhelming majority of answers are "that is simply their name": this platform
 * serves customers whose names are one word, four words, hyphenated, apostrophised
 * or written in a script the heuristic was never taught.
 *
 * So it goes in its own column, it blocks nothing, and an administrator sees it on
 * the customer record. Using `status` for this would have meant flagging a
 * customer by making their account unusable, which is the opposite of the
 * requirement — "flag for review", not "punish for looking odd".
 *
 * ## Values
 *
 *   * `null`      — never examined, which is every account that existed before this
 *                   migration and every account the heuristic found nothing odd
 *                   about. Absence is not a pass: it is "no opinion", and it is the
 *                   default so that no existing row changes meaning.
 *   * `pending`   — the heuristic flagged it at sign-up or on a name change. Nobody
 *                   has looked yet.
 *   * `approved`  — an administrator looked and the name is genuine.
 *   * `rejected`  — an administrator looked and it is not, which is a note rather
 *                   than an action: whatever happens to the account is decided with
 *                   the account controls, under the same audit as everything else.
 *
 * `name_review_reason` is the heuristic's own finding in words, stored beside the
 * flag rather than recomputed later. The heuristic will change; what it said on the
 * day is evidence, and a record that silently reinterprets itself is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'name_review')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('name_review', 16)->nullable()->default(null)->after('last_name');

                // A query for "what is waiting on a human?" is the one this column
                // exists for, and it is run on every admin dashboard load.
                $table->index('name_review');
            });
        }

        if (! Schema::hasColumn('users', 'name_review_reason')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('name_review_reason', 255)->nullable()->default(null)->after('name_review');
            });
        }

        /*
         * No backfill, deliberately.
         *
         * Existing accounts are left at `null` — "no opinion" — rather than being
         * run through the heuristic. A migration that flagged a few hundred
         * customers for review on the strength of a rule they never had a chance to
         * satisfy would produce a queue nobody could clear and would train whoever
         * owns it to ignore the flag.
         *
         * The heuristic is applied from now on: at sign-up, and whenever a name is
         * changed.
         */
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'name_review')) {
                // The index is dropped with the column on MySQL, but naming it
                // explicitly keeps the rollback working on a driver that does not.
                $table->dropIndex(['name_review']);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            foreach (['name_review_reason', 'name_review'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
