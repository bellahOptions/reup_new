<?php

namespace App\Lifecycle;

use App\Models\User;
use App\Services\AffiliateService;
use App\Support\Money;

/**
 * The approved template variables, and the only thing that can put a value into a
 * template.
 *
 * ## Two guarantees, and each is a separate decision
 *
 *   1. **An allowlist.** A template may only name the variables listed here. There is
 *      no dynamic lookup, no `{user.anything}`, no way to reach a model attribute that
 *      was not deliberately exposed. So a template cannot be used to read a column
 *      nobody intended to publish — `transaction_pin`, an internal note, another
 *      customer's data.
 *   2. **Escaping.** Every value is escaped for HTML on substitution. A customer whose
 *      first name is `<script>` and whose template is `Hello {{ first_name }}` must not
 *      produce a script tag in an email client or in the admin preview. Escaping is
 *      applied here rather than in the template, because a template author cannot be
 *      relied on to remember it and should not have to.
 *
 * ## What is deliberately absent
 *
 * No wallet balance, no transaction amounts, no reference numbers. The brief asks to
 * avoid exposing sensitive wallet or transaction data unnecessarily, and an email is
 * the least controllable place a figure can end up — forwarded, screenshotted, and
 * read over a shoulder. A reminder email does not need a balance to be useful; "you
 * have not funded your wallet yet" is the whole message. The condition builder *can*
 * read a balance, because that is a decision made on the server and never printed.
 */
class LifecycleVariables
{
    /**
     * Variable name => a closure that produces its value, or null when unavailable.
     *
     * Keys are the names a template may use, without braces.
     *
     * @var array<string, callable(User):?string>
     */
    private array $resolvers = [];

    public function __construct(private readonly AffiliateService $affiliates)
    {
    }

    /** Every variable a template may reference, for the editor's help text. */
    public static function allowed(): array
    {
        return [
            'first_name' => 'The customer\'s first name.',
            'last_name' => 'The customer\'s last name.',
            'full_name' => 'The customer\'s full name.',
            'email' => 'The customer\'s email address.',
            'referral_url' => 'The customer\'s personal referral link.',
            'referral_code' => 'The customer\'s referral code.',
            'referral_reward' => 'The reward amount for a successful referral, if the programme is active.',
            'app_name' => 'The platform name.',
            'support_email' => 'The support address.',
            'wallet_url' => 'The wallet funding page.',
            'dashboard_url' => 'The customer dashboard.',
            'services_url' => 'The services catalogue.',
            'unsubscribe_url' => 'The marketing unsubscribe link. Marketing templates should include it.',
        ];
    }

    /**
     * Build the value map for one customer.
     *
     * @return array<string,string> already escaped, ready to substitute
     */
    public function for(User $user): array
    {
        return [
            'first_name' => e((string) ($user->first_name ?: $user->name)),
            'last_name' => e((string) $user->last_name),
            'full_name' => e((string) $user->name),
            'email' => e((string) $user->email),
            'referral_url' => e($this->referralUrl($user)),
            'referral_code' => e((string) ($user->referral_code ?? '')),
            'referral_reward' => e($this->referralReward()),
            'app_name' => e((string) config('app.name', 'ReUp')),
            'support_email' => e((string) config('services.support.email', '')),
            'wallet_url' => e(route('wallet.fund')),
            'dashboard_url' => e(route('dashboard')),
            'services_url' => e(route('services.catalogue')),
            'unsubscribe_url' => e($this->unsubscribeUrl($user)),
        ];
    }

    /**
     * Replace `{{ variable }}` in a string.
     *
     * An unknown variable is left **as written** rather than blanked. Two reasons: a
     * blank is invisible, so an author who mistyped `{{ firstname }}` would ship a
     * template with a silent hole in it; and leaving the token visible makes the
     * mistake obvious in the preview, which is where it should be caught.
     */
    public function substitute(string $text, array $values): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z_]+)\s*\}\}/i',
            fn ($m) => $values[strtolower($m[1])] ?? $m[0],
            $text
        ) ?? $text;
    }

    /**
     * Variables a template body refers to that are not on the allowlist.
     *
     * Used by the editor to warn before saving, and by the test to prove that an
     * invented variable is reported rather than silently substituted.
     *
     * @return array<int,string>
     */
    public function unknownVariables(string $text): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/i', $text, $matches);

        $allowed = array_keys(self::allowed());

        return array_values(array_unique(array_filter(
            array_map('strtolower', $matches[1]),
            fn ($name) => ! in_array($name, $allowed, true)
        )));
    }

    private function referralUrl(User $user): string
    {
        return $this->affiliates->shareUrl($user);
    }

    /**
     * The referral reward, described only when a programme is actually running.
     *
     * The brief is explicit: explain referral benefits only when the active programme
     * supports them. A reward figure is a promise, and printing one while the
     * programme is disabled — or before the customer has qualified for anything —
     * would be advertising money the platform is not paying.
     */
    private function referralReward(): string
    {
        if (! (bool) config('wallet.referral.enabled', true)) {
            return '';
        }

        return Money::fromNaira((string) AffiliateService::REWARD_AMOUNT)->format();
    }

    /**
     * A signed unsubscribe link, so an opt-out cannot be triggered for an address the
     * caller does not control and cannot be forged for somebody else.
     */
    private function unsubscribeUrl(User $user): string
    {
        return \URL::temporarySignedRoute(
            'lifecycle.unsubscribe',
            now()->addDays(180),
            ['user' => $user->getKey()]
        );
    }
}
