<?php

namespace Tests\Feature;

use App\Models\Transactions;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletLedger;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The customer's dedicated virtual account (pay-in account).
 *
 * Funding by transfer already worked — Paystack issues one permanent NUBAN per
 * customer and the existing webhook credits the wallet when money lands on it.
 * What was missing was a surface: the account was only ever shown as a step
 * inside "fund a specific amount by transfer", so a customer who simply wanted
 * an account number to save in their banking app had no way to get one.
 *
 * Three claims matter here, and they fail independently:
 *
 *   1. the account is issued **once** — Paystack rejects a second assignment for
 *     the same customer, so issuing twice would surface a provider error to a
 *     customer who did nothing wrong;
 *   2. a failure to issue is explained rather than 500'd, because the other
 *     funding routes still work and the customer needs to be told so;
 *   3. the account is the customer's own — an admin cannot reach the page at
 *     all, and one customer can never be shown another's account.
 */
class VirtualAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * The dedicated-account provider list is cached for a day, and
         * `RefreshDatabase` does not clear the cache between tests — so a value
         * cached by one test would silently answer another's question. Flushed
         * here rather than in the tests that care, because every test in this
         * file goes through `dvaBank()`.
         */
        Cache::flush();

        config([
            'services.paystack.secret_key' => 'sk_test_dva',
            'services.paystack.dva_bank' => 'wema-bank',
            /*
             * A real `sk_test_` key cannot issue a personal account at all —
             * every partner bank answers "not available in test mode" — and the
             * controller reports that instead of calling the gateway. The flag
             * lets these tests exercise the assignment path with a test key
             * without weakening the production check.
             */
            'services.paystack.assume_live_dva' => true,
        ]);
    }

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            // Present so `ensureCustomer()` does not have to create a Paystack
            // customer first; that path is covered by PaystackSettlementTest.
            'paystack_customer_code' => 'CUS_test_1',
            /*
             * A phone number is mandatory for a virtual account — Paystack will
             * not attach one to a customer record without it — so every test
             * about the happy path needs an account that has one. The tests that
             * care about its absence pass `phone => null` explicitly.
             */
            'phone' => '08031234567',
        ], $attributes));
    }

    /**
     * The empty "you have none" answer to the read-back.
     *
     * `dedicatedAccount()` asks `GET /dedicated_account?customer=…` *before* it
     * creates anything, so that a customer whose account already exists on
     * Paystack's side — but not on ours — is recovered rather than being shown
     * "you already have one". Every test of the create path therefore has to
     * answer that question; this is the shape Paystack returns when the customer
     * genuinely has no account.
     *
     * @return array<string,mixed>
     */
    private function fakeNoExistingAccount(): array
    {
        return [
            'api.paystack.co/dedicated_account?*' => Http::response([
                'status' => true,
                'message' => 'Dedicated accounts retrieved',
                'data' => [],
            ], 200),
        ];
    }

    /**
     * The provider list, in the shape Paystack returns it.
     *
     * Its own helper because `/dedicated_account/available_providers` is *also*
     * matched by the `/dedicated_account*` wildcard the create call needs.
     * Laravel resolves the first matching fake, so the specific pattern has to be
     * declared first — and getting that wrong is silent: the provider list is
     * answered with an account object, `dvaBank()` sees an empty list, and every
     * create is refused for a reason the test never mentions.
     *
     * @return array<string,mixed>
     */
    private function providerListResponse(): array
    {
        return [
            'status' => true,
            'message' => 'Providers retrieved',
            'data' => [
                ['slug' => 'wema-bank', 'name' => 'Wema Bank'],
                ['slug' => 'titan-paystack', 'name' => 'Titan Paystack'],
            ],
        ];
    }

    /** `PUT /customer/{code}` — the name and phone sync, plus the provider list. */
    private function fakePhoneSync(): void
    {
        Http::fake([
            'api.paystack.co/dedicated_account/available_providers*' => Http::response($this->providerListResponse(), 200),
            'api.paystack.co/customer/*' => Http::response([
                'status' => true,
                'message' => 'Customer updated',
                'data' => ['customer_code' => 'CUS_test_1'],
            ], 200),
        ] + $this->fakeNoExistingAccount());
    }

    /**
     * The shape Paystack returns for the calls that make up an assignment.
     *
     * The provider list is answered before the read-back, the read-back before
     * the create: all three are matched by overlapping URL prefixes, and
     * `Http::fake` resolves the first matching pattern. That is exactly why these
     * fakes are declared in one place rather than repeated per test.
     */
    private function fakeAssignment(string $number = '9876543210'): void
    {
        Http::fake([
            // Declared first: see providerListResponse().
            'api.paystack.co/dedicated_account/available_providers*' => Http::response($this->providerListResponse(), 200),
            // The read-back that precedes the assignment.
            'api.paystack.co/dedicated_account?*' => Http::response([
                'status' => true,
                'message' => 'Dedicated accounts retrieved',
                'data' => [],
            ], 200),
            // The name and phone sync.
            'api.paystack.co/customer/*' => Http::response([
                'status' => true,
                'message' => 'Customer updated',
                'data' => ['customer_code' => 'CUS_test_1'],
            ], 200),
            // The assignment itself.
            'api.paystack.co/dedicated_account*' => Http::response([
                'status' => true,
                'message' => 'Assign dedicated account in progress',
                'data' => [
                    'id' => 253,
                    'account_number' => $number,
                    'account_name' => 'REUP / TEST CUSTOMER',
                    'bank' => ['name' => 'Wema Bank', 'id' => 20, 'slug' => 'wema-bank'],
                    'active' => true,
                    'currency' => 'NGN',
                ],
            ], 200),
        ]);
    }

    /* =====================================================================
     | Issuing the account
     | =================================================================== */

    public function test_visiting_the_page_issues_an_account_once(): void
    {
        $customer = $this->customer();
        $this->fakeAssignment();

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('9876543210', false)
            ->assertSee('Wema Bank', false)
            ->assertSee('REUP / TEST CUSTOMER', false);

        $this->assertSame('9876543210', $customer->fresh()->dva_account_number);
        $this->assertSame('Wema Bank', $customer->fresh()->dva_bank_name);
        $this->assertSame('wema-bank', $customer->fresh()->dva_bank_slug);
        $this->assertSame(253, $customer->fresh()->dva_account_id);
        $this->assertNotNull($customer->fresh()->dva_created_at);

        /*
         * Four requests, and each one is load-bearing:
         *
         *   1. `GET /dedicated_account?customer=…` — read back first, so a
         *      customer whose account already exists on Paystack's side is
         *      recovered instead of being refused;
         *   2. `PUT /customer/{code}` — the name and phone, without which the
         *      assignment is refused for a record created during an earlier card
         *      payment;
         *   3. `POST /dedicated_account` — the assignment;
         *   4. the same GET again, which is what turns "already assigned" into
         *      the success it is.
         *
         * Asserted by count rather than by "no error" so a future change that
         * drops one of them is caught here rather than in production.
         */
        Http::assertSentCount(4);
    }

    public function test_the_assignment_sends_the_customers_first_and_last_name(): void
    {
        /*
         * Paystack's own error reference is explicit: "first_name, last_name are
         * required in request body and must not be empty". They are what the
         * account *name* of the customer's personal account number is built
         * from — the name a payer reads in their banking app before sending
         * money — so they are sent on the assignment call as well as on the
         * customer record, and taken from the fields the customer typed rather
         * than guessed from a whole name.
         */
        $customer = $this->customer([
            'first_name' => 'Ọ̀ṣun',
            'last_name' => "O'Brien-Smith",
            'name' => "Ọ̀ṣun O'Brien-Smith",
        ]);

        $this->fakeAssignment();

        $this->actingAs($customer)->get(route('wallet.virtual-account'))->assertOk();

        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/dedicated_account')
                && ($request['first_name'] ?? null) === 'Ọ̀ṣun'
                && ($request['last_name'] ?? null) === "O'Brien-Smith"
                && ($request['preferred_bank'] ?? null) === 'wema-bank';
        });

        // And on the customer record, which is where Paystack reads them from.
        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/customer/CUS_test_1')
                && ($request['first_name'] ?? null) === 'Ọ̀ṣun'
                && ($request['last_name'] ?? null) === "O'Brien-Smith"
                && ($request['phone'] ?? null) === '2348031234567';
        });
    }

    public function test_the_account_is_recovered_when_paystack_already_has_one(): void
    {
        /*
         * The read-back exists for this: the customer holds an account at the
         * gateway and our row lost it (a rolled-back deploy, or an account
         * assigned by hand in the Paystack dashboard). Asking for a second one
         * would be refused with a message about a duplicate; reading it back
         * instead turns a lost row into a repaired one.
         */
        $customer = $this->customer();

        Http::fake([
            'api.paystack.co/dedicated_account?*' => Http::response([
                'status' => true,
                'data' => [[
                    'id' => 99,
                    'account_number' => '5555000011',
                    'account_name' => 'REUP / RECOVERED',
                    'bank' => ['name' => 'Wema Bank', 'slug' => 'wema-bank'],
                    'active' => true,
                    'currency' => 'NGN',
                ]],
            ], 200),
            'api.paystack.co/customer/*' => Http::response(['status' => true, 'data' => []], 200),
        ]);

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('5555000011', false);

        $this->assertSame('5555000011', $customer->fresh()->dva_account_number);

        // Nothing was created: the only write to Paystack was the details sync.
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_the_configured_bank_is_checked_against_the_providers_this_account_has(): void
    {
        /*
         * The documentation names Wema Bank and Titan Paystack, but which of
         * them an integration may use is Paystack's decision and is returned by
         * `GET /dedicated_account/available_providers`. Asking a bank that is not
         * on that list fails at the last step of a flow the customer has already
         * waited through, with a message about a *bank* for a problem in our
         * configuration.
         */
        \Illuminate\Support\Facades\Cache::flush();

        config(['services.paystack.dva_bank' => 'wema-bank']);

        Http::fake([
            'api.paystack.co/dedicated_account/available_providers' => Http::response([
                'status' => true,
                'data' => [
                    ['slug' => 'wema-bank', 'name' => 'Wema Bank'],
                    ['slug' => 'titan-paystack', 'name' => 'Titan Paystack'],
                ],
            ], 200),
        ]);

        $this->assertSame('wema-bank', app(\App\Services\PaystackService::class)->dvaBank());
    }

    public function test_a_bank_this_account_does_not_have_is_refused_with_the_available_list(): void
    {
        \Illuminate\Support\Facades\Cache::flush();

        config(['services.paystack.dva_bank' => 'first-bank']);

        Http::fake([
            'api.paystack.co/dedicated_account/available_providers' => Http::response([
                'status' => true,
                'data' => [
                    ['slug' => 'wema-bank', 'name' => 'Wema Bank'],
                    ['slug' => 'titan-paystack', 'name' => 'Titan Paystack'],
                ],
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/wema-bank, titan-paystack/');

        app(\App\Services\PaystackService::class)->dvaBank();
    }

    public function test_an_unreachable_provider_list_does_not_block_the_assignment(): void
    {
        /*
         * The provider list is a *diagnostic*. Refusing to try because a
         * diagnostic call failed would turn a transient gateway problem into an
         * outage of a feature that would have worked — so the configured bank is
         * passed through and the assignment call is the authority.
         */
        \Illuminate\Support\Facades\Cache::flush();

        config(['services.paystack.dva_bank' => 'wema-bank']);

        Http::fake([
            'api.paystack.co/dedicated_account/available_providers' => Http::response(['status' => false], 500),
        ]);

        $this->assertSame('wema-bank', app(\App\Services\PaystackService::class)->dvaBank());
    }

    public function test_access_denied_is_reported_as_the_feature_being_disabled(): void
    {
        /*
         * Paystack's error reference for this endpoint: "Access Denied — you need
         * to confirm that you've been activated for this feature. Reach out to
         * support." That is the one failure with an action attached, and it names
         * a *feature*, not a bank — so it is raised as a distinguishable message
         * rather than being relayed as "we could not generate a bank account",
         * which reads like a bug in us.
         */
        $customer = $this->customer();

        Http::fake([
            'api.paystack.co/dedicated_account?*' => Http::response(['status' => true, 'data' => []], 200),
            'api.paystack.co/customer/*' => Http::response(['status' => true, 'data' => []], 200),
            'api.paystack.co/dedicated_account*' => Http::response([
                'status' => false,
                'message' => 'Access Denied',
            ], 403),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not enabled on this Paystack business/');

        app(\App\Services\PaystackService::class)->dedicatedAccount($customer);
    }

    public function test_the_customer_never_sees_the_access_denied_wording(): void
    {
        // The customer gets the same sanitised message as any other provider
        // failure; the diagnostic belongs in the log and in `paystack:diagnose-dva`.
        $customer = $this->customer();

        Http::fake([
            'api.paystack.co/dedicated_account?*' => Http::response(['status' => true, 'data' => []], 200),
            'api.paystack.co/customer/*' => Http::response(['status' => true, 'data' => []], 200),
            'api.paystack.co/dedicated_account*' => Http::response([
                'status' => false,
                'message' => 'Access Denied',
            ], 403),
        ]);

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertDontSee('Access Denied', false)
            ->assertDontSee('Paystack', false);
    }

    public function test_a_second_visit_does_not_ask_paystack_again(): void
    {
        /*
         * The important one. Paystack refuses a second dedicated account for a
         * customer, so a page that re-requested on every load would show a
         * provider error to a returning customer who had done nothing wrong.
         */
        $customer = $this->customer();
        $this->fakeAssignment();

        $this->actingAs($customer)->get(route('wallet.virtual-account'))->assertOk();
        $this->actingAs($customer)->get(route('wallet.virtual-account'))->assertOk();
        $this->actingAs($customer)->get(route('wallet.virtual-account'))->assertOk();

        /*
         * Still exactly four — the read-back, the details sync, the one
         * assignment, and the read-back that confirms it — across three visits.
         * The account exists after the first, so the second and third return it
         * from the stored row without touching the gateway at all. A count that
         * grew with the visits would mean the assignment (or the sync) had become
         * per-page-load.
         */
        Http::assertSentCount(4);
    }

    public function test_a_customer_who_already_has_an_account_never_calls_paystack(): void
    {
        $customer = $this->customer([
            'dva_account_number' => '1111222233',
            'dva_bank_name' => 'Wema Bank',
            'dva_account_name' => 'REUP / EXISTING',
        ]);

        Http::fake(['*' => Http::response(['status' => false], 500)]);

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('1111222233', false);

        Http::assertNothingSent();
    }

    public function test_the_form_post_issues_the_account_and_redirects_back(): void
    {
        // The page works with JavaScript disabled, and so does the retry button.
        $customer = $this->customer();
        $this->fakeAssignment();

        $this->actingAs($customer)
            ->post(route('wallet.virtual-account.store'))
            ->assertRedirect(route('wallet.virtual-account'));

        $this->assertSame('9876543210', $customer->fresh()->dva_account_number);
    }

    /* =====================================================================
     | Failure is explained, not fatal
     | =================================================================== */

    public function test_a_provider_failure_shows_a_message_and_keeps_the_page(): void
    {
        $customer = $this->customer();

        // The real shape of the common failure: personal accounts are not
        // enabled on the Paystack account being used.
        Http::fake([
            'api.paystack.co/dedicated_account?*' => Http::response(['status' => true, 'data' => []], 200),
            'api.paystack.co/customer/*' => Http::response(['status' => true, 'data' => []], 200),
            'api.paystack.co/dedicated_account*' => Http::response([
                'status' => false,
                'message' => 'wema-bank is not available in test mode',
            ], 400),
        ]);

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('We could not issue your personal account number', false);

        // Nothing half-written: no account number, and the customer can retry.
        $this->assertNull($customer->fresh()->dva_account_number);
    }

    public function test_the_raw_provider_message_is_never_shown(): void
    {
        // Provider text describes our configuration. It is logged, not printed.
        $customer = $this->customer();

        Http::fake([
            'api.paystack.co/dedicated_account*' => Http::response([
                'status' => false,
                'message' => 'Invalid key sk_live_leaked_fragment',
            ], 401),
        ]);

        $response = $this->actingAs($customer)->get(route('wallet.virtual-account'))->assertOk();

        $response->assertDontSee('sk_live_leaked_fragment', false);
        $response->assertDontSee('Invalid key', false);
    }

    public function test_an_unconfigured_gateway_explains_itself_without_an_error(): void
    {
        config(['services.paystack.secret_key' => '']);

        $customer = $this->customer();

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('not available on this deployment yet', false);

        Http::assertNothingSent();
    }

    /* =====================================================================
     | A phone number is required, and it is the customer's to supply
     ==================================================================== */

    public function test_an_account_without_a_phone_is_told_to_add_one_and_no_request_is_made(): void
    {
        /*
         * The bug this covers: Paystack refuses to attach a virtual account to a
         * customer record with no phone — "Customer phone number is required" —
         * and the raw message surfaced as "we could not issue your personal
         * account number". A dead end, for something the customer fixes in ten
         * seconds.
         *
         * Nothing is spent on the gateway either: the condition is visible before
         * the call, so asking is pointless.
         */
        $customer = $this->customer(['phone' => null]);

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            /*
             * Matched on the call to action, not the heading: the heading is
             * chosen by a Blade conditional, so it is not present verbatim in the
             * compiled view and `assertSee` on it would pass or fail for reasons
             * unrelated to what the customer sees.
             */
            ->assertSee('Add your phone number', false)
            ->assertSee(route('profile.index'), false);

        // Never reaches the provider, and never shows its wording.
        Http::assertNothingSent();
        $this->assertNull($customer->fresh()->dva_account_number);
    }

    public function test_the_phone_prompt_does_not_leak_the_provider_message(): void
    {
        $customer = $this->customer(['phone' => null]);

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $response = $this->actingAs($customer)->get(route('wallet.virtual-account'))->assertOk();

        $response->assertDontSee('Customer phone number is required', false);
        $response->assertDontSee('Could not generate a bank account', false);
    }

    public function test_the_form_post_also_reports_the_phone_requirement(): void
    {
        // The retry button must not turn an actionable state into a generic one.
        $customer = $this->customer(['phone' => null]);

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->actingAs($customer)
            ->post(route('wallet.virtual-account.store'))
            ->assertRedirect(route('wallet.virtual-account'))
            ->assertSessionHas('warning_code');

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('Add your phone number', false);

        Http::assertNothingSent();
    }

    public function test_a_customer_with_a_phone_gets_an_account_as_before(): void
    {
        $customer = $this->customer();
        $this->fakeAssignment();

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('9876543210', false)
            ->assertDontSee('Add your phone number to get your account number', false);
    }

    /* =====================================================================
     | The phone is pushed to an existing customer record
     =================================================================== */

    public function test_the_phone_is_attached_to_the_existing_provider_customer_first(): void
    {
        /*
         * The real shape of the bug. A customer record created during an earlier
         * card payment has no phone — card funding does not need one — and
         * `ensureCustomer()` returns the stored code without ever revisiting the
         * record, so the gap was permanent and the failure surfaced much later,
         * on a different page, as a provider error.
         *
         * The number must therefore be sent *before* the assignment is requested.
         */
        $customer = $this->customer(['phone' => '08031234567']);

        $this->fakeAssignment();

        $this->actingAs($customer)->get(route('wallet.virtual-account'))->assertOk();

        /*
         * The provider's own record is what has to be fixed, and it has to be
         * fixed *before* the assignment is asked for. Checked by position rather
         * than by "a PUT was sent": a test that only asserted the call happened
         * would pass even if the sync had drifted to after the assignment — where
         * it is useless.
         *
         * The provider list is read first (it is cached for a day, so this is one
         * request for the whole deployment), then the read-back, then the details,
         * then the assignment.
         */
        $sent = [];
        Http::assertSent(function ($request) use (&$sent) {
            $sent[] = $request->method() . ' ' . strtok($request->url(), '?');

            return true;
        });

        $this->assertSame('GET https://api.paystack.co/dedicated_account/available_providers', $sent[0] ?? '');
        $this->assertSame('GET https://api.paystack.co/dedicated_account', $sent[1] ?? '');
        $this->assertSame('PUT https://api.paystack.co/customer/CUS_test_1', $sent[2] ?? '');
        $this->assertSame('POST https://api.paystack.co/dedicated_account', $sent[3] ?? '');
    }

    public function test_the_phone_is_sent_in_international_form(): void
    {
        /*
         * The local form (`0803…`) is accepted when creating a customer but
         * leaves a record the assignment endpoint rejects, which is a confusing
         * failure a long way from its cause. `formatted_phone` is the one
         * conversion rule, so both paths agree.
         */
        $customer = $this->customer(['phone' => '08031234567']);

        $this->fakeAssignment();

        $this->actingAs($customer)->get(route('wallet.virtual-account'))->assertOk();

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/customer/CUS_test_1')
                && ($request['phone'] ?? null) === '2348031234567';
        });
    }

    public function test_a_failed_details_sync_does_not_break_the_page(): void
    {
        /*
         * A gateway that refuses the update must not turn a working page into an
         * error: the assignment call is what decides the outcome. The one thing
         * that must *not* happen is the record being stamped as synced — a
         * refused update recorded as done would leave the customer's Paystack
         * record incomplete forever while every later page load skipped the call
         * that would have fixed it.
         */
        $customer = $this->customer();

        Http::fake([
            'api.paystack.co/dedicated_account/available_providers*' => Http::response($this->providerListResponse(), 200),
            'api.paystack.co/dedicated_account?*' => Http::response(['status' => true, 'data' => []], 200),
            'api.paystack.co/customer/*' => Http::response(['status' => false, 'message' => 'nope'], 400),
            'api.paystack.co/dedicated_account*' => Http::response([
                'status' => true,
                'data' => [
                    'account_number' => '9876543210',
                    'account_name' => 'REUP / TEST CUSTOMER',
                    'bank' => ['name' => 'Wema Bank'],
                    'active' => true,
                    'currency' => 'NGN',
                ],
            ], 200),
        ]);

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('9876543210', false);

        $this->assertNull(
            $customer->fresh()->paystack_details_synced_at,
            'A refused sync must not be stamped as done, or the record stays incomplete forever.'
        );
    }

    public function test_a_customer_whose_details_are_already_synced_is_not_updated_again(): void
    {
        /*
         * The cost control. Without the stamp, every load of the virtual-account
         * page would pay for a `PUT /customer/{code}` that changes nothing —
         * for a page customers reload while waiting for a transfer to land.
         */
        $customer = $this->customer(['paystack_details_synced_at' => now()]);
        $this->fakeAssignment();

        $this->actingAs($customer)->get(route('wallet.virtual-account'))->assertOk();

        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');

        // The read-back, the assignment and the confirming read-back still happened.
        Http::assertSentCount(3);
    }

    /* =====================================================================
     | Saving a phone number is what makes the retry work
     ==================================================================== */

    public function test_saving_a_phone_number_syncs_it_to_the_provider(): void
    {
        /*
         * Without this, the customer follows the prompt, saves their number,
         * comes back — and is told the same thing again, because the provider's
         * copy of the record still has no phone. That reads as the profile form
         * not saving.
         */
        $customer = $this->customer(['phone' => '08031234567']);

        Http::fake([
            'api.paystack.co/customer/*' => Http::response(['status' => true, 'data' => []], 200),
        ]);

        $this->actingAs($customer)
            ->put(route('profile.update'), [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => '08031234567',
            ])
            ->assertSessionHasNoErrors();

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/customer/CUS_test_1')
                && ($request['phone'] ?? null) === '2348031234567';
        });
    }

    public function test_a_profile_save_without_a_provider_customer_makes_no_request(): void
    {
        // The common case: most saves happen before any card payment, so there is
        // no customer code and nothing to sync.
        $customer = User::factory()->create([
            'phone' => '08031234567',
            'paystack_customer_code' => null,
        ]);

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->actingAs($customer)
            ->put(route('profile.update'), [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => '08031234567',
            ])
            ->assertSessionHasNoErrors();

        Http::assertNothingSent();
    }

    public function test_a_test_key_reports_itself_instead_of_blaming_a_bank(): void
    {
        /*
         * Paystack refuses every partner bank in test mode with
         * `"<bank> is not available in test mode"` — proven for all five by
         * `paystack:diagnose-dva`. Relaying that sends whoever investigates it
         * looking for a bank misconfiguration that does not exist, and the
         * customer gets the same "we could not issue your account" they get for
         * every other cause.
         *
         * So the deployment says what is actually wrong, and says it without
         * spending a gateway request on a question whose answer is known.
         */
        config(['services.paystack.assume_live_dva' => false]);

        $customer = $this->customer();

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('need a live Paystack key', false);

        Http::assertNothingSent();
        $this->assertNull($customer->fresh()->dva_account_number);
    }

    public function test_the_customers_own_phone_gap_is_reported_before_a_deployment_one(): void
    {
        /*
         * With a test key *and* no phone, the customer is asked for the phone.
         * "This deployment has no live key" is true and useless to them: it is
         * the one cause of the two they cannot do anything about.
         */
        config(['services.paystack.assume_live_dva' => false]);

        $customer = $this->customer(['phone' => null]);

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('Add your phone number', false)
            ->assertDontSee('need a live Paystack key', false);
    }

    /* =====================================================================
     | The account belongs to one customer
     =================================================================== */

    public function test_an_administrator_cannot_reach_the_page(): void
    {
        // An administrator is not a customer, and a pay-in account is a
        // customer's funding instrument.
        $admin = User::factory()->superAdmin()->create();

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->actingAs($admin)
            ->get(route('wallet.virtual-account'))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertNull($admin->fresh()->dva_account_number);
    }

    public function test_a_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('wallet.virtual-account'))->assertRedirect(route('login'));
    }

    public function test_the_account_shown_is_the_authenticated_customers_own(): void
    {
        $mine = $this->customer([
            'dva_account_number' => '5555666677',
            'dva_bank_name' => 'Wema Bank',
            'dva_account_name' => 'REUP / MINE',
        ]);

        $theirs = $this->customer([
            'paystack_customer_code' => 'CUS_test_2',
            'dva_account_number' => '9999888877',
            'dva_bank_name' => 'Wema Bank',
            'dva_account_name' => 'REUP / THEIRS',
        ]);

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->actingAs($mine)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('5555666677', false)
            ->assertDontSee('9999888877', false);

        $this->assertSame('9999888877', $theirs->fresh()->dva_account_number);
    }

    /* =====================================================================
     | The page's supporting content
     | =================================================================== */

    public function test_transfers_already_received_are_listed(): void
    {
        $customer = $this->customer([
            'dva_account_number' => '1231231234',
            'dva_bank_name' => 'Wema Bank',
            'dva_account_name' => 'REUP / LISTED',
        ]);

        // A settled bank transfer, in the shape the webhook writes.
        Transactions::create([
            'user_id' => $customer->id,
            'reference' => 'DVA-REF-1',
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Bank transfer — GTBank',
            'amount' => 2500,
            'service_fee' => 0,
            'total_amount' => 2500,
            'provider' => 'paystack',
            'payment_method' => 'bank_transfer',
            'payment_status' => 'success',
            'status' => 'success',
        ]);

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('DVA-REF-1', false)
            ->assertSee('2,500.00', false);
    }

    public function test_the_link_is_reachable_from_the_wallet(): void
    {
        // A page nobody can find is not a feature. Both entry points are
        // asserted, since either could be removed in a later refactor.
        $customer = $this->customer([
            'dva_account_number' => '4321432143',
            'dva_bank_name' => 'Wema Bank',
            'dva_account_name' => 'REUP / LINKED',
        ]);

        app(WalletService::class)->credit(
            user: $customer,
            amount: \App\Support\Money::fromNaira('1000'),
            countsAsFunding: true,
            entryType: WalletLedger::ENTRY_CREDIT,
            description: 'Test fixture',
        );

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->actingAs($customer)
            ->get(route('wallet.index'))
            ->assertOk()
            ->assertSee(route('wallet.virtual-account'), false);

        $this->actingAs($customer)
            ->get(route('wallet.fund'))
            ->assertOk()
            ->assertSee(route('wallet.virtual-account'), false);
    }

    public function test_the_wallet_balance_is_shown(): void
    {
        $customer = $this->customer([
            'dva_account_number' => '7777777777',
            'dva_bank_name' => 'Wema Bank',
            'dva_account_name' => 'REUP / BALANCE',
        ]);

        app(WalletService::class)->credit(
            user: $customer,
            amount: \App\Support\Money::fromNaira('7350.50'),
            countsAsFunding: true,
            entryType: WalletLedger::ENTRY_CREDIT,
            description: 'Test fixture',
        );

        Http::fake(['*' => Http::response(['status' => true], 200)]);

        $this->actingAs($customer)
            ->get(route('wallet.virtual-account'))
            ->assertOk()
            ->assertSee('7,350.50', false);

        $this->assertSame('7350.50', (string) Wallet::where('user_id', $customer->id)->value('balance'));
    }
}
