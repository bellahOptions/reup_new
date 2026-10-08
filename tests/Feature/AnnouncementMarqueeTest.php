<?php

namespace Tests\Feature;

use App\Models\PromotionNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The announcement marquee — a right-to-left, seamless, endless loop.
 *
 * ## Why some of these assertions read the stylesheet
 *
 * Seamlessness is a *geometric* property of the CSS, not of the HTML: the loop
 * closes only when the animated track is exactly twice one group's width. Asserting
 * "the content appears twice" would pass on the broken version too, which is how the
 * original defect survived. So the arithmetic is asserted directly — the spacing
 * inside the group and its absence on the track — and the HTML assertions cover what
 * the CSS cannot: that the two groups are identical, and that the duplicate is hidden
 * from assistive technology.
 */
class AnnouncementMarqueeTest extends TestCase
{
    use RefreshDatabase;

    /** The stylesheet the marquee's behaviour lives in. */
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    private function render(array $items, array $attributes = []): string
    {
        return Blade::render(
            '<x-marquee :items="$items" ' . implode(' ', $attributes) . ' />',
            ['items' => $items],
        );
    }

    private function announcements(): array
    {
        return [
            ['icon' => 'sparkles', 'title' => 'MTN Promo', 'badge' => 'NEW'],
            ['icon' => 'fire', 'title' => 'Data Bonus', 'badge' => 'HOT'],
        ];
    }

    /* =====================================================================
     | The loop closes
     =================================================================== */

    public function test_the_track_spaces_groups_rather_than_items(): void
    {
        $css = $this->css();

        /*
         * The defect: `gap` on the animated element holding both copies makes the
         * two-copy width `2 × (items + gaps) − gap`. Half of that is a group plus half
         * a gap, so -50% advances 1rem too little and the marquee hitches once per
         * cycle. The spacing therefore has to live inside each group.
         */
        $this->assertMatchesRegularExpression(
            '/\.marquee-group\s*\{[^}]*gap:\s*2rem/s',
            $css,
            'The gap between items must be on the group, not the animated track.',
        );

        $this->assertMatchesRegularExpression(
            '/\.marquee-group\s*\{[^}]*padding-right:\s*2rem/s',
            $css,
            'Each group needs a trailing gap equal to the item gap, or the junction between the two copies is short.',
        );

        // …and the track must NOT carry a gap of its own, which is the bug itself.
        $this->assertDoesNotMatchRegularExpression(
            '/\.marquee-track\s*\{[^}]*gap:/s',
            $css,
            'A gap on the track breaks the -50% arithmetic.',
        );
    }

    public function test_the_track_translates_by_exactly_half_its_width(): void
    {
        $css = $this->css();

        // Read the keyframes body rather than pattern-matching across the nested
        // braces, which a `[^}]*` cannot cross.
        preg_match('/@keyframes reup-marquee\s*\{(.*?)\n\}/s', $css, $matches);

        $body = $matches[1] ?? '';

        $this->assertNotSame('', $body, 'The reup-marquee keyframes are missing.');
        $this->assertStringContainsString('translateX(0)', $body);
        $this->assertStringContainsString('translateX(-50%)', $body);

        // Right-to-left: a negative X. A positive value would scroll the other way.
        $this->assertStringNotContainsString('translateX(50%)', $css);
    }

    public function test_the_animation_repeats_forever_and_linearly(): void
    {
        // `infinite` is the endless part; `linear` is what stops the loop pulsing as
        // it restarts.
        $this->assertMatchesRegularExpression(
            '/\.marquee-track\s*\{[^}]*animation:\s*reup-marquee[^;]*linear infinite/s',
            $this->css(),
        );
    }

    /* =====================================================================
     | The two copies
     =================================================================== */

    public function test_the_two_copies_are_identical(): void
    {
        $html = $this->render($this->announcements());

        // Split on the group marker: [0] is before the first, [1] and [2] are the
        // groups' contents.
        $parts = explode('class="marquee-group', $html);

        $this->assertCount(3, $parts, 'Expected exactly two marquee groups.');

        // The split leaves the tail of each opening tag at the start of its part, so
        // drop everything up to the first `>` — the `aria-hidden` flag is a deliberate
        // difference between the copies, and this test is about the *content*.
        $text = fn (string $part) => preg_replace(
            '/\s+/',
            ' ',
            trim(strip_tags(substr($part, strpos($part, '>') + 1))),
        );

        /*
         * Identical, because the loop's arithmetic depends on it: any width difference
         * between the copies reintroduces a seam even with the CSS correct.
         */
        $this->assertSame(
            $text($parts[1]),
            $text($parts[2]),
            'The duplicate group must be identical to the first.',
        );

        // Each group carries every announcement once.
        $this->assertSame(2, substr_count($html, 'MTN Promo'));
        $this->assertSame(2, substr_count($html, 'Data Bonus'));
    }

    public function test_the_duplicate_is_hidden_from_assistive_technology(): void
    {
        $html = $this->render($this->announcements());

        /*
         * The second copy exists only to make the loop seamless. Without `aria-hidden`
         * a screen reader reads every announcement twice — the kind of defect nobody
         * notices until a customer reports hearing the promotion twice.
         *
         * Matched on the group element specifically: `<x-icon>` also emits
         * `aria-hidden="true"`, so counting the attribute globally would count the icons.
         */
        $this->assertSame(
            1,
            preg_match_all('/class="marquee-group[^"]*"\s+aria-hidden="true"/', $html),
            'Exactly one group must be hidden from assistive technology.',
        );

        // …and it is the second, not the first.
        $firstGroup = strpos($html, 'class="marquee-group');
        $hidden = strpos($html, 'aria-hidden="true"');

        $this->assertGreaterThan($firstGroup, $hidden);
    }

    /* =====================================================================
     | It keeps moving
     =================================================================== */

    public function test_the_duration_has_a_real_default_so_the_marquee_cannot_freeze(): void
    {
        /*
         * `animation` without a duration is `0s`: the row renders clipped and static,
         * which reads as a broken page rather than as a missing setting. The utility
         * therefore carries a fallback rather than relying on the inline property.
         */
        $this->assertMatchesRegularExpression(
            '/\.marquee-track\s*\{[^}]*animation:\s*reup-marquee\s+var\(--marquee-duration,\s*[1-9]\d*s\)/s',
            $this->css(),
            'The animation needs a non-zero default duration.',
        );
    }

    public function test_the_component_sets_the_duration_and_clamps_a_zero(): void
    {
        $html = $this->render($this->announcements(), ['speed="45"']);
        $this->assertStringContainsString('--marquee-duration: 45s', $html);

        // A zero or negative speed would divide nothing and freeze the row.
        foreach (['speed="0"', 'speed="-10"'] as $attribute) {
            $clamped = $this->render($this->announcements(), [$attribute]);

            $this->assertStringNotContainsString('--marquee-duration: 0s', $clamped);
            $this->assertStringNotContainsString('--marquee-duration: -', $clamped);
            $this->assertStringContainsString('--marquee-duration: 1s', $clamped);
        }
    }

    /* =====================================================================
     | Accessibility and emptiness
     =================================================================== */

    public function test_the_marquee_stops_moving_when_motion_is_reduced(): void
    {
        $css = $this->css();

        /*
         * An endlessly scrolling row is precisely what `prefers-reduced-motion` is for.
         * Pausing alone would leave the content clipped mid-word, so the track also
         * wraps and the redundant duplicate is dropped — every announcement readable,
         * none of them moving.
         */
        $this->assertMatchesRegularExpression(
            '/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{.*?\.marquee-track\s*\{[^}]*animation:\s*none.*?\}/s',
            $css,
            'Reduced motion must stop the animation rather than merely slow it.',
        );

        $this->assertMatchesRegularExpression(
            '/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{.*?\.marquee-track\s*\{[^}]*flex-wrap:\s*wrap/s',
            $css,
            'A stopped, clipped row would hide announcements; it must wrap instead.',
        );

        $this->assertMatchesRegularExpression(
            '/@media\s*\(prefers-reduced-motion:\s*reduce\)\s*\{.*?\.marquee-group\[aria-hidden=.true.\]\s*\{\s*display:\s*none/s',
            $css,
        );
    }

    public function test_nothing_is_rendered_without_announcements(): void
    {
        // No fallback offers: a visitor must never be shown an expired promotion.
        $this->assertSame('', trim($this->render([])));

        // …and a row with neither a title nor a badge is not a row.
        $this->assertSame('', trim($this->render([['icon' => 'fire']])));
    }

    public function test_pause_on_hover_can_be_switched_off(): void
    {
        $this->assertStringContainsString('pause-on-hover', $this->render($this->announcements(), [':pauseOnHover="true"']));
        $this->assertStringNotContainsString('pause-on-hover', $this->render($this->announcements(), [':pauseOnHover="false"']));
    }

    /* =====================================================================
     | The pages that use it
     =================================================================== */

    public function test_the_dashboard_renders_the_marquee_with_the_new_markup(): void
    {
        $user = \App\Models\User::factory()->create();

        PromotionNotification::create([
            'title' => 'MTN Promo',
            'content' => 'Double data this weekend',
            'type' => 'promotion',
            'is_active' => true,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('marquee-track', false);
        $response->assertSee('marquee-group', false);
        $response->assertSee('MTN Promo', false);

        // The class that made the loop hitch is gone.
        $response->assertDontSee('animate-marquee', false);
    }
}
