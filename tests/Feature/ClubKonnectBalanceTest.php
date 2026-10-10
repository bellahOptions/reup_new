<?php

namespace Tests\Feature;

use App\Services\ClubKonnectService;
use App\Services\Providers\ClubKonnectProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ClubKonnect reports its balance as a **display string**, not a number:
 *
 *     {"date":"10th-Oct-2026","id":"…","phoneno":"…","balance":"4,985.28"}
 *
 * Every consumer asked the obvious question — `is_numeric($response['balance'])`
 * — which is `false` for `"4,985.28"`. So a funded, reachable, correctly
 * configured provider was reported as:
 *
 *   * `ping() === false` → `ProviderManager` skipped it, so no airtime or data
 *     sale could ever route to it;
 *   * "Unable to fetch balance" on the admin dashboard;
 *   * `canCover() === false` for the float check.
 *
 * The whole upstream was out of service because of one thousands separator.
 * These tests pin the normalisation and, more importantly, the *diagnosis*: the
 * balance string is asserted to be one `is_numeric` rejects, so if someone
 * "simplifies" the fix away the failure is explained rather than mysterious.
 */
class ClubKonnectBalanceTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://www.nellobytesystems.com/APIWalletBalanceV1.asp';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.clubkonnect.client_id' => 'CK_test_client',
            'services.clubkonnect.api_key' => 'ck_test_key',
        ]);

        Cache::flush();
    }

    /** Exactly what NelloBytes returns, verbatim. */
    private function fakeBalance(string $balance = '4,985.28'): void
    {
        Http::fake([
            self::ENDPOINT . '*' => Http::response(
                '{"date":"10th-Oct-2026","id":"12345","phoneno":"09031412354","balance":"' . $balance . '"}',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);
    }

    /* =====================================================================
     | The premise
     | =================================================================== */

    public function test_the_providers_balance_string_is_not_numeric(): void
    {
        /*
         * The bug, stated as an assertion. If the provider ever starts sending a
         * real JSON number this test fails — which is correct: the normalisation
         * would then be unnecessary and this file should be revisited rather
         * than kept as folklore.
         */
        $this->assertFalse(
            is_numeric('4,985.28'),
            'The premise of the fix has changed: the provider now sends a numeric balance.'
        );
    }

    /* =====================================================================
     | The service normalises it
     | =================================================================== */

    public function test_check_balance_returns_a_number_and_keeps_the_original(): void
    {
        $this->fakeBalance();

        $result = app(ClubKonnectService::class)->checkBalance();

        $this->assertIsArray($result);
        $this->assertSame(4985.28, $result['balance'], 'The balance must be a usable number.');
        $this->assertSame('4,985.28', $result['balance_formatted'], 'The original string must be preserved.');

        // The rest of the payload is untouched.
        $this->assertSame('09031412354', $result['phoneno']);
        $this->assertSame('10th-Oct-2026', $result['date']);
    }

    public function test_a_balance_without_a_separator_still_works(): void
    {
        $this->fakeBalance('850');

        $result = app(ClubKonnectService::class)->checkBalance();

        $this->assertSame(850.0, $result['balance']);
    }

    public function test_a_numeric_balance_is_passed_through(): void
    {
        // A provider that switches to a real number must not be double-handled.
        Http::fake([
            self::ENDPOINT . '*' => Http::response(
                '{"date":"10th-Oct-2026","balance":4985.28}',
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);

        $result = app(ClubKonnectService::class)->checkBalance();

        $this->assertSame(4985.28, $result['balance']);
        $this->assertArrayNotHasKey('balance_formatted', $result);
    }

    public function test_an_unparseable_balance_is_left_alone_rather_than_zeroed(): void
    {
        /*
         * A zero would look like an empty wallet — and, worse, the float check
         * would then refuse every sale for a reason that is not real. Leaving the
         * value as it arrived keeps `is_numeric` failing, so the caller reports
         * the payload instead of inventing a number.
         */
        $this->fakeBalance('unavailable');

        $result = app(ClubKonnectService::class)->checkBalance();

        $this->assertSame('unavailable', $result['balance']);
        $this->assertFalse(is_numeric($result['balance']));
    }

    public function test_an_error_response_is_untouched(): void
    {
        Http::fake([
            self::ENDPOINT . '*' => Http::response(
                '{"status":"INVALID_CREDENTIALS","message":"Invalid API key"}',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $result = app(ClubKonnectService::class)->checkBalance();

        $this->assertSame('INVALID_CREDENTIALS', $result['status']);
        $this->assertArrayNotHasKey('balance', $result);
    }

    /* =====================================================================
     | What the three consumers see
     | =================================================================== */

    public function test_the_health_probe_passes_on_a_formatted_balance(): void
    {
        /*
         * The one that took the provider out of service. `ping()` is what
         * `ProviderManager` believes before it will route a sale.
         */
        $this->fakeBalance();

        $provider = app(ClubKonnectProvider::class);

        $this->assertTrue($provider->ping(), 'A funded provider must not be reported down.');
    }

    public function test_the_balance_report_is_a_success(): void
    {
        $this->fakeBalance();

        $balance = app(ClubKonnectProvider::class)->balance();

        $this->assertTrue($balance['success'], 'The balance must be reported as fetched.');
        $this->assertSame(4985.28, $balance['balance']);
        $this->assertSame('NGN', $balance['currency']);
    }

    public function test_the_float_check_uses_the_real_balance(): void
    {
        /*
         * `canCover()` decides whether a sale may be attempted at all. A
         * provider with ₦4,985 must cover a ₦100 sale and must not be treated as
         * empty.
         */
        $this->fakeBalance();

        $provider = app(ClubKonnectProvider::class);
        $balances = app(\App\Services\ProviderBalanceService::class);

        $this->assertTrue($balances->canCover($provider, 100.0));
        $this->assertTrue($balances->canCover($provider, 4000.0));
        $this->assertFalse($balances->canCover($provider, 6000.0), 'A sale above the float must be refused.');
    }

    /* =====================================================================
     | Routing
     | =================================================================== */

    public function test_clubkonnect_is_routable_when_its_balance_is_formatted(): void
    {
        /*
         * End to end for the reported symptom: the provider must survive the
         * health and float filters and appear in the candidate list. Before the
         * fix it was filtered out and every purchase failed over or was refused.
         */
        $this->fakeBalance();

        $candidates = app(\App\Services\ProviderManager::class)->candidates('airtime');

        $this->assertNotEmpty($candidates, 'No provider would be tried, so every purchase would be refused.');

        $labels = array_map(fn ($c) => strtolower($c->label()), $candidates);

        $this->assertContains(
            'clubkonnect',
            $labels,
            'ClubKonnect must be a routing candidate when it is answering with a balance.'
        );
    }
}
