<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** A complete, valid signup payload. Tests override single fields. */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'phone' => '08031234567',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => true,
        ], $overrides);
    }

    public function test_registration_screen_can_be_rendered()
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register()
    {
        $response = $this->post('/register', $this->payload());

        $this->assertAuthenticated();
        $response->assertRedirect(RouteServiceProvider::HOME);
    }

    public function test_registration_requires_accepting_the_terms()
    {
        $this->post('/register', $this->payload(['terms' => false]))
            ->assertSessionHasErrors('terms');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    /* =====================================================================
     | Phone number
     =================================================================== */

    public function test_registration_requires_a_phone_number(): void
    {
        /*
         * Required rather than optional, because two things need it and neither
         * works without it: Paystack will not issue a dedicated virtual account
         * to a customer with no phone, and support cannot reach an account that
         * has only an email address.
         */
        $data = $this->payload();
        unset($data['phone']);

        $this->post('/register', $data)->assertSessionHasErrors('phone');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_a_phone_number_is_stored_on_the_new_account(): void
    {
        $this->post('/register', $this->payload(['phone' => '08031234567']));

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertSame('08031234567', $user->phone);
    }

    /**
     * Formats a person might reasonably type or paste, each paired with the
     * number it means.
     *
     * A data provider rather than a loop inside one test, and that is not
     * stylistic: registration signs the new account in, so a second pass in the
     * same test is correctly redirected away by the `guest` middleware and
     * creates nothing. The first version of this was a loop and failed on the
     * second iteration for exactly that reason. A provider gives each format its
     * own test, and therefore its own database and session.
     *
     * @return array<string, array{string, string}>
     */
    public static function phoneFormats(): array
    {
        return [
            'international with spaces' => ['08031234567', '+234 803 123 4567'],
            'dashes' => ['08061234567', '0806-123-4567'],
            'spaces' => ['08071234567', '0807 123 4567'],
            'brackets' => ['08081234567', '(0808) 1234567'],
            'plain' => ['08091234567', '08091234567'],
        ];
    }

    /**
     * @dataProvider phoneFormats
     */
    public function test_a_phone_number_is_normalised_before_it_is_validated(string $canonical, string $typed): void
    {
        $email = $canonical . '@example.com';

        $this->post('/register', $this->payload([
            'email' => $email,
            'phone' => $typed,
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', $email)->first();

        $this->assertNotNull($user, "Registration with phone \"{$typed}\" created no account.");
        $this->assertSame(
            $canonical,
            $user->phone,
            "Typing \"{$typed}\" should store the same number as the canonical form."
        );
    }

    public function test_an_unusable_phone_number_is_rejected(): void
    {
        foreach (['12345', '0803123456', '080312345678', 'abcdefghijk', '09031234abc'] as $bad) {
            $this->post('/register', $this->payload([
                'email' => 'bad-' . md5($bad) . '@example.com',
                'phone' => $bad,
            ]))->assertSessionHasErrors('phone');
        }

        $this->assertGuest();
    }

    public function test_a_phone_number_already_in_use_is_rejected(): void
    {
        User::factory()->create(['phone' => '08031234567']);

        $this->post('/register', $this->payload(['phone' => '08031234567']))
            ->assertSessionHasErrors('phone');

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_the_register_page_asks_for_a_phone_number(): void
    {
        // The field has to be on the form, not only in the validation rules —
        // otherwise every signup fails with an error the visitor cannot resolve.
        $this->get('/register')
            ->assertOk()
            ->assertSee('name="phone"', false)
            ->assertSee('Phone number', false);
    }

    public function test_a_new_account_can_immediately_be_issued_a_virtual_account(): void
    {
        /*
         * The purpose of requiring it: a new customer is no longer blocked from
         * "fund by transfer". Before this, the phone was optional and Paystack
         * refused the account, which is what the "add your phone number" prompt
         * on that page exists for.
         */
        $this->post('/register', $this->payload(['phone' => '08031234567']));

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertTrue(
            app(\App\Services\PaystackService::class)->hasPhoneForDedicatedAccount($user),
            'A freshly registered account must satisfy the virtual-account phone requirement.'
        );
    }

    /* =====================================================================
     | First name and last name
     =================================================================== */

    public function test_the_register_page_asks_for_a_first_and_last_name(): void
    {
        /*
         * Both fields have to be on the form, not only in the validation rules —
         * otherwise every signup fails with an error the visitor cannot resolve.
         * The old single "Full name" field must be gone: leaving it in place
         * while requiring the halves is how a form ends up asking for a name
         * twice.
         */
        $this->get('/register')
            ->assertOk()
            ->assertSee('name="first_name"', false)
            ->assertSee('name="last_name"', false)
            ->assertSee('First name', false)
            ->assertSee('Last name', false)
            ->assertDontSee('Full name', false)
            ->assertDontSee('name="name"', false);
    }

    public function test_a_name_split_across_two_fields_is_stored_as_both_and_as_a_whole(): void
    {
        /*
         * Three values have to come out of two inputs, and all three are used:
         *
         *   * `first_name` and `last_name` are what Paystack is given — they
         *     build the account name printed on the customer's personal account
         *     number, and both are compulsory for a customer who is to be
         *     assigned one;
         *   * `name` is what the console, the dashboard greeting and the chat
         *     widgets read, and dozens of views still use it.
         *
         * Getting only one of them right is the failure mode this test exists to
         * catch: the account would be issued in one name and the customer
         * greeted with another.
         */
        $this->post('/register', $this->payload([
            'first_name' => 'Ada',
            'last_name' => 'Obi Nwosu',
        ]));

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertSame('Ada', $user->first_name);
        $this->assertSame('Obi Nwosu', $user->last_name);
        $this->assertSame('Ada Obi Nwosu', $user->name);
    }

    public function test_a_two_word_given_name_survives_registration(): void
    {
        /*
         * The reason this change exists. Splitting a single "Full name" on the
         * first space made the second word of a given name into part of the
         * surname — "Chidinma Nneka Okafor" was stored as first "Chidinma", last
         * "Nneka Okafor" — and the account name Paystack prints is the name a
         * payer reads in their own banking app before sending money. Asking for
         * the two halves is the only way to know them rather than infer them.
         */
        $this->post('/register', $this->payload([
            'first_name' => 'Chidinma Nneka',
            'last_name' => 'Okafor',
        ]));

        $user = User::where('email', 'test@example.com')->firstOrFail();

        $this->assertSame('Chidinma Nneka', $user->first_name);
        $this->assertSame('Okafor', $user->last_name);
        $this->assertSame('Chidinma Nneka Okafor', $user->name);
    }

    public function test_a_single_given_name_is_rejected_rather_than_invented(): void
    {
        // A last name cannot be guessed, and Paystack refuses an empty one, so
        // the customer is asked instead of being given a placeholder surname.
        $this->post('/register', $this->payload(['last_name' => '']))
            ->assertSessionHasErrors('last_name');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    /**
     * Names people actually have, each paired with the two halves it must
     * produce. A data provider so each has its own database, as above.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function realNames(): array
    {
        return [
            'apostrophe' => ["O'Brien", 'Ada', "O'Brien"],
            'hyphen' => ['Al-Amin', 'Yusuf', 'Al-Amin'],
            'initial' => ['J. R.', 'Tolkien', 'J. R.'],
            'accented' => ['Aïcha', 'Diallo', 'Aïcha'],
            'non-latin script' => ['Nguyễn', 'Minh', 'Nguyễn'],
            'diacritics' => ['Ọ̀ṣun', 'Adéwálé', 'Ọ̀ṣun'],
        ];
    }

    /**
     * @dataProvider realNames
     */
    public function test_names_with_punctuation_and_accents_are_accepted(string $last, string $first, string $expectedLast): void
    {
        /*
         * The field validates a name, not an identifier. Restricting it to ASCII
         * letters would reject "Aïcha" and "Nguyễn" — real people, told their own
         * name is invalid by a form that then prints a different one on the
         * account their money goes into. Apostrophes, hyphens and full stops are
         * allowed for the same reason: O'Brien, Al-Amin and J. R. all exist.
         */
        $email = md5($first . $last) . '@example.com';

        $this->post('/register', $this->payload([
            'email' => $email,
            'first_name' => $first,
            'last_name' => $last,
        ]))->assertSessionHasNoErrors();

        $user = User::where('email', $email)->first();

        $this->assertNotNull($user, "Registration with the name \"{$first} {$last}\" created no account.");
        $this->assertSame($first, $user->first_name);
        $this->assertSame($expectedLast, $user->last_name);
    }

    public function test_a_name_containing_a_digit_is_rejected(): void
    {
        // The account name is read by a human being deciding whether to send
        // money. A digit or an emoji in it is not a name.
        foreach (['Ad4', 'Ada2Obi', '<script>', 'Ada 😀'] as $bad) {
            $this->post('/register', $this->payload([
                'email' => 'bad-name-' . md5($bad) . '@example.com',
                'first_name' => $bad,
            ]))->assertSessionHasErrors('first_name');
        }

        $this->assertGuest();
    }

    public function test_a_request_carrying_only_a_whole_name_still_registers(): void
    {
        /*
         * The previous form posted one `name` field. A visitor who already has
         * that page open — or a cached copy served by a CDN — posts it, and
         * rejecting them with "The first name field is required" for a form this
         * very site showed them is a hostile way to lose a sign-up. The halves
         * are derived from the whole name instead, and every derived value is
         * stored so nothing downstream has to guess again.
         */
        $this->post('/register', [
            'name' => 'Ada Obi Nwosu',
            'email' => 'legacy@example.com',
            'phone' => '08031234567',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => true,
        ])->assertSessionHasNoErrors();

        $user = User::where('email', 'legacy@example.com')->firstOrFail();

        $this->assertSame('Ada', $user->first_name);
        $this->assertSame('Obi Nwosu', $user->last_name);
        $this->assertSame('Ada Obi Nwosu', $user->name);
    }
}
