<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The immutable wallet ledger.
 *
 * Every change to a wallet balance writes one row here, in the same database
 * transaction as the balance change itself. The ledger is the audit trail that
 * answers "why is this balance what it is" — the question the `transactions`
 * table cannot answer, because a transaction records what the customer bought
 * and only incidentally what happened to the wallet.
 *
 * ## Why entry types and not just credit/debit
 *
 * `direction` says which way the money moved; `entry_type` says why. A refund
 * and a funding credit are both money in, and treating them as the same thing
 * is what makes a statement unreadable and a reconciliation ambiguous. The
 * five types here are the ones this application can actually produce:
 *
 *   CREDIT            — funding (card, bank transfer, dedicated account)
 *   DEBIT             — a purchase or other spend
 *   REFUND            — a compensating credit for a failed purchase
 *   REVERSAL          — an admin force-fail/cancel of a settled transaction
 *   ADMIN_ADJUSTMENT  — an operator moving a balance by hand
 *
 * ## Immutability
 *
 * Enforced, not merely intended:
 *
 *   * `wallet_id` and `transaction_id` use `restrictOnDelete`, so a wallet or a
 *     transaction that has ledger history cannot be deleted out from under it;
 *   * `WalletLedger` has no `updated_at` and overrides `save()`/`delete()` to
 *     refuse updates;
 *   * nothing in the application updates a ledger row.
 *
 * ## Backfill
 *
 * Existing wallets already have a balance that came from transactions we cannot
 * replay exactly. Rather than invent history, this migration writes a single
 * `ADMIN_ADJUSTMENT` opening entry per wallet whose current balance is non-zero,
 * with `reference` = `OPENING-<wallet id>`. That gives every ledger a true
 * starting point (an opening balance that is *known* to be an assumption)
 * without fabricating the movements that produced it. Wallets at zero get no
 * opening entry, since zero is the natural starting state.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('wallet_ledger')) {
            return;
        }

        Schema::create('wallet_ledger', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // restrictOnDelete: a wallet with ledger history must not be deleted.
            $table->foreignId('wallet_id')->constrained('wallets')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // Nullable: funding movements (and admin adjustments to a wallet that
            // is not tied to a purchase) have no originating transaction.
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();

            // Same precision and scale as every other money column in this
            // schema. Values are written from App\Support\Money, so they are
            // exact to the kobo.
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('NGN');

            $table->enum('direction', ['credit', 'debit']);
            $table->enum('entry_type', ['CREDIT', 'DEBIT', 'REFUND', 'REVERSAL', 'ADMIN_ADJUSTMENT']);

            $table->decimal('balance_before', 15, 2);
            $table->decimal('balance_after', 15, 2);

            // Unique so a naive retry cannot write the same movement twice.
            // Prefixed by entry type, e.g. "TXN-260210-ABCDEFGH", "OPENING-42".
            $table->string('reference')->unique();

            $table->string('description')->nullable();
            $table->json('metadata')->nullable();

            // Who caused it, for ADMIN_ADJUSTMENT and REVERSAL rows.
            $table->unsignedBigInteger('actor_id')->nullable();

            // created_at only: see the class docblock on immutability.
            $table->timestamp('created_at')->useCurrent();

            // Statement rendering, and the reconciliation queries that compare
            // the ledger against the wallet.
            $table->index(['wallet_id', 'created_at'], 'ledger_wallet_created_index');
            $table->index(['user_id', 'created_at'], 'ledger_user_created_index');
            $table->index('entry_type');
        });

        $this->writeOpeningBalances();
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_ledger');
    }

    /**
     * One opening entry per wallet that currently holds a balance.
     *
     * @return void
     */
    private function writeOpeningBalances(): void
    {
        $now = now()->toDateTimeString();

        \Illuminate\Support\Facades\DB::table('wallets')
            ->orderBy('id')
            ->chunkById(200, function ($wallets) use ($now) {
                $rows = [];

                foreach ($wallets as $wallet) {
                    $balance = (string) $wallet->balance;

                    if ((float) $balance == 0.0) {
                        continue;
                    }

                    $rows[] = [
                        'uuid' => (string) \Illuminate\Support\Str::uuid(),
                        'wallet_id' => $wallet->id,
                        'user_id' => $wallet->user_id,
                        'transaction_id' => null,
                        'amount' => $balance,
                        'currency' => 'NGN',
                        'direction' => (float) $balance < 0 ? 'debit' : 'credit',
                        'entry_type' => 'ADMIN_ADJUSTMENT',
                        'balance_before' => '0.00',
                        'balance_after' => $balance,
                        'reference' => 'OPENING-' . $wallet->id,
                        'description' => 'Opening balance recorded when the ledger was introduced.',
                        'metadata' => json_encode(['source' => 'ledger_backfill']),
                        'actor_id' => null,
                        'created_at' => $now,
                    ];
                }

                if ($rows) {
                    \Illuminate\Support\Facades\DB::table('wallet_ledger')->insert($rows);
                }
            });
    }
};
