<?php

namespace App\Smm;

use App\Models\ProviderProduct;

/**
 * Whether a target is something the provider will accept.
 *
 * ## Why this exists at all
 *
 * A social-boost order is the only purchase in this application where a wrong
 * detail cannot be corrected. A mistyped meter number is rejected before
 * delivery; a mistyped Instagram username is followed, liked or boosted on
 * somebody else's account, and the customer has paid for it. The provider does
 * validate the link — and answers `Invalid link` — but that answer arrives after
 * the wallet debit, minutes later, as an opaque provider error.
 *
 * Checking here turns it into an inline message while the customer is still on
 * the form.
 *
 * ## The asymmetry, stated plainly
 *
 * A pattern that is too **strict** refuses a legitimate target, and the customer
 * has no way to proceed — they cannot argue with a regex. A pattern that is too
 * **loose** lets the provider reject it, which is exactly today's behaviour.
 *
 * So every pattern in `config/smm.php` is deliberately permissive: it accepts the
 * documented forms plus anything recognisably on the platform's own domain, and a
 * platform with no entry falls back to "a domain, a URL or a bare username"
 * rather than to a refusal. The form's placeholder text — the `hint` — is the
 * primary mechanism; this is the backstop.
 *
 * ## Usernames
 *
 * Several platforms accept a bare username where others require a full URL, and
 * the provider's own documentation does not say which for which service. A bare
 * username is therefore accepted wherever the platform's config says
 * `accepts_username`, which is where an operator can correct it.
 */
class SmmTargetValidator
{
    /**
     * The shape rules for a platform, resolved and defaulted.
     *
     * @return array{hint:string,label:string,accepts_username:bool,pattern:string}
     */
    public function rulesFor(?string $platform): array
    {
        $key = $this->platformKey($platform);
        $configured = $key === null ? null : config("smm.targets.{$key}");

        if (! is_array($configured)) {
            $configured = (array) config('smm.target_fallback', []);
        }

        return [
            'hint' => (string) ($configured['hint'] ?? 'https://example.com/your-page'),
            'label' => (string) ($configured['label'] ?? 'Link or username'),
            'accepts_username' => (bool) ($configured['accepts_username'] ?? true),
            'pattern' => (string) ($configured['pattern'] ?? '~^.+\..+$~i'),
        ];
    }

    /**
     * Validate a target against a platform's shape.
     *
     * @return array{ok:bool,message:?string}
     */
    public function validate(?string $platform, ?string $target): array
    {
        $rules = $this->rulesFor($platform);
        $target = trim((string) $target);

        if ($target === '') {
            return ['ok' => false, 'message' => 'Enter the ' . mb_strtolower($rules['label']) . ' you want boosted.'];
        }

        /*
         * Whitespace inside a URL or username is always a paste error — a copied
         * link with a space in it is the single most common malformed target, and
         * letting it through means the provider rejects it after the debit.
         */
        if (preg_match('/\s/', $target) === 1) {
            return [
                'ok' => false,
                'message' => 'That ' . mb_strtolower($rules['label']) . ' contains a space. '
                    . 'Please check it and paste it again.',
            ];
        }

        /*
         * A bare username, where the platform allows one. Checked before the URL
         * pattern because a username is not a URL and would fail it — and checked
         * conservatively (a short, restricted character set) so that a mistyped
         * URL is not accepted as a username.
         */
        if ($rules['accepts_username'] && $this->looksLikeUsername($target)) {
            return ['ok' => true, 'message' => null];
        }

        if (@preg_match($rules['pattern'], $target) === 1) {
            return ['ok' => true, 'message' => null];
        }

        /*
         * A bare domain with no scheme, which customers paste constantly. The
         * patterns already allow an optional scheme, so reaching here means it is
         * not on the expected domain at all — retried with `https://` prepended
         * only to give the same answer, not as a second chance.
         */
        if (@preg_match($rules['pattern'], 'https://' . $target) === 1) {
            return ['ok' => true, 'message' => null];
        }

        return [
            'ok' => false,
            'message' => 'That does not look like a ' . mb_strtolower($rules['label']) . '. '
                . 'For example: ' . $rules['hint'],
        ];
    }

    /**
     * Whether a value is plausibly a bare username rather than a mistyped URL.
     *
     * Deliberately narrow: letters, digits, dot, underscore and hyphen, starting
     * with a letter or digit, between 2 and 40 characters. Those are the
     * characters every platform in the catalogue allows in a handle, and the
     * restriction is what stops `instagram.com/foo` — which has a slash and would
     * still match the loose URL pattern — being accepted as a username here and
     * skipped past the platform check.
     */
    public function looksLikeUsername(string $target): bool
    {
        if (str_contains($target, '/') || str_contains($target, ':') || str_contains($target, '?')) {
            return false;
        }

        if (str_contains($target, '.')) {
            /*
             * A dot that looks like a domain extension is a domain, not a handle.
             * `@user.name` is a handle; `user.name` is ambiguous and is treated as
             * a domain so the platform check runs.
             */
            return str_starts_with($target, '@');
        }

        return preg_match('/^@?[A-Za-z0-9][A-Za-z0-9._\-]{1,39}$/', $target) === 1;
    }

    /**
     * A short human description of what a platform expects, for the form.
     */
    public function hintFor(?string $platform): string
    {
        return $this->rulesFor($platform)['hint'];
    }

    public function labelFor(?string $platform): string
    {
        return $this->rulesFor($platform)['label'];
    }

    /**
     * The same normalisation `SmmCatalogue` uses, so a platform resolves to the
     * same rules in both places.
     */
    private function platformKey(?string $platform): ?string
    {
        if (! is_string($platform) || trim($platform) === '') {
            return null;
        }

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '', $platform) ?? '');

        return $slug === '' ? null : $slug;
    }
}
