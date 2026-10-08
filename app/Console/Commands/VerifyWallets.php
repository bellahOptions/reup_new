<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Console\Command;

/**
 * Prove that every wallet balance equals the sum of its ledger.
 *
 * This is the invariant the immutable ledger exists to make checkable. It is
 * written in the same database transaction as every balance change, so the two
 * must agree exactly; a wallet that fails this check has had its balance touched
 * outside `WalletService`, which is the one thing the architecture forbids.
 *
 * ## Usage
 *
 * ```
 * php artisan wallet:verify                 # every wallet; non-zero exit on drift
 * php artisan wallet:verify --user=42       # one customer
 * php artisan wallet:verify --statement=42  # print that customer's statement
 * ```
 *
 * ## On historical drift
 *
 * Wallets created before the ledger existed were given a single opening
 * `ADMIN_ADJUSTMENT` entry recording the balance they held at the time, so the
 * chain starts from a known point rather than a reconstructed one. This command
 * therefore checks the chain, not history: a wallet that was already inconsistent
 * with its old transaction rows still passes, because the ledger says the same
 * thing the wallet does. That is deliberate — the alternative is rewriting
 * financial history to make a check go green.
 *
 * The `expected_from_transactions` column is printed for information and is not
 * part of the verdict, for the same reason.
 */
class VerifyWallets extends Command
{
    protected $signature = 'wallet:verify
                            {--user= : Verify only this user id}
                            {--statement= : Print the ledger statement for this user id}
                            {--limit=0 : Stop after this many wallets (0 = all)}';

    protected $description = 'Verify that wallet balances equal the sum of their immutable ledger';

    public function handle(WalletService $wallets): int
    {
        if ($statementUserId = $this->option('statement')) {
            return $this->statement($wallets, (int) $statementUserId);
        }

        $query = Wallet::query()->orderBy('id');

        if ($userId = $this->option('user')) {
            $query->where('user_id', (int) $userId);
        }

        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }

        $checked = 0;
        $drifted = 0;
        $rows = [];

        $query->chunkById(200, function ($walletBatch) use ($wallets, &$checked, &$drifted, &$rows) {
            foreach ($walletBatch as $wallet) {
                $verification = $wallets->verify($wallet);
                $checked++;

                if (! $verification['consistent']) {
                    $drifted++;
                    $rows[] = [
                        $wallet->id,
                        $wallet->user_id,
                        $verification['stored'],
                        $verification['from_ledger'],
                        $verification['entries'],
                        $verification['first_drift']['entry_id'] ?? '—',
                    ];
                }
            }
        });

        if ($drifted > 0) {
            $this->error("{$drifted} of {$checked} wallet(s) disagree with their ledger:");
            $this->newLine();
            $this->table(
                ['Wallet', 'User', 'Stored balance', 'From ledger', 'Entries', 'First bad entry'],
                $rows
            );
            $this->newLine();
            $this->line('A wallet whose balance differs from its ledger has been mutated outside');
            $this->line('App\Services\WalletService. Investigate the entry id above; nothing was');
            $this->line('changed by this command.');

            return self::FAILURE;
        }

        $this->info("{$checked} wallet(s) verified — every balance equals the sum of its ledger.");

        return self::SUCCESS;
    }

    private function statement(WalletService $wallets, int $userId): int
    {
        $wallet = Wallet::where('user_id', $userId)->first();

        if (! $wallet) {
            $this->error("No wallet for user {$userId}.");

            return self::FAILURE;
        }

        $verification = $wallets->verify($wallet);

        $this->line('Wallet ' . $wallet->id . ' — user ' . $userId);
        $this->line('Balance: ' . $verification['stored']);

        $rows = $wallet->ledger()->get()->map(fn ($entry) => [
            $entry->created_at?->format('Y-m-d H:i:s'),
            $entry->entry_type,
            $entry->direction,
            $entry->amountMoney()->format(),
            (string) $entry->balance_before,
            (string) $entry->balance_after,
            $entry->reference,
        ])->all();

        $this->newLine();
        $this->table(
            ['When', 'Type', 'Direction', 'Amount', 'Before', 'After', 'Reference'],
            $rows
        );

        $this->newLine();
        $this->line('Ledger total: ' . $verification['from_ledger']
            . '  (transactions imply ' . $verification['expected_from_transactions'] . ')');

        if (! $verification['consistent']) {
            $this->error('This wallet does not reconcile to its ledger.');

            return self::FAILURE;
        }

        $this->info('Consistent.');

        return self::SUCCESS;
    }
}
