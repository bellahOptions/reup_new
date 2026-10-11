<?php

namespace App\Console\Commands;

use App\Models\Transactions;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Collect funding service fees that could not be taken when funding settled.
 *
 * ## Why a fee can be outstanding
 *
 * `WalletService::chargeFundingFee()` never throws and never overdrafts: if the
 * balance cannot cover the fee, the fee is refused, the outcome is written to
 * the funding row's `meta.funding_fee_status = 'failed'`, and the funding credit
 * stands. That is deliberate — the customer has already paid, so the credit is
 * theirs regardless — but it means an uncollected fee is a real state that has to
 * be *resolved*, not merely recorded.
 *
 * The common cause is benign: a customer funds and spends the money within
 * seconds, so the fee arrives to an empty wallet. It usually succeeds on the next
 * attempt, once they fund again.
 *
 * ## Why it is safe to retry
 *
 * The fee is a `transactions` row keyed `FEE-<funding reference>`, and that
 * reference is unique. A funding that already paid its fee cannot be charged a
 * second time, however many times this runs or whatever else settles it
 * concurrently. The fee is also capped at the amount funded.
 *
 * ## What it will not do
 *
 * It does not force a wallet negative, and it does not touch a funding that has
 * already paid. A fee that is genuinely uncollectable — an account that funded
 * and never funded again — is reported, not written off: whether to forgive it is
 * a commercial decision, not this command's.
 */
class ChargeFundingFees extends Command
{
    protected $signature = 'payments:charge-funding-fees
                            {--minutes=5 : Only consider funding older than this, so the normal path wins first}
                            {--limit=200 : Maximum funding rows to examine}
                            {--dry-run : Report what would be charged without charging}';

    protected $description = 'Retry service fees on funding whose fee could not be collected when it settled';

    public function handle(WalletService $wallets): int
    {
        $minutes = max(0, (int) $this->option('minutes'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        /*
         * Select on the *status*, not on a null: a funding row that settled
         * before fees existed has no `funding_fee_status` at all, and treating
         * that absence as "outstanding" would charge a fee against historical
         * funding the customer was never told about. Only a row that explicitly
         * recorded a failure is retried.
         */
        $candidates = Transactions::query()
            ->where('service_type', 'funding')
            ->where('type', 'credit')
            ->where('status', 'success')
            ->where('meta->funding_fee_status', 'failed')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No outstanding funding fees.');

            return self::SUCCESS;
        }

        $charged = 0;
        $stillFailing = 0;
        $skipped = 0;
        $errored = 0;

        foreach ($candidates as $funding) {
            $user = $funding->user;

            if (! $user) {
                $this->line("  <fg=yellow>?</> {$funding->reference} — no customer attached");
                $skipped++;

                continue;
            }

            $fee = $wallets->fundingFeeFor(\App\Support\Money::fromDatabase($funding->amount));

            if (! $fee->isPositive()) {
                // The rate changed to zero since the failure was recorded, so
                // there is nothing to collect any more. Clear the flag rather
                // than leaving a permanent "failed" on the row.
                if (! $dryRun) {
                    $funding->forceFill([
                        'meta' => array_merge($funding->meta ?? [], [
                            'funding_fee_status' => 'none',
                            'funding_fee' => '0.00',
                            'funding_fee_error' => null,
                        ]),
                    ])->save();
                }

                $skipped++;

                continue;
            }

            if ($dryRun) {
                $this->line("  <fg=cyan>+</> {$funding->reference} — would charge {$fee->format()} (user #{$user->id})");
                $charged++;

                continue;
            }

            try {
                $result = $wallets->chargeFundingFee($user, $funding);
            } catch (Throwable $e) {
                // chargeFundingFee is written not to throw; if it ever does, this
                // must not take the whole sweep down with it.
                $errored++;

                Log::error('Funding fee retry threw', [
                    'funding_transaction_id' => $funding->id,
                    'error' => $e->getMessage(),
                ]);

                $this->line("  <fg=red>!</> {$funding->reference} — {$e->getMessage()}");

                continue;
            }

            $funding->forceFill([
                'meta' => array_merge($funding->meta ?? [], [
                    'funding_fee' => $result['fee'],
                    'funding_fee_status' => $result['status'],
                    'funding_fee_transaction_id' => $result['transaction']?->getKey(),
                    'funding_fee_charged_at' => $result['status'] === 'charged' ? now()->toDateTimeString() : null,
                    'funding_fee_error' => $result['message'],
                    'funding_fee_retried_at' => now()->toDateTimeString(),
                ]),
            ])->save();

            if ($result['status'] === 'charged' || $result['status'] === 'already_charged') {
                $charged++;
                $this->line("  <fg=green>+</> {$funding->reference} — {$result['fee']} collected");

                Log::info('Outstanding funding fee collected', [
                    'funding_transaction_id' => $funding->id,
                    'user_id' => $user->id,
                    'fee' => $result['fee'],
                ]);

                continue;
            }

            $stillFailing++;
            $this->line("  <fg=yellow>-</> {$funding->reference} — still uncollected: {$result['message']}");
        }

        $this->newLine();
        $this->table(
            ['Collected', 'Still outstanding', 'Skipped', 'Errored'],
            [[$charged, $stillFailing, $skipped, $errored]]
        );

        if ($stillFailing > 0) {
            $this->warn(
                '  Outstanding fees are almost always a wallet spent before the fee was taken. '
                . 'They are retried on every run, and collected as soon as the balance allows.'
            );
        }

        return $errored > 0 ? self::FAILURE : self::SUCCESS;
    }
}
