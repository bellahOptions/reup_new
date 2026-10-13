<?php

namespace Tests\Feature;

use App\Models\PricingRule;
use App\Pricing\PricingEngine;
use App\Services\ProviderCostResolver;
use App\Services\NetworkBrand;
use App\Smm\SmmPricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The eight deliverables, each asserted against the running application.
 *
 * ## Why this file exists separately from the feature tests
 *
 * The feature tests prove the *behaviour* of each module. This one checks the
 * **policy statements** in the brief that are easy to satisfy in one place and
 * violate in another  "airtime stays face-value", "no client price is ever
 * trusted", "every network has the right asset". A rule asserted once at the
 * place it is implemented tends to survive; a rule that lives only in a report
 * does not.
 */
class DeliverablesPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /* =====================================================================
     | 2. Pricing policy: markup, and markup is not margin
     |=================================================================== */

    /**
     * A real pricing rule row.
     *
     * `PricingEngine::resolveRule()` selects the rule it applies from the
     * **database**, by scope and priority  a rule passed in a context array is
     * not consulted at all, and an engine test using one silently prices at zero.
     * So the policy tests below create the rule the way the console does, and then
     * assert on what the engine did with it.
     */
    private function rule(int $markupBps, string $type = PricingRule::MARKUP_PERCENTAGE): PricingRule
    {
        return PricingRule::create([
            'name' => 'Policy test rule ' . $markupBps,
            'scope' => PricingRule::SCOPE_GLOBAL,
            'markup_type' => $type,
            'markup_percentage_bps' => $markupBps,
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
    }

    /**
     * The brief's worked example, asserted as arithmetic.
     */
    public function test_the_documented_example_prices_correctly(): void
    {
        $this->rule(300);

        // Provider cost NGN 1,000, markup 3%, selling price NGN 1,030, gross profit NGN 30.
        $quote = app(PricingEngine::class)->quote(
            providerCost: 100_000,
            quantity: 1,
            context: ['capability' => ProviderCostResolver::CAPABILITY_DATA, 'product_key' => 'example'],
        );

        $this->assertSame(100_000, $quote->baseCostMinor, 'Provider cost: NGN 1,000');
        $this->assertSame(103_000, $quote->customerPriceMinor, 'Selling price: NGN 1,030');
        $this->assertSame(3_000, $quote->grossProfitMinor, 'Expected gross profit: NGN 30');
    }

    /**
     * The distinction the brief calls out explicitly.
     */
    public function test_a_three_percent_markup_is_not_a_three_percent_margin(): void
    {
        $this->rule(300);

        $quote = app(PricingEngine::class)->quote(
            providerCost: 100_000,
            quantity: 1,
            context: ['capability' => ProviderCostResolver::CAPABILITY_DATA, 'product_key' => 'example'],
        );

        // 3000 / 103000 = 2.9126...%, rounded half-up to 291 bps. Reporting 300
        // here would claim a margin the business does not earn.
        $this->assertSame(291, $quote->profitMarginBps, 'A 3% markup is a 2.91% margin');
        $this->assertSame(300, $quote->markupPercentageBps, 'The markup is 3%');
        $this->assertNotSame(
            $quote->markupPercentageBps,
            $quote->profitMarginBps,
            'Markup and margin are different numbers, and reporting one as the other overstates the business'
        );
    }

    public function test_the_approved_data_markup_band_is_two_to_three_percent(): void
    {
        $this->assertSame(200, (int) config('pricing.data_markup_bps_min'), 'The approved floor is 2%');
        $this->assertSame(300, (int) config('pricing.data_markup_bps_max'), 'The approved ceiling is 3%');
        $this->assertSame(300, (int) config('pricing.data_default_markup_bps'), 'The default is 3%');
    }

    /**
     * Requirement: never sell below cost unless an explicit rule permits it.
     *
     * The route to a below-cost sale is a **promotional discount larger than the
     * markup**, not a negative markup -- `pricing_rules.markup_percentage_bps` is
     * UNSIGNED, so a negative one cannot be stored in the first place. That is the
     * first line of defence; this asserts the second.
     */
    public function test_a_discount_that_prices_below_cost_is_refused(): void
    {
        $lossMaking = new PricingRule([
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 300, // NGN 30 markup on NGN 1,000
            'discount_bps' => 1000,         // NGN 103 discount on NGN 1,030
            'promotion_starts_at' => now()->subDay(),
            'promotion_ends_at' => now()->addDay(),
            'minimum_profit_minor' => 0,
            'minimum_margin_bps' => 0,
            'customer_fee_enabled' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
            'allow_negative_margin' => false,
        ]);

        $outcome = app(PricingEngine::class)->quoteWithRule(
            costMinor: 100_000,
            quantity: 1,
            rule: $lossMaking,
        );

        $this->assertTrue($outcome->isLoss(), 'The discount takes the price below cost');
        $this->assertFalse($outcome->isSellable(), 'A below-cost sale must be refused, not sold at a loss');
        $this->assertStringContainsString('below cost', (string) $outcome->refusalReason);
    }

    /**
     * And the explicit authorisation that permits it, so the refusal above is a
     * policy rather than an inability.
     */
    public function test_the_same_discount_is_allowed_when_the_rule_explicitly_permits_a_loss(): void
    {
        $authorised = new PricingRule([
            'markup_type' => PricingRule::MARKUP_PERCENTAGE,
            'markup_percentage_bps' => 300,
            'discount_bps' => 1000,
            'promotion_starts_at' => now()->subDay(),
            'promotion_ends_at' => now()->addDay(),
            'minimum_profit_minor' => 0,
            'minimum_margin_bps' => 0,
            'customer_fee_enabled' => false,
            'rounding_step_minor' => 0,
            'rounding_mode' => PricingRule::ROUNDING_NEAREST,
            'on_unprofitable' => PricingRule::ON_UNPROFITABLE_WARNING,
            'allow_negative_margin' => true,
        ]);

        $outcome = app(PricingEngine::class)->quoteWithRule(
            costMinor: 100_000,
            quantity: 1,
            rule: $authorised,
        );

        $this->assertTrue($outcome->isSellable(), 'An explicitly authorised loss may proceed');
        $this->assertTrue($outcome->isLoss(), 'And the quote still reports itself as a loss rather than hiding it');
    }

    /* =====================================================================
     | Airtime must remain face-value
     |=================================================================== */

    public function test_airtime_remains_face_value_and_is_not_marked_up(): void
    {
        /*
         * One FACE_VALUE rule and one percentage rule, both active, both global 
         * which is exactly the situation this policy has to survive.
         */
        $this->rule(0, PricingRule::MARKUP_FACE_VALUE);
        $this->rule(300, PricingRule::MARKUP_PERCENTAGE);

        // A NGN 1,000 airtime purchase: the customer pays the denomination, and the
        // FACE_VALUE rule is why no percentage markup reaches it.
        $quote = app(PricingEngine::class)->quote(
            providerCost: 98_000, // the network's discounted cost to us
            quantity: 1,
            context: ['capability' => 'airtime', 'product_key' => 'mtn-airtime'],
            at: null,
            priceBasisMinor: 100_000, // the NGN 1,000 the customer chose
        );

        $this->assertSame(
            100_000,
            $quote->customerPriceMinor,
            'Airtime is sold at face value; the 2-3% data markup must not reach it'
        );
        $this->assertSame(2_000, $quote->grossProfitMinor, 'The margin is the network discount, not a markup');
    }

    /* =====================================================================
     | 5. Network logos
     |=================================================================== */

    public function test_every_canonical_network_has_exactly_the_named_asset_on_disk(): void
    {
        // The brief names these four files as the exact assets to use.
        $expected = [
            'mtn' => 'mtn.jpeg',
            'airtel' => 'airtel.jpeg',
            'glo' => 'glo.png',
            '9mobile' => 't2.png',
        ];

        foreach ($expected as $key => $file) {
            $this->assertSame(
                $file,
                NetworkBrand::logoPath($key),
                "The asset for {$key} must be the one the brief names"
            );

            $this->assertFileExists(
                public_path($file),
                "{$file} must be deployed; a logo path that 404s is worse than no logo"
            );
        }
    }

    public function test_the_current_branding_is_t2mobile_for_9mobile(): void
    {
        /*
         * `label()` takes a canonical key; `labelFor()` takes whatever a value
         * happens to be  a transaction's `network_display`, a provider's network
         * string. Testing the rebrand through `labelFor()` is what actually proves
         * aliases are handled, because that is the call the views make.
         */
        $this->assertSame('T2mobile', NetworkBrand::label('9mobile'));
        $this->assertSame('T2mobile', NetworkBrand::labelFor('9mobile'));
        $this->assertSame('T2mobile', NetworkBrand::labelFor('9 Mobile'));
        $this->assertSame('T2mobile', NetworkBrand::labelFor('T2'));
        $this->assertSame('T2mobile', NetworkBrand::labelFor('T2mobile'));
        $this->assertSame('T2mobile', NetworkBrand::labelFor('Etisalat'));

        $this->assertSame('t2.png', NetworkBrand::logoPath('9mobile'));
        $this->assertStringContainsString(
            't2.png',
            (string) NetworkBrand::logoUrlFor('T2'),
            'The alias draws the same asset as the canonical key'
        );

        // But the canonical key stays 9mobile, so provider identifiers and
        // historical rows are not rewritten by a rebrand.
        $this->assertSame('9mobile', NetworkBrand::key('T2mobile'));
        $this->assertSame('9mobile', NetworkBrand::key('9 Mobile'));
    }

    public function test_every_spelling_of_the_four_networks_resolves_to_one_canonical_key(): void
    {
        $cases = [
            'mtn' => ['MTN', 'mtn', 'MTN Nigeria', 'MTN-NG'],
            'airtel' => ['Airtel', 'airtel', 'AIRTEL', 'Airtel Nigeria', 'Zain', 'Celtel'],
            'glo' => ['Glo', 'glo', 'GLO', 'Globacom', 'Glo Mobile'],
            '9mobile' => ['9mobile', '9 Mobile', 'T2', 'T2mobile', 'Etisalat'],
        ];

        foreach ($cases as $canonical => $spellings) {
            foreach ($spellings as $spelling) {
                $this->assertSame(
                    $canonical,
                    NetworkBrand::key($spelling),
                    "[{$spelling}] must resolve to {$canonical}"
                );
            }
        }
    }

    public function test_an_unknown_network_resolves_to_null_rather_than_a_wrong_logo(): void
    {
        // Showing MTN's logo for an unrecognised network is worse than showing
        // none: the customer reads it as confirmation of a choice they did not
        // make.
        $this->assertNull(NetworkBrand::key('SomeNewNetwork'));
        $this->assertNull(NetworkBrand::logoPath('SomeNewNetwork'));
    }

    public function test_the_component_renders_the_logo_with_alt_text_and_a_fallback(): void
    {
        $html = $this->renderComponent('<x-network-logo network="mtn" />');

        $this->assertStringContainsString('mtn.jpeg', $html);
        $this->assertStringContainsString('alt="MTN', $html, 'A logo needs accessible alt text');

        // The graceful fallback: if the image fails, the network name is still
        // shown rather than a broken-image icon.
        $this->assertStringContainsString('onerror', $html);
    }

    public function test_the_component_renders_nothing_for_an_unknown_network(): void
    {
        $html = trim($this->renderComponent('<x-network-logo network="Nonexistent" />'));

        $this->assertStringNotContainsString('<img', $html, 'No image rather than a wrong one');
    }

    public function test_the_component_accepts_a_t2mobile_alias_and_draws_the_9mobile_asset(): void
    {
        $html = $this->renderComponent('<x-network-logo network="T2mobile" />');

        $this->assertStringContainsString('t2.png', $html);
    }

    /* =====================================================================
     | 3 and 5. The network logo is used everywhere networks appear
     |=================================================================== */

    public function test_every_customer_surface_that_names_a_network_uses_the_component(): void
    {
        $surfaces = [
            'resources/views/airtime-data/index.blade.php',
            'resources/views/airtime-data/history.blade.php',
            'resources/views/wallet/history.blade.php',
            'resources/views/transactions/success.blade.php',
            'resources/views/transactions/failed.blade.php',
            'resources/views/dashboard.blade.php',
        ];

        foreach ($surfaces as $surface) {
            $this->assertStringContainsString(
                '<x-network-logo',
                (string) file_get_contents(base_path($surface)),
                "{$surface} shows a network and must use the shared component, not its own path"
            );
        }
    }

    /**
     * Requirement: remove obsolete or inconsistent logo paths.
     */
    public function test_no_view_hard_codes_a_network_asset_path(): void
    {
        $offenders = [];

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            // A literal asset path for a network asset is the duplication this
            // component exists to remove.
            if (preg_match('~/(mtn|airtel|glo|t2)\.(jpe?g|png|svg)~i', $contents, $m)) {
                $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname())
                    . ' -> ' . $m[0];
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These views hard-code a network asset path instead of using the component:\n  - "
            . implode("\n  - ", $offenders)
        );
    }

    /* =====================================================================
     | The two markups are separate policies
     |=================================================================== */

    public function test_the_data_and_smm_markups_are_independent_settings(): void
    {
        // Social Boost's floor is a floor; data's is an approved band. They are
        // different policies and must not share a value that drifts.
        $this->assertSame(1000, (int) config('pricing.smm.minimum_markup_bps'));
        $this->assertSame(1000, app(SmmPricing::class)->minimumMarkupBps());
        $this->assertSame(200, (int) config('pricing.data_markup_bps_min'));
        $this->assertSame(300, (int) config('pricing.data_markup_bps_max'));
    }

    /**
     * Render a Blade string in the application context.
     *
     * Named `renderComponent` rather than `blade` because `TestCase` already has a
     * protected `blade()` and redeclaring it privately is a fatal error, not an
     * override.
     */
    private function renderComponent(string $template): string
    {
        return \Illuminate\Support\Facades\Blade::render($template);
    }
}
