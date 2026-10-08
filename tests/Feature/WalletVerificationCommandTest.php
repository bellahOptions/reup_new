<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The operator-facing integrity check.
 *
 * `wallet:verify` is the tool somebody reaches for when a customer says their
 * balance is wrong, so it has to be right in both directions: it must pass a
 * healthy wallet and it must catch a wallet that has been mutated outside the
 * wallet service. A checker that always reports "fine" is worse than none.
 *
 * Note on style: Laravel 8's `PendingCommand` has `expectsOutput()` (exact
 * match) but not `expectsOutputToContain()` (Laravel 9), and `Artisan::output()`
 * is not populated by the test harness. These therefore assert the whole line
 * that makes the verdict readable, together with the exit code — which is what
 * an operator's cron job and monitoring actually depend on.
 */
class WalletVerificationCommandTest extends TestCase
{
    use RefreshDatabase;

    private function fundedUser(string $amount = '1000.00'): User
    {
        $user = User::factory()->create();
        app(WalletService::class)->credit($user, $amount);

        return $user->fresh();
    }

    public function test_a_healthy_wallet_verifies(): void
    {
        $user = $this->fundedUser('1000.00');
        app(WalletService::class)->debit($user, '250.00');

        $this->artisan('wallet:verify')
            ->expectsOutput('1 wallet(s) verified — every balance equals the sum of its ledger.')
            ->assertExitCode(0);
    }

    public function test_it_reports_a_wallet_whose_balance_was_changed_outside_the_service(): void
    {
        $user = $this->fundedUser('1000.00');

        /*
         * Simulate the exact defect the ledger was introduced to detect: a
         * balance written directly, with no matching ledger row. This is what
         * the four controllers and the admin screen used to do.
         */
        Wallet::where('user_id', $user->id)->update(['balance' => '999999.00']);

        /*
         * The verdict line and the exit code are what an operator acts on, and
         * the exit code is what a cron job branches on. The table's exact column
         * widths are Symfony's business, not this test's — asserting them would
         * make the test fail on a console-width change that broke nothing.
         */
        $this->artisan('wallet:verify')
            ->expectsOutput('1 of 1 wallet(s) disagree with their ledger:')
            ->assertExitCode(1);
    }

    public function test_a_non_zero_exit_code_is_what_makes_it_usable_from_cron(): void
    {
        $user = $this->fundedUser('50.00');
        Wallet::where('user_id', $user->id)->update(['balance' => '0.00']);

        // The exit code is the whole contract: `wallet:verify || alert` only
        // works if drift is a failure.
        $this->artisan('wallet:verify')->assertExitCode(1);

        // And a healthy database exits clean.
        Wallet::where('user_id', $user->id)->update(['balance' => '50.00']);
        $this->artisan('wallet:verify')->assertExitCode(0);
    }

    public function test_it_can_verify_a_single_user(): void
    {
        $healthy = $this->fundedUser('500.00');
        $broken = $this->fundedUser('500.00');

        Wallet::where('user_id', $broken->id)->update(['balance' => '1.00']);

        // Scoped to the healthy user, so the broken one is not examined.
        $this->artisan('wallet:verify', ['--user' => $healthy->id])->assertExitCode(0);

        // Scoped to the broken one, which must fail.
        $this->artisan('wallet:verify', ['--user' => $broken->id])->assertExitCode(1);
    }

    public function test_the_statement_renders_a_consistent_ledger(): void
    {
        $user = User::factory()->create();
        $wallets = app(WalletService::class);

        /*
         * Funding, a purchase and a compensating refund, each with the
         * transaction row the real pipeline writes, so the statement's second
         * figure is computed from history that actually exists.
         */
        $funding = Transactions::create([
            'user_id' => $user->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding — Card',
            'amount' => '1000.00',
            'service_fee' => '0.00',
            'total_amount' => '1000.00',
            'payment_method' => 'paystack',
            'status' => 'success',
            'payment_status' => 'success',
        ]);

        $credit = $wallets->credit($user, '1000.00', transaction: $funding);

        $purchase = Transactions::create([
            'user_id' => $user->id,
            'type' => 'debit',
            'service_type' => 'airtime',
            'description' => 'Airtime — MTN',
            'amount' => '250.00',
            'service_fee' => '0.00',
            'total_amount' => '250.00',
            'status' => 'success',
            'payment_status' => 'success',
        ]);

        $debit = $wallets->debit($user, '250.00', transaction: $purchase);
        $refund = $wallets->refund($user, '250.00');

        $wallet = Wallet::where('user_id', $user->id)->firstOrFail();

        $this->artisan('wallet:verify', ['--statement' => $user->id])
            ->expectsOutput("Wallet {$wallet->id} — user {$user->id}")
            ->expectsOutput('Balance: 1000.00')
            /*
             * Both figures are shown, and they differ on purpose: the ledger
             * stands at 1000.00 (funded 1000, spent 250, refunded 250), while
             * the transaction-implied figure is 750.00 because refunds are
             * excluded from it — a refund is recorded as a credit whose
             * `service_type` is `refund`, and counting it as income would
             * double-count the money that was returned.
             */
            ->expectsOutput('Ledger total: 1000.00  (transactions imply 750.00)')
            ->expectsOutput('Consistent.')
            ->assertExitCode(0);

        /*
         * The table rows themselves are not asserted here: Symfony pads every
         * cell to the widest value, so pinning a row would fail on a console
         * width change that broke nothing. The ledger contents are asserted
         * directly instead, which is the fact that matters.
         */
        $references = $wallet->ledger()->pluck('reference')->all();

        $this->assertContains($credit['ledger']->reference, $references);
        $this->assertContains($debit['ledger']->reference, $references);
        $this->assertContains($refund['ledger']->reference, $references);
        $this->assertCount(3, $references);
    }

    public function test_the_statement_flags_an_inconsistent_wallet(): void
    {
        $user = $this->fundedUser('100.00');
        Wallet::where('user_id', $user->id)->update(['balance' => '0.01']);

        $this->artisan('wallet:verify', ['--statement' => $user->id])
            ->expectsOutput('This wallet does not reconcile to its ledger.')
            ->assertExitCode(1);
    }

    public function test_it_reports_cleanly_for_an_unknown_user(): void
    {
        $this->artisan('wallet:verify', ['--statement' => 999999])
            ->expectsOutput('No wallet for user 999999.')
            ->assertExitCode(1);
    }

    public function test_the_preflight_tool_is_present_and_is_documented(): void
    {
        /*
         * `tools/preflight-financial-integrity.php` is deliberately NOT executed
         * here. It reads whatever `DB_*` points at, so running it from the test
         * suite would inspect the development database rather than the throwaway
         * one — and it is a pre-deploy gate against a copy of production data,
         * which is where it belongs (see docs/DEPLOYMENT-FINANCIAL-HARDENING.md,
         * step 2).
         *
         * What is worth asserting is that it exists and that the runbook still
         * tells an operator to run it, because the failure mode being guarded
         * against is a migration that aborts mid-deploy.
         */
        $script = base_path('tools/preflight-financial-integrity.php');
        $runbook = base_path('docs/DEPLOYMENT-FINANCIAL-HARDENING.md');

        $this->assertFileExists($script);
        $this->assertFileExists($runbook);

        $this->assertStringContainsString(
            'preflight-financial-integrity.php',
            file_get_contents($runbook),
            'The deployment runbook must reference the pre-flight gate.'
        );
    }
}
