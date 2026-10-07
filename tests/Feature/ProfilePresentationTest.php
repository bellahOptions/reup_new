<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customisable avatar and the first sign-in tips.
 */
class ProfilePresentationTest extends TestCase
{
    use RefreshDatabase;

    /* =====================================================================
     | Avatar
     |=================================================================== */

    public function test_the_avatar_falls_back_to_initials(): void
    {
        $user = User::factory()->create([
            'name' => 'Ada Lovelace',
            'avatar_color' => null,
            'avatar_icon' => null,
        ]);

        $html = $this->renderBlade('<x-avatar :user="$user" />', ['user' => $user]);

        $this->assertStringContainsString('AL', $html);
    }

    public function test_a_chosen_icon_replaces_the_initials(): void
    {
        $user = User::factory()->create([
            'avatar_color' => 'ocean',
            'avatar_icon' => 'bolt',
        ]);

        $html = $this->renderBlade('<x-avatar :user="$user" />', ['user' => $user]);

        $this->assertStringNotContainsString('>AL<', $html);
        // The gradient from the configured palette is applied.
        $this->assertStringContainsString('#0ea5e9', $html);
    }

    public function test_an_unknown_colour_falls_back_to_the_default(): void
    {
        // A stale or hand-edited value must not render an unstyled avatar.
        $user = User::factory()->create(['avatar_color' => 'not-a-real-colour']);

        $html = $this->renderBlade('<x-avatar :user="$user" />', ['user' => $user]);

        $default = config('avatars.colors.' . config('avatars.default_color') . '.from');
        $this->assertStringContainsString($default, $html);
    }

    public function test_an_uploaded_photo_still_wins(): void
    {
        // Existing uploads must keep working after the avatar change.
        $user = User::factory()->create([
            'profile_picture' => '/storage/profiles/old.jpg',
            'avatar_icon' => 'bolt',
        ]);

        $html = $this->renderBlade('<x-avatar :user="$user" />', ['user' => $user]);

        $this->assertStringContainsString('/storage/profiles/old.jpg', $html);
        $this->assertStringContainsString('<img', $html);
    }

    public function test_a_user_can_save_avatar_choices(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_color' => 'plum',
                'avatar_icon' => 'star',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('plum', $user->fresh()->avatar_color);
        $this->assertSame('star', $user->fresh()->avatar_icon);
    }

    public function test_an_invalid_avatar_choice_is_rejected(): void
    {
        // Validated against the configured keys, so a crafted POST cannot store
        // a colour or glyph the renderer does not know about.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_color' => 'javascript:alert(1)',
                'avatar_icon' => '../../etc/passwd',
            ])
            ->assertSessionHasErrors(['avatar_color', 'avatar_icon']);

        $this->assertNull($user->fresh()->avatar_color);
    }

    public function test_the_initials_option_clears_the_icon(): void
    {
        $user = User::factory()->create(['avatar_icon' => 'star']);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => $user->name,
                'email' => $user->email,
                'avatar_color' => 'brand',
                'avatar_icon' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->avatar_icon);
    }

    /* =====================================================================
     | First sign-in tips
     |=================================================================== */

    public function test_tips_are_shown_to_a_user_who_has_not_seen_them(): void
    {
        $user = User::factory()->create(['tips_seen_at' => null]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Getting started')
            ->assertSee('Fund your wallet first');
    }

    public function test_tips_are_hidden_once_seen(): void
    {
        $user = User::factory()->create(['tips_seen_at' => now()]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Fund your wallet first')
            // …but remain reachable.
            ->assertSee('Show the getting-started tips again');
    }

    public function test_dismissing_tips_records_the_timestamp(): void
    {
        $user = User::factory()->create(['tips_seen_at' => null]);

        $this->actingAs($user)
            ->post(route('tips.dismiss'))
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->tips_seen_at);

        // And they stay dismissed.
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertDontSee('Fund your wallet first');
    }

    public function test_tips_can_be_replayed(): void
    {
        $user = User::factory()->create(['tips_seen_at' => now()]);

        $this->actingAs($user)->post(route('tips.replay'))->assertRedirect(route('dashboard'));

        $this->assertNull($user->fresh()->tips_seen_at);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSee('Fund your wallet first');
    }

    public function test_dismissing_twice_does_not_move_the_timestamp(): void
    {
        // Re-posting must not rewrite when the introduction was first seen.
        $user = User::factory()->create(['tips_seen_at' => null]);

        $this->actingAs($user)->post(route('tips.dismiss'));
        $first = $user->fresh()->tips_seen_at;

        $this->travel(5)->minutes();
        $this->actingAs($user)->post(route('tips.dismiss'));

        $this->assertEquals($first->timestamp, $user->fresh()->tips_seen_at->timestamp);
    }

    public function test_guests_cannot_dismiss_tips(): void
    {
        $this->post(route('tips.dismiss'))->assertRedirect(route('login'));
    }

    /**
     * Render a Blade string with the given data.
     */
    private function renderBlade(string $template, array $data = []): string
    {
        return \Illuminate\Support\Facades\Blade::render($template, $data);
    }
}
