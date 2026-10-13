<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\Provider;
use App\Models\ProviderNetworkCost;
use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Services\ClubKonnectCatalogue;
use App\Services\NetworkBrand;
use App\Services\NetworkResolver;
use App\Services\PhoneNumber;
use App\Services\SecurityService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesProviders;
use Tests\TestCase;

/**
 * Network detection from a phone number, and the safeguards it feeds.
 *
 * ## What is tested here, and what deliberately is not
 *
 * Detection is a **prefix hint**. Nigerian mobile number portability means it
 * cannot establish the serving network, and neither ClubKonnect nor Pairgate
 * publishes a number-to-network lookup, so there is no authoritative check to test
 * against. The tests below therefore pin two different things:
 *
 *   1. the parsing and the prefix mapping are right about what they *do* claim;
 *   2. the uncertainty is represented rather than hidden — `detect()` never returns
 *      `certain` for a prefix, an unlisted prefix yields no candidate at all, and a
 *      contradiction with the customer's choice is always a refusal they can
 *      override rather than a silent switch of network.
 *
 * A test asserting "detection is correct" would assert something the method cannot
 * deliver, which is why there is no such test.
 */
class NetworkDetectionTest extends TestCase
{
    use RefreshDatabase;
    use FakesProviders;

    /** The plan the mocked catalogue serves, and its ClubKonnect network code. */
    private array $plan = [
        'network_code' => '01',
        'network' => 'MTN',
        'network_key' => 'mtn',
        'plan_id' => '500',
        'plan_code' => 'MTN-1GB-30D',
        'plan_name' => 'MTN 1GB Monthly',
        'data_volume' => '1 GB',
        'validity' => '30 days',
        'plan_type' => 'SME Data',
        'clubkonnect_price' => 500.0,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'networks.allow_prefix_inference' => true,
            'bills.check_provider_health' => false,
            'bills.check_provider_balance' => false,
            'bills.optimise_cost' => false,
            'bills.provider_order' => ['clubkonnect'],
            'bills.limits.per_minute' => 100,
        ]);

        /*
         * Airtime's own cost and rule. Airtime is sold at face value, so it needs a
         * FACE_VALUE rule and a verified per-network cost; without them the cost
         * resolver refuses, which is the behaviour `AirtimePricingTest` pins.
         */
        ProviderNetworkCost::create([
            'provider_id' => Provider::firstOrCreate(
                ['slug' => 'clubkonnect'],
                ['name' => 'Clubkonnect', 'driver' => 'clubkonnect', 'is_active' => true, 'priority' => 100]
            )->getKey(),
            'capability' => 'airtime',
            'network' => 'mtn',
            'discount_bps' => 300,
            'provider_fee_minor' => 0,
            'currency' => 'NGN',
            'verified_at' => now()->subHour(),
        ]);

        PricingRule::create([
            'name' => 'Airtime — mtn',
            'scope' => PricingRule::SCOPE_NETWORK,
            'network' => 'mtn',
            'markup_type' => PricingRule::MARKUP_FACE_VALUE,
            'markup_percentage_bps' => 0,
            'markup_fixed_minor' => 0,
            'minimum_profit_minor' => 0,
            'minimum_margin_bps' => 0,
            'customer_fee_enabled' => false,
            'allow_negative_margin' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ]);

        PricingRule::create([
            'name' => 'Data — default markup',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 300,
            'minimum_profit_minor' => 1,
            'customer_fee_enabled' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ]);

        $test = $this;

        $this->mock(ClubKonnectCatalogue::class, function ($mock) use ($test) {
            $mock->shouldReceive('plan')->andReturnUsing(fn () => $test->currentPlan());
            $mock->shouldReceive('plans')->andReturnUsing(fn () => [
                'data_plans' => [$test->currentPlan()],
                'total_plans' => 1,
                'last_updated' => now()->toDateTimeString(),
            ]);
            $mock->shouldReceive('cachedPlans')->andReturn(null);
            $mock->shouldReceive('planPrice')->andReturn(null);
            $mock->shouldReceive('status')->andReturn([
                'last_success_at' => now()->toDateTimeString(),
                'age_seconds' => 5,
                'stale' => false,
                'plan_count' => 1,
                'consecutive_failures' => 0,
                'error_type' => null,
                'last_failure_at' => null,
            ]);
            $mock->shouldReceive('isStale')->andReturn(false);
            $mock->shouldReceive('forget')->andReturnNull();
        });
    }

    /** Public so the mock closure above can read it. */
    public function currentPlan(): array
    {
        return $this->plan;
    }

    /* =====================================================================
     | Parsing
     |=================================================================== */

    public function test_a_local_number_normalises_to_the_canonical_international_form(): void
    {
        $parsed = PhoneNumber::parse('08031234567');

        $this->assertTrue($parsed['valid']);
        $this->assertSame('08031234567', $parsed['national']);
        $this->assertSame('2348031234567', $parsed['international'], 'The form the requirement names');
    }

    public function test_every_supported_spelling_of_the_same_number_parses_identically(): void
    {
        $expected = '2348031234567';

        foreach ([
            '08031234567',
            '+2348031234567',
            '2348031234567',
            '0803 123 4567',
            '0803-123-4567',
            '+234 803 123 4567',
            '(0803) 123 4567',
        ] as $input) {
            $this->assertSame(
                $expected,
                PhoneNumber::international($input),
                "[{$input}] must parse to the same canonical number"
            );
        }
    }

    public function test_an_invalid_number_is_rejected_with_a_reason_a_customer_can_act_on(): void
    {
        $cases = [
            '' => 'empty',
            '0803123456' => 'ten digits',
            '080312345678' => 'twelve digits',
            '0803123456a' => 'contains a letter',
            '12345678901' => 'no leading zero',
            '0123456789' => 'a landline',
        ];

        foreach ($cases as $input => $description) {
            $parsed = PhoneNumber::parse((string) $input);

            $this->assertFalse($parsed['valid'], "[{$input}] is invalid ({$description})");
            $this->assertNotNull($parsed['reason'], "[{$input}] must explain why");
            $this->assertNull($parsed['international']);
        }
    }

    /**
     * The reason matters: "enter your number" and "that is 10 digits, not 11" are
     * different problems and the customer has to fix a specific one.
     */
    public function test_the_rejection_reason_names_the_actual_problem(): void
    {
        $this->assertStringContainsString('11 digits', (string) PhoneNumber::parse('0803123456')['reason']);
        $this->assertStringContainsString('starts with 0', (string) PhoneNumber::parse('80312345678')['reason']);
        $this->assertStringContainsString('too long', (string) PhoneNumber::parse('23480312345678')['reason']);
    }

    public function test_a_non_nigerian_international_number_is_refused_clearly(): void
    {
        $parsed = PhoneNumber::parse('+448031234567');

        $this->assertFalse($parsed['valid']);
        $this->assertStringContainsString('Nigerian', (string) $parsed['reason']);
    }

    public function test_a_valid_number_is_formatted_for_a_human_to_check(): void
    {
        $this->assertSame('0803 123 4567', PhoneNumber::format('08031234567'));
        $this->assertSame('0803 123 4567', PhoneNumber::format('+2348031234567'));
    }

    public function test_every_mobile_prefix_band_is_accepted_and_landlines_are_not(): void
    {
        foreach (['0703', '0803', '0813', '0903', '0913'] as $prefix) {
            $this->assertTrue(PhoneNumber::isValid($prefix . '1234567'), "[{$prefix}] is a mobile prefix");
        }

        $this->assertTrue(PhoneNumber::isValid('08021234567'));

        // A landline — 01 is Lagos — is not a mobile number and cannot receive a
        // bundle or a top-up.
        $this->assertFalse(PhoneNumber::isValid('0123456789'));

        /*
         * ## A known limitation, asserted rather than hidden
         *
         * The shape rule accepts any `0[789][01]` prefix. Two of those — 0700 and
         * 0800 — are the ones this rule cannot distinguish from a mobile band
         * without a prefix allocation list it does not have, so they pass shape
         * validation and then detect **no network**, because `prefix_hints` does not
         * list them.
         *
         * That is the safe direction: the customer is asked to choose, rather than
         * being sent to a guessed network. Tightening the shape rule from memory
         * would be worse — a wrongly rejected band refuses a real customer's real
         * number, and there is no way to tell from here which prefixes that would be.
         */
        $this->assertTrue(PhoneNumber::isValid('08001234567'), 'The shape passes...');
        $this->assertNull(
            PhoneNumber::detect('08001234567')['key'],
            '...but no network is claimed for it, so the customer chooses'
        );
    }

    /* =====================================================================
     | Detection
     |=================================================================== */

    public function test_each_canonical_network_is_detected_from_its_own_prefix(): void
    {
        foreach ([
            '08031234567' => 'mtn',
            '08021234567' => 'airtel',
            '08051234567' => 'glo',
            '08091234567' => '9mobile',
        ] as $number => $expected) {
            $detection = PhoneNumber::detect($number);

            $this->assertNotNull($detection);
            $this->assertSame($expected, $detection['key'], "[{$number}] is a {$expected} number");
        }
    }

    /**
     * The core honesty requirement: a prefix is never presented as certainty.
     */
    public function test_detection_is_never_reported_as_certain(): void
    {
        foreach (['08031234567', '08021234567', '08051234567', '08091234567'] as $number) {
            $detection = PhoneNumber::detect($number);

            $this->assertSame(
                PhoneNumber::CONFIDENCE_UNCERTAIN,
                $detection['confidence'],
                'A prefix cannot prove the serving network, so nothing here may claim it does'
            );
            $this->assertSame(PhoneNumber::SOURCE_PREFIX, $detection['source']);

            // The source never names a provider, because no provider lookup exists.
            $this->assertStringNotContainsString('provider', $detection['source']);
        }
    }

    public function test_an_unlisted_prefix_yields_no_candidate_rather_than_a_guess(): void
    {
        // A valid Nigerian mobile number on a prefix `prefix_hints` does not list —
        // which is also what a newly allocated range looks like.
        $detection = PhoneNumber::detect('07041234567');

        $this->assertNotNull($detection, 'The number is valid, so detection ran');
        $this->assertNull($detection['key'], 'An unlisted prefix must produce no candidate, not a default');
        $this->assertNull($detection['name']);
    }

    public function test_an_invalid_number_detects_nothing_at_all(): void
    {
        $this->assertNull(PhoneNumber::detect('0803123456'));
        $this->assertNull(PhoneNumber::detect(''));
        $this->assertNull(PhoneNumber::detect(null));
        $this->assertNull(PhoneNumber::detect('not a number'));
    }

    public function test_the_t2mobile_alias_resolves_to_the_canonical_9mobile_key(): void
    {
        foreach (['9mobile', '9 Mobile', 'T2', 'T2mobile', 'Etisalat', '03'] as $spelling) {
            $this->assertSame(
                '9mobile',
                NetworkResolver::key($spelling, 'clubkonnect'),
                "[{$spelling}] must resolve to the canonical key"
            );
        }

        // And the customer-facing label is the current brand, from the one mapping.
        $this->assertSame('T2mobile', NetworkResolver::labelFor('9mobile'));
        $this->assertSame('T2mobile', NetworkBrand::label('9mobile'));
        $this->assertSame('T2mobile', NetworkBrand::labelFor('T2'));
        $this->assertSame('t2.png', NetworkBrand::logoPath('9mobile'));
    }

    public function test_prefix_inference_can_be_switched_off(): void
    {
        config(['networks.allow_prefix_inference' => false]);

        $detection = PhoneNumber::detect('08031234567');

        $this->assertNotNull($detection, 'The number is still valid');
        $this->assertNull($detection['key'], 'With inference off, detection yields no candidate');
    }

    /**
     * A prefix listed under two networks would make the mapping depend on array
     * order — a silent, unorderable defect.
     */
    public function test_no_prefix_is_claimed_by_two_networks(): void
    {
        $seen = [];

        foreach ((array) config('networks.prefix_hints', []) as $key => $prefixes) {
            foreach ((array) $prefixes as $prefix) {
                $this->assertArrayNotHasKey(
                    $prefix,
                    $seen,
                    "Prefix {$prefix} is claimed by more than one network ({$key} is the second)"
                );

                $seen[$prefix] = $key;
            }
        }

        $this->assertNotEmpty($seen);
    }

    /* =====================================================================
     | The detection endpoint
     |=================================================================== */

    public function test_the_detection_endpoint_returns_the_network_the_form_code_and_the_logo(): void
    {
        $response = $this->actingAs($this->customer())
            ->getJson(route('airtime-data.detect-network', ['phone' => '08031234567']));

        $response->assertOk();

        $this->assertTrue($response->json('valid'));
        $this->assertSame('mtn', $response->json('detected.key'));
        $this->assertSame('01', $response->json('detected.code'), 'The code the form posts');
        $this->assertSame('MTN', $response->json('detected.name'));
        $this->assertStringContainsString('mtn.jpeg', (string) $response->json('detected.logo'));
        $this->assertSame('0803 123 4567', $response->json('formatted'));

        // The uncertainty travels with the answer so the page can word it.
        $this->assertTrue($response->json('detected.portable'));
        $this->assertSame(PhoneNumber::CONFIDENCE_UNCERTAIN, $response->json('detected.confidence'));
    }

    public function test_the_detection_endpoint_makes_no_provider_request(): void
    {
        /*
         * There is no number-to-network endpoint in either integration, so a
         * detection that contacted a provider would be contacting an endpoint that
         * does not exist. This is the assertion that keeps that true.
         */
        Http::fake();

        $this->actingAs($this->customer())
            ->getJson(route('airtime-data.detect-network', ['phone' => '08031234567']))
            ->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_detection_endpoint_explains_an_invalid_number(): void
    {
        $response = $this->actingAs($this->customer())
            ->getJson(route('airtime-data.detect-network', ['phone' => '0803123456']));

        $response->assertOk();
        $this->assertFalse($response->json('valid'));
        $this->assertNotNull($response->json('reason'));
        $this->assertNull($response->json('detected'));
    }

    public function test_the_detection_endpoint_returns_no_network_for_an_unlisted_prefix(): void
    {
        $response = $this->actingAs($this->customer())
            ->getJson(route('airtime-data.detect-network', ['phone' => '07041234567']));

        $response->assertOk();
        $this->assertTrue($response->json('valid'), 'The number is valid');
        $this->assertNull($response->json('detected.key'), 'But no network is guessed');
    }

    /* =====================================================================
     | The page
     |=================================================================== */

    public function test_the_purchase_page_renders_the_detection_affordances(): void
    {
        $response = $this->actingAs($this->customer())->get(route('airtime-data.index'));

        $response->assertOk();

        // The readout, announced because it changes as a result of typing.
        $response->assertSee('dataNetworkDetect', false);
        $response->assertSee('aria-live="polite"', false);

        // The override field the dialog writes.
        $response->assertSee('name="network_confirmation"', false);

        // Both decisions' copy, in one dialog.
        $response->assertSee('Check your network', false);
        $response->assertSee('Use my selected network', false);
        $response->assertSee('Data bundle price updated', false);
    }

    /* =====================================================================
     | The safeguards detection feeds
     |=================================================================== */

    public function test_a_data_purchase_is_refused_when_the_network_contradicts_the_number(): void
    {
        $user = $this->customer();

        // The catalogue's plan is MTN and the form agrees; the number is Airtel's.
        $response = $this->actingAs($user)->postJson(route('data.purchase'), $this->dataPayload([
            'phone' => '08021234567',
        ]));

        $response->assertStatus(409);
        $this->assertSame('network_mismatch', $response->json('status'));
        $this->assertSame('mtn', $response->json('selected_network_key'));
        $this->assertSame('airtel', $response->json('detected_network_key'));
        $this->assertSame('0802 123 4567', $response->json('phone_formatted'));

        $this->assertSame(0, Transactions::where('user_id', $user->id)->count());
        $this->assertSame('5000.00', $this->balanceOf($user), 'Nothing was charged');
    }

    /**
     * Confirming is the override, and it lets the purchase through — the ported
     * number case, which is the one a prefix gets wrong.
     */
    public function test_confirming_the_network_allows_the_purchase(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $payload = $this->dataPayload(['phone' => '08021234567']);

        // First attempt refuses and asks.
        $this->actingAs($user)->postJson(route('data.purchase'), $payload)->assertStatus(409);

        // The customer confirms, and it goes through.
        $this->actingAs($user)->post(route('data.purchase'), $payload + ['network_confirmation' => '1'])
            ->assertRedirect();

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($transaction, 'The confirmed purchase was placed');
        $this->assertSame('mtn', $transaction->network_code);
        $this->assertNotSame('5000.00', $this->balanceOf($user), 'And the wallet was debited');
    }

    public function test_a_number_matching_the_selected_network_is_not_questioned(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->actingAs($user)->post(route('data.purchase'), $this->dataPayload([
            'phone' => '08031234567', // MTN number, MTN plan
        ]))->assertRedirect();

        $this->assertSame(1, Transactions::where('user_id', $user->id)->count());
    }

    /**
     * An unlisted prefix must not block a sale — the customer's choice stands.
     */
    public function test_an_unlisted_prefix_does_not_block_a_purchase(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->actingAs($user)->post(route('data.purchase'), $this->dataPayload([
            'phone' => '07041234567', // valid, unlisted prefix
        ]))->assertRedirect();

        $this->assertSame(1, Transactions::where('user_id', $user->id)->count());
    }

    /**
     * The customer's confirmation is recorded as *theirs*, not as a detection we
     * made — a support question about a bundle that did not arrive needs to know
     * which happened.
     */
    public function test_a_confirmed_network_is_recorded_as_the_customers_own_statement(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->actingAs($user)->post(route('data.purchase'), $this->dataPayload([
            'phone' => '08021234567',
        ]) + ['network_confirmation' => '1'])->assertRedirect();

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame(
            PhoneNumber::SOURCE_CUSTOMER,
            $transaction->meta['network_source'] ?? null,
            'A confirmed network is the customer\'s statement, not a detection'
        );
        $this->assertSame('airtel', $transaction->meta['network_detected'] ?? null);
    }

    public function test_a_matched_network_is_recorded_as_the_prefix_detection(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->actingAs($user)->post(route('data.purchase'), $this->dataPayload([
            'phone' => '08031234567',
        ]))->assertRedirect();

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame(PhoneNumber::SOURCE_PREFIX, $transaction->meta['network_source'] ?? null);
    }

    /**
     * A plan from another network is refused, not silently reassigned.
     */
    public function test_a_bundle_from_another_network_is_refused(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $response = $this->actingAs($user)->postJson(route('data.purchase'), $this->dataPayload([
            'data_network' => '04', // the form claims Airtel; the plan is MTN
        ]));

        $response->assertStatus(409);
        $this->assertStringContainsString('different network', (string) $response->json('message'));
        $this->assertSame(0, Transactions::where('user_id', $user->id)->count());
        $this->assertSame('5000.00', $this->balanceOf($user));
    }

    /**
     * The check lives in the pricing service, so no caller can bypass it.
     *
     * The controller used to pass a network that the service then ignored, which is
     * exactly what let a purchase claim one network and be stored under another.
     * This asserts the guard at the layer that holds it.
     */
    public function test_the_pricing_service_refuses_a_network_that_contradicts_the_plan(): void
    {
        $service = app(\App\Services\DataPriceService::class);

        // The mocked plan is MTN; ask for it as an Airtel bundle.
        $mismatched = $service->quote($this->plan['plan_id'], 'airtel');

        $this->assertTrue($mismatched['found'], 'The bundle exists');
        $this->assertNull($mismatched['price_minor'], 'But it cannot be priced as another network');
        $this->assertStringContainsString('different network', (string) $mismatched['refusal']);
        $this->assertSame('mtn', $mismatched['network_key'], 'The plan\'s own network is reported');

        // The matching network prices normally.
        $matched = $service->quote($this->plan['plan_id'], 'mtn');

        $this->assertNotNull($matched['price_minor']);
        $this->assertSame('mtn', $matched['network_key']);

        // A caller with no claim to verify — the price list — is not blocked.
        $this->assertNotNull($service->quote($this->plan['plan_id'])['price_minor']);
    }

    /**
     * Airtime is delivered to the same number, so it gets the same check.
     */
    public function test_an_airtime_purchase_is_refused_when_the_network_contradicts_the_number(): void
    {
        $user = $this->customer();

        $response = $this->actingAs($user)->postJson(route('airtime.purchase'), [
            'network' => '01', // MTN
            'phone' => '08021234567', // Airtel
            'amount' => 500,
            'pin' => '5931',
        ]);

        $response->assertStatus(409);
        $this->assertSame('network_mismatch', $response->json('status'));
        $this->assertSame('5000.00', $this->balanceOf($user));
    }

    /**
     * Airtime has no bundle, so the dialog still has to describe something.
     */
    public function test_the_airtime_mismatch_describes_the_denomination(): void
    {
        $user = $this->customer();

        $response = $this->actingAs($user)->postJson(route('airtime.purchase'), [
            'network' => '01',
            'phone' => '08021234567',
            'amount' => 500,
            'pin' => '5931',
        ]);

        $response->assertStatus(409);
        $this->assertStringContainsString('500', (string) $response->json('product.plan_name'));
        $this->assertSame('MTN', $response->json('product.network'));
    }

    public function test_an_airtime_purchase_from_a_matching_number_is_not_questioned(): void
    {
        $user = $this->customer();
        $this->fakeVending();

        $this->actingAs($user)->post(route('airtime.purchase'), [
            'network' => '01',
            'phone' => '08031234567', // MTN
            'amount' => 500,
            'pin' => '5931',
        ])->assertRedirect();

        $this->assertSame(1, Transactions::where('user_id', $user->id)->count());
    }

    /* =====================================================================
     | Fixtures
     |=================================================================== */

    private function dataPayload(array $overrides = []): array
    {
        return array_merge([
            'data_network' => '01',
            'data_plan' => $this->plan['plan_id'],
            'phone' => '08031234567',
            'plan_name' => $this->plan['plan_name'],
            'plan_price' => '515.00',
            'plan_type' => $this->plan['plan_type'],
            'pin' => '5931',
        ], $overrides);
    }

    private function fakeVending(): void
    {
        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));
    }

    private function customer(string $balance = '5000.00'): User
    {
        $user = User::factory()->create();
        app(SecurityService::class)->setPin($user, '5931');
        app(WalletService::class)->credit($user, $balance);

        return $user->fresh();
    }

    private function balanceOf(User $user): string
    {
        return (string) Wallet::where('user_id', $user->id)->value('balance');
    }
}
