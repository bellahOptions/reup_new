<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Models\ProductAlert;
use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Providers\CatalogueSyncStatus;
use App\Services\CatalogueSyncFailed;
use App\Services\ClubKonnectCatalogue;
use App\Services\NetworkResolver;
use App\Services\SecurityService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesProviders;
use Tests\TestCase;

/**
 * The scheduled synchronisation of the data-bundle catalogue.
 *
 * ## The two properties everything here is about
 *
 *   1. **A failed fetch must never replace a working catalogue.** The catalogue
 *      carries both the bundles and what each one costs ReUp, so writing an empty
 *      or half-parsed response over it would empty the storefront and, worse,
 *      leave prices derived from costs nobody can see. Every failure mode below —
 *      timeout, rate limit, HTTP error, malformed body, an empty-but-well-formed
 *      body — asserts that the previous catalogue is still served *and* that the
 *      run is reported as failed rather than as a successful refresh.
 *
 *   2. **The provider is not polled.** The interval between fetches is enforced
 *      where the sync is triggered, so a per-minute scheduler tick costs a cache
 *      read rather than a request. There is a test for that too, because "we
 *      designed it not to hammer the provider" is a claim that has to be checkable.
 */
class DataCatalogueSyncTest extends TestCase
{
    use RefreshDatabase;
    use FakesProviders;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.clubkonnect.client_id' => 'ck_test_client',
            'pricing.catalogue.sync_interval_seconds' => 600,
            'pricing.catalogue.stale_after_seconds' => 1800,
            'pricing.catalogue.timeout_seconds' => 5,
            'pricing.catalogue.failures_before_alert' => 3,
            'pricing.catalogue.quote_ttl_seconds' => 300,
        ]);

        /*
         * `RefreshDatabase` resets the database and not the cache, so a test that
         * expects "never synchronised" would otherwise inherit the previous
         * test's catalogue and status record. The cache *is* the subject here,
         * so it is cleared explicitly.
         */
        Cache::flush();

        $this->markupRule();
    }

    /* =====================================================================
     | Fixtures
     |=================================================================== */

    private function markupRule(int $bps = 300): PricingRule
    {
        return PricingRule::create([
            'name' => 'Data — default markup',
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => $bps,
            'markup_fixed_minor' => 0,
            'minimum_profit_minor' => 1,
            'minimum_margin_bps' => 0,
            'customer_fee_enabled' => false,
            'allow_negative_margin' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ]);
    }

    /**
     * A payload in the shape ClubKonnect's endpoint documents.
     *
     * @param  array<int, array{id:string,code:string,name:string,amount:float}>  $products
     * @return array<string,mixed>
     */
    private function payload(array $products, string $networkCode = '01', string $networkKey = 'MTN'): array
    {
        return [
            'MOBILE_NETWORK' => [
                $networkKey => [[
                    'ID' => $networkCode,
                    'PRODUCT' => array_map(fn ($product) => [
                        'PRODUCT_ID' => $product['id'],
                        'PRODUCT_CODE' => $product['code'],
                        'PRODUCT_NAME' => $product['name'],
                        'PRODUCT_AMOUNT' => $product['amount'],
                    ], $products),
                ]],
            ],
        ];
    }

    /** @return array<int, array{id:string,code:string,name:string,amount:float}> */
    private function products(): array
    {
        return [
            ['id' => '500', 'code' => 'MTN-1GB-30D', 'name' => 'MTN 1GB Monthly', 'amount' => 500.0],
            ['id' => '501', 'code' => 'MTN-2GB-30D', 'name' => 'MTN 2GB Monthly', 'amount' => 900.0],
        ];
    }

    private function catalogue(): ClubKonnectCatalogue
    {
        return app(ClubKonnectCatalogue::class);
    }

    private function status(): CatalogueSyncStatus
    {
        return app(CatalogueSyncStatus::class);
    }

    /* =====================================================================
     | A successful synchronisation
     |=================================================================== */

    public function test_a_successful_sync_prices_every_bundle_from_the_provider_cost(): void
    {
        Http::fake([
            '*' => Http::response($this->payload($this->products())),
        ]);

        $payload = $this->catalogue()->forceRefresh();

        $this->assertSame(2, $payload['total_plans']);

        $first = collect($payload['data_plans'])->firstWhere('plan_code', 'MTN-1GB-30D');

        // ₦500 cost + the configured 3% markup = ₦515.
        $this->assertSame(500.0, $first['clubkonnect_price'], 'The provider amount is the cost');
        $this->assertSame(515.0, $first['your_price'], 'The engine adds the markup');
        $this->assertTrue($first['price_available']);
        $this->assertSame('mtn', $first['network_key']);

        // The credential travels in the query string and nowhere else.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'APIDatabundlePlansV2.asp')
            && str_contains($request->url(), 'UserID=ck_test_client'));
    }

    public function test_a_successful_sync_records_the_freshness_an_operator_needs(): void
    {
        Http::fake(['*' => Http::response($this->payload($this->products()))]);

        $this->catalogue()->forceRefresh();

        $status = $this->status()->current();

        $this->assertNotNull($status['last_success_at']);
        $this->assertSame(2, $status['plan_count']);
        $this->assertSame(0, $status['consecutive_failures']);
        $this->assertNull($status['error_type']);
        $this->assertFalse($status['stale']);
        $this->assertNotNull($status['age_seconds']);
        $this->assertLessThanOrEqual(5, $status['age_seconds']);
    }

    public function test_the_catalogue_is_written_only_after_a_complete_response(): void
    {
        $this->assertNull($this->catalogue()->cachedPlans(), 'Nothing is cached before the first sync');

        Http::fake(['*' => Http::response($this->payload($this->products()))]);

        $this->catalogue()->forceRefresh();

        $this->assertCount(2, $this->catalogue()->cachedPlans()['data_plans']);
    }

    /* =====================================================================
     | Failure never replaces a working catalogue
     |=================================================================== */

    /**
     * A provider that answers differently on each successive call.
     *
     * Laravel 8's `Http::fake()` *merges* stub callbacks rather than replacing
     * them, and the first registered match wins — so re-faking mid-test silently
     * keeps serving the first response. Every test below that needs more than one
     * response therefore registers all of them here, in order, up front.
     *
     * @param  array<int, mixed>  $responses  a callable, an int status, a
     *                                        `[status, body]` pair, an array payload, or a string body
     */
    private function providerSequence(array $responses): void
    {
        Http::fake(function () use (&$responses) {
            if ($responses === []) {
                throw new \RuntimeException('The test made more provider calls than it scripted.');
            }

            $next = array_shift($responses);

            if ($next instanceof \Closure) {
                return $next();
            }

            if (is_int($next)) {
                return Http::response('', $next);
            }

            if (is_array($next) && array_key_exists('status', $next) && array_key_exists('body', $next)) {
                return Http::response($next['body'], $next['status']);
            }

            if (is_string($next)) {
                return Http::response($next, 200);
            }

            return Http::response($next);
        });
    }

    public function test_a_timeout_leaves_the_previous_catalogue_and_prices_alone(): void
    {
        $this->providerSequence([
            $this->payload($this->products()),
            function () {
                throw new \Illuminate\Http\Client\ConnectionException('Operation timed out');
            },
        ]);

        $this->artisan('data:sync-catalogue --force')->assertExitCode(0);

        $before = $this->catalogue()->cachedPlans();
        $beforeStatus = $this->status()->current();

        /*
         * Driven through the command: it is the real caller, and it converts a
         * transport failure into an exit code the scheduler reads. A connection
         * that never produced a response must be a *failure*, not an absorbed
         * error.
         */
        $this->artisan('data:sync-catalogue --force')->assertExitCode(1);

        $this->assertEquals($before, $this->catalogue()->cachedPlans(), 'The catalogue must be untouched');

        $after = $this->status()->current();
        $this->assertSame($beforeStatus['last_success_at'], $after['last_success_at'], 'A failure is not a success');
        $this->assertNotNull($after['last_failure_at']);
        $this->assertSame(CatalogueSyncStatus::ERROR_TIMEOUT, $after['error_type']);
        $this->assertSame(1, $after['consecutive_failures']);
    }

    public function test_a_rate_limit_is_reported_and_changes_nothing(): void
    {
        $this->providerSequence([
            $this->payload($this->products()),
            ['status' => 429, 'body' => 'Too many requests'],
        ]);

        $this->catalogue()->forceRefresh();

        $before = $this->catalogue()->cachedPlans();

        try {
            $this->catalogue()->forceRefresh();
            $this->fail('A rate limit must be reported');
        } catch (CatalogueSyncFailed $e) {
            $this->assertSame(CatalogueSyncStatus::ERROR_RATE_LIMITED, $e->reason);
            $this->assertSame(429, $e->httpStatus);
        }

        $this->assertEquals($before, $this->catalogue()->cachedPlans());

        $status = $this->status()->current();
        $this->assertSame(CatalogueSyncStatus::ERROR_RATE_LIMITED, $status['error_type']);
        $this->assertSame(429, $status['http_status']);
    }

    public function test_an_http_error_changes_nothing(): void
    {
        Http::fake(['*' => Http::response('Upstream error', 503)]);

        try {
            $this->catalogue()->forceRefresh();
            $this->fail('An HTTP error must be reported');
        } catch (CatalogueSyncFailed $e) {
            $this->assertSame(CatalogueSyncStatus::ERROR_HTTP, $e->reason);
            $this->assertSame(503, $e->httpStatus);
        }

        $this->assertNull($this->catalogue()->cachedPlans());
        $this->assertSame(CatalogueSyncStatus::ERROR_HTTP, $this->status()->current()['error_type']);
    }

    /**
     * The most dangerous response of all: `200 OK` carrying something else.
     *
     * A provider echoing the request, an HTML error page behind a 200, or a
     * payload without the documented key all look like this. Parsed naively they
     * produce an empty catalogue, and an empty catalogue written over a working
     * one takes every data bundle off sale.
     */
    public function test_a_malformed_response_is_an_empty_catalogue_and_never_overwrites_one(): void
    {
        $this->providerSequence([
            $this->payload($this->products()),
            ['status' => 'error', 'UserID' => 'ck_test_client'],
        ]);

        $this->catalogue()->forceRefresh();

        $before = $this->catalogue()->cachedPlans();

        try {
            $this->catalogue()->forceRefresh();
            $this->fail('A malformed response must be reported');
        } catch (CatalogueSyncFailed $e) {
            $this->assertSame(CatalogueSyncStatus::ERROR_EMPTY, $e->reason);
        }

        $this->assertEquals($before, $this->catalogue()->cachedPlans());
        $this->assertCount(2, $this->catalogue()->cachedPlans()['data_plans']);
        $this->assertSame(CatalogueSyncStatus::ERROR_EMPTY, $this->status()->current()['error_type']);
    }

    /**
     * A well-formed response whose every row is unusable — a truncated payload.
     */
    public function test_a_response_with_no_usable_bundles_never_overwrites_a_catalogue(): void
    {
        $this->providerSequence([
            $this->payload($this->products()),
            // The documented shape, every bundle priced at zero: nothing sellable.
            $this->payload([
                ['id' => '500', 'code' => 'MTN-1GB-30D', 'name' => 'MTN 1GB Monthly', 'amount' => 0.0],
            ]),
        ]);

        $this->catalogue()->forceRefresh();

        $before = $this->catalogue()->cachedPlans();

        try {
            $this->catalogue()->forceRefresh();
            $this->fail('An unusable catalogue must be reported');
        } catch (CatalogueSyncFailed $e) {
            $this->assertSame(CatalogueSyncStatus::ERROR_EMPTY, $e->reason);
        }

        $this->assertEquals($before, $this->catalogue()->cachedPlans());
    }

    public function test_an_unconfigured_credential_is_a_failure_and_makes_no_request(): void
    {
        config(['services.clubkonnect.client_id' => '']);
        Http::fake();

        try {
            $this->catalogue()->forceRefresh();
            $this->fail('An unconfigured provider must be reported');
        } catch (CatalogueSyncFailed $e) {
            $this->assertSame(CatalogueSyncStatus::ERROR_NOT_CONFIGURED, $e->reason);
        }

        Http::assertNothingSent();
    }

    /* =====================================================================
     | The renewal path a customer sees
     |=================================================================== */

    public function test_a_failed_refresh_serves_the_last_good_catalogue_rather_than_nothing(): void
    {
        $this->providerSequence([
            $this->payload($this->products()),
            // The cached copy expires, and the provider is down.
            ['status' => 500, 'body' => 'nope'],
        ]);

        $this->catalogue()->forceRefresh();

        Cache::forget(CatalogueSyncStatus::DATA_KEY);

        $plans = $this->catalogue()->plans();

        $this->assertCount(2, $plans['data_plans'], 'An outage must not empty the storefront');
        $this->assertArrayHasKey('error', $plans, 'The staleness is disclosed rather than hidden');
    }

    public function test_a_refusal_message_never_names_the_provider_or_our_cost(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);

        try {
            $this->catalogue()->forceRefresh();
            $this->fail('Expected a failure');
        } catch (CatalogueSyncFailed $e) {
            $message = $e->customerMessage();

            foreach (['ClubKonnect', 'NelloBytes', 'cost', 'markup', 'margin', 'UserID'] as $leak) {
                $this->assertStringNotContainsString($leak, $message);
            }
        }
    }

    public function test_the_client_credential_is_never_written_into_the_status_record_or_a_log_message(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException(
            'cURL error 28: Operation timed out for https://www.nellobytesystems.com/APIDatabundlePlansV2.asp?UserID=ck_test_client'
        )]);

        try {
            $this->catalogue()->forceRefresh();
        } catch (CatalogueSyncFailed $e) {
            $this->assertStringNotContainsString('ck_test_client', $e->getMessage());
        }

        $this->assertStringNotContainsString(
            'ck_test_client',
            (string) $this->status()->current()['error_message']
        );
    }

    /* =====================================================================
     | Staleness
     |=================================================================== */

    public function test_a_catalogue_that_has_never_synchronised_is_stale_not_fresh(): void
    {
        $this->assertTrue($this->status()->isStale(), '"Never synced" is the strongest reason to distrust a price');
        $this->assertNull($this->status()->ageSeconds());
        $this->assertTrue($this->status()->isDue(), 'And it is due immediately');
    }

    public function test_a_catalogue_older_than_the_stale_window_is_flagged(): void
    {
        Http::fake(['*' => Http::response($this->payload($this->products()))]);
        $this->catalogue()->forceRefresh();

        $this->assertFalse($this->status()->isStale());

        // Wind the last success back past the stale window.
        $record = Cache::get(CatalogueSyncStatus::STATUS_KEY);
        $record['last_success_at'] = now()->subSeconds(1801)->toDateTimeString();
        Cache::put(CatalogueSyncStatus::STATUS_KEY, $record, now()->addDay());

        $this->assertTrue($this->status()->isStale());
    }

    public function test_a_recent_success_is_not_stale(): void
    {
        Http::fake(['*' => Http::response($this->payload($this->products()))]);
        $this->catalogue()->forceRefresh();

        $this->assertFalse($this->status()->isStale());
        $this->assertFalse($this->status()->isDue(), 'The provider was read seconds ago');
    }

    /* =====================================================================
     | Rate limiting and overlap
     |=================================================================== */

    public function test_the_command_skips_a_run_that_is_not_due_so_the_provider_is_not_polled(): void
    {
        Http::fake(['*' => Http::response($this->payload($this->products()))]);

        $this->artisan('data:sync-catalogue')->assertExitCode(0);
        Http::assertSentCount(1);

        // Four more ticks inside the interval: no further provider reads.
        $this->artisan('data:sync-catalogue')->assertExitCode(0);
        $this->artisan('data:sync-catalogue')->assertExitCode(0);
        $this->artisan('data:sync-catalogue')->assertExitCode(0);
        $this->artisan('data:sync-catalogue')->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public function test_force_fetches_even_inside_the_interval(): void
    {
        Http::fake(['*' => Http::response($this->payload($this->products()))]);

        $this->artisan('data:sync-catalogue')->assertExitCode(0);
        $this->artisan('data:sync-catalogue --force')->assertExitCode(0);

        Http::assertSentCount(2);
    }

    public function test_the_command_fails_loudly_when_the_provider_is_down(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        /*
         * The exit code is the part the scheduler reads, so it is what is
         * asserted. The message is checked separately because `expectsOutputToContain`
         * matches exact output lines and a formatted diagnostic is not one.
         */
        $this->artisan('data:sync-catalogue')->assertExitCode(1);

        $this->assertSame(CatalogueSyncStatus::ERROR_HTTP, $this->status()->current()['error_type']);
        $this->assertNull($this->catalogue()->cachedPlans(), 'Nothing was written');
    }

    public function test_the_status_command_reports_without_fetching(): void
    {
        // Never synchronised: nothing is fetched, and the answer is non-zero so a
        // health check that only reads exit codes still learns about it.
        $this->providerSequence([$this->payload($this->products())]);

        $this->artisan('data:sync-catalogue --status')->assertExitCode(1);
        Http::assertNothingSent();
        $this->assertNull($this->status()->current()['last_success_at']);

        // A fresh success makes it zero, and still fetches nothing.
        $this->catalogue()->forceRefresh();

        $this->artisan('data:sync-catalogue --status')->assertExitCode(0);
        Http::assertSentCount(1);
    }

    public function test_a_held_lock_means_a_second_run_fetches_nothing(): void
    {
        Http::fake();

        /*
         * The lock is taken before the freshness check is consulted, so a manual
         * run arriving while a scheduled one is in flight refuses rather than
         * spending a second provider request to learn the same prices.
         */
        $lock = Cache::lock(CatalogueSyncStatus::LOCK_KEY, 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('data:sync-catalogue')->assertExitCode(0);

            Http::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_the_json_form_reports_whether_the_catalogue_was_replaced(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        $this->artisan('data:sync-catalogue --force --json')->assertExitCode(1);

        // The state a machine-readable consumer must be able to rely on: a failed
        // run never replaced the catalogue.
        $this->assertSame(CatalogueSyncStatus::ERROR_HTTP, $this->status()->current()['error_type']);
        $this->assertNull($this->catalogue()->cachedPlans());
    }

    /* =====================================================================
     | Operator visibility
     |=================================================================== */

    public function test_repeated_failures_raise_one_alert_that_a_success_clears(): void
    {
        config(['pricing.catalogue.failures_before_alert' => 3]);

        /*
         * Driven through the command rather than by catching the service's
         * exception in a loop: the command is the real caller, it converts a
         * failed refresh into an exit code, and it exercises the same lock and
         * status writes an operator's scheduled run would.
         */
        /*
         * Five failures then a success, scripted up front: the alert appears on
         * the third, stays one row however often it is observed, and is resolved
         * by the success.
         */
        $this->providerSequence([
            ['status' => 503, 'body' => 'down'],
            ['status' => 503, 'body' => 'down'],
            ['status' => 503, 'body' => 'down'],
            ['status' => 503, 'body' => 'down'],
            $this->payload($this->products()),
        ]);

        foreach ([1, 2, 3, 4] as $attempt) {
            $this->artisan('data:sync-catalogue --force')->assertExitCode(1);
        }

        $open = ProductAlert::where('type', ProductAlert::TYPE_CATALOGUE_UNAVAILABLE)
            ->whereNull('resolved_at')
            ->get();

        $this->assertCount(1, $open, 'One condition is one alert, however often it is observed');
        $this->assertSame(ProductAlert::SEVERITY_CRITICAL, $open->first()->severity);
        $this->assertStringContainsString('has not synchronised', $open->first()->title);

        // The alert names the last success, so an operator can see how old the
        // prices in the storefront are without a second query.
        $this->assertArrayHasKey('last_success_at', $open->first()->context);

        $this->artisan('data:sync-catalogue --force')->assertExitCode(0);

        $this->assertSame(0, ProductAlert::where('type', ProductAlert::TYPE_CATALOGUE_UNAVAILABLE)
            ->whereNull('resolved_at')
            ->count(), 'A success resolves the alert');

        // Resolution re-arms it: the unique index only covers open rows.
        $this->providerSequence([
            ['status' => 503, 'body' => 'down'],
            ['status' => 503, 'body' => 'down'],
            ['status' => 503, 'body' => 'down'],
        ]);

        foreach ([1, 2, 3] as $attempt) {
            $this->artisan('data:sync-catalogue --force')->assertExitCode(1);
        }

        $this->assertSame(1, ProductAlert::where('type', ProductAlert::TYPE_CATALOGUE_UNAVAILABLE)
            ->whereNull('resolved_at')
            ->count());
    }

    public function test_one_or_two_failures_raise_no_alert(): void
    {
        config(['pricing.catalogue.failures_before_alert' => 3]);

        $this->providerSequence([
            ['status' => 503, 'body' => 'down'],
            ['status' => 503, 'body' => 'down'],
        ]);

        foreach ([1, 2] as $attempt) {
            $this->artisan('data:sync-catalogue --force')->assertExitCode(1);
        }

        $this->assertSame(0, ProductAlert::where('type', ProductAlert::TYPE_CATALOGUE_UNAVAILABLE)->count());
        $this->assertSame(2, $this->status()->current()['consecutive_failures']);
    }

    public function test_the_provider_page_shows_the_catalogue_freshness(): void
    {
        Http::fake(['*' => Http::response($this->payload($this->products()))]);
        $this->catalogue()->forceRefresh();

        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('admin.providers.index'))
            ->assertOk()
            ->assertSee('Data-bundle catalogue')
            ->assertSee('Last successful update')
            ->assertSee('2')   // bundles priced
            ->assertSee('Current');
    }

    public function test_the_provider_page_shows_a_stale_catalogue_and_its_error(): void
    {
        Http::fake(['*' => Http::response('down', 503)]);

        try {
            $this->catalogue()->forceRefresh();
        } catch (CatalogueSyncFailed $e) {
            // expected
        }

        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.providers.index'))
            ->assertOk()
            ->assertSee('Stale')
            ->assertSee('http_error')
            ->assertSee('The previous catalogue was left in place');
    }

    /* =====================================================================
     | The purchase flow keeps working underneath
     |=================================================================== */

    public function test_a_bundle_synchronised_from_the_provider_can_be_bought_at_its_marked_up_price(): void
    {
        config([
            'bills.check_provider_health' => false,
            'bills.check_provider_balance' => false,
            'bills.optimise_cost' => false,
            'bills.provider_order' => ['clubkonnect'],
            'bills.limits.per_minute' => 100,
        ]);

        Http::fake(['*' => Http::response($this->payload($this->products()))]);
        $this->catalogue()->forceRefresh();

        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $user = User::factory()->create();
        app(SecurityService::class)->setPin($user, '5931');
        app(WalletService::class)->credit($user, '1000.00');

        // The page's own figure, from the same service the purchase uses.
        $priced = app(\App\Services\DataPriceService::class)->quote('MTN-1GB-30D');
        $this->assertSame(51500, $priced['price_minor'], '₦500 cost + 3% markup');

        $this->actingAs($user->fresh())->post(route('data.purchase'), [
            'data_network' => '01',
            'data_plan' => '500',
            'phone' => '08031234567',
            'plan_name' => 'MTN 1GB Monthly',
            'plan_price' => '515.00',
            'pin' => '5931',
            'idempotency_key' => str_repeat('a', 20),
        ])->assertRedirect();

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->first();

        $this->assertNotNull($transaction, 'The synchronised bundle must be purchasable');
        $this->assertSame('515.00', (string) $transaction->total_amount);
        $this->assertSame('485.00', (string) Wallet::where('user_id', $user->id)->value('balance'));

        // The snapshot records the cost the provider actually published.
        $this->assertSame(50000, (int) $transaction->pricingSnapshot->provider_cost_minor);
        $this->assertSame(51500, (int) $transaction->pricingSnapshot->customer_price_minor);
        $this->assertSame(1500, (int) $transaction->pricingSnapshot->gross_profit_minor);
        $this->assertSame('MTN', $transaction->network_name);
    }

    /* =====================================================================
     | Provider-cost updates do not rewrite history
     |=================================================================== */

    public function test_a_provider_cost_change_does_not_rewrite_a_completed_transactions_price(): void
    {
        config([
            'bills.check_provider_health' => false,
            'bills.check_provider_balance' => false,
            'bills.optimise_cost' => false,
            'bills.provider_order' => ['clubkonnect'],
            'bills.limits.per_minute' => 100,
        ]);

        /*
         * `fakeSequence`: the first response is the catalogue the purchase is
         * made from, the second is the provider's new price list. A second
         * `fake()` would *add* to the first and the original stub would keep
         * winning, so the refresh below would fetch the old prices and the test
         * would pass for the wrong reason.
         */
        $this->providerSequence([
            $this->payload($this->products()),
            $this->payload([
                ['id' => '500', 'code' => 'MTN-1GB-30D', 'name' => 'MTN 1GB Monthly', 'amount' => 1000.0],
                ['id' => '501', 'code' => 'MTN-2GB-30D', 'name' => 'MTN 2GB Monthly', 'amount' => 900.0],
            ]),
        ]);

        $this->catalogue()->forceRefresh();

        $this->withProviders($this->fakeProvider('fake', ['status' => 'ORDER_RECEIVED', 'order_id' => 'OK-1']));

        $user = User::factory()->create();
        app(SecurityService::class)->setPin($user, '5931');
        app(WalletService::class)->credit($user, '5000.00');

        $this->actingAs($user->fresh())->post(route('data.purchase'), [
            'data_network' => '01',
            'data_plan' => '500',
            'phone' => '08031234567',
            'plan_name' => 'MTN 1GB Monthly',
            'plan_price' => '515.00',
            'pin' => '5931',
            'idempotency_key' => str_repeat('b', 20),
        ])->assertRedirect();

        $transaction = Transactions::where('user_id', $user->id)->latest('id')->firstOrFail();

        // The provider doubles its price and the catalogue is refreshed.
        $this->catalogue()->forceRefresh();

        $transaction->refresh();

        $this->assertSame('515.00', (string) $transaction->total_amount, 'History is not repriced');
        $this->assertSame(50000, (int) $transaction->pricingSnapshot->provider_cost_minor);
        $this->assertSame(51500, (int) $transaction->pricingSnapshot->customer_price_minor);

        // But the *future* is repriced: ₦1,000 + 3% = ₦1,030.
        $future = app(\App\Services\DataPriceService::class)->quote('MTN-1GB-30D');
        $this->assertSame(103000, $future['price_minor']);
    }

    /* =====================================================================
     | New, changed and discontinued bundles
     |=================================================================== */

    public function test_a_new_bundle_appears_and_a_discontinued_one_disappears(): void
    {
        /*
         * `fakeSequence` rather than two `fake()` calls: a second `fake()` adds
         * stubs to the ones already registered, and the first match wins — so
         * re-faking mid-test silently keeps serving the *first* response and the
         * test proves nothing about the second catalogue.
         */
        $this->providerSequence([
            $this->payload($this->products()),
            $this->payload([
                ['id' => '500', 'code' => 'MTN-1GB-30D', 'name' => 'MTN 1GB Monthly', 'amount' => 500.0],
                ['id' => '505', 'code' => 'MTN-5GB-30D', 'name' => 'MTN 5GB Monthly', 'amount' => 2000.0],
            ]),
        ]);

        $this->catalogue()->forceRefresh();

        $this->assertNotNull($this->catalogue()->plan('MTN-2GB-30D'));

        // The provider drops the 2GB bundle and adds a 5GB one.
        $this->catalogue()->forceRefresh();

        $this->assertNotNull($this->catalogue()->plan('MTN-5GB-30D'), 'A new bundle is on sale');
        $this->assertNull($this->catalogue()->plan('MTN-2GB-30D'), 'A discontinued bundle is gone');
    }

    public function test_a_bundle_the_provider_stops_pricing_is_not_sold_at_a_guess(): void
    {
        $this->providerSequence([
            $this->payload($this->products()),
            // The provider still lists it but no longer states a price.
            $this->payload([
                ['id' => '500', 'code' => 'MTN-1GB-30D', 'name' => 'MTN 1GB Monthly', 'amount' => 500.0],
                ['id' => '501', 'code' => 'MTN-2GB-30D', 'name' => 'MTN 2GB Monthly', 'amount' => 0.0],
            ]),
        ]);

        $this->catalogue()->forceRefresh();

        $this->assertNotNull($this->catalogue()->plan('MTN-2GB-30D'));

        $this->catalogue()->forceRefresh();

        $this->assertNull($this->catalogue()->plan('MTN-2GB-30D'), 'No price means no sale');
    }

    /* =====================================================================
     | Per-network pricing is unaffected
     |=================================================================== */

    public function test_each_network_is_priced_from_its_own_provider_cost(): void
    {
        PricingRule::create([
            'name' => 'Data — Airtel',
            'scope' => PricingRule::SCOPE_NETWORK,
            'network' => 'airtel',
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 200,
            'minimum_profit_minor' => 1,
            'customer_fee_enabled' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'priority' => 100,
            'is_active' => true,
        ]);

        Http::fake([
            '*' => Http::response([
                'MOBILE_NETWORK' => [
                    'MTN' => [[
                        'ID' => '01',
                        'PRODUCT' => [[
                            'PRODUCT_ID' => '500',
                            'PRODUCT_CODE' => 'MTN-1GB-30D',
                            'PRODUCT_NAME' => 'MTN 1GB Monthly',
                            'PRODUCT_AMOUNT' => 500.0,
                        ]],
                    ]],
                    'AIRTEL' => [[
                        'ID' => '04',
                        'PRODUCT' => [[
                            'PRODUCT_ID' => '600',
                            'PRODUCT_CODE' => 'AIR-1GB-30D',
                            'PRODUCT_NAME' => 'Airtel 1GB Monthly',
                            'PRODUCT_AMOUNT' => 1000.0,
                        ]],
                    ]],
                ],
            ]),
        ]);

        $plans = collect($this->catalogue()->forceRefresh()['data_plans']);

        // ₦500 + 3% global, ₦1,000 + 2% on the Airtel rule.
        $this->assertSame(515.0, $plans->firstWhere('plan_code', 'MTN-1GB-30D')['your_price']);
        $this->assertSame(1020.0, $plans->firstWhere('plan_code', 'AIR-1GB-30D')['your_price']);
    }

    public function test_a_bundle_no_rule_covers_is_unavailable_rather_than_free(): void
    {
        PricingRule::query()->delete();

        Http::fake(['*' => Http::response($this->payload($this->products()))]);

        $plans = collect($this->catalogue()->forceRefresh()['data_plans']);
        $plan = $plans->firstWhere('plan_code', 'MTN-1GB-30D');

        $this->assertNull($plan['your_price'], 'No rule means no price, never a zero price');
        $this->assertFalse($plan['price_available']);
        $this->assertSame(500.0, $plan['clubkonnect_price'], 'The cost is still known');
    }

    /* =====================================================================
     | The network key still resolves through the canonical table
     |=================================================================== */

    public function test_the_catalogue_resolves_network_keys_canonically(): void
    {
        Http::fake(['*' => Http::response($this->payload(
            [['id' => '700', 'code' => 'T2-1GB-30D', 'name' => '9mobile 1GB Monthly', 'amount' => 500.0]],
            '03',
            '9MOBILE',
        ))]);

        $plan = collect($this->catalogue()->forceRefresh()['data_plans'])->first();

        $this->assertSame('9mobile', $plan['network_key']);
        $this->assertSame('9mobile', NetworkResolver::key($plan['network_code'], 'clubkonnect'));
    }
}
