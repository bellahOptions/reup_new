<?php

namespace Tests\Feature;

use App\Models\AdminLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Names at every entry point that accepts one.
 *
 * The unit test (`Tests\Unit\NameTest`) proves the rule. This proves the rule is
 * actually *wired in* — that sign-up, the profile form and the admin customer
 * editor all go through it, normalise what they store, and flag rather than refuse.
 * A rule that three of four endpoints forget is worse than no rule, because the
 * fourth one makes it look handled.
 *
 * ## Why the cases are shaped the way they are
 *
 * Every "invalid" case asserts on the *field* that failed, not on the response
 * code. A sign-up that is rejected for the wrong reason still rejects, so a test
 * that only checked "does not create a user" would pass while the name was being
 * validated nowhere.
 */
class CustomerNameTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function customer(array $attributes = []): User
    {
        $attributes = array_merge([
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'phone' => '08031234567',
            'password' => Hash::make('password'),
        ], $attributes);

        /*
         * A caller that overrides `name` gets the halves derived from it.
         *
         * Without this the model's `saving` hook rebuilds `name` from the *default*
         * halves, so `customer(['name' => "Ada O'Brien"])` silently stored
         * "Ada Obi" — which is exactly the sort of thing a test about escaping must
         * not be measuring by accident.
         */
        [$attributes['first_name'], $attributes['last_name']] = \App\Models\User::splitName((string) $attributes['name']);

        return User::factory()->create($attributes);
    }

    /** A complete, valid signup payload. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'email' => 'new@example.com',
            'phone' => '08031234567',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => true,
        ], $overrides);
    }

    /* =====================================================================
     | Sign-up
     =================================================================== */

    /**
     * Names that must register successfully, each paired with what must be stored.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function signupNames(): array
    {
        return [
            'nigerian' => ['Chidinma', 'Okafor', 'Chidinma', 'Okafor'],
            'two-word given name' => ['Chidinma Nneka', 'Okafor', 'Chidinma Nneka', 'Okafor'],
            'apostrophe' => ['Ada', "O'Brien", 'Ada', "O'Brien"],
            'hyphen' => ['Yusuf', 'Al-Amin', 'Yusuf', 'Al-Amin'],
            'stops and spaces' => ['J. R.', 'Tolkien', 'J. R.', 'Tolkien'],
            'accented' => ['Aïcha', 'Diallo', 'Aïcha', 'Diallo'],
            'combining diacritics' => ['Ọ̀ṣun', 'Adéwálé', 'Ọ̀ṣun', 'Adéwálé'],
            'vietnamese' => ['Minh', 'Nguyễn', 'Minh', 'Nguyễn'],
            'arabic' => ['محمد', 'علي', 'محمد', 'علي'],
            'chinese' => ['伟', '张', '伟', '张'],
        ];
    }

    /**
     * @dataProvider signupNames
     */
    public function test_international_names_register_and_are_stored_exactly(
        string $first,
        string $last,
        string $expectedFirst,
        string $expectedLast
    ): void {
        $email = md5($first . $last) . '@example.com';

        $this->post('/register', $this->payload([
            'email' => $email,
            'first_name' => $first,
            'last_name' => $last,
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', $email)->first();

        $this->assertNotNull($user, "Registration with \"{$first} {$last}\" created no account.");
        $this->assertSame($expectedFirst, $user->first_name);
        $this->assertSame($expectedLast, $user->last_name);
        $this->assertSame(trim($expectedFirst . ' ' . $expectedLast), $user->name);
    }

    public function test_whitespace_is_collapsed_and_trimmed_before_storage(): void
    {
        $this->post('/register', $this->payload([
            'first_name' => '  Ada   Nneka ',
            'last_name' => "\tObi  ",
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertSame('Ada Nneka', $user->first_name);
        $this->assertSame('Obi', $user->last_name);
        $this->assertSame('Ada Nneka Obi', $user->name);
    }

    public function test_invisible_characters_are_removed_rather_than_rejected(): void
    {
        /*
         * A paste out of a styled document carries a zero-width space, and a phone
         * keyboard can emit a composing sequence. None of it is deliberate, so the
         * name is cleaned and accepted — the customer never sees an error about a
         * character they cannot see.
         */
        $this->post('/register', $this->payload([
            'first_name' => "Ada\u{200B}",
            'last_name' => "O\u{0301}bi",
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertSame('Ada', $user->first_name);
        // `Óbi`, composed from `O` + a combining acute: one character, not two.
        $this->assertSame('Óbi', $user->last_name);
        $this->assertSame(3, mb_strlen($user->last_name));
    }

    public function test_the_form_redisplays_the_normalised_name_after_a_failure(): void
    {
        /*
         * The rewritten value goes into the request, not into a controller's local
         * variable, which is what makes this work: a visitor who typed "  Ada  " and
         * then failed the phone check gets "Ada" back in the box, not the padding.
         */
        $this->post('/register', $this->payload([
            'first_name' => '  Ada  ',
            'phone' => 'nonsense',
        ]))->assertSessionHasErrors('phone');

        $this->assertSame('Ada', old('first_name'));
    }

    /**
     * Values that must be refused at sign-up, with the field and a message fragment.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function rejectedSignupNames(): array
    {
        return [
            'digits in the first name' => ['first_name', 'Ada1', 'first_name', 'numbers'],
            'digits in the last name' => ['last_name', 'Obi2', 'last_name', 'numbers'],
            'emoji' => ['first_name', 'Ada 😀', 'first_name', 'emoji'],
            'emoji alone' => ['first_name', '🎉', 'first_name', 'using letters'],
            'digits alone' => ['first_name', '123', 'first_name', 'using letters'],
            'underscore' => ['first_name', 'Ada_', 'first_name', 'letters'],
            'at sign' => ['first_name', 'Ada@x.com', 'first_name', 'letters'],
            'leading hyphen' => ['first_name', '-Ada', 'first_name', 'start with a letter'],
            'html' => ['first_name', '<script>', 'first_name', 'letters'],
            'empty' => ['first_name', '', 'first_name', 'We need your first name'],
            'only spaces' => ['last_name', '   ', 'last_name', 'We need your last name'],
        ];
    }

    /**
     * @dataProvider rejectedSignupNames
     */
    public function test_a_name_that_is_not_a_name_is_refused(
        string $field,
        string $value,
        string $expectedField,
        string $expectedFragment
    ): void {
        $response = $this->post('/register', $this->payload([$field => $value]));

        $response->assertSessionHasErrors($expectedField);

        $message = session('errors')->first($expectedField);

        $this->assertStringContainsStringIgnoringCase(
            $expectedFragment,
            $message,
            "Refusing \"{$value}\" should have said why. Got: {$message}"
        );

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_a_name_that_is_too_long_is_refused_before_the_column_truncates_it(): void
    {
        /*
         * `users.first_name` is a `varchar(191)`. Without this check MySQL truncates
         * silently, and the customer's name on their bank account stops mid-word —
         * which they discover when somebody tries to pay them.
         */
        $this->post('/register', $this->payload([
            'first_name' => str_repeat('a', 101),
        ]))->assertSessionHasErrors('first_name');

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    /* =====================================================================
     | Suspicious names are flagged, not refused
     =================================================================== */

    public function test_a_placeholder_name_registers_and_is_flagged_for_review(): void
    {
        /*
         * The requirement, and the line it draws: detection for obvious filler, and
         * *flagging* rather than rejection. The account works, the customer is not
         * accused of anything, and a human gets a question.
         */
        $this->post('/register', $this->payload([
            'first_name' => 'asdf',
            'last_name' => 'qwerty',
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertAuthenticated();
        $this->assertSame(User::NAME_REVIEW_PENDING, $user->name_review);
        $this->assertNotNull($user->name_review_reason);
        $this->assertStringContainsString('placeholder word', (string) $user->name_review_reason);
    }

    public function test_a_flagged_account_is_still_fully_usable(): void
    {
        /*
         * The flag exists so that a name can be looked at. It must not be a
         * suspension in disguise: `status`, `is_blocked` and the wallet are
         * untouched, and the customer can sign in and transact.
         */
        $this->post('/register', $this->payload([
            'first_name' => 'test',
            'last_name' => 'test',
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertSame(User::NAME_REVIEW_PENDING, $user->name_review);
        $this->assertSame('active', $user->status);
        $this->assertFalse($user->is_blocked);
        $this->assertFalse($user->isSuspended());
        $this->assertNotNull($user->wallet);
    }

    /**
     * Real names that must register without being flagged.
     *
     * A data provider rather than a loop inside one test, and that is not
     * stylistic: registration signs the new account in, so a second pass in the
     * same test is correctly redirected away by the `guest` middleware and creates
     * nothing. The loop version failed on its second iteration for exactly that
     * reason.
     *
     * @return array<string, array{string, string}>
     */
    public static function ordinaryNames(): array
    {
        return [
            'nigerian' => ['Ada', 'Obi'],
            'two-word given name' => ['Chidinma Nneka', 'Okafor'],
            'apostrophe' => ["O'Brien", 'Diallo'],
            'combining diacritics' => ['Ọ̀ṣun', 'Adéwálé'],
            'no-vowel surname' => ['Kwame', 'Nkrumah'],
            'two-letter surname' => ['Wei', 'Ng'],
        ];
    }

    /**
     * @dataProvider ordinaryNames
     */
    public function test_an_ordinary_name_is_not_flagged(string $first, string $last): void
    {
        // The false-positive guard at the endpoint: a real name must not enter the
        // review queue, or the queue becomes noise nobody reads.
        $this->post('/register', $this->payload([
            'first_name' => $first,
            'last_name' => $last,
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertNull(
            $user->name_review,
            "\"{$first} {$last}\" is a real name and must not be flagged."
        );
    }

    /* =====================================================================
     | Profile update
     =================================================================== */

    public function test_the_profile_update_normalises_and_stores_a_name(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'first_name' => '  Chidinma   Nneka ',
                'last_name' => "O'Brien ",
                'email' => $user->email,
                'phone' => $user->phone,
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('Chidinma Nneka', $user->first_name);
        $this->assertSame("O'Brien", $user->last_name);
        $this->assertSame("Chidinma Nneka O'Brien", $user->name);
    }

    public function test_the_profile_update_refuses_what_cannot_be_a_name(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'first_name' => 'Ada1',
                'last_name' => 'Obi',
                'email' => $user->email,
                'phone' => $user->phone,
            ])
            ->assertSessionHasErrors('first_name');

        // And nothing was written: a refused name must not half-save.
        $this->assertSame('Ada', $user->fresh()->first_name);
    }

    public function test_a_legacy_profile_post_carrying_only_a_whole_name_still_works(): void
    {
        /*
         * An open tab, or a script, that posts the old single `name` field. The
         * halves are derived from it — and normalised — rather than the request
         * being rejected for a field the form used to have.
         */
        $user = $this->customer();

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'name' => '  Ada   Obi Nwosu  ',
                'email' => $user->email,
                'phone' => $user->phone,
            ])
            ->assertSessionHasNoErrors();

        $user->refresh();

        $this->assertSame('Ada', $user->first_name);
        $this->assertSame('Obi Nwosu', $user->last_name);
        $this->assertSame('Ada Obi Nwosu', $user->name);
    }

    public function test_changing_a_name_to_a_placeholder_flags_it_for_review(): void
    {
        $user = $this->customer();

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'first_name' => 'asdf',
                'last_name' => 'asdf',
                'email' => $user->email,
                'phone' => $user->phone,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(User::NAME_REVIEW_PENDING, $user->fresh()->name_review);
    }

    public function test_a_profile_save_that_does_not_change_the_name_does_not_re_open_a_review(): void
    {
        /*
         * The requirement not to duplicate the review machinery, at the level a
         * customer would notice: an administrator clears a name, the customer later
         * edits their address, and the flag must not come back asking the same
         * question again.
         */
        $user = $this->customer(['name_review' => User::NAME_REVIEW_APPROVED]);

        $this->actingAs($user)
            ->put(route('profile.update'), [
                'first_name' => 'Ada',
                'last_name' => 'Obi',
                'email' => $user->email,
                'phone' => $user->phone,
                'address' => '12 Marina Road',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(User::NAME_REVIEW_APPROVED, $user->fresh()->name_review);
    }

    /* =====================================================================
     | Admin customer editor
     =================================================================== */

    public function test_the_admin_can_correct_a_customer_name(): void
    {
        $admin = $this->superAdmin();
        $customer = $this->customer();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $customer), [
                'name' => 'Adaeze Obi',
                'email' => $customer->email,
                'phone' => $customer->phone,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.show', $customer));

        $customer->refresh();

        $this->assertSame('Adaeze Obi', $customer->name);
        // The halves are derived, so the account name on the customer's next
        // virtual account matches what the administrator typed.
        $this->assertSame('Adaeze', $customer->first_name);
        $this->assertSame('Obi', $customer->last_name);
    }

    public function test_the_admin_cannot_save_a_name_that_is_not_a_name(): void
    {
        /*
         * The admin editor goes through the same rule as sign-up. Without that, an
         * administrator could store a name the customer themselves would have been
         * refused — and the parts derived from it would be sent to Paystack as the
         * account name on a bank account.
         */
        $admin = $this->superAdmin();
        $customer = $this->customer();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $customer), [
                'name' => 'Ada1',
                'email' => $customer->email,
                'phone' => $customer->phone,
            ])
            ->assertSessionHasErrors('name');

        $this->assertSame('Ada Obi', $customer->fresh()->name);
    }

    public function test_the_admin_editor_normalises_whitespace(): void
    {
        $admin = $this->superAdmin();
        $customer = $this->customer();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $customer), [
                'name' => '  Ada   Obi Nwosu  ',
                'email' => $customer->email,
                'phone' => $customer->phone,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Ada Obi Nwosu', $customer->fresh()->name);
    }

    public function test_the_admin_saving_a_placeholder_name_flags_it_and_logs_it(): void
    {
        $admin = $this->superAdmin();
        $customer = $this->customer();

        $this->actingAs($admin)
            ->put(route('admin.users.update', $customer), [
                'name' => 'asdf asdf',
                'email' => $customer->email,
                'phone' => $customer->phone,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(User::NAME_REVIEW_PENDING, $customer->fresh()->name_review);

        $this->assertDatabaseHas('admin_logs', [
            'user_id' => $admin->id,
            'action' => 'update_user',
        ]);
    }

    public function test_the_admin_clearing_a_placeholder_name_clears_the_flag(): void
    {
        $admin = $this->superAdmin();
        $customer = $this->customer([
            'name' => 'asdf asdf',
            'first_name' => 'asdf',
            'last_name' => 'asdf',
            'name_review' => User::NAME_REVIEW_PENDING,
            'name_review_reason' => 'contains a placeholder word',
        ]);

        $this->actingAs($admin)
            ->put(route('admin.users.update', $customer), [
                'name' => 'Ada Obi',
                'email' => $customer->email,
                'phone' => $customer->phone,
            ])
            ->assertSessionHasNoErrors();

        $customer->refresh();

        $this->assertNull($customer->name_review);
        $this->assertNull($customer->name_review_reason);
    }

    /* =====================================================================
     | The review queue
     =================================================================== */

    public function test_an_admin_can_confirm_a_flagged_name(): void
    {
        $admin = $this->superAdmin();
        $customer = $this->customer([
            'name_review' => User::NAME_REVIEW_PENDING,
            'name_review_reason' => 'contains a placeholder word',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.users.name-review', $customer), ['decision' => 'approved'])
            ->assertRedirect();

        $this->assertSame(User::NAME_REVIEW_APPROVED, $customer->fresh()->name_review);

        $this->assertDatabaseHas('admin_logs', [
            'user_id' => $admin->id,
            'action' => 'review_customer_name',
        ]);
    }

    public function test_marking_a_name_as_not_genuine_does_not_touch_the_account(): void
    {
        /*
         * The integration boundary. A name decision is a records decision: it must
         * not suspend, block, sign out or otherwise alter the account, because the
         * heuristic that raised the flag is a guess. Acting on a customer is done
         * with the account controls that already exist, under their own audit.
         */
        $admin = $this->superAdmin();
        $customer = $this->customer([
            'name_review' => User::NAME_REVIEW_PENDING,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.users.name-review', $customer), ['decision' => 'rejected'])
            ->assertRedirect();

        $customer->refresh();

        $this->assertSame(User::NAME_REVIEW_REJECTED, $customer->name_review);
        $this->assertSame('active', $customer->status);
        $this->assertFalse($customer->is_blocked);
        $this->assertNotNull($customer->wallet);
    }

    public function test_the_review_endpoint_refuses_an_unknown_decision(): void
    {
        $admin = $this->superAdmin();
        $customer = $this->customer(['name_review' => User::NAME_REVIEW_PENDING]);

        $this->actingAs($admin)
            ->post(route('admin.users.name-review', $customer), ['decision' => 'delete-the-account'])
            ->assertSessionHasErrors('decision');

        $this->assertSame(User::NAME_REVIEW_PENDING, $customer->fresh()->name_review);
    }

    public function test_the_review_endpoint_needs_the_manage_users_permission(): void
    {
        // A moderator without `manage_users` cannot reach the customer list, and
        // must not be able to reach its actions either.
        $moderator = User::factory()->admin(['view_dashboard'])->create();
        $customer = $this->customer(['name_review' => User::NAME_REVIEW_PENDING]);

        $this->actingAs($moderator)
            ->post(route('admin.users.name-review', $customer), ['decision' => 'approved'])
            ->assertForbidden();

        $this->assertSame(User::NAME_REVIEW_PENDING, $customer->fresh()->name_review);
    }

    public function test_the_admin_list_can_filter_to_names_awaiting_review(): void
    {
        $admin = $this->superAdmin();

        $this->customer([
            'email' => 'flagged@example.com',
            'name' => 'asdf asdf',
            'first_name' => 'asdf',
            'last_name' => 'asdf',
            'name_review' => User::NAME_REVIEW_PENDING,
            'name_review_reason' => 'contains a placeholder word',
        ]);
        $this->customer(['email' => 'clean@example.com', 'name' => 'Ada Obi']);

        /*
         * Asserted on the email rather than on the name. The name is the thing under
         * question in this test, so an assertion on it would make the test fail for
         * the reason it is investigating rather than for the reason it checks.
         */
        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => 'name_review']))
            ->assertOk()
            ->assertSee('flagged@example.com', false)
            ->assertDontSee('clean@example.com', false);

        // And the flag, with the heuristic's reason, is on the row in the plain list.
        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('flagged@example.com', false)
            ->assertSee('Name review', false)
            ->assertSee('contains a placeholder word', false);
    }

    public function test_a_customer_cannot_review_their_own_name(): void
    {
        // `admin:manage_users` refuses a customer outright. Asserted as a 403 rather
        // than a redirect because that is what the permission middleware does, and a
        // test that accepted "not 200" would pass if the route started redirecting
        // customers into a page that half-worked.
        $customer = $this->customer(['name_review' => User::NAME_REVIEW_PENDING]);

        $this->actingAs($customer)
            ->post(route('admin.users.name-review', $customer), ['decision' => 'approved'])
            ->assertForbidden();

        $this->assertSame(User::NAME_REVIEW_PENDING, $customer->fresh()->name_review);
    }

    /* =====================================================================
     | Escaping in the admin UI
     =================================================================== */

    public function test_the_template_layer_escapes_a_name_in_text_and_in_an_attribute(): void
    {
        /*
         * The display side of the requirement, tested at the layer that does it.
         *
         * A name reaches Blade in two contexts: an HTML text node (`{{ $user->name }}`)
         * and an attribute or a JavaScript-read `data-` value — the admin customer
         * list puts it in a `title`, and other screens interpolate it into
         * `data-…-name` attributes that scripts read back. `{{ }}` escapes for all of
         * them, and that is the whole reason no view in this application uses
         * `{!! !!}` for a customer's name.
         *
         * ## Why this renders a template rather than fetching a page
         *
         * The first version of this test went through `admin.users.index` and
         * asserted on the rendered HTML — and passed for the *wrong reason* until it
         * turned out the customer row was not on the first page of the paginated
         * list at all, so it was asserting about a name that was never rendered.
         * A page-level assertion couples "is the escaping right?" to pagination,
         * ordering and filters; this couples it to nothing.
         *
         * The apostrophe is the right character because it is *allowed* in a name, so
         * it genuinely reaches the view, and it is the character that ends an HTML
         * attribute — a name breaking out of `title="…"` is exactly the failure being
         * guarded against.
         */
        $user = $this->customer(['name' => "Ada O'Brien"]);

        $html = (string) $this->blade(
            '<span title="{{ $user->name }}">{{ $user->name }}</span>',
            ['user' => $user]
        );

        $this->assertStringContainsString('title="', $html);
        // Rendered twice — once in the attribute, once as the text — and encoded
        // both times.
        $this->assertSame(
            2,
            preg_match_all('/Ada O(?:&#0?39;|&apos;)Brien/', $html),
            'The name should appear, encoded, in both the attribute and the text.'
        );
        $this->assertStringNotContainsString("Ada O'Brien", $html, 'The raw apostrophe must not survive into markup.');        $this->assertMatchesRegularExpression(
            '/Ada O(&#0?39;|&apos;)Brien/',
            $html,
            'The apostrophe in the customer name should have been HTML-encoded.'
        );
    }

    public function test_the_customer_page_escapes_the_name_when_it_renders_one(): void
    {
        /*
         * And the same guarantee on a real page, for a customer who is definitely on
         * it — asserted through the customer's own profile, which renders their name
         * in a form field rather than in a paginated row.
         */
        $user = $this->customer(['name' => "Ada O'Brien"]);

        $html = $this->actingAs($user)->get(route('profile.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('value="Ada O\'Brien"', $html);
        $this->assertMatchesRegularExpression('/Ada O(&#0?39;|&apos;)Brien/', $html);
    }

    public function test_a_customer_cannot_store_a_name_containing_markup(): void
    {
        // The first line of defence: the value is refused before it is stored, so
        // nothing downstream has to be the only thing standing between a name and
        // the page it is rendered on.
        $this->post('/register', $this->payload([
            'first_name' => '<script>',
            'last_name' => 'Obi',
        ]))->assertSessionHasErrors('first_name');

        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }

    public function test_a_name_cannot_carry_a_control_character_into_a_view(): void
    {
        // A right-to-left override in a name can reverse how the rest of the line
        // renders, which is how a name spoofs the row it appears in. It never
        // reaches the column: `normalise()` removes it.
        $this->post('/register', $this->payload([
            'first_name' => "\u{202E}Ada",
            'last_name' => 'Obi',
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', 'new@example.com')->firstOrFail();

        $this->assertSame('Ada', $user->first_name);
        $this->assertStringNotContainsString("\u{202E}", (string) $user->name);
    }

    /* =====================================================================
     | Existing accounts
     =================================================================== */

    public function test_existing_accounts_are_not_affected_by_the_new_column(): void
    {
        /*
         * The column is nullable with a null default and there is no backfill, so
         * every account that existed before it — and every account the heuristic
         * finds nothing odd about — reads as "no opinion" rather than as flagged.
         */
        $user = $this->customer();

        $this->assertNull($user->name_review);
        $this->assertFalse($user->nameReviewPending());
        $this->assertSame(0, User::awaitingNameReview()->count());
    }

    public function test_the_review_flag_is_not_a_suspension(): void
    {
        // `status` and `is_blocked` are the existing account-state controls, and the
        // name flag must not be a third one that behaves like them.
        $flagged = $this->customer([
            'name_review' => User::NAME_REVIEW_PENDING,
            'email' => 'flagged@example.com',
        ]);

        $this->assertFalse($flagged->isSuspended());
        $this->assertTrue($flagged->isAdmin() === false);

        // And such an account can still sign in.
        $this->post('/login', [
            'email' => 'flagged@example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($flagged);
    }
}
