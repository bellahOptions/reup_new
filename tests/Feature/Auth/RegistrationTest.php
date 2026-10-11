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
            'name' => 'Test User',
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
}
