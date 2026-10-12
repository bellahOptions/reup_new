<?php

namespace Database\Seeders;

use App\Lifecycle\LifecycleTrigger;
use App\Models\LifecycleAutomation;
use App\Models\LifecycleEmailTemplate;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The five recommended lifecycle emails, as **disabled** drafts.
 *
 * ## Why a seeder and not a migration
 *
 * The brief asks for these as "configurable examples, not automatically enabled
 * campaigns". A migration would run on every deployment and would create them whether
 * or not anybody wanted them — and, worse, a migration that *enables* an automation
 * starts mailing customers as a side effect of a deploy. A seeder is run deliberately,
 * once, by a person who has decided to use them:
 *
 *     php artisan db:seed --class=LifecycleSeeder
 *
 * Everything it creates is `draft`: a template that no automation references and an
 * automation that fires for nobody. An operator reads the copy, edits it, tests it
 * against a real customer, and then activates what they want. That ordering is the
 * whole point — the platform does not decide on its own to start emailing people.
 *
 * ## Idempotent
 *
 * `firstOrCreate` on the template key and `updateOrCreate` on the automation key, so
 * re-running it after editing a template does not overwrite the edit. A seeder that
 * silently reverts an operator's copy changes would be worse than no seeder.
 */
class LifecycleSeeder extends Seeder
{
    public function run(): void
    {
        $actor = User::where('is_super_admin', true)->value('id');

        foreach ($this->templates() as $key => $definition) {
            $template = LifecycleEmailTemplate::firstOrCreate(
                ['key' => $key],
                [
                    'name' => $definition['name'],
                    'subject' => $definition['subject'],
                    'preview_text' => $definition['preview_text'] ?? null,
                    'html_body' => $definition['html_body'],
                    'text_body' => $definition['text_body'] ?? null,
                    'cta_buttons' => $definition['cta_buttons'] ?? null,
                    'category' => $definition['category'] ?? LifecycleEmailTemplate::CATEGORY_MARKETING,
                    'from_name' => $definition['from_name'] ?? config('lifecycle.from_name'),
                    'status' => LifecycleEmailTemplate::STATUS_DRAFT,
                    'created_by' => $actor,
                ]
            );

            if ($template->wasRecentlyCreated) {
                $template->recordVersion('Created by the lifecycle seeder', $actor);
            }
        }

        foreach ($this->automations() as $key => $definition) {
            $template = LifecycleEmailTemplate::where('key', $definition['template'])->first();

            if (! $template) {
                continue;
            }

            LifecycleAutomation::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'trigger_event' => $definition['trigger'],
                    'template_id' => $template->getKey(),
                    'conditions' => $definition['conditions'] ?? [],
                    'condition_logic' => 'and',
                    'delay_minutes' => $definition['delay_minutes'] ?? 0,
                    'limit_per_run' => 200,
                    'limit_per_user_per_day' => 1,
                    // Created as a draft, deliberately. See the class docblock.
                    'status' => LifecycleAutomation::STATUS_DRAFT,
                    'created_by' => $actor,
                ]
            );
        }

        $this->command?->info('Lifecycle templates and automations created as drafts. Nothing will send until you activate an automation.');
    }

    /**
     * The template bodies.
     *
     * Deliberately plain, inline-styled HTML with no external assets: this is what
     * actually renders in an email client, and it is a starting point an operator is
     * expected to edit rather than a finished design.
     *
     * @return array<string, array<string,mixed>>
     */
    private function templates(): array
    {
        $personal = config('lifecycle.from_name') ?: config('app.name');

        // The sign-off name, resolved at seed time so the body contains no
        // placeholder the variable allowlist does not know. `from_name` is not a
        // template variable — it is configuration — and leaving `{{ from_name }}`
        // in the body would ship a visible token to the first customer who got it.
        $signOff = e($personal);

        return [
            'welcome-email' => [
                'name' => 'Welcome email',
                'subject' => 'Welcome to ReUp! Let\'s get you started.',
                'preview_text' => 'Your ReUp journey starts here. Discover a simpler way to pay for everyday digital services.',
                'from_name' => $personal,
                'category' => LifecycleEmailTemplate::CATEGORY_MARKETING,
                'cta_buttons' => [
                    ['label' => 'Fund My Wallet', 'url' => '{{ wallet_url }}'],
                    ['label' => 'Explore ReUp', 'url' => '{{ services_url }}'],
                    ['label' => 'Invite Friends', 'url' => '{{ referral_url }}'],
                ],
                'html_body' => str_replace(
                    '{{ from_name_placeholder }}',
                    $signOff,
                    <<<'HTML'
<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Hi {{ first_name }},
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Thank you for signing up to {{ app_name }}. We are glad you are here.
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    {{ app_name }} is a product of Bellah Options, built so that paying for everyday
    digital services takes seconds rather than a queue. Airtime, data, cable TV,
    electricity tokens and exam PINs — all from one wallet, at the price you are shown.
</p>

<p style="margin:0 0 8px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Three things to do first:
</p>

<p style="margin:0 0 10px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    <strong>1. Fund your wallet.</strong> Pay by card or by transfer to your own personal
    account number. You are credited the exact amount you pay.
</p>

<p style="margin:0 0 10px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    <strong>2. Make your first purchase.</strong> Top up a phone, buy a data bundle, or
    pay an electricity bill. It takes about ten seconds.
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    <strong>3. Invite a friend.</strong> Share your personal link — your friend gets set up
    in minutes, and you earn {{ referral_reward }} when they fund their wallet and make
    their first purchase.
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Your personal referral link:
    <br>
    <span style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;color:#1b720c;word-break:break-all;">
        {{ referral_url }}
    </span>
</p>

<p style="margin:0 0 4px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    If anything is unclear, just reply to this email — a person reads it.
</p>

<p style="margin:16px 0 0;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Warm regards,<br>
    {{ from_name_placeholder }}
</p>
HTML
                ),
                'text_body' => <<<'TEXT'
Hi {{ first_name }},

Thank you for signing up to {{ app_name }}. We are glad you are here.

{{ app_name }} is a product of Bellah Options, built so that paying for everyday
digital services takes seconds rather than a queue. Airtime, data, cable TV,
electricity tokens and exam PINs — all from one wallet, at the price you are shown.

Three things to do first:

1. Fund your wallet: {{ wallet_url }}
2. Make your first purchase: {{ services_url }}
3. Invite a friend: {{ referral_url }}

You earn {{ referral_reward }} when a friend you invite funds their wallet and makes
their first purchase.

If anything is unclear, just reply — a person reads it.

Warm regards,
{{ app_name }}

Unsubscribe from marketing email: {{ unsubscribe_url }}
TEXT,
            ],

            'wallet-funding-reminder' => [
                'name' => 'Wallet funding reminder',
                'subject' => 'Your ReUp wallet is ready — add funds in a minute',
                'preview_text' => 'Fund your wallet by card or transfer and start using ReUp straight away.',
                /*
                 * Transactional, not marketing.
                 *
                 * This one is about the customer's own account state rather than an
                 * offer, and it is the message that gets somebody from "signed up" to
                 * "using it". A customer who unsubscribed from promotions still needs
                 * to be told their account is ready; a kill switch thrown during a
                 * complaint spike should not leave every new account stranded.
                 */
                'category' => LifecycleEmailTemplate::CATEGORY_TRANSACTIONAL,
                'from_name' => $personal,
                'cta_buttons' => [
                    ['label' => 'Fund My Wallet', 'url' => '{{ wallet_url }}'],
                ],
                'html_body' => <<<'HTML'
<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Hi {{ first_name }},
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Your {{ app_name }} wallet is open and waiting. Nothing else is needed to start —
    just add funds.
</p>

<p style="margin:0 0 10px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    <strong>By card:</strong> one step, and the amount you choose is the amount you pay.
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    <strong>By transfer:</strong> you have your own personal account number. Save it in your
    banking app, transfer any amount, any time, and it lands in your wallet automatically.
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Once it is funded, airtime and data take about ten seconds.
</p>

<p style="margin:16px 0 0;font-size:15px;line-height:1.7;color:#1b1f1b;">
    {{ app_name }}
</p>
HTML,
                'text_body' => <<<'TEXT'
Hi {{ first_name }},

Your {{ app_name }} wallet is open and waiting. Nothing else is needed to start —
just add funds.

By card: one step, and the amount you choose is the amount you pay.
By transfer: you have your own personal account number. Save it in your banking app,
transfer any amount, any time, and it lands in your wallet automatically.

Fund your wallet: {{ wallet_url }}

Once it is funded, airtime and data take about ten seconds.

{{ app_name }}
TEXT,
            ],

            'first-transaction-reminder' => [
                'name' => 'First transaction reminder',
                'subject' => 'Your ReUp wallet is funded — make your first purchase',
                'preview_text' => 'Airtime or data in about ten seconds. Here is how.',
                'category' => LifecycleEmailTemplate::CATEGORY_TRANSACTIONAL,
                'from_name' => $personal,
                'cta_buttons' => [
                    ['label' => 'Buy Airtime', 'url' => '{{ services_url }}'],
                ],
                'html_body' => <<<'HTML'
<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Hi {{ first_name }},
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Your wallet has money in it, which means everything is ready. The quickest way to see
    how {{ app_name }} works is a small airtime top-up:
</p>

<p style="margin:0 0 10px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    1. Pick your network.<br>
    2. Enter the phone number.<br>
    3. Enter the amount and your transaction PIN.
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    That is the whole thing. Data bundles, cable TV, electricity tokens and exam PINs work
    the same way.
</p>

<p style="margin:16px 0 0;font-size:15px;line-height:1.7;color:#1b1f1b;">
    {{ app_name }}
</p>
HTML,
                'text_body' => <<<'TEXT'
Hi {{ first_name }},

Your wallet has money in it, which means everything is ready. The quickest way to see
how {{ app_name }} works is a small airtime top-up:

1. Pick your network.
2. Enter the phone number.
3. Enter the amount and your transaction PIN.

That is the whole thing. Data bundles, cable TV, electricity tokens and exam PINs work
the same way.

Buy airtime: {{ services_url }}

{{ app_name }}
TEXT,
            ],

            'referral-invitation' => [
                'name' => 'Referral invitation',
                'subject' => 'You are set up — now invite a friend to ReUp',
                'preview_text' => 'Share your personal link and earn {{ referral_reward }} per friend.',
                'category' => LifecycleEmailTemplate::CATEGORY_MARKETING,
                'from_name' => $personal,
                'cta_buttons' => [
                    ['label' => 'Invite Friends', 'url' => '{{ referral_url }}'],
                    ['label' => 'Explore ReUp', 'url' => '{{ services_url }}'],
                ],
                'html_body' => <<<'HTML'
<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Hi {{ first_name }},
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    You have made your first purchase on {{ app_name }} — so you know how quick it is.
    Somebody you know is still queuing for airtime.
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Share your personal link. When a friend signs up, funds their wallet and makes their
    first purchase, you earn <strong>{{ referral_reward }}</strong>.
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Your link:
    <br>
    <span style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:13px;color:#1b720c;word-break:break-all;">
        {{ referral_url }}
    </span>
</p>

<p style="margin:16px 0 0;font-size:15px;line-height:1.7;color:#1b1f1b;">
    {{ app_name }}
</p>
HTML,
                'text_body' => <<<'TEXT'
Hi {{ first_name }},

You have made your first purchase on {{ app_name }} — so you know how quick it is.
Somebody you know is still queuing for airtime.

Share your personal link. When a friend signs up, funds their wallet and makes their
first purchase, you earn {{ referral_reward }}.

Your link: {{ referral_url }}

{{ app_name }}

Unsubscribe from marketing email: {{ unsubscribe_url }}
TEXT,
            ],

            're-engagement' => [
                'name' => 'Re-engagement',
                'subject' => 'We have saved your ReUp account',
                'preview_text' => 'Your wallet and personal account number are still here whenever you need them.',
                'category' => LifecycleEmailTemplate::CATEGORY_MARKETING,
                'from_name' => $personal,
                'cta_buttons' => [
                    ['label' => 'Fund My Wallet', 'url' => '{{ wallet_url }}'],
                    ['label' => 'Explore ReUp', 'url' => '{{ services_url }}'],
                ],
                'html_body' => <<<'HTML'
<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    Hi {{ first_name }},
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    It has been a while. Your {{ app_name }} account is exactly as you left it — same
    wallet, same personal account number, nothing to set up again.
</p>

<p style="margin:0 0 14px;font-size:15px;line-height:1.7;color:#1b1f1b;">
    If something put you off, we would genuinely like to know. Reply to this email and
    tell us — it goes to a person, not a queue.
</p>

<p style="margin:16px 0 0;font-size:15px;line-height:1.7;color:#1b1f1b;">
    {{ app_name }}
</p>
HTML,
                'text_body' => <<<'TEXT'
Hi {{ first_name }},

It has been a while. Your {{ app_name }} account is exactly as you left it — same
wallet, same personal account number, nothing to set up again.

If something put you off, we would genuinely like to know. Reply to this email and
tell us — it goes to a person, not a queue.

{{ wallet_url }}

{{ app_name }}

Unsubscribe from marketing email: {{ unsubscribe_url }}
TEXT,
            ],
        ];
    }

    /**
     * The five recommended automations, each pointing at the template above.
     *
     * The delays and conditions are the ones the brief describes, expressed as data.
     * Read them as the starting point they are: an operator changes the delay, tightens
     * a condition, or deletes the ones they do not want.
     *
     * @return array<string, array<string,mixed>>
     */
    private function automations(): array
    {
        return [
            'welcome' => [
                'name' => 'Welcome email',
                'description' => 'Sent immediately after a customer registers. Thanks them, introduces ReUp as a product of Bellah Options, and points at funding, a first purchase and referrals.',
                'trigger' => LifecycleTrigger::USER_REGISTERED,
                'template' => 'welcome-email',
                'delay_minutes' => 0,
                'conditions' => [
                    // Nothing. Every registration gets a welcome, and a condition here
                    // would mean some customers are never greeted at all.
                ],
            ],

            'wallet-funding-reminder' => [
                'name' => 'Wallet funding reminder',
                'description' => 'Sent 24 hours after registration, only if the customer has not successfully funded their wallet yet.',
                'trigger' => LifecycleTrigger::USER_REGISTERED,
                'template' => 'wallet-funding-reminder',
                'delay_minutes' => 1440,
                'conditions' => [
                    [
                        'field' => 'successful_fundings',
                        'operator' => 'less_or_equal',
                        // Not funded at all, by the time the delay elapses. This is the
                        // condition the brief's "funding reminder" example depends on,
                        // and it is re-evaluated immediately before sending rather than
                        // trusted from scheduling time.
                        'value' => 0,
                    ],
                ],
            ],

            'first-transaction-reminder' => [
                'name' => 'First transaction reminder',
                'description' => 'Sent 6 hours after registration, only if the customer has not completed a successful transaction yet.',
                'trigger' => LifecycleTrigger::USER_REGISTERED,
                'template' => 'first-transaction-reminder',
                'delay_minutes' => 360,
                'conditions' => [
                    [
                        'field' => 'successful_transactions',
                        'operator' => 'less_or_equal',
                        'value' => 0,
                    ],
                ],
            ],

            'referral-invitation' => [
                'name' => 'Referral invitation after a first purchase',
                'description' => 'Sent an hour after a customer\'s first successful transaction, inviting them to share their link.',
                'trigger' => LifecycleTrigger::TRANSACTION_SUCCEEDED,
                'template' => 'referral-invitation',
                'delay_minutes' => 60,
                'conditions' => [
                    [
                        'field' => 'successful_transactions',
                        'operator' => 'greater_or_equal',
                        // One, not greater: this fires on the transaction that *made*
                        // it one, which is the moment the customer has just proved the
                        // product works and is most likely to tell somebody.
                        'value' => 1,
                    ],
                ],
            ],

            're-engagement' => [
                'name' => 'Re-engagement after inactivity',
                'description' => 'Sent once a customer has been inactive for 14 days, from the inactivity sweep.',
                'trigger' => LifecycleTrigger::USER_BECAME_INACTIVE,
                'template' => 're-engagement',
                'delay_minutes' => 0,
                'conditions' => [
                    [
                        'field' => 'inactive_days',
                        'operator' => 'greater_or_equal',
                        'value' => 14,
                    ],
                    [
                        'field' => 'successful_transactions',
                        'operator' => 'greater_or_equal',
                        // Somebody who registered and never did anything has already had
                        // the funding and first-transaction reminders. Telling them again
                        // is how a sender gets marked as spam.
                        'value' => 1,
                    ],
                ],
            ],
        ];
    }
}
