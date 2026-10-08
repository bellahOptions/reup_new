<?php

namespace Tests\Feature;

use App\Catalogue\ServiceCatalogue;
use App\Models\Provider;
use App\Models\ProviderProduct;
use App\Models\ServiceCategory;
use App\Models\ServiceProduct;
use App\Models\User;
use App\Support\UiCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The service catalogue — what the customer can actually buy, and why not.
 *
 * §51.42 requires the frontend to take availability, products and status from the
 * backend rather than a template. These tests hold that line in the two directions
 * that matter:
 *
 *   * a service must not appear as available when no provider can serve it, because
 *     the customer finds out at checkout, which is the worst possible place;
 *   * a service withheld for pricing must say so without disclosing a cost, a margin
 *     or a provider.
 */
class ServiceCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private Provider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = Provider::create([
            'slug' => 'sogo',
            'name' => 'Sogo',
            'driver' => 'sogo',
            'capabilities' => ['data', 'gift_cards'],
            'is_active' => true,
            'priority' => 10,
            'credential_env_prefix' => 'SOGO',
        ]);
    }

    private function category(string $slug, string $group = ServiceCategory::GROUP_PAY_BILLS, string $name = 'Data'): ServiceCategory
    {
        return ServiceCategory::create([
            'group' => $group,
            'slug' => $slug,
            'name' => $name,
            'sort_order' => 10,
            'is_active' => true,
        ]);
    }

    private function product(
        ServiceCategory $category,
        string $key,
        string $status = ServiceProduct::STATUS_ACTIVE,
        string $availability = ServiceProduct::AVAILABILITY_AVAILABLE
    ): ServiceProduct {
        return ServiceProduct::create([
            'category_id' => $category->getKey(),
            'product_key' => $key,
            'slug' => $key,
            'name' => strtoupper($key),
            'status' => $status,
            'availability' => $availability,
        ]);
    }

    private function offer(ServiceProduct $product, ?int $costMinor = 40000): ProviderProduct
    {
        return ProviderProduct::create([
            'provider_id' => $this->provider->getKey(),
            'service_product_id' => $product->getKey(),
            'provider_product_id' => 'PP-' . $product->product_key,
            'provider_name' => 'Provider internal name',
            'provider_cost_minor' => $costMinor,
            'is_active' => true,
        ]);
    }

    private function catalogue(): ServiceCatalogue
    {
        return app(ServiceCatalogue::class);
    }

    /* =====================================================================
     | Availability is derived, never declared
     =================================================================== */

    public function test_a_published_product_with_a_fundable_provider_offer_is_available(): void
    {
        $product = $this->product($this->category('data'), 'mtn-1gb');
        $this->offer($product);

        $this->assertTrue($this->catalogue()->isAvailable($product));
    }

    public function test_a_product_no_provider_can_serve_is_not_available(): void
    {
        $product = $this->product($this->category('data'), 'mtn-1gb');

        /*
         * The failure this prevents: the customer browses the product, reaches
         * checkout, is debited, and only then discovers nothing can fulfil it. The
         * order would land in UNKNOWN and hold their money while somebody reconciled
         * it by hand.
         */
        $this->assertFalse($this->catalogue()->isAvailable($product));
    }

    public function test_a_withdrawn_provider_offer_makes_the_product_unavailable_immediately(): void
    {
        $product = $this->product($this->category('data'), 'mtn-1gb');
        $offer = $this->offer($product);

        $this->assertTrue($this->catalogue()->isAvailable($product));

        // What the catalogue sync does when a provider stops publishing a plan.
        $offer->forceFill(['unavailable_at' => now()])->save();

        $this->assertFalse($this->catalogue()->isAvailable($product->fresh()));
    }

    public function test_an_offer_with_no_known_cost_does_not_make_a_product_available(): void
    {
        $product = $this->product($this->category('data'), 'mtn-1gb');
        $this->offer($product, null);

        // A cost we do not know cannot be priced, and a product that cannot be priced
        // would reach checkout only for the pricing engine to refuse it.
        $this->assertFalse($this->catalogue()->isAvailable($product));
    }

    public function test_an_unpublished_product_is_not_available_even_with_a_provider_offer(): void
    {
        $product = $this->product($this->category('data'), 'mtn-1gb', ServiceProduct::STATUS_DISCOVERED);
        $this->offer($product);

        /*
         * The publication gate. A synced catalogue must not reach the storefront on its
         * own: a discovered product has no price and no review, and SMM catalogues in
         * particular routinely contain services with purchaser pre-requisites.
         */
        $this->assertFalse($this->catalogue()->isAvailable($product));
        $this->assertSame(UiCopy::get('availability.unpublished'), UiCopy::unavailableMessage($product));
    }

    /* =====================================================================
     | The profitability gate
     =================================================================== */

    public function test_a_product_withheld_for_pricing_says_so_without_disclosing_reup_economics(): void
    {
        $product = $this->product(
            $this->category('data'),
            'mtn-1gb',
            ServiceProduct::STATUS_UNPROFITABLE,
            ServiceProduct::AVAILABILITY_UNAVAILABLE,
        );

        // Still offered by a provider, so the only reason it is withheld is the margin.
        $this->offer($product);

        $this->assertFalse($this->catalogue()->isAvailable($product));

        $description = $this->catalogue()->describe($product);

        $this->assertFalse($description['available']);
        $this->assertSame(UiCopy::get('availability.pricing'), $description['unavailable_reason']);

        // §51.37: the customer is told the service is unavailable, never why in
        // economic terms.
        $this->assertStringNotContainsString('cost', strtolower((string) $description['unavailable_reason']));
        $this->assertStringNotContainsString('margin', strtolower((string) $description['unavailable_reason']));
        $this->assertStringNotContainsString('provider', strtolower((string) $description['unavailable_reason']));
    }

    public function test_a_product_available_with_a_warning_is_still_sellable(): void
    {
        $product = $this->product(
            $this->category('data'),
            'mtn-1gb',
            ServiceProduct::STATUS_ACTIVE,
            ServiceProduct::AVAILABILITY_WITH_WARNING,
        );

        $this->offer($product);

        /*
         * A warning is not a block. The margin is below target but above the hard
         * floor and the operator has chosen to keep selling, so the product stays
         * available and the flag drives internal reporting only.
         */
        $this->assertTrue($this->catalogue()->isAvailable($product));

        $description = $this->catalogue()->describe($product);

        $this->assertTrue($description['available']);
        $this->assertTrue($description['with_warning']);
        $this->assertNull($description['unavailable_reason']);
    }

    /* =====================================================================
     | The payload a customer-facing frontend receives
     =================================================================== */

    public function test_the_payload_never_carries_a_price_a_cost_or_a_provider(): void
    {
        $category = $this->category('data');
        $product = $this->product($category, 'mtn-1gb');
        $this->offer($product, 40000);

        $payload = $this->catalogue()->payload();
        $encoded = json_encode($payload);

        // A price belongs to a quote at checkout (§51.38); a cost and a provider name
        // are internal (§51.36, §51.37).
        $this->assertStringNotContainsString('"price_minor"', (string) $encoded);
        $this->assertStringNotContainsString('provider', strtolower((string) $encoded));
        $this->assertStringNotContainsString('40000', (string) $encoded);
        $this->assertStringNotContainsString('Provider internal name', (string) $encoded);

        $service = $payload['services'][0];

        $this->assertArrayHasKey('price', $service);
        $this->assertNull($service['price']);
    }

    public function test_the_payload_exposes_the_approved_sections_in_order_with_their_copy(): void
    {
        // One available product per group, so every section has something to show.
        $this->offer($this->product($this->category('data'), 'mtn-1gb'));

        $this->offer($this->product(
            $this->category('gift-cards', ServiceCategory::GROUP_DIGITAL, 'Gift Cards'),
            'amazon-10',
        ));

        $this->offer($this->product(
            $this->category('social-boost', ServiceCategory::GROUP_SOCIAL, 'Social Boost'),
            'ig-followers',
        ));

        $payload = $this->catalogue()->payload();

        $this->assertSame(
            [ServiceCategory::GROUP_PAY_BILLS, ServiceCategory::GROUP_DIGITAL, ServiceCategory::GROUP_SOCIAL],
            array_column($payload['sections'], 'group'),
        );

        $this->assertSame('Pay Bills', $payload['sections'][0]['name']);
        $this->assertSame('Everyday payments, made simple.', $payload['sections'][0]['dashboard_description']);
        $this->assertSame('Boost your social presence.', $payload['sections'][2]['dashboard_description']);

        // My ReUp is a section of the dashboard but not a sellable group.
        $this->assertSame('My ReUp', $payload['my_reup']['name']);
    }

    public function test_a_section_with_nothing_available_is_not_rendered_at_all(): void
    {
        /*
         * The reported behaviour: three empty sections, each a heading above a generic
         * "This service is currently unavailable" card. That is not information — it
         * makes a working product look broken and pushes what a customer *can* buy
         * further down the page.
         */
        $payload = $this->catalogue()->payload();

        $this->assertSame([], $payload['sections']);
        $this->assertSame([], $payload['services']);
    }

    public function test_an_unavailable_service_is_omitted_from_a_section_that_has_others_available(): void
    {
        $category = $this->category('data');

        $this->offer($this->product($category, 'mtn-1gb'));

        // Exists, has a provider, but has been withheld for pricing.
        $this->offer($this->product(
            $category,
            'mtn-2gb',
            ServiceProduct::STATUS_UNPROFITABLE,
            ServiceProduct::AVAILABILITY_UNAVAILABLE,
        ));

        $payload = $this->catalogue()->payload();
        $names = array_column($payload['services'], 'name');

        $this->assertContains('MTN-1GB', $names);
        $this->assertNotContains('MTN-2GB', $names);

        // The section survives because it still has something to sell, so its heading
        // is not an empty promise.
        $this->assertCount(1, $payload['sections']);
        $this->assertCount(1, $payload['sections'][0]['services']);
    }

    public function test_a_section_disappears_the_moment_its_last_service_goes(): void
    {
        $offer = $this->offer($this->product($this->category('data'), 'mtn-1gb'));

        $this->assertCount(1, $this->catalogue()->payload()['sections']);

        // The provider withdraws the plan.
        $offer->forceFill(['unavailable_at' => now()])->save();

        // The section is gone rather than left as an empty heading.
        $this->assertSame([], $this->catalogue()->payload()['sections']);
    }

    public function test_the_unfiltered_form_keeps_the_whole_picture_for_diagnosis(): void
    {
        /*
         * Hiding a product from customers must not make it invisible to the people
         * asking why it is not on sale. The unfiltered form is what answers that, and it
         * is the only place `unavailable_reason` is populated — no customer-facing
         * surface reads it.
         */
        $this->offer($this->product(
            $this->category('data'),
            'mtn-2gb',
            ServiceProduct::STATUS_UNPROFITABLE,
            ServiceProduct::AVAILABILITY_UNAVAILABLE,
        ));

        $this->assertSame([], $this->catalogue()->payload()['sections']);

        $full = $this->catalogue()->payload(onlyAvailable: false);

        $this->assertCount(1, $full['sections']);
        $this->assertFalse($full['sections'][0]['has_available']);
        $this->assertCount(1, $full['sections'][0]['services']);
        $this->assertSame(
            UiCopy::get('availability.pricing'),
            $full['sections'][0]['services'][0]['unavailable_reason'],
        );
    }

    public function test_quick_actions_include_only_services_that_are_actually_available(): void
    {
        $available = $this->product($this->category('data'), 'mtn-1gb');
        $this->offer($available);

        $unavailable = $this->product($this->category('gift-cards', ServiceCategory::GROUP_DIGITAL, 'Gift Cards'), 'amazon-10');

        $labels = array_column($this->catalogue()->quickActions(), 'label');

        $this->assertContains('MTN-1GB', $labels);
        $this->assertNotContains('AMAZON-10', $labels);

        // A quick action that led to a dead end would be worse than no quick action.
        $unavailable->refresh();
        $this->assertFalse($this->catalogue()->isAvailable($unavailable));
    }

    /* =====================================================================
     | The endpoint
     =================================================================== */

    public function test_the_catalogue_endpoint_requires_authentication(): void
    {
        // An unauthenticated list of what ReUp sells is free reconnaissance.
        $this->get(route('services.catalogue'))->assertRedirect(route('login'));
    }

    public function test_the_catalogue_endpoint_returns_the_dynamic_catalogue_as_json(): void
    {
        $user = User::factory()->create();
        $product = $this->product($this->category('data'), 'mtn-1gb');
        $this->offer($product);

        $response = $this->actingAs($user)->getJson(route('services.catalogue'));

        $response->assertOk();
        $response->assertJsonPath('services.0.name', 'MTN-1GB');
        $response->assertJsonPath('services.0.available', true);
        $response->assertJsonPath('services.0.price', null);
        $response->assertJsonPath('sections.0.name', 'Pay Bills');

        // Availability changes the moment a provider is withdrawn, so a shared cache
        // would serve a customer a service that no longer exists.
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_services_page_renders_from_the_catalogue(): void
    {
        $user = User::factory()->create();
        $product = $this->product($this->category('data'), 'mtn-1gb');
        $this->offer($product);

        $response = $this->actingAs($user)->get(route('services.index'));

        $response->assertOk();
        $response->assertSee('Pay Bills', false);
        $response->assertSee('MTN-1GB', false);

        // The provider's own name for the plan never reaches the page.
        $response->assertDontSee('Provider internal name', false);
    }
}
