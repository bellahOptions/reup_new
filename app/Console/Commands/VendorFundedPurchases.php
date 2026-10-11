<?php

namespace App\Console\Commands;

use App\Models\Transactions;
use App\Orders\FundedPurchase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Vend purchases that were paid for but never delivered.
 *
 * ## The gap this closes
 *
 * A card purchase is two steps across two requests: Paystack settles the payment
 * (webhook, callback, or one of the reconciliation sweeps), and *then* the product
 * is vended. The settlement path triggers the vend inline, so in the ordinary case
 * this command finds nothing.
 *
 * The cases it exists for are the ones nothing else can cover: the worker died
 * between the credit and the provider call; the provider was down for the thirty
 * seconds that mattered; a deploy restarted the queue mid-flight. In all of them the
 * customer has paid, does not have what they bought, and **no other process in this
 * application is looking for it** — `payments:auto-resolve` resolves the *payment*
 * and finds it already settled, so it stops there.
 *
 * That is the worst state a commerce system can leave a customer in, so it gets its
 * own sweep rather than being left to a support ticket.
 *
 * ## Idempotency
 *
 * `FundedPurchase::vend()` moves the intent to `vending` under a row lock before it
 * calls a provider and to `vended` after, and it refuses to act on an intent in
 * either state. So running this every two minutes, concurrently with a webhook, or
 * twice in a row cannot vend one purchase twice — and a duplicate vend is a free
 * product that cannot be taken back.
 */
class VendorFundedPurchases extends Command
{
    protected $signature = 'payments:vendor-funded-purchases
                            {--limit=50 : Maximum purchases to attempt in one run}
                            {--grace=1 : Skip payments younger than this many minutes, so settlement can win first}
                            {--dry-run : List what would be attempted and write nothing}';

    protected $description = 'Vend purchases that were paid for by card but never delivered';

    public function handle(FundedPurchase $purchases): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $grace = max(0, (int) $this->option('grace'));

        if ($dryRun) {
            return $this->preview($purchases, $limit, $grace);
        }

        $result = $purchases->sweep($limit, $grace);

        if ($result['examined'] === 0) {
            $this->info('No undelivered card purchases.');

            return self::SUCCESS;
        }

        $this->table(
            ['Examined', 'Vended', 'Failed'],
            [[$result['examined'], $result['vended'], $result['failed']]]
        );

        if ($result['vended'] > 0 || $result['failed'] > 0) {
            /*
             * Logged only when something happened. A sweep that runs every two
             * minutes and logs "nothing to do" buries the runs that did something,
             * and this log is how an operator reconstructs why a customer received
             * an item late.
             */
            Log::info('Funded-purchase sweep ran', $result);
        }

        if ($result['failed'] > 0) {
            /*
             * A non-zero exit for a delivery that failed. The money is safe in the
             * customer's wallet, but somebody needs to look — either the provider is
             * down, or a product has become unsellable, and both are operational
             * problems rather than one customer's bad luck.
             */
            $this->warn(
                "  {$result['failed']} purchase(s) could not be delivered. "
                . 'The money is in the customer\'s wallet and the intent is left vendable, '
                . 'so the next run retries it — but check the provider.'
            );

            return self::FAILURE;
        }

        $this->info("  Vended {$result['vended']} purchase(s).");

        return self::SUCCESS;
    }

    /**
     * Report what would be attempted, and prove nothing changed.
     *
     * Read-only, including the state check: it selects on the same criteria the real
     * sweep does and prints the rows, without calling a provider or writing an
     * intent. A dry run that vended something would be the opposite of a dry run.
     */
    private function preview(FundedPurchase $purchases, int $limit, int $grace): int
    {
        $candidates = Transactions::query()
            ->where('service_type', 'funding')
            ->where('status', 'success')
            ->whereNotNull('meta->' . FundedPurchase::META_KEY)
            ->where('created_at', '<=', now()->subMinutes($grace))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $pending = $candidates->filter(function (Transactions $funding) use ($purchases) {
            $intent = $purchases->intent($funding);

            return $intent !== null && ($intent['state'] ?? null) !== FundedPurchase::STATE_VENDED;
        });

        if ($pending->isEmpty()) {
            $this->info('[dry run] No undelivered card purchases.');

            return self::SUCCESS;
        }

        $this->info('[dry run] Would vendor ' . $pending->count() . ' purchase(s):');

        foreach ($pending as $funding) {
            $intent = $purchases->intent($funding) ?? [];

            $this->line(sprintf(
                '  %s — %s to %s (state: %s, attempts: %d)',
                $funding->reference,
                ucfirst((string) ($intent['product'] ?? '?')),
                (string) ($intent['recipient'] ?? '?'),
                (string) ($intent['state'] ?? 'unknown'),
                (int) ($intent['attempts'] ?? 0)
            ));
        }

        return self::SUCCESS;
    }
}
