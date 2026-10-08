<?php

namespace Tests\Feature\Admin;

use App\Models\PromotionNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The announcement module.
 *
 * Written after an audit that found the feature half-wired: the icon column was
 * populated by the seeder but had no form field and rendered on exactly one
 * page; `notification` and `news` announcements rendered nowhere; the badge
 * colour field accepted Tailwind class names and wrote them into a `style`
 * attribute; an unchecked "active" box silently saved as active; and a global
 * view composer ran two announcement queries on every page for output no view
 * read.
 */
class AnnouncementModuleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function announcement(array $attributes = []): PromotionNotification
    {
        return PromotionNotification::create(array_merge([
            'type' => 'promotion',
            'title' => 'Weekend promo',
            'content' => 'Double data this weekend.',
            'icon' => 'megaphone',
            'is_active' => true,
        ], $attributes));
    }

    /* =====================================================================
     | The live() scope
     |=================================================================== */

    public function test_live_excludes_inactive_announcements(): void
    {
        $this->announcement(['is_active' => false]);

        $this->assertSame(0, PromotionNotification::query()->live()->count());
    }

    public function test_live_excludes_announcements_that_have_not_started(): void
    {
        $this->announcement(['starts_at' => now()->addDay()]);

        $this->assertSame(0, PromotionNotification::query()->live()->count());
    }

    public function test_live_excludes_expired_announcements(): void
    {
        $this->announcement(['ends_at' => now()->subDay()]);

        $this->assertSame(0, PromotionNotification::query()->live()->count());
    }

    public function test_live_includes_an_announcement_inside_its_window(): void
    {
        $this->announcement([
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->assertSame(1, PromotionNotification::query()->live()->count());
    }

    public function test_live_treats_a_null_schedule_as_always_on(): void
    {
        // Null start means "already started"; null end means "never expires".
        $this->announcement(['starts_at' => null, 'ends_at' => null]);

        $this->assertSame(1, PromotionNotification::query()->live()->count());
    }

    /* =====================================================================
     | Every type renders
     |=================================================================== */

    public function test_all_three_types_render_in_the_marquee(): void
    {
        /*
         * Previously only `type = promotion` reached a marquee: the
         * `notification`/`news` half was destined for a modal component that was
         * never included in any layout, so those announcements were invisible.
         */
        foreach (['promotion', 'notification', 'news'] as $type) {
            $this->announcement(['type' => $type, 'title' => ucfirst($type) . ' item']);
        }

        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

        foreach (['Promotion item', 'Notification item', 'News item'] as $title) {
            $response->assertSee($title);
        }
    }

    public function test_the_type_badge_shows_when_no_badge_text_is_set(): void
    {
        $this->announcement([
            'type' => 'news',
            'title' => 'Service update',
            'badge' => null,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Service update')
            ->assertSee('News');
    }

    /* =====================================================================
     | Icons
     |=================================================================== */

    public function test_the_icon_renders_as_an_svg_not_an_emoji(): void
    {
        $this->announcement(['icon' => 'fire', 'title' => 'Hot deal']);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))->assertOk()->getContent();

        $block = $this->marqueeBlock($html);

        $this->assertNotSame('', $block, 'The marquee was not found on the dashboard.');
        $this->assertStringContainsString('<svg', $block, 'The icon should render as an inline SVG.');
        $this->assertSame(0, preg_match('/[\x{1F300}-\x{1FAFF}]/u', $block), 'No emoji may be emitted.');
    }

    public function test_the_icon_renders_on_the_airtime_page_too(): void
    {
        // It used to render here and nowhere else, because this was the only view
        // with a hand-written emoji→icon map.
        $this->announcement(['icon' => 'sparkles', 'title' => 'Airtime promo']);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('airtime-data.index'))->assertOk()->getContent();

        $block = $this->marqueeBlock($html);

        $this->assertNotSame('', $block, 'The marquee was not found on the airtime page.');
        $this->assertStringContainsString('Airtime promo', $block);
        $this->assertStringContainsString('<svg', $block);
    }

    public function test_a_legacy_emoji_icon_is_translated(): void
    {
        // Rows created before icons were stored as names hold emoji. They must
        // still render an icon rather than the raw character.
        $this->announcement(['icon' => "\u{1F389}", 'title' => 'Legacy row']);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))->assertOk()->getContent();

        $block = $this->marqueeBlock($html);

        $this->assertStringContainsString('Legacy row', $block);
        $this->assertStringContainsString('<svg', $block);
        $this->assertStringNotContainsString("\u{1F389}", $block);
    }

    /**
     * The rendered marquee, delimited by counting divs.
     *
     * Anchored on the component's own `marquee` wrapper and closed by walking
     * `<div`/`</div>` pairs until they balance, so the returned block is the
     * component and nothing else.
     *
     * Deliberately not anchored on the animated track's class: the previous version of
     * these tests keyed on `animate-marquee`, and renaming that class for the seamless
     * loop silently reduced them to asserting against an empty string. A block that
     * cannot be found now fails loudly instead.
     */
    private function marqueeBlock(string $html): string
    {
        $start = strpos($html, '<div class="marquee"');

        if ($start === false) {
            return '';
        }

        // The wrapper's own opening tag has been consumed.
        $depth = 1;
        $offset = $start + 4;
        $length = strlen($html);

        while ($offset < $length) {
            $open = strpos($html, '<div', $offset);
            $close = strpos($html, '</div>', $offset);

            if ($close === false) {
                break;
            }

            if ($open !== false && $open < $close) {
                $depth++;
                $offset = $open + 4;

                continue;
            }

            $depth--;
            $offset = $close + 6;

            if ($depth === 0) {
                return substr($html, $start, $offset - $start);
            }
        }

        return substr($html, $start);
    }

    public function test_an_unknown_icon_falls_back_rather_than_breaking(): void
    {
        $this->announcement(['icon' => 'not-a-real-icon', 'title' => 'Odd icon']);

        $html = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))->assertOk()->getContent();

        // Renders, with the fallback glyph.
        $this->assertStringContainsString('Odd icon', $html);
        $this->assertStringNotContainsString('not-a-real-icon', $html);
    }

    /* =====================================================================
     | Admin form: the fields that were missing or unvalidated
     |=================================================================== */

    public function test_the_form_offers_an_icon_picker(): void
    {
        // The column existed and the seeder filled it, but there was no control.
        $this->actingAs($this->admin())
            ->get(route('admin.announcement.create'))
            ->assertOk()
            ->assertSee('name="icon"', false);
    }

    public function test_an_announcement_can_be_created_with_an_icon(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.announcement.store'), [
                'type' => 'promotion',
                'title' => 'Icon test',
                'content' => 'Body',
                'icon' => 'gift',
                'is_active' => '1',
            ])
            ->assertRedirect(route('admin.announcement.index'));

        $this->assertSame('gift', PromotionNotification::where('title', 'Icon test')->value('icon'));
    }

    public function test_an_unknown_icon_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.announcement.store'), [
                'type' => 'promotion',
                'title' => 'Bad icon',
                'content' => 'Body',
                'icon' => 'definitely-not-an-icon',
            ])
            ->assertSessionHasErrors('icon');

        $this->assertDatabaseMissing('promotion_notifications', ['title' => 'Bad icon']);
    }

    /* =====================================================================
     | Admin form: the is_active trap
     |=================================================================== */

    public function test_an_unchecked_active_box_saves_as_inactive(): void
    {
        /*
         * An unchecked checkbox submits nothing, so `is_active` was absent and
         * the column's DEFAULT 1 made the announcement live — the opposite of
         * what the admin asked for.
         */
        $this->actingAs($this->admin())
            ->post(route('admin.announcement.store'), [
                'type' => 'promotion',
                'title' => 'Draft announcement',
                'content' => 'Not ready yet',
                'icon' => 'megaphone',
                // is_active deliberately omitted, as an unchecked box does.
            ])
            ->assertRedirect(route('admin.announcement.index'));

        $created = PromotionNotification::where('title', 'Draft announcement')->firstOrFail();

        $this->assertFalse((bool) $created->is_active, 'Unchecked must mean inactive.');
        $this->assertSame(0, PromotionNotification::query()->live()->count());
    }

    public function test_a_checked_active_box_saves_as_active(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.announcement.store'), [
                'type' => 'promotion',
                'title' => 'Live announcement',
                'content' => 'Ready',
                'icon' => 'megaphone',
                'is_active' => '1',
            ]);

        $this->assertTrue((bool) PromotionNotification::where('title', 'Live announcement')->value('is_active'));
    }

    public function test_updating_can_deactivate_an_announcement(): void
    {
        $announcement = $this->announcement();

        $this->actingAs($this->admin())
            ->put(route('admin.announcement.update', $announcement->id), [
                'type' => $announcement->type,
                'title' => $announcement->title,
                'content' => $announcement->content,
                'icon' => $announcement->icon,
                // omitted => deactivate
            ])
            ->assertRedirect(route('admin.announcement.index'));

        $this->assertFalse((bool) $announcement->fresh()->is_active);
    }

    /* =====================================================================
     | Colour validation
     |=================================================================== */

    public function test_a_tailwind_class_is_rejected_as_a_badge_colour(): void
    {
        // The value is written into a `style` attribute, so a class name
        // produced `background-color: bg-purple-100 text-purple-800` — invalid
        // CSS the browser silently discards.
        $this->actingAs($this->admin())
            ->post(route('admin.announcement.store'), [
                'type' => 'promotion',
                'title' => 'Bad colour',
                'content' => 'Body',
                'icon' => 'megaphone',
                'badge_color' => 'bg-purple-100 text-purple-800',
            ])
            ->assertSessionHasErrors('badge_color');
    }

    public function test_a_hex_badge_colour_is_accepted(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.announcement.store'), [
                'type' => 'promotion',
                'title' => 'Good colour',
                'content' => 'Body',
                'icon' => 'megaphone',
                'badge_color' => '#7e22ce',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('#7e22ce', PromotionNotification::where('title', 'Good colour')->value('badge_color'));
    }

    /* =====================================================================
     | Authorization
     | =================================================================== */

    public function test_announcements_require_the_settings_permission(): void
    {
        $support = User::factory()->admin(\App\Support\Permissions::ROLE_DEFAULTS['support'], 'support')->create();

        // `support` has no manage_settings, so the announcement console is closed.
        $this->actingAs($support)
            ->get(route('admin.announcement.index'))
            ->assertForbidden();
    }
}
