<?php

namespace Tests\Feature;

use App\Services\ProviderManager;
use App\Services\Providers\PairgateProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Pairgate is the failover upstream, so what is worth asserting here is the
 * behaviour that decides whether failing over is *safe*:
 *
 *   * identifiers are translated rather than guessed — the rest of the app
 *     speaks ClubKonnect's codes, Pairgate wants its own slugs;
 *   * a rejection that must never be retried on the other provider (a duplicate
 *     reference, which would vend twice) is classified fatal, while a rejection
 *     the other provider may not share (short float) is retryable;
 *   * the account name a verification returns is visible where every controller
 *     looks for it.
 *
 * Nothing here touches the database: the provider is HTTP plus config.
 */
class PairgateProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.pairgate.api_key' => 'pg_test_key',
            'services.pairgate.base_url' => 'https://pairgate.com/api/v1',
            'services.pairgate.test_mode' => false,
        ]);
    }

    private function provider(): PairgateProvider
    {
        return $this->app->make(PairgateProvider::class);
    }

    private function manager(): ProviderManager
    {
        return $this->app->make(ProviderManager::class);
    }

    public function test_the_health_probe_accepts_a_successful_wallet_enquiry(): void
    {
        Http::fake([
            '*pairgate.com/api/v1/wallet/balance' => Http::response([
                'code' => 200,
                'status' => 'success',
                'data' => ['balance' => 1500.00],
            ]),
        ]);

        $this->assertTrue($this->provider()->ping());
    }

    public function test_the_health_probe_treats_a_rejected_key_as_unavailable(): void
    {
        Http::fake([
            '*pairgate.com/api/v1/wallet/balance' => Http::response([
                'code' => 401,
                'status' => 'error',
                'message' => 'Invalid API key',
            ], 401),
        ]);

        $this->assertFalse($this->provider()->ping());
    }

    public function test_the_health_probe_treats_a_dead_endpoint_as_unavailable(): void
    {
        Http::fake([
            '*pairgate.com/api/v1/wallet/balance' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timed out'),
        ]);

        $this->assertFalse($this->provider()->ping());
    }

    public function test_a_recorded_plan_price_is_used_without_calling_the_catalogue(): void
    {
        Http::fake();

        config(['bills.pairgate.data_plans' => ['CK-1' => ['plan_id' => '45', 'price' => 480.00]]]);

        $this->assertSame(480.00, $this->provider()->cost('data', ['network' => '01', 'plan' => 'CK-1']));

        // Pricing a purchase must never add an upstream round trip to checkout.
        Http::assertNothingSent();
    }

    public function test_an_unmapped_plan_has_no_price_at_all(): void
    {
        Http::fake();

        $this->assertNull($this->provider()->cost('data', ['network' => '01', 'plan' => 'CK-1']));
        Http::assertNothingSent();
    }

    public function test_the_manager_holds_both_upstreams_in_the_configured_order(): void
    {
        $names = array_map(fn ($provider) => $provider->name(), $this->manager()->all());

        $this->assertSame(['clubkonnect', 'pairgate'], $names);
        $this->assertSame('Pairgate', $this->manager()->byName('pairgate')->label());

        // Every provider the config advertises must be registered, or an entry
        // in `providers` would be dead weight that never vends.
        $this->assertSame(array_keys((array) config('bills.providers')), $names);
    }

    public function test_an_order_entry_with_no_provider_behind_it_is_reported(): void
    {
        // This is what a stale BILL_PROVIDER_ORDER looks like after a vendor is
        // swapped out: the old name matches nothing and failover silently
        // disappears. It has to be visible in the log.
        config([
            'bills.provider_order' => ['clubkonnect', 'ghost'],
            'services.clubkonnect.client_id' => 'ck-id',
            'services.clubkonnect.api_key' => 'ck-key',
        ]);

        Log::spy();

        $providers = $this->manager()->candidates('airtime');

        $this->assertSame(['clubkonnect'], array_map(fn ($provider) => $provider->name(), $providers));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message) => str_contains((string) $message, 'do not exist'))
            ->once();
    }

    public function test_airtime_is_translated_from_a_clubkonnect_code_and_success_is_normalised(): void
    {
        Http::fake([
            '*pairgate.com/api/v1/airtime/purchase' => Http::response([
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'status' => true,
                    'message' => 'Airtime purchase successful & processing.',
                    'reference_code' => 'TRXVTU20260615104818CQ3',
                ],
            ]),
        ]);

        $provider = $this->provider();

        $response = $provider->purchase('airtime', [
            'network' => '01',
            'phone' => '08031234567',
            'amount' => 500,
        ], 'TXN-260615-ABCDEFGHIJKL');

        $this->assertTrue($provider->isSuccess($response));
        $this->assertSame('TRXVTU20260615104818CQ3', $provider->orderReference($response));

        Http::assertSent(fn ($request) => $request->url() === 'https://pairgate.com/api/v1/airtime/purchase'
            && $request['provider_id'] === 'mtn'
            && $request['recipient'] === '08031234567'
            && $request['reference'] === 'TXN-260615-ABCDEFGHIJKL'
            && $request->hasHeader('Authorization', 'Bearer pg_test_key'));
    }

    public function test_a_data_plan_with_no_mapping_is_refused_locally_rather_than_guessed(): void
    {
        Http::fake();

        $provider = $this->provider();

        $response = $provider->purchase('data', [
            'network' => '01',
            'plan' => 'CK-PLAN-99',
            'phone' => '08031234567',
        ], 'TXN-260615-ABCDEFGHIJKL');

        $this->assertSame('UNSUPPORTED', $response['status']);

        // Nothing may reach Pairgate: a ClubKonnect plan id sent as a Pairgate
        // plan_id is a request that can only be rejected, and the failover
        // budget is better spent on the provider that can serve it.
        Http::assertNothingSent();

        $this->assertSame('retryable', $this->manager()->classify($provider, $response));
    }

    public function test_a_mapped_data_plan_is_sent_as_pairgate_plan_id(): void
    {
        config(['bills.pairgate.data_plans' => ['CK-PLAN-99' => '45']]);

        Http::fake([
            '*pairgate.com/api/v1/data/purchase' => Http::response([
                'code' => 200,
                'status' => 'success',
                'data' => ['status' => true, 'reference_code' => 'TRXDATA20260615100238ONI'],
            ]),
        ]);

        $response = $this->provider()->purchase('data', [
            'network' => '03',
            'plan' => 'CK-PLAN-99',
            'phone' => '08031234567',
        ], 'TXN-260615-ABCDEFGHIJKL');

        $this->assertTrue($this->provider()->isSuccess($response));

        Http::assertSent(fn ($request) => $request['provider_id'] === '9mobile'
            && $request['plan_id'] === '45');
    }

    public function test_electricity_translates_the_disco_code_and_the_meter_type_words(): void
    {
        Http::fake([
            '*pairgate.com/api/v1/electricity/purchase' => Http::response([
                'code' => 200,
                'status' => 'success',
                'data' => ['status' => true, 'reference_code' => 'TRXEE20260615104818CQ3'],
            ]),
        ]);

        $this->provider()->purchase('electricity', [
            'disco' => '01',
            'meter_number' => '1234567890',
            'meter_type' => 'prepaid',
            'amount' => 5000,
            'phone' => '08031234567',
        ], 'TXN-260615-ABCDEFGHIJKL');

        Http::assertSent(fn ($request) => $request['provider_id'] === 'ikedc'
            && $request['meter_type'] === 1
            && $request['amount'] === 5000.0);
    }

    public function test_verification_lifts_the_customer_name_to_where_controllers_read_it(): void
    {
        Http::fake([
            '*pairgate.com/api/v1/cable/verify' => Http::response([
                'code' => 200,
                'status' => 'success',
                'data' => ['status' => true, 'customer_name' => 'John Doe'],
            ]),
        ]);

        $provider = $this->provider();

        $response = $provider->verifyCustomer('cable_tv', [
            'provider' => 'dstv',
            'smartcard_number' => '1234567890',
        ]);

        $this->assertTrue($provider->isSuccess($response));
        $this->assertSame('John Doe', $response['customer_name']);

        Http::assertSent(fn ($request) => $request->url() === 'https://pairgate.com/api/v1/cable/verify'
            && $request['provider_id'] === 'dstv'
            && $request['smartcard'] === '1234567890');
    }

    public function test_insufficient_float_is_retryable(): void
    {
        Http::fake([
            '*pairgate.com/api/v1/electricity/purchase' => Http::response([
                'code' => 422,
                'status' => 'error',
                'message' => 'Insufficient balance.',
            ], 422),
        ]);

        $provider = $this->provider();

        $response = $provider->purchase('electricity', [
            'disco' => '01',
            'meter_number' => '1234567890',
            'meter_type' => 'postpaid',
            'amount' => 2000,
            'phone' => '08031234567',
        ], 'TXN-260615-ABCDEFGHIJKL');

        $this->assertSame('INSUFFICIENT_BALANCE', $response['status']);
        $this->assertSame('retryable', $this->manager()->classify($provider, $response));
        $this->assertSame('Provider float is insufficient.', $provider->errorMessage($response));
    }

    public function test_a_duplicate_reference_is_fatal_so_it_is_never_vended_twice(): void
    {
        Http::fake([
            '*pairgate.com/api/v1/electricity/purchase' => Http::response([
                'code' => 422,
                'status' => 'error',
                'message' => 'This purchase was already processed.',
            ], 422),
        ]);

        $provider = $this->provider();

        $response = $provider->purchase('electricity', [
            'disco' => '01',
            'meter_number' => '1234567890',
            'meter_type' => 'postpaid',
            'amount' => 2000,
            'phone' => '08031234567',
        ], 'TXN-260615-ABCDEFGHIJKL');

        $this->assertSame('DUPLICATE_REFERENCE', $response['status']);
        $this->assertSame('fatal', $this->manager()->classify($provider, $response));
    }

    public function test_balance_reads_the_documented_field(): void
    {
        Http::fake([
            '*pairgate.com/api/v1/wallet/balance' => Http::response([
                'code' => 200,
                'status' => 'success',
                'data' => ['balance' => 1500.75, 'currency' => 'NGN'],
            ]),
        ]);

        $balance = $this->provider()->balance();

        $this->assertTrue($balance['success']);
        $this->assertSame(1500.75, $balance['balance']);
        $this->assertSame('NGN', $balance['currency']);
    }

    public function test_test_mode_prefixes_every_call(): void
    {
        config(['services.pairgate.test_mode' => true]);

        Http::fake([
            '*pairgate.com/api/v1/test/airtime/purchase' => Http::response([
                'code' => 200,
                'status' => 'success',
                'data' => ['status' => true, 'test_mode' => true],
            ]),
        ]);

        $this->provider()->purchase('airtime', [
            'network' => '04',
            'phone' => '08031234567',
            'amount' => 100,
        ], 'TXN-260615-ABCDEFGHIJKL');

        Http::assertSent(fn ($request) => $request->url() === 'https://pairgate.com/api/v1/test/airtime/purchase'
            && $request['provider_id'] === 'airtel');
    }

    public function test_jamb_is_not_claimed_and_is_refused_with_a_reason(): void
    {
        $provider = $this->provider();

        $this->assertFalse($provider->supports('jamb'));
        $this->assertTrue($provider->supports('waec'));

        Http::fake();

        $response = $provider->purchase('jamb', [
            'exam_type' => 'utme',
            'quantity' => 1,
            'phone' => '08031234567',
        ], 'TXN-260615-ABCDEFGHIJKL');

        $this->assertFalse($provider->isSuccess($response));
        $this->assertStringContainsString('JAMB', $provider->errorMessage($response));
        Http::assertNothingSent();
    }

    public function test_exam_pins_are_purchased_against_the_education_slug(): void
    {
        Http::fake([
            '*pairgate.com/api/v1/education/purchase' => Http::response([
                'code' => 200,
                'status' => 'success',
                'data' => [
                    'status' => true,
                    'reference' => 'TXN-260615-ABCDEFGHIJKL',
                    'quantity' => 2,
                    'details' => [
                        ['reference' => 'TRXEXAM20260615104818CQ3', 'pin' => null],
                        ['reference' => 'TRXEXAM20260615104818CQ4', 'pin' => null],
                    ],
                ],
            ]),
        ]);

        $provider = $this->provider();

        // The controller posts the exam name upper-cased; the map is lower-case.
        $response = $provider->purchase('waec', [
            'exam_type' => 'WAEC',
            'quantity' => 2,
            'phone' => '08031234567',
        ], 'TXN-260615-ABCDEFGHIJKL');

        $this->assertTrue($provider->isSuccess($response));
        $this->assertSame('TRXEXAM20260615104818CQ3', $provider->orderReference($response));

        Http::assertSent(fn ($request) => $request['provider_id'] === 'waec' && $request['quantity'] === 2);
    }
}
