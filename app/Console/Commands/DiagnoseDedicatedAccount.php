<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PaystackService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Diagnose why a dedicated virtual account cannot be created.
 *
 * ## Why this is a command and not just a log line
 *
 * Virtual-account assignment fails for several unrelated reasons that all reach
 * the customer as one sentence — "we could not issue your personal account
 * number just now" — because everything the gateway says about *our*
 * configuration is deliberately kept out of the UI. That is the right call for
 * the customer and a dead end for whoever has to fix it:
 *
 *   * the secret key is a **test** key, and personal accounts are not issued in
 *     test mode at all;
 *   * the configured partner bank is not enabled on the account;
 *   * the customer record is missing a phone number;
 *   * the account has no "dedicated virtual account" capability enabled.
 *
 * Each needs a different action, and each is invisible from inside the
 * application. So this asks Paystack directly, one question at a time, and says
 * which of them it is. It never prints the secret key — only its mode.
 *
 * `--user` names the account to test against, because a DVA is per customer and
 * three of the four causes above are per customer too.
 */
class DiagnoseDedicatedAccount extends Command
{
    protected $signature = 'paystack:diagnose-dva
                            {--user= : The user id to test with (defaults to the most recent customer with a phone)}
                            {--bank=* : Partner bank slugs to try as a fallback when the configured one is refused}';

    protected $description = 'Diagnose why a Paystack dedicated virtual account cannot be created';

    /**
     * The slugs Paystack has historically supported for NG dedicated accounts.
     *
     * Tried only as a *diagnostic*, never selected automatically: which partner
     * bank issues the account is a commercial decision, not something a fallback
     * loop should quietly make.
     */
    private const CANDIDATE_BANKS = ['wema-bank', 'titan-paystack', 'first-bank', 'sterling-bank', 'gtbank'];

    public function handle(PaystackService $paystack): int
    {
        if (! $paystack->isConfigured()) {
            $this->error('Paystack is not configured (PAYSTACK_SECRET_KEY is empty).');

            return self::FAILURE;
        }

        $secret = (string) config('services.paystack.secret_key');
        $mode = str_starts_with($secret, 'sk_test_') ? 'TEST' : (str_starts_with($secret, 'sk_live_') ? 'LIVE' : 'UNKNOWN');

        $this->line('Key mode:   <options=bold>' . $mode . '</>  (prefix only; the key itself is never printed)');
        $this->line('DVA bank:   <options=bold>' . config('services.paystack.dva_bank', 'wema-bank') . '</>');

        if ($mode === 'TEST') {
            $this->newLine();
            $this->warn(
                'A test key cannot issue personal account numbers. Paystack\'s dedicated virtual accounts are a '
                . 'live-mode product, so this will fail however it is configured. The failure is expected and the '
                . 'application reports it to the customer as "not available on this account yet".'
            );
        }

        // ---------------------------------------------------------------
        // 1. Which partner banks does this account actually offer?
        // ---------------------------------------------------------------
        $this->newLine();
        $this->line('<options=bold>1. Partner banks</>');

        try {
            $response = Http::withToken($secret)->acceptJson()->timeout(30)
                ->get('https://api.paystack.co/dedicated_account/available_providers');

            $body = $response->json() ?? [];

            if ($response->successful() && ($body['status'] ?? false)) {
                $providers = array_map(
                    fn ($p) => is_array($p) ? ($p['slug'] ?? $p['name'] ?? json_encode($p)) : (string) $p,
                    (array) ($body['data'] ?? [])
                );

                $this->line('   HTTP ' . $response->status() . ' — ' . (count($providers) ? implode(', ', $providers) : '(none listed)'));

                if ($providers === []) {
                    $this->error('   No partner banks are available on this account, so no virtual account can be issued.');
                }
            } else {
                $this->line('   HTTP ' . $response->status() . ' — ' . ($body['message'] ?? 'no message'));
            }
        } catch (Throwable $e) {
            $this->line('   unreachable: ' . $e->getMessage());
        }

        // ---------------------------------------------------------------
        // 2. The customer record, and its phone number
        // ---------------------------------------------------------------
        $this->newLine();
        $this->line('<options=bold>2. Customer record</>');

        $user = $this->resolveUser();

        if (! $user) {
            $this->error('   No customer with a phone number to test with. Pass --user=<id>.');

            return self::FAILURE;
        }

        $this->line('   user #' . $user->id . '  ' . $user->email);
        $this->line('   phone on file: ' . ($user->formatted_phone ?: '<fg=red>MISSING</>'));
        $this->line('   paystack customer code: ' . ($user->paystack_customer_code ?: '<fg=yellow>none yet</>'));
        $this->line('   already has an account: ' . ($user->dva_account_number ?: 'no'));

        if (! $paystack->hasPhoneForDedicatedAccount($user)) {
            $this->newLine();
            $this->error('   This account has no phone number, and Paystack will not create a virtual account without one.');
            $this->line('   The customer sees a prompt to add one at /wallet/virtual-account.');

            return self::FAILURE;
        }

        // ---------------------------------------------------------------
        // 3. Ask for an account, and report the gateway's own answer
        // ---------------------------------------------------------------
        $this->newLine();
        $this->line('<options=bold>3. Assignment attempt</>');

        try {
            $customerCode = $paystack->ensureCustomer($user);
            $this->line('   customer code: ' . $customerCode);

            $result = $this->attempt($secret, $customerCode, (string) config('services.paystack.dva_bank', 'wema-bank'));

            if ($result['ok']) {
                $this->info('   ASSIGNED — ' . ($result['account'] ?? 'account number in payload'));

                return self::SUCCESS;
            }

            $this->error('   REFUSED — ' . $result['message']);

            // -----------------------------------------------------------
            // 4. Is it the bank, or is it everything?
            // -----------------------------------------------------------
            $candidates = array_values(array_filter(
                array_unique(array_merge((array) $this->option('bank'), self::CANDIDATE_BANKS)),
                fn ($bank) => $bank !== (string) config('services.paystack.dva_bank', 'wema-bank')
            ));

            $this->newLine();
            $this->line('<options=bold>4. Is the bank the problem?</>');
            $this->line('   Trying ' . count($candidates) . ' other partner bank(s) with the same customer...');

            foreach ($candidates as $bank) {
                $attempt = $this->attempt($secret, $customerCode, $bank);

                if ($attempt['ok']) {
                    $this->info('   ' . str_pad($bank, 18) . ' WORKS');
                    $this->newLine();
                    $this->warn(
                        'Set PAYSTACK_DVA_BANK=' . $bank . ' in .env. Changing it affects only *new* '
                        . 'assignments — customers who already hold an account keep the one they have.'
                    );

                    return self::SUCCESS;
                }

                $this->line('   ' . str_pad($bank, 18) . ' ' . $attempt['message']);
            }

            $this->newLine();
            $this->error(
                'Every partner bank was refused, so the cause is not the bank. The usual reason is a '
                . 'test key, or an account without dedicated-virtual-account capability enabled.'
            );
            $this->line('   Ask Paystack support to enable "Dedicated Virtual Accounts" for this business.');
        } catch (Throwable $e) {
            $this->error('   Failed before the gateway answered: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::FAILURE;
    }

    /**
     * Ask for a dedicated account with a given partner bank.
     *
     * The bank is sent as `preferred_bank`, which is what the application sends
     * in production — so a success here means a success after the config change,
     * not merely that the API accepted the word.
     *
     * @return array{ok:bool,message:string,account:?string}
     */
    private function attempt(string $secret, string $customerCode, string $bank): array
    {
        try {
            $response = Http::withToken($secret)->acceptJson()->timeout(30)
                ->post('https://api.paystack.co/dedicated_account', [
                    'customer' => $customerCode,
                    'preferred_bank' => $bank,
                ]);

            $body = $response->json() ?? [];

            if ($response->successful() && ($body['status'] ?? false)) {
                $data = $body['data'] ?? [];

                return [
                    'ok' => true,
                    'message' => 'ok',
                    'account' => $data['account_number'] ?? data_get($data, 'accounts.0.account_number'),
                ];
            }

            return [
                'ok' => false,
                'message' => 'HTTP ' . $response->status() . ' — ' . ($body['message'] ?? 'no message'),
                'account' => null,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'unreachable: ' . $e->getMessage(), 'account' => null];
        }
    }

    private function resolveUser(): ?User
    {
        if ($id = $this->option('user')) {
            return User::find((int) $id);
        }

        return User::whereNotNull('phone')
            ->where('phone', '!=', '')
            ->where('is_admin', false)
            ->latest('id')
            ->first();
    }
}
