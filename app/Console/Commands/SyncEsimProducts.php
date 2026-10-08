<?php

namespace App\Console\Commands;

use App\Providers\ProviderRegistry;
use Illuminate\Console\Command;

/**
 * eSIM catalogue sync — which does nothing, on purpose, and says so.
 *
 * ## Why this command exists at all
 *
 * The brief asks for an eSIM catalogue sync. This build ships none, because **no
 * integrated provider publishes an eSIM endpoint**:
 *
 *   * Sogo's marketing pages list eSIM among the products its bill API covers, but
 *     the published API reference documents no eSIM catalogue or purchase endpoint.
 *     See `providers.sogo.pending_capabilities`, which records the reason next to the
 *     capability it withholds.
 *   * VTUGate's published endpoint groups are account, transactions, services,
 *     airtime, data, cable, electricity, education, SMS and international top-up.
 *     There is no eSIM group.
 *   * VTpass documents a service catalogue, and no eSIM service is among the
 *     services it lists.
 *   * Nitro is an SMM panel.
 *
 * An eSIM is not a bill payment: delivering one means issuing an ICCID and an
 * activation payload against a carrier, which is a different kind of contract from
 * every other product here.
 *
 * ## Why not just omit it
 *
 * Because a scheduled command that does not exist is indistinguishable from a
 * scheduled command that is broken, and an operator reading a list of expected syncs
 * would reasonably conclude eSIM was being synced. This command runs, finds nothing
 * to sync, explains why, and exits with a distinct code so a monitoring system can
 * tell "intentionally not enabled" apart from "failed".
 *
 * It also refuses to be silently enabled: it reports whether any adapter claims the
 * capability, and if one ever does, it fails loudly rather than doing nothing — at
 * which point whoever added the capability has to come here and implement the sync.
 *
 * ## Exit codes
 *
 *   0 — eSIM is not enabled anywhere, as expected. Nothing to do.
 *   1 — an adapter claims `esim`, which means this command is now a stub that must be
 *       implemented before that provider can be sold eSIM.
 */
class SyncEsimProducts extends Command
{
    protected $signature = 'providers:sync-esim-products
                            {--json : Emit machine-readable output}';

    protected $description = 'Report that no integrated provider publishes an eSIM catalogue (no-op by design)';

    public function handle(ProviderRegistry $registry): int
    {
        /*
         * `driverIsRoutable` counts only capabilities some adapter actually declares.
         * An `esim` entry on a `providers` row is therefore not enough to make this
         * command do anything, which is the point: declaring a capability cannot
         * conjure an endpoint.
         */
        $claimed = $registry->driverIsRoutable('esim');

        $payload = [
            'enabled' => false,
            'any_adapter_claims_esim' => $claimed,
            'reason' => $this->reason(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT));

            return $claimed ? self::FAILURE : self::SUCCESS;
        }

        if (! $claimed) {
            $this->info('eSIM is not enabled: no integrated provider publishes an eSIM catalogue or purchase endpoint.');
            $this->line($this->reason());
            $this->newLine();
            $this->line('Nothing was synced. This is the intended state, not a failure.');

            return self::SUCCESS;
        }

        /*
         * Reached only if somebody has added `esim` to an adapter's capability list.
         * Doing so routes customer orders into a guaranteed failure unless a real
         * endpoint and a real sync exist, so this fails rather than reporting success.
         */
        $this->error('An adapter now claims the `esim` capability, but this command is still a stub.');
        $this->line('Implement the eSIM catalogue sync before enabling eSIM for customers — a declared capability');
        $this->line('without an endpoint routes orders into a guaranteed failure.');

        return self::FAILURE;
    }

    private function reason(): string
    {
        return 'Sogo describes eSIM as a covered product but publishes no eSIM endpoint; VTUGate, VTpass and Nitro '
            . 'publish none either. An eSIM is issued against a carrier rather than bought as a bill, so it needs a '
            . 'contract none of the integrated providers currently offers.';
    }
}
