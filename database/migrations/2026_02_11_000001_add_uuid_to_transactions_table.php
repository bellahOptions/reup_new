<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Canonical UUID per transaction.
 *
 * `reference` is a human-facing string ("FND-261007-XXAGSJUUZXC4") that support
 * staff read aloud and customers quote, so it is worth keeping. But it is a
 * *display* identifier, and it was doing double duty as the gateway reference.
 *
 * `uuid` splits the two concerns: it is the stable machine identifier, it is what
 * is handed to Paystack as the payment reference, and it is what the reconciler
 * uses to poll `GET /transaction/verify/{uuid}`. Keeping it distinct means the
 * display format can change without invalidating references already lodged with
 * the gateway.
 *
 * Backfill is done in PHP rather than SQL because MySQL has no UUID function
 * without an extension, and each existing row needs a *distinct* value for the
 * unique index to be addable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transactions', 'uuid')) {
            Schema::table('transactions', function (Blueprint $table) {
                // Nullable at first: the unique index cannot be created until
                // every existing row has a distinct value.
                $table->uuid('uuid')->nullable()->after('reference');
            });
        }

        // Backfill existing rows.
        DB::table('transactions')
            ->whereNull('uuid')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('transactions')
                        ->where('id', $row->id)
                        ->update(['uuid' => (string) Str::uuid()]);
                }
            });

        // The reconciler looks transactions up by uuid, and the gateway sends it
        // back on webhooks, so it must be unique.
        Schema::table('transactions', function (Blueprint $table) {
            $table->unique('uuid');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropColumn('uuid');
        });
    }
};
