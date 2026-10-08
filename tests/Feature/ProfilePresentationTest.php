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

    /* =====================================================================
     | Transaction PIN — shown on request
     =================================================================== */

    public function test_the_pin_form_is_not_rendered_until_it_is_asked_for(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.index'));

        $response->assertOk();

        // The status is shown …
        $response->assertSee('Transaction PIN', false);
        $response->assertSee('Not set', false);

        // … and the form is not. "New PIN / Confirm PIN" sitting open on a profile
        // page reads as something to fill in, and a customer who types a PIN they did
        // not mean to set has changed their own credentials by accident.
        $response->assertDontSee('Confirm PIN', false);
        $response->assertDontSee('Email authorisation code', false);

        // The CSRF token the PIN form would carry is absent too: server-side gating
        // rather than a CSS-hidden panel, so nothing is merely invisible.
        $response->assertDontSee('name="pin_confirmation"', false);
        $response->assertDontSee('action="' . route('profile.pin') . '"', false);
    }

    public function test_the_pin_form_is_rendered_when_the_customer_asks_for_it(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.index', ['pin' => 1]));

        $response->assertOk();
        $response->assertSee('Confirm PIN', false);
        $response->assertSee('Email authorisation code', false);
        $response->assertSee('Set PIN', false);

        // No current-PIN field on a first-time set: there is nothing to verify against.
        $response->assertDontSee('Current PIN', false);
    }

    public function test_a_change_asks_for_the_current_pin(): void
    {
        $user = User::factory()->create(['transaction_pin' => bcrypt('1357')]);

        // Collapsed: the honest label is "Change PIN", not "Set PIN".
        $collapsed = $this->actingAs($user)->get(route('profile.index'));

        $collapsed->assertSee('Change PIN', false);
        $collapsed->assertDontSee('Confirm PIN', false);

        $opened = $this->actingAs($user)->get(route('profile.index', ['pin' => 1]));

        $opened->assertSee('Current PIN', false);
        $opened->assertSee('Change PIN', false);
    }

    public function test_the_form_stays_open_when_validation_failed(): void
    {
        $user = User::factory()->create();

        /*
         * The failure this prevents: a validation error rendered inside a collapsed
         * form is an error nobody sees. The customer submits, the page reloads to the
         * closed state, and they are told nothing.
         */
        $this->actingAs($user)
            ->put(route('profile.pin'), ['pin' => '12', 'pin_code' => ''])
            ->assertSessionHasErrors();

        $response = $this->actingAs($user)->get(route('profile.index'));

        $response->assertSee('Confirm PIN', false);
        $response->assertSee('Email authorisation code', false);
    }

    public function test_the_form_stays_open_when_the_authorisation_code_is_wrong(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('profile.pin'), [
                'pin' => '2580',
                'pin_confirmation' => '2580',
                'pin_code' => '000000',
            ])
            ->assertSessionHasErrors('pin_code');

        // `pin_code` is one of the keys that re-opens the form, so the customer can see
        // what went wrong and request another code.
        $this->actingAs($user)
            ->get(route('profile.index'))
            ->assertSee('Email authorisation code', false);
    }

    public function test_the_profile_still_renders_with_the_form_open_or_closed(): void
    {
        // A guard against the disclosure change breaking the page in either state.
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.index'))->assertOk();
        $this->actingAs($user)->get(route('profile.index', ['pin' => 1]))->assertOk();
    }

    /**
     * Render a Blade string with the given data.
     */
    private function renderBlade(string $template, array $data = []): string
    {
        return \Illuminate\Support\Facades\Blade::render($template, $data);
    }
}
