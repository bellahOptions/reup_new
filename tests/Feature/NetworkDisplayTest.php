<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Services\NetworkResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The mobile network shown to a customer.
 *
 * ## The defect these tests pin
 *
 * A customer's receipt read **"Network: ClubKonnect"**. ClubKonnect is the
 * upstream API ReUp buys airtime from; it is not the customer's mobile operator.
 * The cause was structural, not cosmetic: the only network-ish value on a
 * transaction was `provider`, and the payment pipeline overwrote that with the
 * provider adapter's label. The real network lived in `meta` JSON and no template
 * read it.
 *
 * So these tests assert three things, and the third is the one that matters most:
 *
 *   1. a real network resolves to its canonical name;
 *   2. a network that cannot be resolved gets a neutral label;
 *   3. **the provider's name is never returned as the network under any
 *      combination of stored values**, including a legacy row that has the
 *      provider label sitting where a network name belongs.
 *
 * They also assert that historical display does not depend on the provider's
 * current catalogue — a receipt printed today for last year's purchase must show
 * what was true then.
 */
class NetworkDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function transaction(array $attributes = []): Transactions
    {
        $user = User::factory()->create();

        return Transactions::create(array_merge([
            'user_id' => $user->getKey(),
            'reference' => 'TXN-TEST-' . uniqid(),
            'type' => 'debit',
            'service_type' => 'airtime',
            'description' => 'Airtime',
            'amount' => '200.00',
            'service_fee' => '0.00',
            'total_amount' => '200.00',
            'recipient' => '08031234567',
            'payment_method' => 'wallet',
            'payment_status' => 'success',
            'status' => 'success',
        ], $attributes));
    }

    /* =====================================================================
     | Canonical resolution
     |=================================================================== */

    public function test_mtn_is_displayed_as_mtn_and_never_as_the_provider(): void
    {
        $transaction = $this->transaction([
            'network_code' => 'mtn',
            'network_name' => 'MTN',
            // The provider column says ClubKonnect, exactly as the pipeline leaves it.
            'provider' => 'ClubKonnect',
        ]);

        $this->assertSame('MTN', $transaction->network_display);
        $this->assertNotSame('ClubKonnect', $transaction->network_display);
    }

    public function test_every_network_maps_to_its_canonical_name(): void
    {
        $expected = [
            '01' => 'MTN',
            '02' => 'Glo',
            '03' => '9mobile',
            '04' => 'Airtel',
        ];

        foreach ($expected as $code => $name) {
            $this->assertSame(
                $name,
                NetworkResolver::displayName($code, 'clubkonnect'),
                "ClubKonnect code {$code} should resolve to {$name}"
            );

            // And the transaction-level display agrees.
            $transaction = $this->transaction([
                'network_code' => NetworkResolver::key($code, 'clubkonnect'),
                'network_name' => $name,
                'provider' => 'ClubKonnect',
            ]);

            $this->assertSame($name, $transaction->network_display);
        }
    }

    public function test_provider_specific_codes_resolve_to_canonical_names(): void
    {
        // ClubKonnect speaks numbers, Pairgate speaks slugs, and both must land on
        // the same canonical network.
        $this->assertSame('mtn', NetworkResolver::key('01', 'clubkonnect'));
        $this->assertSame('mtn', NetworkResolver::key('mtn', 'pairgate'));
        $this->assertSame('9mobile', NetworkResolver::key('03', 'clubkonnect'));
        $this->assertSame('9mobile', NetworkResolver::key('9mobile', 'pairgate'));

        // Case and spacing are not significant.
        $this->assertSame('mtn', NetworkResolver::key('MTN'));
        $this->assertSame('9mobile', NetworkResolver::key('9 MOBILE'));
        $this->assertSame('glo', NetworkResolver::key('Glo'));
    }

    public function test_an_unknown_network_uses_the_neutral_fallback_and_not_the_provider(): void
    {
        $transaction = $this->transaction([
            'network_code' => 'zz',
            'network_name' => null,
            'provider' => 'ClubKonnect',
        ]);

        $this->assertSame('Network unavailable', $transaction->network_display);
        $this->assertNotSame('ClubKonnect', $transaction->network_display);

        // The fallback is explicitly not a provider name.
        $this->assertFalse(NetworkResolver::isProviderName(NetworkResolver::unavailableLabel()));
    }

    /**
     * The regression guard.
     *
     * A legacy row can carry a provider label in `network_name`, in
     * `meta.network_name`, or have nothing at all. None of those may yield the
     * provider's name as the network.
     */
    public function test_a_provider_name_is_never_returned_as_the_network(): void
    {
        $combinations = [
            // A provider label written into the network column by a bad fix.
            ['network_name' => 'ClubKonnect', 'network_code' => null, 'meta' => null],
            ['network_name' => 'clubkonnect', 'network_code' => null, 'meta' => null],
            // A provider label recorded in meta.
            ['network_name' => null, 'network_code' => null, 'meta' => ['network_name' => 'ClubKonnect']],
            ['network_name' => null, 'network_code' => null, 'meta' => ['network_name' => 'Pairgate']],
            // Nothing resolvable at all, with the provider column populated.
            ['network_name' => null, 'network_code' => null, 'meta' => null],
        ];

        foreach ($combinations as $attributes) {
            $transaction = $this->transaction($attributes + ['provider' => 'ClubKonnect']);

            $this->assertNotSame(
                'ClubKonnect',
                $transaction->network_display,
                'A provider name must never be shown as the mobile network'
            );

            $this->assertNotSame('Pairgate', $transaction->network_display);
        }
    }

    /* =====================================================================
     | Legacy rows
     |=================================================================== */

    public function test_a_legacy_row_resolves_its_network_from_the_meta_it_stored(): void
    {
        // Written before the network columns existed: the name is only in meta.
        $withName = $this->transaction([
            'network_code' => null,
            'network_name' => null,
            'meta' => ['network_code' => '04', 'network_name' => 'Airtel', 'requested_provider' => 'clubkonnect'],
            'provider' => 'ClubKonnect',
        ]);

        $this->assertSame('Airtel', $withName->network_display);

        // Only the code was stored.
        $withCode = $this->transaction([
            'network_code' => null,
            'network_name' => null,
            'meta' => ['network_code' => '04'],
            'provider' => 'ClubKonnect',
        ]);

        $this->assertSame('Airtel', $withCode->network_display);
    }

    /**
     * A receipt must not change because configuration or a catalogue changed later.
     *
     * The network is persisted on the row, so display reads it rather than
     * re-resolving against whatever the provider says today. Renaming a network in
     * configuration must not rewrite history.
     */
    public function test_historical_display_does_not_depend_on_current_configuration(): void
    {
        $transaction = $this->transaction([
            'network_code' => 'mtn',
            'network_name' => 'MTN',
            'provider' => 'ClubKonnect',
        ]);

        // Simulate a later rename of the canonical label.
        config(['networks.networks.mtn.name' => 'MTN Nigeria (renamed)']);

        $this->assertSame(
            'MTN',
            $transaction->fresh()->network_display,
            'The stored label must win, so a past receipt keeps saying what it said'
        );
    }

    public function test_has_resolved_network_distinguishes_a_real_network_from_the_fallback(): void
    {
        $resolved = $this->transaction(['network_code' => 'glo', 'network_name' => 'Glo']);
        $unresolved = $this->transaction(['network_code' => null, 'network_name' => null]);

        $this->assertTrue($resolved->hasResolvedNetwork());
        $this->assertFalse($unresolved->hasResolvedNetwork());
    }

    /* =====================================================================
     | Surfaces
     |=================================================================== */

    public function test_the_transaction_success_page_shows_the_network_and_not_the_provider(): void
    {
        $user = User::factory()->create();

        $transaction = $this->transaction([
            'user_id' => $user->getKey(),
            'network_code' => 'mtn',
            'network_name' => 'MTN',
            'provider' => 'ClubKonnect',
        ]);

        $response = $this->actingAs($user)->get(route('transactions.success', $transaction->reference));

        $response->assertOk();
        $response->assertSee('MTN');
        $response->assertDontSee('ClubKonnect');
    }

    public function test_the_email_receipt_shows_the_network_and_not_the_provider(): void
    {
        $user = User::factory()->create();

        $transaction = $this->transaction([
            'user_id' => $user->getKey(),
            'network_code' => 'airtel',
            'network_name' => 'Airtel',
            'provider' => 'ClubKonnect',
        ]);

        $html = (string) $this->view('emails.transaction-receipt', ['transaction' => $transaction]);

        $this->assertStringContainsString('Airtel', $html);
        $this->assertStringNotContainsString('ClubKonnect', $html);
        // The misleading label that carried the provider name is gone.
        $this->assertStringNotContainsString('Network / provider', $html);
    }

    public function test_the_email_receipt_never_contains_provider_cost_or_profit(): void
    {
        $user = User::factory()->create();

        $transaction = $this->transaction([
            'user_id' => $user->getKey(),
            'network_code' => 'mtn',
            'network_name' => 'MTN',
            'provider' => 'ClubKonnect',
        ]);

        $html = (string) $this->view('emails.transaction-receipt', ['transaction' => $transaction]);

        foreach (['provider_cost', 'gross_profit', 'Gross profit', 'Margin', 'markup', 'Provider cost'] as $leak) {
            $this->assertStringNotContainsString($leak, $html, "Receipt must not expose [{$leak}]");
        }
    }

    public function test_the_airtime_history_lists_the_network_and_not_the_provider(): void
    {
        $user = User::factory()->create();

        $this->transaction([
            'user_id' => $user->getKey(),
            'network_code' => '9mobile',
            'network_name' => '9mobile',
            'provider' => 'ClubKonnect',
        ]);

        $response = $this->actingAs($user)->get(route('airtime-data.history'));

        $response->assertOk();
        $response->assertSee('9mobile');
        $response->assertDontSee('ClubKonnect');
    }

    public function test_the_wallet_history_lists_the_network_and_not_the_provider(): void
    {
        $user = User::factory()->create();

        $this->transaction([
            'user_id' => $user->getKey(),
            'network_code' => 'mtn',
            'network_name' => 'MTN',
            'provider' => 'ClubKonnect',
        ]);

        $response = $this->actingAs($user)->get(route('transactions.index'));

        $response->assertOk();
        $response->assertSee('MTN');
        $response->assertDontSee('ClubKonnect');
    }

    public function test_the_provider_remains_available_for_administration(): void
    {
        // The provider must stay visible internally — for reconciliation and
        // support — and only be withheld from the customer's network field.
        $transaction = $this->transaction([
            'network_code' => 'mtn',
            'network_name' => 'MTN',
            'provider' => 'ClubKonnect',
        ]);

        $this->assertSame('ClubKonnect', $transaction->provider);
        $this->assertNotSame($transaction->provider, $transaction->network_display);
    }
}
