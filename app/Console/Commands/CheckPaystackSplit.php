<?php

namespace App\Console\Commands;

use App\Services\PaystackService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Confirm the configured Paystack split exists, is active, and pays the right
 * people — before a payment depends on it.
 *
 * ## Why this is worth a command
 *
 * A `split_code` is easy to get subtly wrong and impossible to notice from the
 * application side:
 *
 *   * Paystack returns an authorization URL whether or not the split is the one
 *     intended, so a checkout "succeeds" while every settlement routes somewhere
 *     unintended — invisible until somebody reconciles the bank;
 *   * a code that has been deleted, or copied from another Paystack account,
 *     makes initialisation fail, and `WalletController` then **falls back to
 *     Bachs** — the customer still pays, but through a different gateway where
 *     this split does not apply at all. So a bad split code quietly changes who
 *     settles the money rather than producing a visible error.
 *
 * Neither failure is detectable from inside the app, which is why this asks
 * Paystack directly. Run it after changing the split code, and as part of a
 * deployment check.
 *
 * Prints the split's own configuration — never the secret key.
 */
class CheckPaystackSplit extends Command
{
    protected $signature = 'paystack:check-split';

    protected $description = 'Verify the configured Paystack transaction split exists, is active, and splits to the expected subaccounts';

    public function handle(PaystackService $paystack): int
    {
        if (! $paystack->isConfigured()) {
            $this->error('Paystack is not configured (PAYSTACK_SECRET_KEY is empty).');

            return self::FAILURE;
        }

        try {
            $code = $paystack->splitCode();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($code === null) {
            $this->warn('PAYSTACK_SPLIT_CODE is empty: splitting is DISABLED and settlements land wholly in the main account.');

            return self::SUCCESS;
        }

        $this->line('Checking split: ' . $code);

        $response = Http::withToken((string) config('services.paystack.secret_key'))
            ->acceptJson()
            ->timeout(20)
            ->get('https://api.paystack.co/split/' . rawurlencode($code));

        $body = $response->json() ?? [];

        /*
         * 404 is the important case and gets its own message: the code is
         * well-formed but unknown to this account, which is exactly the state a
         * deleted or cross-account code produces.
         */
        if ($response->status() === 404) {
            $this->error('Paystack does not recognise this split code for this account (404).');
            $this->line('Card checkouts will fail initialisation and fall back to the other gateway,');
            $this->line('where this split does not apply. Fix PAYSTACK_SPLIT_CODE.');

            return self::FAILURE;
        }

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            $this->error('Paystack rejected the lookup: ' . ($body['message'] ?? 'HTTP ' . $response->status()));

            return self::FAILURE;
        }

        $split = $body['data'] ?? [];

        if (! is_array($split) || $split === []) {
            $this->error('Paystack returned no split data.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(['Field', 'Value'], [
            ['Name', (string) ($split['name'] ?? '—')],
            ['Code', (string) ($split['split_code'] ?? $code)],
            ['Type', (string) ($split['type'] ?? '—')],
            ['Currency', (string) ($split['currency'] ?? '—')],
            ['Active', ($split['active'] ?? false) ? 'yes' : 'NO'],
            ['Bearer', (string) ($split['bearer_type'] ?? '—')],
        ]);

        $subaccounts = $split['subaccounts'] ?? [];

        if (is_array($subaccounts) && $subaccounts !== []) {
            $this->newLine();
            $this->line('Split recipients:');
            $this->table(
                ['Subaccount', 'Share'],
                array_map(fn ($s) => [
                    (string) ($s['subaccount']['subaccount_code'] ?? $s['subaccount'] ?? '—'),
                    (string) ($s['share'] ?? '—'),
                ], $subaccounts)
            );
        }

        $problems = [];

        if (($split['active'] ?? false) !== true) {
            $problems[] = 'The split is INACTIVE. Paystack will not apply it.';
        }

        $currency = strtoupper((string) ($split['currency'] ?? ''));

        if ($currency !== '' && $currency !== 'NGN') {
            // ReUp initialises every checkout as NGN, and Paystack will not apply
            // a split whose currency differs.
            $problems[] = "The split is in {$currency} but every checkout is initialised as NGN.";
        }

        if ($problems !== []) {
            $this->newLine();

            foreach ($problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Split looks usable: active, NGN, and recognised by this Paystack account.');

        return self::SUCCESS;
    }
}
