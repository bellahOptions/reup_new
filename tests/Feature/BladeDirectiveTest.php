<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Blade directives that Laravel 9 added and this application (8.x) does not have.
 *
 * Blade passes an unrecognised `@directive` through to the browser as literal
 * text instead of raising an error. That is how the profile page ended up
 * printing the avatar picker's own source code, how the registration terms
 * checkbox stopped being restored after a validation error, and how every filter
 * dropdown silently lost its selected value.
 *
 * `AppServiceProvider` backfills @checked / @selected / @disabled / @required /
 * @readonly. These tests pin both the directive behaviour and the absence of
 * leakage, because the failure mode is invisible to a status-code check.
 */
class BladeDirectiveTest extends TestCase
{
    use RefreshDatabase;

    /* =====================================================================
     | The directives exist and compile
     |=================================================================== */

    public function test_the_backfilled_directives_compile(): void
    {
        foreach (['checked', 'selected', 'disabled', 'required', 'readonly'] as $attribute) {
            $compiled = Blade::compileString("@{$attribute}(\$condition)");

            $this->assertStringNotContainsString(
                "@{$attribute}",
                $compiled,
                "@{$attribute} was not compiled — it would render as literal text."
            );

            $this->assertStringContainsString("echo '{$attribute}'", $compiled);
        }
    }

    public function test_compiled_output_is_valid_php(): void
    {
        /*
         * The first attempt at these directives produced `if$x: echo …; endif;`
         * — a parse error — because Blade strips the expression's parentheses
         * before handing it to a custom directive. Asserting validity catches
         * that class of mistake directly.
         */
        foreach ([
            '@checked($x)',
            "@selected(request('type') === 'credit')",
            '@disabled($disabled)',
            "@checked((\$user->avatar_color ?: 'brand') === \$key)",
        ] as $template) {
            $compiled = Blade::compileString($template);
            $php = preg_replace('/^<\?php\s*/', '', $compiled);
            $php = preg_replace('/\s*\?>$/', '', $php);

            $file = tempnam(sys_get_temp_dir(), 'blade') . '.php';
            file_put_contents($file, "<?php " . $php);

            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output, $exit);
            @unlink($file);

            $this->assertSame(0, $exit, "Invalid PHP from {$template}: " . implode(' ', $output));
            $output = [];
        }
    }

    public function test_the_directives_emit_the_attribute_only_when_true(): void
    {
        $this->assertSame('checked', Blade::render('@checked($v)', ['v' => true]));
        $this->assertSame('', Blade::render('@checked($v)', ['v' => false]));

        $this->assertSame('selected', Blade::render('@selected($v)', ['v' => true]));
        $this->assertSame('', Blade::render('@selected($v)', ['v' => false]));

        $this->assertSame('disabled', Blade::render('@disabled($v)', ['v' => true]));
        $this->assertSame('', Blade::render('@disabled($v)', ['v' => false]));
    }

    /* =====================================================================
     | The pages that were affected
     |=================================================================== */

    public function test_the_profile_page_does_not_leak_blade_source(): void
    {
        // The reported symptom: the avatar picker printed its own code.
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('profile.index'))->assertOk();

        foreach (['@checked', '@selected', '@disabled', 'avatar_color ?:', '=== $key'] as $leak) {
            $response->assertDontSee($leak, false);
        }
    }

    public function test_the_profile_page_marks_the_saved_avatar_choice_as_checked(): void
    {
        $user = User::factory()->create([
            'avatar_color' => 'plum',
            'avatar_icon' => 'bolt',
        ]);

        $html = $this->actingAs($user)->get(route('profile.index'))->assertOk()->getContent();

        // The saved colour must be pre-selected, not silently reset.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="avatar_color"[^>]*value="plum"[^>]*checked/',
            $html
        );

        // And so must the saved symbol.
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="avatar_icon"[^>]*value="bolt"[^>]*checked/',
            $html
        );

        // Every configured colour renders a swatch.
        $this->assertSame(
            count(config('avatars.colors')),
            preg_match_all('/name="avatar_color"/', $html)
        );
    }

    public function test_the_registration_terms_checkbox_is_restored_after_a_validation_error(): void
    {
        // Submit with the box ticked but another field missing, so the form
        // re-renders with old input.
        $this->from('/register')->post('/register', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'short',
            'terms' => true,
        ])->assertSessionHasErrors();

        $this->get('/register')
            ->assertOk()
            ->assertSee('checked', false);
    }

    public function test_wallet_history_reselects_the_submitted_filter(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->get(route('transactions.index', ['type' => 'debit']))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<option value="debit"[^>]*selected/',
            $html
        );

        // The other option must not also be marked.
        $this->assertDoesNotMatchRegularExpression(
            '/<option value="credit"[^>]*selected/',
            $html
        );
    }
}
