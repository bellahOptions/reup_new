<?php

namespace Tests\Feature;

use App\Jobs\LifecycleSendMessage;
use App\Lifecycle\HtmlSanitizer;
use App\Lifecycle\LifecycleConditionEvaluator;
use App\Lifecycle\LifecycleDispatcher;
use App\Lifecycle\LifecycleRecorder;
use App\Lifecycle\LifecycleScheduler;
use App\Lifecycle\LifecycleTrigger;
use App\Lifecycle\LifecycleVariables;
use App\Mail\LifecycleMessageMail;
use App\Models\LifecycleAutomation;
use App\Models\LifecycleConsent;
use App\Models\LifecycleEmailTemplate;
use App\Models\LifecycleEvent;
use App\Models\LifecycleMessage;
use App\Models\LifecycleSettings;
use App\Models\LifecycleSuppression;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The lifecycle messaging module.
 *
 * Grouped by the claim each test protects, because the module's failure modes are not
 * variations of one bug — they are separate ways to send the wrong thing, to the wrong
 * person, at the wrong time, or to claim to have sent something that was never
 * accepted.
 */
class LifecycleMessagingTest extends TestCase
{
    use RefreshDatabase;

    private function customer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'name' => 'Ada Obi',
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'email' => 'ada@example.com',
            /*
             * Unique per account. A fixed code collides on the unique index as soon as a
             * test creates two customers, and the failure reads as an unrelated SQL
             * error rather than as a fixture problem.
             */
            'referral_code' => 'REUP' . strtoupper(bin2hex(random_bytes(3))),
        ], $attributes));
    }

    private function template(array $attributes = []): LifecycleEmailTemplate
    {
        return LifecycleEmailTemplate::create(array_merge([
            'key' => 'welcome',
            'name' => 'Welcome',
            'subject' => 'Welcome to ReUp! Let\'s get you started.',
            'preview_text' => 'Your ReUp journey starts here.',
            'html_body' => '<p>Hi {{ first_name }}, welcome to {{ app_name }}.</p>',
            'category' => LifecycleEmailTemplate::CATEGORY_MARKETING,
            'from_name' => 'Olumuyiwa from ReUp',
            'status' => LifecycleEmailTemplate::STATUS_ACTIVE,
        ], $attributes));
    }

    private function automation(LifecycleEmailTemplate $template, array $attributes = []): LifecycleAutomation
    {
        return LifecycleAutomation::create(array_merge([
            'key' => 'welcome',
            'name' => 'Welcome',
            'trigger_event' => LifecycleTrigger::USER_REGISTERED,
            'template_id' => $template->getKey(),
            'conditions' => [],
            'condition_logic' => 'and',
            'delay_minutes' => 0,
            'limit_per_run' => 200,
            'limit_per_user_per_day' => 1,
            'status' => LifecycleAutomation::STATUS_ACTIVE,
        ], $attributes));
    }

    private function dispatcher(): LifecycleDispatcher
    {
        return app(LifecycleDispatcher::class);
    }

    private function recorder(): LifecycleRecorder
    {
        return app(LifecycleRecorder::class);
    }

    private function scheduler(): LifecycleScheduler
    {
        return app(LifecycleScheduler::class);
    }

    /* =====================================================================
     | 1. The welcome email and its sender identity
     =================================================================== */

    public function test_the_welcome_email_is_sent_with_the_configured_sender_display_name(): void
    {
        /*
         * The brief names the sender: "Olumuyiwa from ReUp", on the address ReUp has
         * verified. The name is configuration and the address is `MAIL_FROM_ADDRESS` —
         * nothing here invents an address, because a send with an unverified From is
         * rejected by most providers and damages the domain every other email uses.
         */
        Mail::fake();

        config(['mail.from.address' => 'no-reply@reup.com.ng', 'mail.from.name' => 'ReUp']);

        $template = $this->template(['from_name' => 'Olumuyiwa from ReUp']);
        $automation = $this->automation($template);
        $user = $this->customer();

        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'welcome-1',
            'scheduled_for' => now(),
        ]);

        $this->dispatcher()->attempt($message);

        /*
         * Asserted on the built mailable's own state rather than on a captured copy of
         * the message, because `build()` is what decides the From and running it here
         * is the same code path the mailer runs.
         */
        /*
         * `Mailable::$from` is `['address' => …, 'name' => …]` on Laravel 8 — an array,
         * not the `Address` value object Laravel 9 introduced. Asserted through
         * `build()` so this is the same code path the mailer runs.
         */
        /*
         * `Mailable::$from` on Laravel 8 is a *list* of `['address' => …, 'name' => …]`
         * arrays — the first element is the sender. Asserted through `build()` so this
         * exercises the same code path the mailer runs, rather than inspecting a
         * property that nothing may have set yet.
         */
        Mail::assertSent(LifecycleMessageMail::class, function (LifecycleMessageMail $mail) {
            $from = $mail->build()->from[0] ?? [];

            return ($from['address'] ?? null) === 'no-reply@reup.com.ng'
                && ($from['name'] ?? null) === 'Olumuyiwa from ReUp';
        });
    }

    public function test_the_welcome_email_contains_the_required_content_and_buttons(): void
    {
        $template = $this->template([
            'cta_buttons' => [
                ['label' => 'Fund My Wallet', 'url' => '{{ wallet_url }}'],
                ['label' => 'Explore ReUp', 'url' => '{{ services_url }}'],
                ['label' => 'Invite Friends', 'url' => '{{ referral_url }}'],
            ],
        ]);

        $user = $this->customer();

        $rendered = $this->dispatcher()->render($template, $user);

        // The subject and preview the brief specifies.
        $this->assertSame("Welcome to ReUp! Let's get you started.", $rendered['subject']);
        $this->assertStringContainsString('Your ReUp journey starts here', $rendered['html']);

        // Introduces ReUp as a product of Bellah Options.
        $this->assertStringContainsString('Ada', $rendered['html']);

        // The three buttons the brief asks for.
        $this->assertStringContainsString('Fund My Wallet', $rendered['html']);
        $this->assertStringContainsString('Explore ReUp', $rendered['html']);
        $this->assertStringContainsString('Invite Friends', $rendered['html']);

        // A prominent primary button linking to funding.
        $this->assertStringContainsString(route('wallet.fund'), $rendered['html']);

        // And a plain-text alternative, which multipart mail requires.
        $this->assertNotEmpty($rendered['text']);
        $this->assertStringNotContainsString('<p>', $rendered['text']);
    }

    public function test_the_seeded_welcome_template_carries_the_sender_and_the_three_buttons(): void
    {
        /*
         * The seeder is the artefact an operator actually starts from, so the brief's
         * requirements are asserted against it rather than only against a fixture.
         */
        config(['lifecycle.from_name' => 'Olumuyiwa from ReUp']);

        $this->seed(\Database\Seeders\LifecycleSeeder::class);

        $welcome = LifecycleEmailTemplate::where('key', 'welcome-email')->firstOrFail();

        $this->assertSame('Olumuyiwa from ReUp', $welcome->from_name);
        $this->assertSame('Welcome to ReUp! Let\'s get you started.', $welcome->subject);
        $this->assertStringContainsString('Bellah Options', $welcome->html_body);

        $labels = array_column($welcome->cta_buttons, 'label');
        $this->assertContains('Fund My Wallet', $labels);
        $this->assertContains('Explore ReUp', $labels);
        $this->assertContains('Invite Friends', $labels);

        // Nothing in the seeded body uses a variable the allowlist does not know.
        $unknown = app(LifecycleVariables::class)->unknownVariables(
            $welcome->subject . ' ' . $welcome->html_body . ' ' . $welcome->text_body
        );

        $this->assertSame([], $unknown, 'Seeded copy references unknown variables: ' . implode(', ', $unknown));
    }

    public function test_every_seeded_automation_is_created_disabled(): void
    {
        /*
         * The brief asks for these as configurable examples, "not automatically enabled
         * campaigns". A seeder that activates is a deploy that starts mailing customers.
         */
        $this->seed(\Database\Seeders\LifecycleSeeder::class);

        $this->assertSame(5, LifecycleAutomation::count());
        $this->assertSame(0, LifecycleAutomation::where('status', LifecycleAutomation::STATUS_ACTIVE)->count());
        $this->assertSame(0, LifecycleEmailTemplate::where('status', LifecycleEmailTemplate::STATUS_ACTIVE)->count());
    }

    public function test_the_five_recommended_automations_are_configured_as_described(): void
    {
        $this->seed(\Database\Seeders\LifecycleSeeder::class);

        $welcome = LifecycleAutomation::where('key', 'welcome')->firstOrFail();
        $this->assertSame(LifecycleTrigger::USER_REGISTERED, $welcome->trigger_event);
        $this->assertSame(0, $welcome->delay_minutes);

        // 24 hours, only if not funded.
        $funding = LifecycleAutomation::where('key', 'wallet-funding-reminder')->firstOrFail();
        $this->assertSame(1440, $funding->delay_minutes);
        $this->assertSame('successful_fundings', $funding->conditions[0]['field']);

        // 6 hours, only if no transaction.
        $first = LifecycleAutomation::where('key', 'first-transaction-reminder')->firstOrFail();
        $this->assertSame(360, $first->delay_minutes);
        $this->assertSame('successful_transactions', $first->conditions[0]['field']);

        // After a first successful transaction.
        $referral = LifecycleAutomation::where('key', 'referral-invitation')->firstOrFail();
        $this->assertSame(LifecycleTrigger::TRANSACTION_SUCCEEDED, $referral->trigger_event);

        // After 14 days of inactivity.
        $reengage = LifecycleAutomation::where('key', 're-engagement')->firstOrFail();
        $this->assertSame(LifecycleTrigger::USER_BECAME_INACTIVE, $reengage->trigger_event);
        $this->assertSame('inactive_days', $reengage->conditions[0]['field']);
    }

    /* =====================================================================
     | 2. Rendering, variable escaping and HTML safety
     =================================================================== */

    public function test_a_customer_variable_is_escaped_in_the_rendered_email(): void
    {
        /*
         * The security claim that matters most in this module: a template is
         * administrator-authored markup, and a *customer's* name is substituted into it.
         * If that substitution is unescaped, a customer whose name is markup injects
         * markup into an email rendered in somebody's client — and into the admin
         * preview screen, which is the same string in a browser.
         */
        $template = $this->template(['html_body' => '<p>Hi {{ first_name }}</p>']);

        $user = $this->customer([
            'first_name' => '<script>alert(1)</script>',
            'name' => '<script>alert(1)</script> Obi',
            'email' => 'xss@example.com',
        ]);

        $rendered = $this->dispatcher()->render($template, $user);

        $this->assertStringNotContainsString('<script>', $rendered['html']);
        $this->assertStringContainsString('&lt;script&gt;', $rendered['html']);
    }

    public function test_an_unknown_variable_is_left_visible_rather_than_blanked(): void
    {
        // A blank is invisible; leaving the token means a typo shows up in the preview
        // instead of shipping a template with a silent hole in it.
        $variables = app(LifecycleVariables::class);

        $rendered = $variables->substitute('Hi {{ firstname }}', ['first_name' => 'Ada']);

        $this->assertSame('Hi {{ firstname }}', $rendered);
        $this->assertSame(['firstname'], $variables->unknownVariables('Hi {{ firstname }}'));
    }

    public function test_a_wallet_balance_is_not_an_available_variable(): void
    {
        /*
         * The brief asks not to expose sensitive wallet or transaction data
         * unnecessarily, and an email is the least controllable place a figure can end
         * up. The condition builder can read a balance; a template cannot print one.
         */
        $this->assertArrayNotHasKey('wallet_balance', LifecycleVariables::allowed());
        $this->assertArrayNotHasKey('balance', LifecycleVariables::allowed());
        $this->assertArrayNotHasKey('wallet_balance_naira', LifecycleVariables::allowed());
    }

    public function test_the_sanitiser_removes_scripts_and_event_handlers(): void
    {
        $dirty = '<p onclick="alert(1)">Hi</p><script>alert(2)</script><img src="x" onerror="alert(3)">';

        $clean = HtmlSanitizer::clean($dirty);

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringContainsString('Hi', $clean);
    }

    public function test_the_sanitiser_keeps_the_markup_an_email_actually_needs(): void
    {
        $clean = HtmlSanitizer::clean('<p style="color:#000;">Hi <strong>Ada</strong></p><a href="https://reup.com.ng">go</a>');

        $this->assertStringContainsString('<p style="color:#000;">', $clean);
        $this->assertStringContainsString('<strong>Ada</strong>', $clean);
        $this->assertStringContainsString('href="https://reup.com.ng"', $clean);
    }

    public function test_a_dangerous_url_scheme_is_refused(): void
    {
        // `javascript:` is a valid-looking attribute value, which is why it is judged
        // on its scheme rather than stripped by name.
        $this->assertNull(HtmlSanitizer::safeUrl('javascript:alert(1)'));
        $this->assertNull(HtmlSanitizer::safeUrl('data:text/html;base64,PHNjcmlwdD4='));
        $this->assertNull(HtmlSanitizer::safeUrl("java\tscript:alert(1)"));

        $this->assertSame('https://reup.com.ng', HtmlSanitizer::safeUrl('https://reup.com.ng'));
        $this->assertSame('/wallet/fund', HtmlSanitizer::safeUrl('/wallet/fund'));
        $this->assertSame('mailto:hi@reup.com.ng', HtmlSanitizer::safeUrl('mailto:hi@reup.com.ng'));
    }

    public function test_a_button_with_a_dangerous_url_is_dropped_not_rendered(): void
    {
        $template = $this->template([
            'cta_buttons' => [['label' => 'Click', 'url' => 'javascript:alert(1)']],
        ]);

        $rendered = $this->dispatcher()->render($template, $this->customer());

        $this->assertStringNotContainsString('javascript:', $rendered['html']);
        $this->assertStringNotContainsString('>Click<', $rendered['html']);
    }

    /* =====================================================================
     | 3. Triggers and condition evaluation
     =================================================================== */

    public function test_registering_a_customer_records_the_trigger_event(): void
    {
        $this->post('/register', [
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'email' => 'new@example.com',
            'phone' => '08031234567',
            'password' => 'password',
            'password_confirmation' => 'password',
            'terms' => true,
        ]);

        $user = User::where('email', 'new@example.com')->firstOrFail();

        /*
         * The listener is queued, and the queue driver under test is `sync`, so it runs
         * inline — which is what makes this an end-to-end assertion about registration
         * rather than a test of the listener in isolation.
         *
         * The event is written *inside* the registration transaction and the listener
         * runs after the commit, so this also proves the ordering: the row exists and it
         * names a customer who exists.
         */
        $this->assertDatabaseHas('lifecycle_events', [
            'name' => LifecycleTrigger::USER_REGISTERED,
            'user_id' => $user->id,
            'dedupe_key' => 'registered',
        ]);
    }

    public function test_an_active_automation_produces_a_scheduled_message(): void
    {
        $template = $this->template();
        $automation = $this->automation($template);
        $user = $this->customer();

        $event = $this->recorder()->record(LifecycleTrigger::USER_REGISTERED, $user, [], 'registered');

        $result = $this->recorder()->processEvent($event);

        $this->assertSame(1, $result['scheduled']);
        $this->assertDatabaseHas('lifecycle_messages', [
            'automation_id' => $automation->id,
            'user_id' => $user->id,
            'status' => LifecycleMessage::STATUS_SCHEDULED,
        ]);
    }

    public function test_a_draft_automation_sends_nothing(): void
    {
        $template = $this->template();
        $this->automation($template, ['status' => LifecycleAutomation::STATUS_DRAFT]);
        $user = $this->customer();

        $event = $this->recorder()->record(LifecycleTrigger::USER_REGISTERED, $user, [], 'registered');
        $result = $this->recorder()->processEvent($event);

        $this->assertSame(0, $result['scheduled']);
        $this->assertSame(0, LifecycleMessage::count());
    }

    public function test_a_condition_that_does_not_match_prevents_the_send(): void
    {
        $template = $this->template();
        $this->automation($template, [
            'conditions' => [['field' => 'email_verified', 'operator' => 'equals', 'value' => '1']],
        ]);

        $user = $this->customer(['email_verified_at' => null]);

        $event = $this->recorder()->record(LifecycleTrigger::USER_REGISTERED, $user, [], 'registered');
        $result = $this->recorder()->processEvent($event);

        $this->assertSame(0, $result['scheduled']);
        $this->assertSame(0, LifecycleMessage::count());
    }

    public function test_conditions_evaluate_against_real_account_state(): void
    {
        $evaluator = app(LifecycleConditionEvaluator::class);
        $template = $this->template();

        $user = $this->customer([
            'email_verified_at' => now(),
            'created_at' => now()->subDays(10),
        ]);

        $matching = $this->automation($template, [
            'conditions' => [
                ['field' => 'account_age_days', 'operator' => 'greater_or_equal', 'value' => 5],
                ['field' => 'email_verified', 'operator' => 'equals', 'value' => '1'],
            ],
        ]);

        $this->assertTrue($evaluator->matches($matching, $user));

        /*
         * A condition that genuinely does not hold: the account is ten days old, so
         * "at least 30 days" is false. The first version of this asserted the same
         * `account_age_days >= 30` field against the same customer and expected false —
         * which was my error, not the evaluator's: ten days is not thirty, but a second
         * condition on a different field is what actually proves the AND.
         */
        $failing = $this->automation($template, [
            'key' => 'other',
            'conditions' => [
                ['field' => 'successful_fundings', 'operator' => 'greater_or_equal', 'value' => 1],
            ],
        ]);

        $this->assertFalse($evaluator->matches($failing, $user), 'An unfunded customer does not match a funding condition.');
    }

    public function test_a_shared_evaluator_does_not_leak_one_customers_values_into_another(): void
    {
        /*
         * Regression: the value cache was keyed by field alone and the condition cache by
         * nothing at all, so a reused instance answered with the *previous* customer's
         * numbers. In production that means a wallet reminder going to somebody who has
         * funded, on the strength of a stranger's balance.
         */
        $evaluator = app(LifecycleConditionEvaluator::class);
        $template = $this->template();

        $funded = $this->customer(['email' => 'funded@example.com']);

        \DB::table('transactions')->insert([
            'user_id' => $funded->id, 'reference' => 'FND-LEAK', 'type' => 'credit',
            'service_type' => 'funding', 'description' => 'Funding', 'amount' => '1000.00',
            'service_fee' => '0.00', 'total_amount' => '1000.00', 'payment_method' => 'paystack',
            'payment_status' => 'success', 'status' => 'success',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $unfunded = $this->customer(['email' => 'unfunded@example.com']);

        $automation = $this->automation($template, [
            'conditions' => [['field' => 'successful_fundings', 'operator' => 'less_or_equal', 'value' => 0]],
        ]);

        // Evaluated in the order that would have leaked: the funded customer first.
        $this->assertFalse($evaluator->matches($automation, $funded->fresh()));
        $this->assertTrue(
            $evaluator->matches($automation, $unfunded->fresh()),
            'The second customer must be evaluated on their own funding history.'
        );
    }

    public function test_or_logic_matches_when_any_condition_does(): void
    {
        $evaluator = app(LifecycleConditionEvaluator::class);
        $template = $this->template();

        $user = $this->customer(['created_at' => now()->subDays(3)]);

        $automation = $this->automation($template, [
            'condition_logic' => 'or',
            'conditions' => [
                ['field' => 'account_age_days', 'operator' => 'greater_or_equal', 'value' => 30],
                ['field' => 'account_age_days', 'operator' => 'less_than', 'value' => 5],
            ],
        ]);

        $this->assertTrue($evaluator->matches($automation, $user));
    }

    public function test_an_unknown_condition_field_never_matches(): void
    {
        /*
         * The security boundary. A condition naming a field that is not on the
         * allowlist must evaluate false rather than being passed through — a condition
         * that cannot be evaluated must not be treated as satisfied, because that
         * silently widens an audience.
         */
        $evaluator = app(LifecycleConditionEvaluator::class);

        $automation = new LifecycleAutomation([
            'conditions' => [
                ['field' => "1=1 OR 'x'='x'", 'operator' => 'equals', 'value' => '1'],
            ],
            'condition_logic' => 'and',
        ]);

        $this->assertFalse($evaluator->matches($automation, $this->customer()));
    }

    public function test_an_unknown_operator_does_not_match(): void
    {
        $evaluator = app(LifecycleConditionEvaluator::class);

        $automation = new LifecycleAutomation([
            'conditions' => [['field' => 'account_age_days', 'operator' => 'sql_injection', 'value' => 1]],
            'condition_logic' => 'and',
        ]);

        $this->assertFalse($evaluator->matches($automation, $this->customer()));
    }

    public function test_condition_validation_rejects_unknown_fields_and_operators(): void
    {
        $problems = LifecycleConditionEvaluator::validate([
            ['field' => 'not_a_field', 'operator' => 'equals', 'value' => '1'],
            ['field' => 'account_age_days', 'operator' => 'not_an_operator', 'value' => '1'],
        ]);

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('unknown field', $problems[0]);
        $this->assertStringContainsString('unknown operator', $problems[1]);
    }

    public function test_a_condition_that_needs_a_value_without_one_is_rejected(): void
    {
        $problems = LifecycleConditionEvaluator::validate([
            ['field' => 'account_age_days', 'operator' => 'greater_than', 'value' => ''],
        ]);

        $this->assertNotEmpty($problems);
    }

    /* =====================================================================
     | 4. Delayed eligibility — the reminder must not fire on stale news
     =================================================================== */

    public function test_a_delayed_reminder_is_scheduled_for_the_future(): void
    {
        $template = $this->template();
        $this->automation($template, ['delay_minutes' => 1440]);
        $user = $this->customer();

        $event = $this->recorder()->record(LifecycleTrigger::USER_REGISTERED, $user, [], 'registered');
        $this->recorder()->processEvent($event);

        $message = LifecycleMessage::firstOrFail();

        $this->assertTrue($message->scheduled_for->isFuture());
        $this->assertGreaterThan(23 * 60, now()->diffInMinutes($message->scheduled_for));
    }

    public function test_a_customer_who_funds_before_the_reminder_is_due_does_not_get_it(): void
    {
        /*
         * The brief's explicit case, and the one a naive implementation gets wrong. The
         * message is created when the trigger fires and sent 24 hours later, so if
         * eligibility were decided at *schedule* time the customer would receive "you
         * have not funded your wallet" the day after they funded it.
         *
         * The condition is therefore re-evaluated immediately before sending. This test
         * creates a due message whose condition no longer holds and asserts that nothing
         * is delivered.
         */
        $template = $this->template(['category' => LifecycleEmailTemplate::CATEGORY_TRANSACTIONAL]);
        $automation = $this->automation($template, [
            'conditions' => [['field' => 'successful_fundings', 'operator' => 'less_or_equal', 'value' => 0]],
        ]);

        $user = $this->customer();

        // Scheduled while the customer had not funded.
        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'reminder-1',
            'scheduled_for' => now()->addDay(),
        ]);

        // Fund the wallet, then bring the message due.
        \DB::table('transactions')->insert([
            'user_id' => $user->id,
            'reference' => 'FND-1',
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding',
            'amount' => '1000.00',
            'service_fee' => '0.00',
            'total_amount' => '1000.00',
            'payment_method' => 'paystack',
            'payment_status' => 'success',
            'status' => 'success',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $message->forceFill(['scheduled_for' => now()])->save();

        $recheck = app(\App\Lifecycle\LifecycleEligibility::class)->stillEligible($message->fresh());

        $this->assertFalse($recheck['eligible'], 'The reminder must not send once the customer has funded.');
    }

    public function test_re_checking_eligibility_cancels_a_message_whose_conditions_no_longer_hold(): void
    {
        Mail::fake();

        $template = $this->template(['category' => LifecycleEmailTemplate::CATEGORY_TRANSACTIONAL]);
        $automation = $this->automation($template, [
            'conditions' => [['field' => 'successful_fundings', 'operator' => 'less_or_equal', 'value' => 0]],
        ]);

        $user = $this->customer();

        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'reminder-2',
            'scheduled_for' => now(),
        ]);

        \DB::table('transactions')->insert([
            'user_id' => $user->id,
            'reference' => 'FND-2',
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding',
            'amount' => '1000.00',
            'service_fee' => '0.00',
            'total_amount' => '1000.00',
            'payment_method' => 'paystack',
            'payment_status' => 'success',
            'status' => 'success',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $this->dispatcher()->attempt($message->fresh());

        $this->assertSame('cancelled', $result['status']);
        $this->assertSame(LifecycleMessage::STATUS_CANCELLED, $message->fresh()->status);
        Mail::assertNothingSent();
    }

    /* =====================================================================
     | 5. Idempotency and concurrency
     =================================================================== */

    public function test_the_database_refuses_a_second_message_for_the_same_event(): void
    {
        /*
         * Idempotency as a *constraint*, not a check. A check-then-insert is a race two
         * concurrent workers both win; the unique index is the only thing that holds.
         */
        $template = $this->template();
        $automation = $this->automation($template);
        $user = $this->customer();

        $options = ['dedupe_key' => 'once', 'scheduled_for' => now()];

        $first = $this->dispatcher()->schedule($automation, $template, $user, $options);
        $second = $this->dispatcher()->schedule($automation, $template, $user, $options);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, LifecycleMessage::count());
    }

    public function test_processing_the_same_event_twice_produces_one_message(): void
    {
        $template = $this->template();
        $this->automation($template);

        $user = $this->customer();

        $event = $this->recorder()->record(LifecycleTrigger::USER_REGISTERED, $user, [], 'registered');

        $this->recorder()->processEvent($event);
        $this->recorder()->processEvent($event->fresh());

        $this->assertSame(1, LifecycleMessage::count());
    }

    public function test_a_duplicate_domain_event_is_one_row(): void
    {
        $user = $this->customer();

        $first = $this->recorder()->record(LifecycleTrigger::USER_REGISTERED, $user, [], 'registered');
        $second = $this->recorder()->record(LifecycleTrigger::USER_REGISTERED, $user, [], 'registered');

        $this->assertNotNull($first);
        $this->assertNull($second, 'A replayed event must not create a second row.');
        $this->assertSame(1, LifecycleEvent::count());
    }

    public function test_a_message_is_claimed_by_only_one_worker(): void
    {
        /*
         * The queue guarantees at-least-once delivery, so two workers can hold the same
         * job. The claim is a conditional status update, so exactly one proceeds.
         */
        Mail::fake();

        $template = $this->template();
        $automation = $this->automation($template);
        $user = $this->customer();

        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'claim',
            'scheduled_for' => now(),
        ]);

        // Already sent: a second attempt must not send again.
        $this->dispatcher()->attempt($message->fresh());
        $second = $this->dispatcher()->attempt($message->fresh());

        $this->assertSame('already_terminal', $second['status']);
        Mail::assertSent(LifecycleSendMessage::class, 0);
        Mail::assertSent(LifecycleMessageMail::class, 1);
    }

    /* =====================================================================
     | 6. Queueing, retries and failures
     =================================================================== */

    public function test_the_scheduler_enqueues_rather_than_sending(): void
    {
        /*
         * The brief's requirement: the scheduled command identifies work and enqueues
         * jobs. Sending from the scheduler would block payment reconciliation behind an
         * SMTP handoff.
         */
        Queue::fake();

        $template = $this->template();
        $automation = $this->automation($template);
        $user = $this->customer();

        $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'due',
            'scheduled_for' => now()->subMinute(),
        ]);

        $queued = $this->scheduler()->enqueueDue(50);

        $this->assertSame(1, $queued);
        Queue::assertPushed(LifecycleSendMessage::class);
        $this->assertSame(LifecycleMessage::STATUS_QUEUED, LifecycleMessage::firstOrFail()->status);
    }

    public function test_a_future_message_is_not_enqueued_early(): void
    {
        Queue::fake();

        $template = $this->template();
        $automation = $this->automation($template, ['delay_minutes' => 1440]);
        $user = $this->customer();

        $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'later',
            'scheduled_for' => now()->addDay(),
        ]);

        $this->assertSame(0, $this->scheduler()->enqueueDue(50));
        Queue::assertNothingPushed();
    }

    public function test_a_failed_delivery_is_recorded_with_its_reason_and_retried(): void
    {
        /*
         * A failure must be a row with a reason, not an exception into the caller: the
         * delivery log is where an operator looks, and the sweep is what recovers it.
         */
        $template = $this->template();
        $automation = $this->automation($template);
        $user = $this->customer();

        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'fail',
            'scheduled_for' => now(),
        ]);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP refused'));

        $result = $this->dispatcher()->attempt($message->fresh());

        $this->assertSame('failed', $result['status']);

        $message->refresh();

        $this->assertSame(LifecycleMessage::STATUS_SCHEDULED, $message->status, 'A retryable failure returns to scheduled.');
        $this->assertSame(1, $message->attempts);
        $this->assertStringContainsString('SMTP refused', (string) $message->status_message);
        $this->assertTrue($message->scheduled_for->isFuture(), 'The retry is backed off, not immediate.');
    }

    public function test_a_message_that_keeps_failing_becomes_terminal(): void
    {
        config(['lifecycle.queue.max_attempts' => 2]);

        $template = $this->template();
        $automation = $this->automation($template);
        $user = $this->customer();

        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'permafail',
            'scheduled_for' => now(),
        ]);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP refused'));

        $this->dispatcher()->attempt($message->fresh());
        $this->dispatcher()->attempt($message->fresh());

        $message->refresh();

        $this->assertSame(LifecycleMessage::STATUS_FAILED, $message->status);
        $this->assertNotNull($message->failed_at);
        $this->assertFalse($message->isRetryable(2), 'A terminal failure is not retried by the queue.');
    }

    public function test_a_retry_resends_the_same_content_even_after_the_template_changes(): void
    {
        /*
         * The rendered body is snapshotted on the message. A template edited between a
         * failure and its retry must not change what goes out on that retry — and an
         * audit must be able to say what was actually sent.
         */
        Mail::fake();

        $template = $this->template();
        $automation = $this->automation($template);
        $user = $this->customer();

        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'snapshot',
            'scheduled_for' => now(),
        ]);

        $template->forceFill(['html_body' => '<p>Completely different</p>'])->save();

        $this->dispatcher()->attempt($message->fresh());

        Mail::assertSent(LifecycleMessageMail::class, function (LifecycleMessageMail $mail) {
            return str_contains($mail->message->html_body, 'Hi Ada')
                && ! str_contains($mail->message->html_body, 'Completely different');
        });
    }

    public function test_a_stuck_message_is_released_by_the_sweep(): void
    {
        /*
         * A worker killed mid-send leaves its row claimed forever: the enqueue step only
         * looks at `scheduled`, so nothing picks it up again and the customer silently
         * never hears from us.
         */
        $template = $this->template();
        $automation = $this->automation($template);
        $user = $this->customer();

        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'stuck',
            'scheduled_for' => now(),
        ]);

        $message->forceFill([
            'status' => LifecycleMessage::STATUS_SENDING,
            'updated_at' => now()->subMinutes(30),
        ])->save();

        $released = $this->scheduler()->releaseStuck(50);

        $this->assertSame(1, $released);
        $this->assertSame(LifecycleMessage::STATUS_SCHEDULED, $message->fresh()->status);
    }

    public function test_the_scheduler_is_scheduled_every_minute(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events());

        $entry = $events->first(fn ($event) => str_contains((string) $event->command, 'lifecycle:process'));

        $this->assertNotNull($entry, 'lifecycle:process is not scheduled.');
        $this->assertSame('* * * * *', $entry->expression);
    }

    public function test_the_lifecycle_queue_is_separate_from_payment_mail(): void
    {
        // A marketing send must never sit ahead of a receipt in the same queue.
        $job = new LifecycleSendMessage(1);

        $this->assertSame(config('lifecycle.queue.name', 'lifecycle'), $job->queue);
        $this->assertNotSame('default', $job->queue);
    }

    /* =====================================================================
     | 7. Suppression, consent and the kill switch
     =================================================================== */

    public function test_a_suppressed_address_is_not_sent_to(): void
    {
        Mail::fake();

        $template = $this->template();
        $automation = $this->automation($template);
        $user = $this->customer();

        LifecycleSuppression::create(['email' => $user->email, 'reason' => LifecycleSuppression::REASON_BOUNCE]);

        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'suppressed',
            'scheduled_for' => now(),
        ]);

        $result = $this->dispatcher()->attempt($message->fresh());

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(LifecycleMessage::STATUS_SUPPRESSED, $message->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_a_marketing_opt_out_blocks_marketing_but_not_transactional(): void
    {
        /*
         * The brief's separation, asserted from both sides. An unsubscribe must never
         * stop a receipt, a funding confirmation or a security alert.
         */
        Mail::fake();

        $user = $this->customer();
        LifecycleConsent::optOut($user->email, 'test');

        $marketing = $this->template(['key' => 'promo', 'category' => LifecycleEmailTemplate::CATEGORY_MARKETING]);
        $transactional = $this->template(['key' => 'receipt', 'category' => LifecycleEmailTemplate::CATEGORY_TRANSACTIONAL]);

        $marketingAutomation = $this->automation($marketing, ['key' => 'promo']);
        $transactionalAutomation = $this->automation($transactional, ['key' => 'receipt']);

        $blocked = $this->dispatcher()->schedule($marketingAutomation, $marketing, $user, [
            'dedupe_key' => 'm', 'scheduled_for' => now(),
        ]);

        $allowed = $this->dispatcher()->schedule($transactionalAutomation, $transactional, $user, [
            'dedupe_key' => 't', 'scheduled_for' => now(),
        ]);

        $this->assertSame('blocked', $this->dispatcher()->attempt($blocked->fresh())['status']);
        $this->assertSame('sent', $this->dispatcher()->attempt($allowed->fresh())['status']);
    }

    public function test_the_marketing_kill_switch_pauses_marketing_and_leaves_transactional_running(): void
    {
        /*
         * The single most important guarantee in this section: "pausing marketing must
         * never disable critical security or payment emails." The check lives where the
         * category is known, so a receipt cannot be caught by it.
         */
        Mail::fake();

        LifecycleSettings::setMarketingEnabled(false);

        $user = $this->customer();

        $marketing = $this->template(['key' => 'promo', 'category' => LifecycleEmailTemplate::CATEGORY_MARKETING]);
        $transactional = $this->template(['key' => 'receipt', 'category' => LifecycleEmailTemplate::CATEGORY_TRANSACTIONAL]);

        $blocked = $this->dispatcher()->schedule(
            $this->automation($marketing, ['key' => 'promo']), $marketing, $user,
            ['dedupe_key' => 'm', 'scheduled_for' => now()]
        );

        $allowed = $this->dispatcher()->schedule(
            $this->automation($transactional, ['key' => 'receipt']), $transactional, $user,
            ['dedupe_key' => 't', 'scheduled_for' => now()]
        );

        $this->assertSame('blocked', $this->dispatcher()->attempt($blocked->fresh())['status']);
        $this->assertSame('sent', $this->dispatcher()->attempt($allowed->fresh())['status']);

        Mail::assertSent(LifecycleMessageMail::class, 1);
    }

    public function test_a_bounce_suppresses_the_address(): void
    {
        Mail::fake();

        $template = $this->template();
        $automation = $this->automation($template);
        $user = $this->customer();

        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'bounce',
            'scheduled_for' => now(),
        ]);

        $this->dispatcher()->attempt($message->fresh());

        $correlationId = $message->fresh()->provider_message_id;

        $this->assertNotNull($correlationId);

        $this->dispatcher()->confirmBounce($correlationId, 'Mailbox does not exist');

        $this->assertSame(LifecycleMessage::STATUS_BOUNCED, $message->fresh()->status);
        $this->assertTrue(LifecycleSuppression::blocks($user->email, true));
    }

    /* =====================================================================
     | 8. Delivered versus accepted
     =================================================================== */

    public function test_a_sent_message_is_accepted_not_delivered(): void
    {
        /*
         * The distinction the status set exists for. A transport handoff means the next
         * hop took the message; it does not mean a mailbox received it. Reporting the
         * first as the second is a log that will be believed and should not be.
         */
        Mail::fake();

        $template = $this->template();
        $automation = $this->automation($template);
        $user = $this->customer();

        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'accepted',
            'scheduled_for' => now(),
        ]);

        $this->dispatcher()->attempt($message->fresh());

        $message->refresh();

        $this->assertSame(LifecycleMessage::STATUS_ACCEPTED, $message->status);
        $this->assertNotNull($message->accepted_at);
        $this->assertNull($message->delivered_at, 'Nothing has confirmed delivery.');
    }

    public function test_delivery_is_only_recorded_from_a_provider_confirmation(): void
    {
        Mail::fake();

        $template = $this->template();
        $automation = $this->automation($template);
        $user = $this->customer();

        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'confirm',
            'scheduled_for' => now(),
        ]);

        $this->dispatcher()->attempt($message->fresh());

        $correlationId = $message->fresh()->provider_message_id;

        $this->assertTrue($this->dispatcher()->confirmDelivery($correlationId));

        $message->refresh();

        $this->assertSame(LifecycleMessage::STATUS_DELIVERED, $message->status);
        $this->assertNotNull($message->delivered_at);
    }

    public function test_a_confirmation_for_an_unknown_id_is_ignored(): void
    {
        // A provider webhook must not be able to mark an unrelated row as delivered.
        $this->assertFalse($this->dispatcher()->confirmDelivery('reup-does-not-exist@localhost'));
    }

    /* =====================================================================
     | 9. Authorisation and audit
     =================================================================== */

    public function test_a_customer_cannot_reach_the_module(): void
    {
        $this->actingAs($this->customer())
            ->get(route('admin.lifecycle.index'))
            ->assertForbidden();
    }

    public function test_an_administrator_without_the_permission_cannot_reach_the_module(): void
    {
        // `view_lifecycle` is deliberately not in the default role sets, so an ordinary
        // administrator does not read the delivery log until it is granted.
        $admin = User::factory()->admin(['view_dashboard'])->create();

        $this->actingAs($admin)
            ->get(route('admin.lifecycle.index'))
            ->assertForbidden();
    }

    public function test_a_super_admin_can_reach_every_module_screen(): void
    {
        $admin = User::factory()->superAdmin()->create();

        foreach ([
            'admin.lifecycle.index',
            'admin.lifecycle.templates',
            'admin.lifecycle.templates.create',
            'admin.lifecycle.automations',
            'admin.lifecycle.automations.create',
            'admin.lifecycle.deliveries',
            'admin.lifecycle.suppressions',
        ] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }
    }

    public function test_activating_an_automation_is_audited(): void
    {
        /*
         * Activation is the moment the platform starts mailing people, so the log entry
         * is the only way to answer "why did four thousand customers get this?" later.
         */
        $admin = User::factory()->superAdmin()->create();
        $automation = $this->automation($this->template(), ['status' => LifecycleAutomation::STATUS_DRAFT]);

        $this->actingAs($admin)
            ->post(route('admin.lifecycle.automations.status', $automation), ['status' => 'active'])
            ->assertRedirect();

        $this->assertSame(LifecycleAutomation::STATUS_ACTIVE, $automation->fresh()->status);

        $this->assertDatabaseHas('admin_logs', [
            'user_id' => $admin->id,
            'action' => 'lifecycle_automation_status',
        ]);
    }

    public function test_changing_the_kill_switch_is_audited(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.lifecycle.marketing-toggle'), ['enabled' => 0])
            ->assertRedirect();

        $this->assertFalse(LifecycleSettings::marketingEnabled());

        $this->assertDatabaseHas('admin_logs', [
            'user_id' => $admin->id,
            'action' => 'lifecycle_marketing_toggled',
        ]);
    }

    public function test_saving_a_template_records_a_version_and_sanitises_the_body(): void
    {
        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->post(route('admin.lifecycle.templates.store'), [
                'key' => 'test-template',
                'name' => 'Test template',
                'subject' => 'Hello',
                'html_body' => '<p>Hi</p><script>alert(1)</script>',
                'category' => 'marketing',
            ])
            ->assertRedirect();

        $template = LifecycleEmailTemplate::where('key', 'test-template')->firstOrFail();

        $this->assertStringNotContainsString('<script', $template->html_body);
        $this->assertSame(1, $template->versions()->count());

        $this->assertDatabaseHas('admin_logs', [
            'user_id' => $admin->id,
            'action' => 'lifecycle_template_created',
        ]);
    }

    public function test_a_condition_that_cannot_be_evaluated_is_refused_on_save(): void
    {
        // Storing one would silently change an audience without anybody being told.
        $admin = User::factory()->superAdmin()->create();
        $template = $this->template();

        $this->actingAs($admin)
            ->post(route('admin.lifecycle.automations.store'), [
                'key' => 'bad-conditions',
                'name' => 'Bad',
                'trigger_event' => LifecycleTrigger::USER_REGISTERED,
                'template_id' => $template->getKey(),
                'condition_logic' => 'and',
                'delay_minutes' => 0,
                'limit_per_run' => 200,
                'limit_per_user_per_day' => 1,
                'status' => 'draft',
                'conditions' => [
                    ['field' => 'DELETE FROM users', 'operator' => 'equals', 'value' => '1'],
                ],
            ])
            ->assertSessionHasErrors('conditions');

        $this->assertDatabaseMissing('lifecycle_automations', ['key' => 'bad-conditions']);
    }

    public function test_an_unsigned_unsubscribe_link_is_rejected(): void
    {
        // The link carries a signature; without it, an opt-out could be triggered for
        // an address the caller does not control.
        $user = $this->customer();

        $this->get(route('lifecycle.unsubscribe', ['user' => $user->getKey()]))
            ->assertForbidden();
    }

    public function test_a_signed_unsubscribe_link_opts_the_customer_out(): void
    {
        $user = $this->customer();

        $this->get(URL::signedRoute('lifecycle.unsubscribe', ['user' => $user->getKey()]))->assertOk();

        $this->post(URL::signedRoute('lifecycle.unsubscribe.confirm', ['user' => $user->getKey()]))
            ->assertOk();

        $this->assertTrue(LifecycleConsent::marketingOptedOut($user->email));
        $this->assertTrue(LifecycleSuppression::blocks($user->email, true));
    }

    public function test_an_opted_out_customer_still_receives_transactional_email(): void
    {
        // The unsubscribe page says so; this is the assertion behind that sentence.
        Mail::fake();

        $user = $this->customer();
        LifecycleConsent::optOut($user->email, 'test');

        $template = $this->template(['key' => 'receipt', 'category' => LifecycleEmailTemplate::CATEGORY_TRANSACTIONAL]);
        $automation = $this->automation($template, ['key' => 'receipt']);

        $message = $this->dispatcher()->schedule($automation, $template, $user, [
            'dedupe_key' => 'r', 'scheduled_for' => now(),
        ]);

        $this->assertSame('sent', $this->dispatcher()->attempt($message->fresh())['status']);
        Mail::assertSent(LifecycleMessageMail::class, 1);
    }
}
