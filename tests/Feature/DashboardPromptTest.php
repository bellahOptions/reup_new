<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardPrompts;
use App\Services\SecurityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The dashboard prompts: the transaction-PIN reminder and the promotional modal.
 *
 * ## The interaction that matters
 *
 * These two compete for the same thing — the one dialog a customer will read — so
 * most of what is worth testing is not whether each renders, but **which one wins**
 * and **how often each comes back**. A promotion that beats the PIN prompt hides the
 * message about the customer's own money; a promotion that re-appears on every page
 * load teaches people to dismiss dialogs without reading them, including the ones
 * that matter.
 *
 * `DashboardPrompts` owns both decisions so they can be asserted directly rather
 * than inferred from markup.
 */
class DashboardPromptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A known starting point: nothing shown, nothing suppressed.
        config([
            'prompts.promo_frequency_hours' => 24,
            'prompts.promotions' => [
                'social_boost' => [
                    'weight' => 1,
                    'icon' => 'rocket-launch',
                    'accent' => 'brand',
                    'eyebrow' => 'New on ReUp',
                    'title' => 'Grow your social media',
                    'body' => 'Buy followers, likes and views from your wallet.',
                    'highlights' => ['Any quantity', 'Automatic refund'],
                    'cta' => 'Explore Social Boost',
                    'route' => 'social-boost.index',
                ],
                'refer_earn' => [
                    'weight' => 1,
                    'icon' => 'users',
                    'accent' => 'emerald',
                    'eyebrow' => 'Earn from ReUp',
                    'title' => 'Refer a friend',
                    'body' => 'Share your link and earn on every top-up.',
                    'highlights' => ['Your own link'],
                    'cta' => 'Get my referral link',
                    'route' => 'affiliate.index',
                ],
            ],
        ]);
    }

    /* =====================================================================
     | The PIN prompt
     |=================================================================== */

    public function test_an_account_without_a_pin_is_prompted(): void
    {
        $user = $this->userWithPin(null);

        $prompts = app(DashboardPrompts::class)->forDashboard($user, request(), app(SecurityService::class));

        $this->assertTrue($prompts['needs_pin'], 'No PIN means the customer must be asked');
    }

    public function test_an_account_with_a_pin_is_not_prompted(): void
    {
        $user = $this->userWithPin('5931');

        $prompts = app(DashboardPrompts::class)->forDashboard($user, request(), app(SecurityService::class));

        $this->assertFalse($prompts['needs_pin'], 'The prompt exists only while it is true');
    }

    public function test_the_dashboard_shows_the_pin_banner_and_modal_when_there_is_no_pin(): void
    {
        $user = $this->userWithPin(null);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();

        // The banner.
        $response->assertSee('Set your transaction PIN', false);
        $response->assertSee('Set my PIN now', false);

        // The modal, with its two steps and the security copy that explains why a
        // code is needed at all.
        $response->assertSee('Step 1', false);
        $response->assertSee('Step 2', false);
        $response->assertSee('Email me a code', false);
        $response->assertSee('we will never ask you for it', false);

        // It posts to the one PIN endpoint, so there is not a second way to install
        // the credential that authorises spending.
        $response->assertSee(route('profile.pin', [], false), false);
        $response->assertSee(route('profile.pin.code', [], false), false);
    }

    public function test_the_pin_prompt_is_absent_once_a_pin_exists(): void
    {
        $user = $this->userWithPin('5931');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();

        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('data-pin-prompt', $html, 'No PIN prompt at all');
        $this->assertStringNotContainsString('data-code-url', $html, 'And no endpoint for one');

        /*
         * A promotion *may* be offered in its place — that is the intended behaviour,
         * so asserting its absence would assert the opposite of what this module is
         * for. What must not be present is the PIN prompt.
         */
        $this->assertStringNotContainsString('Set my PIN now', $html, 'And no button to open one');
    }

    /**
     * The prompt must not be a dead end for a customer without JavaScript.
     */
    public function test_the_pin_banner_carries_a_plain_link_as_a_fallback(): void
    {
        $user = $this->userWithPin(null);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertSee('or open it on my profile', false);
        $response->assertSee(route('profile.index', [], false), false);
    }

    /* =====================================================================
     | The promotional modal
     |=================================================================== */

    public function test_a_promotion_is_chosen_when_one_is_due(): void
    {
        $user = $this->userWithPin('5931');

        $prompts = app(DashboardPrompts::class)->forDashboard($user, request(), app(SecurityService::class));

        $this->assertNotNull($prompts['promotion']);
        $this->assertTrue($prompts['show_promotion']);
        $this->assertContains($prompts['promotion']['key'], ['social_boost', 'refer_earn']);
    }

    /**
     * The whole point of the prompt: it is shown, and its link works.
     */
    public function test_the_dashboard_renders_the_promotional_modal_with_a_working_link(): void
    {
        $user = $this->userWithPin('5931');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Maybe later', false);

        /*
         * Whichever promotion was chosen, its call to action must resolve to a
         * registered route. A promotional tile that links nowhere teaches a customer
         * that the dashboard's buttons are decorative.
         */
        $chosen = null;

        foreach (['social-boost.index', 'affiliate.index'] as $name) {
            if (str_contains((string) $response->getContent(), route($name, [], false))) {
                $chosen = $name;
                break;
            }
        }

        $this->assertNotNull($chosen, 'The rendered promotion must link to a real route');
        $this->assertTrue(Route::has($chosen));
    }

    /**
     * The promotion waits while the PIN prompt is showing.
     */
    public function test_the_pin_prompt_wins_and_the_promotion_waits(): void
    {
        $user = $this->userWithPin(null);

        $prompts = app(DashboardPrompts::class)->forDashboard($user, request(), app(SecurityService::class));

        $this->assertTrue($prompts['needs_pin']);
        $this->assertNotNull($prompts['promotion'], 'One is still chosen...');
        $this->assertFalse(
            $prompts['show_promotion'],
            '...but it is not shown: the PIN prompt takes the one dialog a customer reads'
        );
    }

    public function test_only_one_modal_is_rendered_at_a_time(): void
    {
        // No PIN: the PIN prompt, and no advertisement.
        $noPin = $this->actingAs($this->userWithPin(null))->get(route('dashboard'));
        $noPin->assertSee('data-pin-prompt', false);
        $noPin->assertSee('data-code-url', false);
        $noPin->assertDontSee('data-promo-modal', false);

        // With a PIN: the advertisement, and no PIN prompt.
        $withPin = $this->actingAs($this->userWithPin('5931'))->get(route('dashboard'));
        $withPin->assertSee('data-promo-modal', false);
        $withPin->assertDontSee('data-pin-prompt', false);
    }

    /* =====================================================================
     | Frequency
     |=================================================================== */

    public function test_the_frequency_window_suppresses_a_promotion_that_was_just_shown(): void
    {
        $user = $this->userWithPin('5931');
        $prompts = app(DashboardPrompts::class);

        $this->assertTrue($prompts->mayShowPromotion($user, request()), 'Nothing shown yet');

        // The cookie the dashboard sets, carried on the next request.
        $cookie = $prompts->promotionCookie($user, request());

        $next = request()->duplicate();
        $next->cookies->set(DashboardPrompts::PROMO_COOKIE, $cookie);

        $this->assertFalse(
            $prompts->mayShowPromotion($user, $next),
            'A promotion shown inside the window must not be shown again'
        );
    }

    public function test_the_window_expires(): void
    {
        config(['prompts.promo_frequency_hours' => 1]);

        $user = $this->userWithPin('5931');
        $prompts = app(DashboardPrompts::class);

        // A cookie claiming it was shown two hours ago.
        $record = json_encode([(string) $user->getKey() => now()->subHours(2)->getTimestamp()]);

        $request = request()->duplicate();
        $request->cookies->set(DashboardPrompts::PROMO_COOKIE, $record);

        $this->assertTrue($prompts->mayShowPromotion($user, $request), 'The window has passed');
    }

    /**
     * A shared browser tracks each account separately.
     */
    public function test_the_frequency_window_is_per_account(): void
    {
        $first = $this->userWithPin('5931');
        $second = $this->userWithPin('5931');

        $prompts = app(DashboardPrompts::class);

        $cookie = $prompts->promotionCookie($first, request());

        $next = request()->duplicate();
        $next->cookies->set(DashboardPrompts::PROMO_COOKIE, $cookie);

        $this->assertFalse($prompts->mayShowPromotion($first, $next), 'Shown to the first account');
        $this->assertTrue(
            $prompts->mayShowPromotion($second, $next),
            'The second account on the same browser has been shown nothing'
        );
    }

    public function test_a_zero_frequency_always_shows(): void
    {
        config(['prompts.promo_frequency_hours' => 0]);

        $user = $this->userWithPin('5931');

        $request = request()->duplicate();
        $request->cookies->set(
            DashboardPrompts::PROMO_COOKIE,
            json_encode([(string) $user->getKey() => now()->getTimestamp()])
        );

        $this->assertTrue(app(DashboardPrompts::class)->mayShowPromotion($user, $request));
    }

    /**
     * A cookie is client-controlled; a mangled one must not break the page.
     */
    public function test_a_malformed_cookie_is_ignored_rather_than_breaking_the_page(): void
    {
        $user = $this->userWithPin('5931');
        $prompts = app(DashboardPrompts::class);

        foreach (['not json', '[]', '{"abc":"def"}', 'null', ''] as $junk) {
            $request = request()->duplicate();
            $request->cookies->set(DashboardPrompts::PROMO_COOKIE, $junk);

            $this->assertTrue(
                $prompts->mayShowPromotion($user, $request),
                "A cookie of [{$junk}] means 'never shown', which is the safe reading"
            );
        }

        $response = $this->actingAs($user)
            ->withUnencryptedCookie(DashboardPrompts::PROMO_COOKIE, 'not json')
            ->get(route('dashboard'));

        $response->assertOk();
    }

    /* =====================================================================
     | Configuration
     |=================================================================== */

    public function test_a_zero_weight_disables_a_promotion_without_deleting_its_copy(): void
    {
        config(['prompts.promotions.refer_earn.weight' => 0]);

        $eligible = app(DashboardPrompts::class)->eligiblePromotions();

        $this->assertArrayHasKey('social_boost', $eligible);
        $this->assertArrayNotHasKey('refer_earn', $eligible, 'Weight zero is the off switch');
    }

    public function test_an_empty_configuration_shows_no_modal(): void
    {
        config(['prompts.promotions' => []]);

        $user = $this->userWithPin('5931');

        $this->assertNull(app(DashboardPrompts::class)->promotionFor($user, request()));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-promo-modal', false);
    }

    /**
     * A weighted choice must respect the weights, not merely parse them.
     */
    public function test_a_weight_of_zero_on_everything_shows_nothing(): void
    {
        config([
            'prompts.promotions.social_boost.weight' => 0,
            'prompts.promotions.refer_earn.weight' => 0,
        ]);

        $this->assertSame([], app(DashboardPrompts::class)->eligiblePromotions());
        $this->assertNull(app(DashboardPrompts::class)->promotionFor($this->userWithPin('5931'), request()));
    }

    public function test_every_configured_promotion_links_to_a_route_that_exists(): void
    {
        foreach ((array) config('prompts.promotions') as $key => $promotion) {
            $route = $promotion['route'] ?? null;

            $this->assertNotNull($route, "[{$key}] must name a route");
            $this->assertTrue(Route::has($route), "[{$key}] names [{$route}], which is not a registered route");
        }
    }

    /**
     * Icons are a closed set; an unknown name renders an empty box.
     */
    public function test_every_configured_promotion_uses_an_icon_that_exists(): void
    {
        $iconView = (string) file_get_contents(resource_path('views/components/icon.blade.php'));

        foreach ((array) config('prompts.promotions') as $key => $promotion) {
            $icon = (string) ($promotion['icon'] ?? '');

            $this->assertNotSame('', $icon, "[{$key}] must name an icon");
            $this->assertStringContainsString(
                "'{$icon}'",
                $iconView,
                "[{$key}] uses icon [{$icon}], which the icon component does not define"
            );
        }
    }

    /* =====================================================================
     | The cookie is set only when the modal is actually shown
     |=================================================================== */

    public function test_showing_the_promotion_records_it_on_the_response(): void
    {
        $user = $this->userWithPin('5931');

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();


        $response->assertCookie(DashboardPrompts::PROMO_COOKIE);
    }

    public function test_a_deferred_promotion_does_not_consume_its_own_window(): void
    {
        /*
         * An account with no PIN: the promotion was chosen but the PIN prompt was
         * shown instead. Counting that as a showing would silently skip the
         * customer's next opportunity, so the cookie must not be set.
         */
        $user = $this->userWithPin(null);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertCookieMissing(DashboardPrompts::PROMO_COOKIE);
    }

    public function test_a_suppressed_promotion_is_not_recorded_again(): void
    {
        config(['prompts.promo_frequency_hours' => 24]);

        $user = $this->userWithPin('5931');
        $cookie = app(DashboardPrompts::class)->promotionCookie($user, request());

        $response = $this->actingAs($user)
            ->withCookie(DashboardPrompts::PROMO_COOKIE, $cookie)
            ->get(route('dashboard'));
        $response->assertOk();
        $response->assertDontSee('data-promo-modal', false);
        $response->assertCookieMissing(DashboardPrompts::PROMO_COOKIE, 'Nothing was shown, so nothing is recorded');
    }

    /* =====================================================================
     | Fixtures
     |=================================================================== */

    private function userWithPin(?string $pin): User
    {
        $user = User::factory()->create();

        if ($pin !== null) {
            app(SecurityService::class)->setPin($user, $pin);
        }

        return $user->fresh();
    }
}
