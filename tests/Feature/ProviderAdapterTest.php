<?php

namespace Tests\Feature;

use App\Models\Provider;
use App\Providers\Adapters\NitroSmmProvider;
use App\Providers\Adapters\SogoProvider;
use App\Providers\Adapters\VtpassProvider;
use App\Providers\Adapters\VtugateProvider;
use App\Providers\ProviderRegistry;
use App\Providers\Support\PayloadRedactor;
use App\Providers\Support\ProviderStatus;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Wave 1 provider adapters.
 *
 * The assertions that carry money are:
 *
 *   * an unrecognised status is UNKNOWN, never FAILED;
 *   * a transport failure is UNKNOWN, never FAILED;
 *   * only RETRYABLE permits failover;
 *   * Nitro's `rate × quantity ÷ 1000` is right — an error of three orders of
 *     magnitude is available there;
 *   * a Nitro error arriving with HTTP 200 is still an error;
 *   * no credential and no delivery token reaches a log.
 */
class ProviderAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'providers.sandbox' => false,
            'providers.http.timeout' => 5,
            'providers.http.probe_timeout' => 5,
            'providers.http.rate_limit.max_attempts' => 2,
            'providers.http.rate_limit.base_delay_ms' => 1,
            'providers.http.rate_limit.jitter_percent' => 0,
            // Credentials are set in the process environment for these tests.
            'services.unused' => null,
        ]);

        $this->setEnv('SOGO_SECRET_KEY', 'sogo_sk_test_unit');
        $this->setEnv('NITRO_KEY', 'nitro_test_key');
        $this->setEnv('VTUGATE_KEY', 'vtugate_test_key');
        $this->setEnv('VTPASS_API_KEY', 'vtpass_test_api_key');
        $this->setEnv('VTPASS_PUBLIC_KEY', 'vtpass_test_public_key');
        $this->setEnv('VTPASS_SECRET_KEY', 'vtpass_test_secret_key');
    }

    /**
     * Put a value where `ProviderCredentials` looks for it.
     *
     * `ProviderCredentials` reads the process environment directly — never
     * `config()`, so that a cached config artefact cannot contain a secret — and
     * this is how a test supplies one without touching `.env`.
     */
    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);

            return;
        }

        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    /**
     * The first value of a header, matched case-insensitively.
     *
     * HTTP header names are case-insensitive, and both Guzzle and the adapters
     * reach for different conventions — `Authorization` from `withToken()`,
     * `secret-key` from configuration. Comparing case-sensitively would make these
     * tests fail for a reason that has nothing to do with what they are asserting.
     */
    private function headerValue($request, string $name): ?string
    {
        foreach ($request->headers() as $key => $values) {
            if (strtolower((string) $key) === strtolower($name)) {
                return (string) ($values[0] ?? '');
            }
        }

        return null;
    }

    /**
     * VTpass's documented success response for an international top-up, verbatim.
     *
     * Kept as a fixture rather than built inline because the arithmetic it encodes is
     * the point: amount 2.00, commission 0.08, total_amount 1.92. A test that
     * constructed these numbers itself could not catch the adapter misreading them.
     *
     * @return array<string, mixed>
     */
    private function vtpassDelivered(): array
    {
        return [
            'code' => '000',
            'content' => [
                'transactions' => [
                    'status' => 'delivered',
                    'product_name' => 'International Airtime',
                    'unique_element' => '2345638473434',
                    'unit_price' => '2',
                    'quantity' => 1,
                    'channel' => 'api',
                    'commission' => 0.08,
                    'total_amount' => 1.92,
                    'type' => 'Airtime Recharge',
                    'email' => 'buyer@example.test',
                    'phone' => '123450987623',
                    'convinience_fee' => 0,
                    'amount' => '2',
                    'platform' => 'api',
                    'method' => 'api',
                    'transactionId' => '17416193926764171056841998',
                    'commission_details' => [
                        'amount' => 0.08,
                        'rate' => '4.00',
                        'rate_type' => 'percent',
                        'computation_type' => 'default',
                    ],
                ],
            ],
            'response_description' => 'TRANSACTION SUCCESSFUL',
            'requestId' => 'REQ-1',
            'amount' => 2,
            'transaction_date' => '2025-03-10T15:09:52.000000Z',
            'purchased_code' => '',
            'cards' => null,
        ];
    }

    private function provider(string $slug, string $driver, array $capabilities, array $attributes = []): Provider
    {
        return Provider::create(array_merge([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'driver' => $driver,
            'capabilities' => $capabilities,
            'is_active' => true,
            'priority' => 100,
            'credential_env_prefix' => match ($driver) {
                'sogo' => 'SOGO',
                'nitro' => 'NITRO',
                'vtugate' => 'VTUGATE',
                'vtpass' => 'VTPASS',
                default => strtoupper($slug),
            },
        ], $attributes));
    }

    /* =====================================================================
     | Status mapping — UNKNOWN is not FAILED
     |=================================================================== */

    public function test_sogo_maps_its_documented_statuses_correctly(): void
    {
        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));

        $this->assertSame(ProviderStatus::SUCCESS, $adapter->mapStatus('completed'));
        $this->assertSame(ProviderStatus::PROCESSING, $adapter->mapStatus('processing'));
        $this->assertSame(ProviderStatus::FAILED, $adapter->mapStatus('failed'));
        $this->assertSame(ProviderStatus::REFUNDED, $adapter->mapStatus('refunded'));
        $this->assertSame(ProviderStatus::CANCELLED, $adapter->mapStatus('cancelled'));
    }

    public function test_an_unrecognised_status_is_unknown_not_failed(): void
    {
        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));

        /*
         * The single most important assertion in this file. A status a future API
         * version introduces must not read as a failure, because a failure
         * triggers a refund and refunding a delivered order gives the goods away.
         */
        foreach (['awaiting_fulfilment', 'queued', 'some_new_status', ''] as $unknown) {
            $this->assertSame(
                ProviderStatus::UNKNOWN,
                $adapter->mapStatus($unknown),
                "Status [{$unknown}] must map to UNKNOWN."
            );
        }
    }

    public function test_nitro_maps_its_panel_statuses_including_partial_and_refunded(): void
    {
        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        $this->assertSame(ProviderStatus::PENDING, $adapter->mapStatus('Pending'));
        $this->assertSame(ProviderStatus::PROCESSING, $adapter->mapStatus('In progress'));
        $this->assertSame(ProviderStatus::SUCCESS, $adapter->mapStatus('Completed'));
        $this->assertSame(ProviderStatus::PARTIAL, $adapter->mapStatus('Partial'));
        $this->assertSame(ProviderStatus::CANCELLED, $adapter->mapStatus('Canceled'));
        $this->assertSame(ProviderStatus::REFUNDED, $adapter->mapStatus('Refunded'));
    }

    public function test_a_nitro_error_string_is_not_a_status(): void
    {
        /*
         * `error` is the marker Nitro puts on a refused *request*, not a value its
         * `status` action returns. It must map to UNKNOWN rather than FAILED, so
         * that a status poll can never be read as a non-delivery on the strength
         * of an unrecognised string.
         */
        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        $this->assertSame(ProviderStatus::UNKNOWN, $adapter->mapStatus('error'));

        // Every status the `status` action can actually return maps cleanly.
        foreach (NitroSmmProvider::documentedStatuses() as $status) {
            $this->assertNotSame(
                ProviderStatus::UNKNOWN,
                $adapter->mapStatus($status),
                "Documented Nitro status [{$status}] must map to a canonical status."
            );
        }
    }

    public function test_only_retryable_permits_failover(): void
    {
        // The rule, expressed once and asserted directly.
        $this->assertTrue(ProviderStatus::permitsFailover(ProviderStatus::RETRYABLE));

        foreach ([
            ProviderStatus::SUCCESS,
            ProviderStatus::PENDING,
            ProviderStatus::PROCESSING,
            ProviderStatus::FAILED,
            ProviderStatus::UNKNOWN,
            ProviderStatus::CANCELLED,
            ProviderStatus::PARTIAL,
            ProviderStatus::REFUNDED,
        ] as $status) {
            $this->assertFalse(
                ProviderStatus::permitsFailover($status),
                "[{$status}] must not permit failover."
            );
        }
    }

    public function test_only_a_resolved_status_warrants_a_refund(): void
    {
        $this->assertTrue(ProviderStatus::warrantsRefund(ProviderStatus::FAILED));
        $this->assertTrue(ProviderStatus::warrantsRefund(ProviderStatus::CANCELLED));

        // The important negatives.
        $this->assertFalse(ProviderStatus::warrantsRefund(ProviderStatus::UNKNOWN));
        $this->assertFalse(ProviderStatus::warrantsRefund(ProviderStatus::PENDING));
        $this->assertFalse(ProviderStatus::warrantsRefund(ProviderStatus::PROCESSING));
        $this->assertFalse(ProviderStatus::warrantsRefund(ProviderStatus::PARTIAL));
    }

    public function test_an_unresolved_status_still_holds_customer_funds(): void
    {
        foreach ([ProviderStatus::SUCCESS, ProviderStatus::PENDING, ProviderStatus::PROCESSING, ProviderStatus::UNKNOWN, ProviderStatus::PARTIAL] as $status) {
            $this->assertTrue(ProviderStatus::holdsFunds($status));
        }

        $this->assertFalse(ProviderStatus::holdsFunds(ProviderStatus::REFUNDED));
    }

    /* =====================================================================
     | A transport failure is UNKNOWN, never FAILED
     | =================================================================== */

    public function test_a_connection_failure_produces_unknown_and_not_failed(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out')]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));

        $result = $adapter->purchaseAirtime('mtn', '08012345678', 1000, (string) \Illuminate\Support\Str::uuid());

        $this->assertSame(ProviderStatus::UNKNOWN, $result->status);
        $this->assertNotSame(ProviderStatus::FAILED, $result->status);
        $this->assertFalse($result->permitsFailover(), 'A timeout must not permit failover.');
        $this->assertFalse($result->warrantsRefund(), 'A timeout must not warrant a refund.');
        $this->assertTrue($result->isUnresolved());
    }

    /* =====================================================================
     | Sogo envelopes
     | =================================================================== */

    public function test_a_successful_sogo_airtime_purchase_is_normalised(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'Airtime purchase successful.',
            'data' => [
                'reference' => 'BP_20260524_XY1234ABCDEF',
                'status' => 'completed',
                'type' => 'airtime',
                'amount' => 1000,
                'fee' => 0,
            ],
        ], 201)]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));

        $result = $adapter->purchaseAirtime('mtn', '08012345678', 1000, (string) \Illuminate\Support\Str::uuid());

        $this->assertTrue($result->isSuccess());
        $this->assertSame('BP_20260524_XY1234ABCDEF', $result->providerReference);
        $this->assertSame('completed', $result->providerStatus);
    }

    public function test_a_sogo_gift_card_transaction_envelope_is_normalised(): void
    {
        Http::fake(['*' => Http::response([
            'status' => 'success',
            'message' => 'Your gift card purchase is being processed.',
            'transaction' => [
                'id' => 'e2cd33a2-df98-4c91-9e7f-b6eb5051a8f9',
                'reference' => 'SBXGC24J4P21F',
                'status' => ['value' => 'processing', 'is_final' => false],
                'amount' => ['raw' => 163400.0, 'formatted' => 'NGN 163,400.00', 'currency' => 'NGN'],
                'fee' => ['raw' => 0.0],
            ],
        ], 201)]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['gift_cards'], ['credential_scopes' => ['gift_cards:write']]));

        $result = $adapter->purchaseGiftCard(18270, '100.0', 1, 163400, (string) \Illuminate\Support\Str::uuid());

        $this->assertSame(ProviderStatus::PROCESSING, $result->status);
        $this->assertFalse($result->permitsFailover());
        $this->assertSame('SBXGC24J4P21F', $result->providerReference);
        $this->assertSame(16340000, $result->providerCostMinor);
    }

    public function test_a_sogo_success_the_provider_calls_non_final_stays_processing(): void
    {
        /*
         * `status.value = completed` with `is_final: false` is a contradiction,
         * and the provider's own finality flag is the more specific signal. A
         * sale reported as complete but not final must not be treated as revenue.
         */
        Http::fake(['*' => Http::response([
            'status' => 'success',
            'transaction' => [
                'reference' => 'REF-1',
                'status' => ['value' => 'completed', 'is_final' => false],
                'amount' => ['raw' => 100, 'currency' => 'NGN'],
            ],
        ], 201)]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['gift_cards']));

        $result = $adapter->purchaseGiftCard(1, '100', 1, 100, (string) \Illuminate\Support\Str::uuid());

        $this->assertSame(ProviderStatus::PROCESSING, $result->status);
    }

    public function test_a_sogo_insufficient_funds_error_is_retryable(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'insufficient_funds', 'message' => 'Your wallet balance is too low.'],
        ], 422)]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));

        $result = $adapter->purchaseAirtime('mtn', '08012345678', 1000, (string) \Illuminate\Support\Str::uuid());

        $this->assertSame(ProviderStatus::RETRYABLE, $result->status);
        $this->assertTrue($result->permitsFailover());
    }

    public function test_a_sogo_validation_error_is_fatal_not_retryable(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'verification_failed', 'message' => 'Meter number could not be validated.'],
        ], 422)]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['electricity']));

        $result = $adapter->purchaseElectricity('ikedc', '45012345678', 'prepaid', 5000, (string) \Illuminate\Support\Str::uuid());

        $this->assertSame(ProviderStatus::FAILED, $result->status);
        $this->assertFalse($result->permitsFailover(), 'A validation failure must not fail over.');
    }

    public function test_a_sogo_idempotency_collision_is_unknown(): void
    {
        /*
         * 409 idempotency_request_in_progress means an earlier attempt is still
         * running. The transaction exists, so retrying would be a duplicate — the
         * outcome must be reconciled, not guessed.
         */
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'idempotency_request_in_progress', 'message' => 'Already processing.'],
        ], 409)]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));

        $result = $adapter->purchaseAirtime('mtn', '08012345678', 1000, (string) \Illuminate\Support\Str::uuid());

        $this->assertSame(ProviderStatus::UNKNOWN, $result->status);
        $this->assertFalse($result->permitsFailover());
    }

    public function test_an_unrecognised_sogo_error_code_is_unknown(): void
    {
        Http::fake(['*' => Http::response([
            'error' => ['code' => 'brand_new_error_2027', 'message' => 'Something changed.'],
        ], 400)]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));

        $result = $adapter->purchaseAirtime('mtn', '08012345678', 1000, (string) \Illuminate\Support\Str::uuid());

        $this->assertSame(ProviderStatus::UNKNOWN, $result->status);
    }

    public function test_sogo_reconciliation_treats_a_404_as_never_created_and_safe_to_retry(): void
    {
        /*
         * Sogo documents this explicitly: a 404 from the reconciliation endpoint
         * means the transaction was never created, so retrying with the same
         * idempotency key cannot double-charge. It is the only answer that
         * produces RETRYABLE.
         */
        Http::fake(['*' => Http::response(['error' => ['code' => 'not_found']], 404)]);

        $adapter = new SogoProvider($this->provider('sogo-reconcile-404', 'sogo', ['airtime']));

        $result = $adapter->reconcile('11111111-1111-4111-8111-111111111111');

        $this->assertSame(ProviderStatus::RETRYABLE, $result->status);
        $this->assertTrue($result->permitsFailover());
    }

    public function test_sogo_reconciliation_treats_a_found_transaction_as_not_retryable(): void
    {
        // A 200 means the transaction exists. Its status decides, and a retry
        // would be a second charge.
        Http::fake(['*' => Http::response([
            'data' => ['reference' => 'BP-1', 'status' => 'processing'],
        ], 200)]);

        $adapter = new SogoProvider($this->provider('sogo-reconcile-200', 'sogo', ['airtime']));

        $result = $adapter->reconcile('11111111-1111-4111-8111-111111111111');

        $this->assertSame(ProviderStatus::PROCESSING, $result->status);
        $this->assertFalse($result->permitsFailover());
    }

    public function test_a_sogo_purchase_without_an_idempotency_key_is_refused_before_the_request(): void
    {
        Http::fake(['*' => Http::response(['data' => ['status' => 'completed']], 201)]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));

        try {
            $adapter->purchaseAirtime('mtn', '08012345678', 1000, '   ');
            $this->fail('A purchase without an idempotency key must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('idempotency key', $e->getMessage());
        }

        // Critically: no request was made at all.
        Http::assertNothingSent();
    }

    public function test_sogo_delivery_tokens_are_lifted_out_of_the_response(): void
    {
        Http::fake(['*' => Http::response([
            'message' => 'ePIN purchase successful.',
            'data' => [
                'reference' => 'BP-EPIN-1',
                'status' => 'completed',
                'pins' => [
                    ['pin' => '1234-5678-9012', 'serial' => 'SN-1'],
                    ['pin' => '9876-5432-1098', 'serial' => 'SN-2'],
                ],
            ],
        ], 201)]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['epin']));

        $result = $adapter->purchaseEpin('mtn', 100, 2, (string) \Illuminate\Support\Str::uuid());

        $this->assertNotNull($result->delivery);
        $this->assertSame('epin', $result->delivery->kind);
        $this->assertSame(2, $result->delivery->itemCount());

        // And the persistable payload does contain them, because Sogo's own
        // webhook design puts them here rather than in a webhook. The store
        // encrypts them; the point of this assertion is that the adapter surfaces
        // them as a delivery at all.
        $this->assertSame('1234-5678-9012', $result->delivery->fields['pin'] ?? $result->delivery->items[0]['pin']);
    }

    /* =====================================================================
     | Nitro
     | =================================================================== */

    public function test_nitros_rate_divisor_produces_the_documented_charge(): void
    {
        /*
         * Documented: `rate × quantity ÷ 1000`. The docs' own worked example is a
         * rate of 2720 for 1000 units, charged as ₦2,720.
         */
        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        $this->assertSame(1000, $adapter->rateDivisor());

        // Rate ₦2,720 per 1000 units (272000 kobo), 1000 units → ₦2,720.
        $cost = $adapter->expectedCost(272000, 1000);
        $this->assertSame(272000, $cost->minor());
        $this->assertSame('2720.00', $cost->toDecimalString());

        // 500 units → half, ₦1,360.
        $this->assertSame(136000, $adapter->expectedCost(272000, 500)->minor());

        // 2,000 units → double, ₦5,440.
        $this->assertSame(544000, $adapter->expectedCost(272000, 2000)->minor());

        // The brief's own example: ₦2.72 per 1,000 units, 1,000 ordered.
        $this->assertSame(272, $adapter->expectedCost(272, 1000)->minor());
    }

    public function test_nitros_rate_divisor_does_not_inflate_a_small_quantity(): void
    {
        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        // 100 units at ₦2,720 per 1,000 is ₦272, not ₦272,000.
        $this->assertSame(27200, $adapter->expectedCost(272000, 100)->minor());

        // And the guard rails around it.
        $this->assertSame(272, $adapter->expectedCost(272000, 1)->minor() - 0 + 0);
    }

    public function test_a_zero_or_negative_quantity_is_refused(): void
    {
        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        foreach ([0, -5] as $quantity) {
            try {
                $adapter->expectedCost(272000, $quantity);
                $this->fail("Quantity {$quantity} must be refused.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('at least 1', $e->getMessage());
            }
        }
    }

    public function test_a_nitro_order_is_pending_not_success(): void
    {
        /*
         * `add` returns only an order id. Nitro is asynchronous, so reporting
         * success here would tell the customer their followers had arrived when
         * nothing had started.
         */
        Http::fake(['*' => Http::response(['order' => 4211], 200)]);

        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        $result = $adapter->addOrder(3877, 'https://instagram.com/example', 1000);

        $this->assertSame(ProviderStatus::PENDING, $result->status);
        $this->assertFalse($result->isSuccess());
        $this->assertSame('4211', $result->providerReference);
        $this->assertFalse($result->permitsFailover());
    }

    public function test_a_nitro_status_poll_maps_and_reports_the_charge(): void
    {
        Http::fake(['*' => Http::response([
            'charge' => '2720.00',
            'start_count' => '1200',
            'status' => 'In progress',
            'remains' => '450',
            'currency' => 'NGN',
        ], 200)]);

        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        $result = $adapter->orderStatus([4211]);

        $this->assertSame(ProviderStatus::PROCESSING, $result->status);
        $this->assertSame(272000, $result->providerCostMinor);
        $this->assertSame('NGN', $result->providerCurrency);
    }

    public function test_a_nitro_error_with_http_200_is_still_an_error(): void
    {
        /*
         * The SMM panel convention: errors arrive with HTTP 200 and an `error`
         * field. A naive 2xx check would read this as a vended order.
         */
        Http::fake(['*' => Http::response(['error' => 'Not enough funds'], 200)]);

        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        $result = $adapter->addOrder(3877, 'https://instagram.com/example', 1000);

        $this->assertSame(ProviderStatus::RETRYABLE, $result->status);
        $this->assertFalse($result->isSuccess());

        // Nothing was queued, so the money is still ours to spend elsewhere.
        $this->assertTrue($result->permitsFailover());
    }

    public function test_an_unrecognised_nitro_error_is_unknown(): void
    {
        Http::fake(['*' => Http::response(['error' => 'Some brand new provider problem'], 200)]);

        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        $result = $adapter->addOrder(3877, 'https://instagram.com/example', 1000);

        $this->assertSame(ProviderStatus::UNKNOWN, $result->status);
        $this->assertFalse($result->permitsFailover());
        $this->assertFalse($result->warrantsRefund());
    }

    public function test_every_documented_nitro_error_maps_to_a_retryable_status(): void
    {
        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        foreach ([
            'Invalid API key',
            'Incorrect service ID',
            'Not enough funds',
            'Quantity out of range',
            'Invalid link',
            'Order not found',
        ] as $error) {
            Http::fake(['*' => Http::response(['error' => $error], 200)]);

            $result = $adapter->addOrder(3877, 'https://instagram.com/example', 1000);

            $this->assertSame(
                ProviderStatus::RETRYABLE,
                $result->status,
                "Nitro error [{$error}] should be RETRYABLE."
            );
        }
    }

    public function test_the_nitro_service_catalogue_is_returned_as_a_list(): void
    {
        Http::fake(['*' => Http::response([
            [
                'service' => 3877,
                'name' => 'Instagram Followers · Standard',
                'category' => 'Instagram',
                'rate' => '2720.00',
                'min' => 100,
                'max' => 50000,
                'refill' => true,
                'cancel' => true,
                'type' => 'Default',
                'description' => '',
            ],
            [
                'service' => 4310,
                'name' => 'Discord Members (Offline) · Standard',
                'category' => 'Discord',
                'rate' => '3900.00',
                'min' => 100,
                'max' => 10000,
                'refill' => false,
                'cancel' => false,
                'type' => 'Default',
                'description' => 'Add the bot to your server first.',
            ],
        ], 200)]);

        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        $result = $adapter->services();

        $this->assertSame(ProviderStatus::SUCCESS, $result->status);
        $this->assertCount(2, $result->payload);
        $this->assertSame(3877, $result->payload[0]['service']);
        // The description is present and must be read before the service is sold.
        $this->assertNotSame('', $result->payload[1]['description']);
    }

    public function test_a_nitro_balance_read_is_normalised_to_minor_units(): void
    {
        Http::fake(['*' => Http::response(['balance' => '124500.00', 'currency' => 'NGN'], 200)]);

        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        $balance = $adapter->balance();

        $this->assertTrue($balance['success']);
        $this->assertSame(12450000, $balance['balance_minor']);
        $this->assertSame('NGN', $balance['currency']);
    }

    public function test_a_nitro_bulk_status_poll_is_capped_at_the_documented_limit(): void
    {
        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('At most 100');

        $adapter->orderStatus(range(1, 101));
    }

    public function test_a_nitro_refill_refusal_is_not_a_success(): void
    {
        Http::fake(['*' => Http::response(['refill' => ['error' => 'Order already completed']], 200)]);

        $adapter = new NitroSmmProvider($this->provider('nitro', 'nitro', ['smm']));

        $result = $adapter->refill(4211);

        $this->assertSame(ProviderStatus::RETRYABLE, $result->status);
        $this->assertFalse($result->isSuccess());
    }

    /* =====================================================================
     | Credentials and logs
     | =================================================================== */

    public function test_a_provider_with_no_credentials_is_not_configured(): void
    {
        $this->setEnv('SOGO_SECRET_KEY', null);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));

        $this->assertFalse($adapter->isConfigured());
    }

    public function test_a_credential_is_never_written_to_a_log(): void
    {
        $secret = 'sogo_sk_test_super_secret_value_1234567890';
        $this->setEnv('SOGO_SECRET_KEY', $secret);

        $logged = [];
        \Illuminate\Support\Facades\Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message . ' ' . json_encode($message->context);
        });

        /*
         * A transport failure whose message embeds the request URL — which is how
         * Guzzle reports a DNS failure, and which is where a query-string
         * credential would appear.
         */
        Http::fake([
            '*' => fn () => throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 6: Could not resolve host for https://api.sogo.africa/v1/bills/airtime?key=' . $secret
            ),
        ]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));
        $adapter->purchaseAirtime('mtn', '08012345678', 1000, (string) \Illuminate\Support\Str::uuid());

        $all = implode("\n", $logged);

        $this->assertStringNotContainsString($secret, $all, 'A credential reached the log.');
    }

    public function test_a_delivery_token_is_never_written_to_a_customer_facing_payload(): void
    {
        /*
         * The redactor strips token-shaped keys from anything an adapter logs.
         * Asserted directly here because it is the mechanism that keeps a gift
         * card code out of a log line.
         */
        $redacted = PayloadRedactor::redact([
            'reference' => 'REF-1',
            'pin' => '1234-5678-9012-3456',
            'code' => 'AQ8BNC7X2P1234',
            'pinCode' => '4321',
            'activation_code' => 'LPA:1$smdp.example$ABC',
            'iccid' => '89012345678901234567',
            'api_key' => 'sk_live_secret',
            'amount' => 1000,
        ]);

        foreach (['pin', 'code', 'pinCode', 'activation_code', 'iccid', 'api_key'] as $key) {
            $this->assertSame(PayloadRedactor::REDACTED, $redacted[$key], "[{$key}] must be redacted.");
        }

        // Non-sensitive fields survive, so the log is still useful.
        $this->assertSame('REF-1', $redacted['reference']);
        $this->assertSame(1000, $redacted['amount']);
    }

    public function test_the_redactor_does_not_redact_an_innocuous_field_whose_name_contains_code(): void
    {
        // `countryCode` contains `code`. Substring-matching the short delivery
        // keys would redact an entire response and make the log useless.
        $redacted = PayloadRedactor::redact([
            'countryCode' => 'GH',
            'currencyCode' => 'GHS',
            'errorCode' => 'rate_limit_exceeded',
        ]);

        $this->assertSame('GH', $redacted['countryCode']);
        $this->assertSame('GHS', $redacted['currencyCode']);
        $this->assertSame('rate_limit_exceeded', $redacted['errorCode']);
    }

    public function test_phone_numbers_are_masked_rather_than_stored_in_full(): void
    {
        $redacted = PayloadRedactor::redact([
            'phone' => '08031234567',
            'recipient_phone' => '233240000000',
        ]);

        $this->assertSame('*******4567', $redacted['phone']);
        $this->assertStringNotContainsString('08031234567', json_encode($redacted));
    }

    /* =====================================================================
     | VTpass — international airtime and data (fallback)
     | =================================================================== */

    public function test_vtpass_maps_its_documented_inner_statuses(): void
    {
        $adapter = new VtpassProvider($this->provider('vtpass', 'vtpass', ['international_airtime']));

        $this->assertSame(ProviderStatus::SUCCESS, $adapter->mapStatus('delivered'));
        $this->assertSame(ProviderStatus::PENDING, $adapter->mapStatus('pending'));
        $this->assertSame(ProviderStatus::PENDING, $adapter->mapStatus('initiated'));
        $this->assertSame(ProviderStatus::FAILED, $adapter->mapStatus('failed'));
        $this->assertSame(ProviderStatus::REFUNDED, $adapter->mapStatus('reversed'));
    }

    public function test_a_vtpass_purchase_reads_the_inner_status_and_the_debited_amount(): void
    {
        Http::fake(['*vtpass.com/api/pay*' => Http::response($this->vtpassDelivered(), 200)]);

        $adapter = new VtpassProvider($this->provider('vtpass', 'vtpass', ['international_airtime']));

        $result = $adapter->purchaseInternational(
            'REQ-1',
            '233240000000',
            '2471',
            '5',
            'GH',
            '1',
            '08031234567',
            'buyer@example.test',
        );

        $this->assertTrue($result->isSuccess());
        $this->assertSame('delivered', $result->providerStatus);
        $this->assertSame('REQ-1', $result->providerReference);

        /*
         * The documented arithmetic is amount 2.00, commission 0.08, total_amount
         * 1.92 — `total_amount` is the debit, so it is the provider cost. Deriving
         * the cost from `amount` instead would overstate it by the commission and
         * understate every margin on the product.
         */
        $this->assertSame(192, $result->providerCostMinor);
        $this->assertSame(200, $result->providerAmountMinor);
    }

    public function test_a_vtpass_code_outside_the_published_table_is_unknown(): void
    {
        Http::fake(['*' => Http::response([
            'code' => '777',
            'response_description' => 'SOMETHING NEW',
        ], 200)]);

        $adapter = new VtpassProvider($this->provider('vtpass', 'vtpass', ['international_airtime']));

        $result = $adapter->requery('REQ-2');

        // VTpass's own instruction is to treat anything outside its table as pending
        // and requery. UNKNOWN produces exactly that; FAILED would refund a top-up
        // that may already have been delivered.
        $this->assertSame(ProviderStatus::UNKNOWN, $result->status);
        $this->assertFalse($result->permitsFailover());
        $this->assertFalse($result->warrantsRefund());
    }

    public function test_a_vtpass_low_wallet_balance_is_retryable(): void
    {
        Http::fake(['*' => Http::response([
            'code' => '018',
            'response_description' => 'LOW WALLET BALANCE',
        ], 200)]);

        $adapter = new VtpassProvider($this->provider('vtpass', 'vtpass', ['international_airtime']));

        $result = $adapter->requery('REQ-3');

        // Our float being short is our problem, not anything about this customer's
        // request, so another configured provider is a better answer than refusing
        // the sale.
        $this->assertSame(ProviderStatus::RETRYABLE, $result->status);
        $this->assertTrue($result->permitsFailover());
        $this->assertFalse($result->warrantsRefund());
    }

    public function test_a_vtpass_failed_transaction_warrants_a_refund_and_never_a_failover(): void
    {
        Http::fake(['*' => Http::response([
            'code' => '016',
            'response_description' => 'TRANSACTION FAILED',
        ], 200)]);

        $adapter = new VtpassProvider($this->provider('vtpass', 'vtpass', ['international_airtime']));

        $result = $adapter->requery('REQ-4');

        $this->assertSame(ProviderStatus::FAILED, $result->status);
        $this->assertTrue($result->warrantsRefund());
        $this->assertFalse($result->permitsFailover());
    }

    public function test_a_vtpass_reversal_is_read_as_a_refund(): void
    {
        Http::fake(['*' => Http::response([
            'code' => '040',
            'response_description' => 'TRANSACTION REVERSAL TO WALLET',
            'requestId' => 'REQ-5',
            'amount' => 48.5,
            'content' => [
                'transactions' => [
                    'status' => 'reversed',
                    'amount' => 50,
                    'commission' => 2,
                    'total_amount' => 48.5,
                    'transactionId' => '1583501216545377109916',
                ],
            ],
        ], 200)]);

        $adapter = new VtpassProvider($this->provider('vtpass', 'vtpass', ['international_airtime']));

        $result = $adapter->requery('REQ-5');

        $this->assertSame(ProviderStatus::REFUNDED, $result->status);
        $this->assertFalse($result->permitsFailover());

        // The reversal payload carries what was actually credited back, which is what
        // a refund must be based on — not the original face value.
        $this->assertSame(4850, $result->providerAmountMinor);
    }

    public function test_a_vtpass_processing_code_holds_funds_without_failing_or_refunding(): void
    {
        Http::fake(['*' => Http::response([
            'code' => '099',
            'response_description' => 'TRANSACTION IS PROCESSING',
        ], 200)]);

        $adapter = new VtpassProvider($this->provider('vtpass', 'vtpass', ['international_airtime']));

        $result = $adapter->requery('REQ-6');

        $this->assertSame(ProviderStatus::PENDING, $result->status);
        $this->assertTrue(ProviderStatus::holdsFunds($result->status));
        $this->assertFalse($result->permitsFailover());
        $this->assertFalse($result->warrantsRefund());
    }

    public function test_a_vtpass_purchase_sends_the_documented_field_names(): void
    {
        Http::fake(['*' => Http::response($this->vtpassDelivered(), 200)]);

        $adapter = new VtpassProvider($this->provider('vtpass', 'vtpass', ['international_airtime']));

        $adapter->purchaseInternational(
            'REQ-7',
            '233240000000',
            '2471',
            '5',
            'GH',
            '1',
            '08031234567',
            'buyer@example.test',
        );

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/pay')
                && $request['request_id'] === 'REQ-7'
                && $request['serviceID'] === 'foreign-airtime'
                && $request['billersCode'] === '233240000000'
                && $request['variation_code'] === '2471'
                && $request['operator_id'] === '5'
                && $request['country_code'] === 'GH'
                && $request['product_type_id'] === '1'
                && $request['phone'] === '08031234567'
                && $request['email'] === 'buyer@example.test';
        });

        // The recipient and the customer are different fields. Sending one where the
        // other belongs top-ups the wrong number.
        Http::assertSent(fn ($request) => $request['billersCode'] !== $request['phone']);
    }

    public function test_vtpass_uses_the_public_key_to_read_and_the_secret_key_to_pay(): void
    {
        $this->setEnv('VTPASS_API_KEY', 'vtpass-api');
        $this->setEnv('VTPASS_PUBLIC_KEY', 'vtpass-public');
        $this->setEnv('VTPASS_SECRET_KEY', 'vtpass-secret');

        Http::fake([
            // Documented shape: a catalogue read carries `response_description` and
            // `content`, and no `code` at all.
            '*countries*' => Http::response([
                'response_description' => '000',
                'content' => ['countries' => []],
            ], 200),
            '*pay*' => Http::response($this->vtpassDelivered(), 200),
        ]);

        $adapter = new VtpassProvider($this->provider('vtpass', 'vtpass', ['international_airtime']));

        /*
         * VTpass documents the credential per method: a GET carries the public key, a
         * POST carries the secret key. Getting this backwards is rejected — and the
         * secret key must never be sent on a read.
         */
        $adapter->countries();
        $adapter->purchaseInternational('REQ-8', '233240000000', '2471', '5', 'GH', '1', '08031234567', 'a@b.test');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'countries')
            && $this->headerValue($request, 'api-key') === 'vtpass-api'
            && $this->headerValue($request, 'public-key') === 'vtpass-public'
            && $this->headerValue($request, 'secret-key') === null);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/pay')
            && $this->headerValue($request, 'api-key') === 'vtpass-api'
            && $this->headerValue($request, 'secret-key') === 'vtpass-secret'
            && $this->headerValue($request, 'public-key') === null);
    }

    public function test_a_vtpass_balance_reads_the_plural_contents_key_and_rounds_the_float(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 1,
            'contents' => ['balance' => 1081.8199999998],
        ], 200)]);

        $adapter = new VtpassProvider($this->provider('vtpass', 'vtpass', ['international_airtime']));

        $balance = $adapter->balance();

        /*
         * `contents` is plural here and `code` is not a response code, unlike every
         * other VTpass response. The float has more decimal places than a kobo — a
         * binary artefact, not real precision — so it is rounded rather than
         * rejected.
         */
        $this->assertTrue($balance['success']);
        $this->assertSame(108182, $balance['balance_minor']);
    }

    public function test_vtpass_declares_itself_unconfigured_until_every_key_is_present(): void
    {
        $adapter = new VtpassProvider($this->provider('vtpass', 'vtpass', ['international_airtime']));

        $this->assertTrue($adapter->isConfigured());

        $this->setEnv('VTPASS_SECRET_KEY', null);

        $this->assertFalse($adapter->isConfigured());
    }

    public function test_vtpass_commission_is_reported_as_basis_points(): void
    {
        $adapter = new VtpassProvider($this->provider('vtpass', 'vtpass', ['international_airtime']));

        $this->assertSame(400, $adapter->commissionRateBps([
            'commission_details' => ['rate' => '4.00', 'rate_type' => 'percent'],
        ]));

        // A flat fee is an amount, not a rate. Reporting it as basis points would
        // invent a percentage that nobody agreed to.
        $this->assertNull($adapter->commissionRateBps([
            'commission_details' => ['rate' => '50', 'rate_type' => 'flat'],
        ]));
    }

    /* =====================================================================
     | VTUGate — international airtime and data (primary once verified)
     | =================================================================== */

    public function test_vtugate_is_not_operational_until_its_field_names_are_verified(): void
    {
        $adapter = new VtugateProvider($this->provider('vtugate', 'vtugate', ['international_airtime']));

        /*
         * The endpoint paths and the bearer scheme are published; the request body
         * field names for the international group are not, and this application does
         * not guess them. Until an operator confirms them, the adapter must refuse to
         * be routed to.
         */
        $this->assertFalse($adapter->isOperational());
        $this->assertNotNull($adapter->operationalReason());

        config(['providers.vtugate.fields_verified' => true]);

        $this->assertTrue($adapter->isOperational());
        $this->assertNull($adapter->operationalReason());
    }

    public function test_the_registry_skips_a_configured_but_unverified_provider(): void
    {
        $this->provider('vtugate', 'vtugate', ['international_airtime'], ['priority' => 1]);
        $this->provider('vtpass', 'vtpass', ['international_airtime'], ['priority' => 100]);

        $registry = app(ProviderRegistry::class);

        $slugs = array_map(fn ($a) => $a->provider()->slug, $registry->candidatesFor('international_airtime'));

        // VTUGate is configured but cannot be addressed safely, so the order goes to
        // the fully documented provider instead.
        $this->assertSame(['vtpass'], $slugs);
    }

    public function test_vtugate_becomes_the_primary_route_once_its_fields_are_verified(): void
    {
        $this->provider('vtugate', 'vtugate', ['international_airtime'], ['priority' => 1]);
        $this->provider('vtpass', 'vtpass', ['international_airtime'], ['priority' => 100]);

        config(['providers.vtugate.fields_verified' => true]);

        $registry = app(ProviderRegistry::class);

        $slugs = array_map(fn ($a) => $a->provider()->slug, $registry->candidatesFor('international_airtime'));

        $this->assertSame(['vtugate', 'vtpass'], $slugs);
    }

    public function test_a_vtugate_catalogue_read_is_a_success_not_an_unknown_transaction(): void
    {
        Http::fake(['*' => Http::response([
            'status' => true,
            'message' => 'Countries fetched successfully',
            'data' => [
                ['code' => 'GH', 'name' => 'Ghana', 'currency' => 'GHS', 'prefix' => '233'],
            ],
        ], 200)]);

        $adapter = new VtugateProvider($this->provider('vtugate', 'vtugate', ['international_airtime']));

        $result = $adapter->countries();

        $this->assertSame(ProviderStatus::SUCCESS, $result->status);
        $this->assertCount(1, $result->payload['data']);
    }

    public function test_a_vtugate_rejection_with_an_unfamiliar_message_is_unknown_not_failed(): void
    {
        Http::fake(['*' => Http::response([
            'status' => false,
            'message' => 'Beneficiary wallet could not be resolved by the upstream partner',
        ], 200)]);

        $adapter = new VtugateProvider($this->provider('vtugate', 'vtugate', ['international_airtime']));

        $result = $adapter->purchaseInternational('REF-1', '233240000000', 'GH', 100);

        /*
         * A free-text message is not evidence that the customer was not served, and
         * the message is documentation rather than a constant — so it may reword
         * tomorrow. UNKNOWN, never FAILED.
         */
        $this->assertSame(ProviderStatus::UNKNOWN, $result->status);
        $this->assertFalse($result->warrantsRefund());
    }

    public function test_a_vtugate_rejection_naming_insufficient_balance_is_retryable(): void
    {
        Http::fake(['*' => Http::response([
            'status' => false,
            'message' => 'Insufficient balance.',
        ], 200)]);

        $adapter = new VtugateProvider($this->provider('vtugate', 'vtugate', ['international_airtime']));

        $result = $adapter->purchaseInternational('REF-2', '233240000000', 'GH', 100);

        // Matching is done on the normalised message, so wording, case and
        // punctuation do not decide whether we can safely try elsewhere.
        $this->assertSame(ProviderStatus::RETRYABLE, $result->status);
        $this->assertTrue($result->permitsFailover());
    }

    public function test_a_vtugate_body_uses_the_configured_field_names(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'data' => []], 200)]);

        /*
         * The field names are configuration precisely so that correcting one is an
         * environment change. This proves the indirection is real: renaming `phone`
         * to `recipient` changes what leaves the process.
         */
        config(['providers.vtugate.fields' => array_merge(
            (array) config('providers.vtugate.fields'),
            ['phone' => 'recipient', 'country' => 'country_iso'],
        )]);

        $adapter = new VtugateProvider($this->provider('vtugate', 'vtugate', ['international_airtime']));

        $adapter->purchaseInternational('REF-3', '233240000000', 'GH', 100, 'mtn');

        Http::assertSent(fn ($request) => ($request['recipient'] ?? null) === '233240000000'
            && ($request['country_iso'] ?? null) === 'GH'
            && ! isset($request['phone']));
    }

    public function test_a_vtugate_body_never_falls_back_to_a_logical_field_name(): void
    {
        Http::fake(['*' => Http::response(['status' => true, 'data' => []], 200)]);

        $fields = (array) config('providers.vtugate.fields');
        unset($fields['phone']);

        config(['providers.vtugate.fields' => $fields]);

        $adapter = new VtugateProvider($this->provider('vtugate', 'vtugate', ['international_airtime']));

        $adapter->purchaseInternational('REF-4', '233240000000', 'GH', 100, 'mtn');

        /*
         * The dangerous fallback would be to send the logical name. An unconfigured
         * field is dropped instead, so a partially-mapped request fails visibly at
         * the provider rather than delivering to a guessed address.
         */
        Http::assertSent(fn ($request) => ! isset($request['phone']) && ! isset($request['recipient']));
    }

    public function test_a_vtugate_transaction_keeps_the_boolean_status_when_no_inner_status_exists(): void
    {
        Http::fake(['*' => Http::response([
            'status' => true,
            'message' => 'Top-up successful',
            'data' => [
                'reference' => 'REF-5',
                'local_amount' => 50,
                'ngn_amount' => 7850,
                'currency' => 'GHS',
            ],
        ], 200)]);

        $adapter = new VtugateProvider($this->provider('vtugate', 'vtugate', ['international_airtime']));

        $result = $adapter->purchaseInternational('REF-5', '233240000000', 'GH', 50);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('REF-5', $result->providerReference);
        $this->assertSame('GHS', $result->providerCurrency);
        $this->assertSame(5000, $result->providerAmountMinor);
        $this->assertSame(785000, $result->providerCostMinor);
    }

    /* =====================================================================
     | Credential resolution — provably-not-sent is retryable
     | =================================================================== */

    public function test_a_missing_credential_is_retryable_rather_than_unknown(): void
    {
        foreach (['SOGO_WRITE_KEY', 'SOGO_API_KEY', 'SOGO_SECRET_KEY', 'SOGO_KEY'] as $name) {
            $this->setEnv($name, null);
        }

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));

        $result = $adapter->purchaseAirtime('mtn', '08031234567', 100, 'KEY-1');

        /*
         * Nothing was sent, so nothing can have been charged — a stronger statement
         * than "we do not know". UNKNOWN here would hold a customer's money against a
         * request that was never made.
         */
        $this->assertSame(ProviderStatus::RETRYABLE, $result->status);
        $this->assertSame('not_configured', $result->errorCode);
        $this->assertFalse($result->warrantsRefund());
    }

    public function test_a_sogo_purchase_sends_the_write_scope_credential_and_the_idempotency_key(): void
    {
        $this->setEnv('SOGO_READ_KEY', 'sogo_read_key');
        $this->setEnv('SOGO_WRITE_KEY', 'sogo_write_key');

        Http::fake([
            '*catalog*' => Http::response(['data' => []], 200),
            '*bills/airtime*' => Http::response([
                'message' => 'Airtime purchased',
                'data' => ['reference' => 'SG-9', 'status' => 'completed', 'currency' => 'NGN'],
            ], 201),
        ]);

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));

        $adapter->catalogue();
        $adapter->purchaseAirtime('mtn', '08031234567', 100, 'KEY-2');

        /*
         * A catalogue read uses the read-only key and a purchase uses the write key.
         * This is the whole point of scoping: a leaked read credential cannot move
         * money. The scope argument is positional, so a call site that drifts by one
         * argument silently disables the boundary — hence this assertion.
         */
        Http::assertSent(fn ($request) => str_contains($request->url(), 'catalog')
            && $this->headerValue($request, 'Authorization') === 'Bearer sogo_read_key');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'bills/airtime')
            && $this->headerValue($request, 'Authorization') === 'Bearer sogo_write_key'
            && $this->headerValue($request, 'Idempotency-Key') === 'KEY-2');
    }

    public function test_a_sogo_purchase_without_an_idempotency_key_is_refused_before_sending(): void
    {
        Http::fake();

        $adapter = new SogoProvider($this->provider('sogo', 'sogo', ['airtime']));

        try {
            $adapter->purchaseAirtime('mtn', '08031234567', 100, '   ');

            $this->fail('A purchase without an idempotency key must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('idempotency key', $e->getMessage());
        }

        // The request must not have been attempted: an unkeyed financial call could
        // never be reconciled.
        Http::assertNothingSent();
    }

    /* =====================================================================
     | Registry
     | =================================================================== */

    public function test_the_registry_orders_providers_by_priority_then_preference(): void
    {
        $nitro = $this->provider('nitro', 'nitro', ['smm'], ['priority' => 200]);
        $alternate = $this->provider('nitro-alt', 'nitro', ['smm'], ['priority' => 10]);

        $registry = app(ProviderRegistry::class);

        $slugs = array_map(
            fn ($adapter) => $adapter->provider()->slug,
            $registry->candidatesFor('smm'),
        );

        $this->assertSame(['nitro-alt', 'nitro'], $slugs);
    }

    public function test_is_primary_beats_priority(): void
    {
        $this->provider('nitro', 'nitro', ['smm'], ['priority' => 1]);
        $this->provider('nitro-primary', 'nitro', ['smm'], ['priority' => 500, 'is_primary' => true]);

        $registry = app(ProviderRegistry::class);

        $slugs = array_map(fn ($a) => $a->provider()->slug, $registry->candidatesFor('smm'));

        $this->assertSame('nitro-primary', $slugs[0]);
    }

    public function test_a_provider_without_credentials_is_not_a_candidate(): void
    {
        $this->provider('nitro', 'nitro', ['smm']);
        $this->setEnv('NITRO_KEY', null);

        $registry = app(ProviderRegistry::class);

        // Selecting an unconfigured provider would be a guaranteed failure for
        // the customer, so it is skipped rather than attempted.
        $this->assertSame([], $registry->candidatesFor('smm'));
    }

    public function test_capabilities_no_adapter_implements_are_not_routable(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertContains('gift_cards', $registry->routableCapabilities());
        $this->assertContains('smm', $registry->routableCapabilities());
        $this->assertContains('international_airtime', $registry->routableCapabilities());

        // eSIM has no documented endpoint in any integrated provider, so it must
        // not be routable — declaring it on a provider row cannot make it work.
        $this->assertNotContains('esim', $registry->routableCapabilities());
        $this->assertFalse($registry->driverIsRoutable('esim'));
    }

    public function test_a_provider_cannot_be_selected_for_a_capability_it_does_not_declare(): void
    {
        // The adapter's own capability list is the second lock: a database row
        // that claims `gift_cards` for Nitro must not be honoured.
        $this->provider('nitro', 'nitro', ['gift_cards']);

        $registry = app(ProviderRegistry::class);

        $this->assertSame([], $registry->candidatesFor('gift_cards'));
    }
}
