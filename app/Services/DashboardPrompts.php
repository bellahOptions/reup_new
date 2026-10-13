<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Which dashboard prompts a customer sees, and how often.
 *
 * ## Why this is a service and not three conditions in a Blade file
 *
 * Three questions have to be answered together and answered the same way every
 * time: does this account have a PIN, has it seen this promotion recently, and may
 * both be shown at once. Scattered across a template those become three `@if`s that
 * drift — and the failure mode is not cosmetic. A promotional dialog that wins the
 * race against the PIN prompt hides the one message that concerns the customer's
 * money, and a template cannot easily be tested for that. Here it is one method
 * with a test.
 *
 * ## State lives in cookies, and that is deliberate
 *
 * The obvious alternative is a column on `users`, and for the promotion it would
 * mean a migration to record something that is pure presentation. The PIN state is
 * *already* real — `SecurityService::hasPin()` reads it — so nothing is duplicated
 * there. The only thing a cookie holds is "this browser has been shown promotion X
 * for account Y", which is exactly as durable as it should be: clearing cookies
 * re-shows a promotion, and that is a fair trade for not carrying a table of
 * impressions nobody will read.
 *
 * The cookie is keyed by user id, so two accounts on one browser are tracked
 * separately and a shared computer does not silence a promotion for the second
 * person to sign in.
 */
class DashboardPrompts
{
    /** The cookie holding the last-shown timestamp for a promotion. */
    public const PROMO_COOKIE = 'reup.promo';

    /** The cookie recording that the PIN prompt has been opened or put off. */
    public const PIN_COOKIE = 'reup.pin_prompt';

    /**
     * Everything the dashboard needs to decide what to render.
     *
     * @return array{
     *     needs_pin:bool,
     *     pin:array<string,mixed>,
     *     promotion:?array<string,mixed>,
     *     show_promotion:bool
     * }
     */
    public function forDashboard(User $user, Request $request, SecurityService $security): array
    {
        $needsPin = ! $security->hasPin($user);

        $promotion = $this->promotionFor($user, $request);

        /*
         * ## The PIN prompt wins, and the promotion waits
         *
         * They are both dialogs, and two dialogs at once is one too many: whichever
         * renders on top is the one that gets read, and it must not be the advert.
         * An account with no PIN is asked to set one, and the promotion is simply
         * deferred — the same random choice is still available next time, and the
         * frequency window has not been consumed by a showing that never happened.
         */
        return [
            'needs_pin' => $needsPin,
            'pin' => (array) config('prompts.pin_banner', []),
            'promotion' => $promotion,
            'show_promotion' => ! $needsPin && $promotion !== null,
        ];
    }

    /**
     * The promotion this account may be shown, or null.
     *
     * Null means "not now" and covers three different reasons, which is correct:
     * the caller renders nothing either way. A promotion that has been shown inside
     * the frequency window is suppressed; one whose weight is zero is disabled; and
     * an empty configuration shows nothing rather than an empty dialog.
     *
     * @return array<string,mixed>|null
     */
    public function promotionFor(User $user, Request $request): ?array
    {
        $promotions = $this->eligiblePromotions();

        if ($promotions === []) {
            return null;
        }

        if (! $this->mayShowPromotion($user, $request)) {
            return null;
        }

        return $this->choose($promotions);
    }

    /**
     * Promotions that can actually be shown.
     *
     * Weight is the enable switch: a zero-weight entry is skipped rather than
     * deleted, so withdrawing a feature from the rotation does not lose the copy
     * that describes it.
     *
     * @return array<string,array<string,mixed>>
     */
    public function eligiblePromotions(): array
    {
        $eligible = [];

        foreach ((array) config('prompts.promotions', []) as $key => $promotion) {
            if ((int) ($promotion['weight'] ?? 1) > 0) {
                $eligible[$key] = $promotion;
            }
        }

        return $eligible;
    }

    /**
     * Whether the frequency window has passed for this account on this browser.
     *
     * An unreadable or absent cookie means "never shown", which is the safe default:
     * the worst outcome is one extra promotion, against the alternative of never
     * showing one because a cookie was mangled.
     */
    public function mayShowPromotion(User $user, Request $request): bool
    {
        $hours = (int) config('prompts.promo_frequency_hours', 24);

        if ($hours <= 0) {
            return true;
        }

        $record = $this->decode($request->cookie(self::PROMO_COOKIE));

        $shownAt = $record[(string) $user->getKey()] ?? null;

        if (! is_numeric($shownAt)) {
            return true;
        }

        return ((int) $shownAt) + ($hours * 3600) <= now()->getTimestamp();
    }

    /**
     * Pick one promotion, respecting weights.
     *
     * Random rather than round-robin because there is nowhere to keep a rotation
     * cursor without the storage this class exists to avoid, and weights cover the
     * only real requirement — being able to favour one promotion over another. A
     * customer who clears their cookies may see the same one twice; that is the cost
     * of the simplicity, and it is smaller than the cost of a table of impressions.
     *
     * Selection is by cumulative weight over a random integer. The bound is the
     * total weight, so a heavier entry occupies a wider slice and is chosen
     * proportionally rather than by array position.
     *
     * @param  array<string,array<string,mixed>>  $promotions
     * @return array<string,mixed>
     */
    private function choose(array $promotions): array
    {
        $total = 0;

        foreach ($promotions as $promotion) {
            $total += (int) ($promotion['weight'] ?? 1);
        }

        $roll = random_int(1, max(1, $total));
        $cursor = 0;

        foreach ($promotions as $key => $promotion) {
            $cursor += (int) ($promotion['weight'] ?? 1);

            if ($roll <= $cursor) {
                return $promotion + ['key' => $key];
            }
        }

        // Unreachable while `$total` is the sum of the weights, but a fallback that
        // returns a promotion is better than one that returns null on a rounding
        // mistake nobody would notice.
        $key = array_key_first($promotions);

        return $promotions[$key] + ['key' => $key];
    }

    /**
     * The cookie value that records a promotion was shown.
     *
     * Returns the whole per-account map so one account's showing does not overwrite
     * another's on a shared browser.
     */
    public function promotionCookie(User $user, Request $request): string
    {
        $record = $this->decode($request->cookie(self::PROMO_COOKIE));
        $record[(string) $user->getKey()] = now()->getTimestamp();

        return $this->encode($record);
    }

    /**
     * Read one of our cookies.
     *
     * A cookie is client-controlled, so this parses defensively and returns an empty
     * map for anything unexpected. Nothing here is a security decision — the worst a
     * forged cookie can do is suppress a customer's own advertisement — which is why
     * it is a plain read rather than a signed one.
     *
     * @return array<string,int>
     */
    private function decode(?string $cookie): array
    {
        if ($cookie === null || $cookie === '') {
            return [];
        }

        $decoded = json_decode($cookie, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_filter($decoded, 'is_numeric');
    }

    /** @param array<string,int> $record */
    private function encode(array $record): string
    {
        return (string) json_encode($record);
    }
}
