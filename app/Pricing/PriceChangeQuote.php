<?php

namespace App\Pricing;

use App\Models\User;
use App\Services\ClubKonnectCatalogue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A server-side, user-bound, expiring confirmation of a changed price.
 *
 * ## The problem this exists to solve
 *
 * A customer is shown ₦940.90, submits, and the engine now says ₦864.90. The
 * previous behaviour was a dead end: an error message telling them the price had
 * moved and to submit again. The figure they submit the second time is the same
 * figure they invented by retyping, and nothing recorded that they had seen and
 * accepted the new amount.
 *
 * ## What a quote is, and what it is not
 *
 * It is **not** a price the client may set. It carries no amount the client can
 * edit: the amount is looked up from this record on the server at confirmation
 * time and re-checked against the live catalogue before anything is charged. The
 * token is an opaque identifier — the browser holds a key, never a figure.
 *
 * What it does carry is *evidence*: which user was shown which price for which
 * bundle, under which pricing rule, and when. Confirmation is valid only while
 * all of those still hold, which is what makes "you accepted this price"
 * truthful rather than a claim the client makes about itself.
 *
 * ## Why the rule version is part of it
 *
 * A price can change for two different reasons, and both invalidate a
 * confirmation:
 *
 *   * the provider's cost moved — the catalogue synchronisation replaced it;
 *   * a Super Admin edited the pricing rule — the markup is now different.
 *
 * Checking only the amount would miss the second case whenever the two happened
 * to produce the same figure, and checking only the cost would miss a rule that
 * was changed and changed back. Both are recorded and both are compared.
 *
 * ## Why it is stored rather than signed
 *
 * A signed token is stateless, so the only thing it can assert is what was put
 * in it — it cannot assert "and this has not been consumed yet" or "and this
 * user is still the one who asked". A confirmation is a one-time decision about
 * money, so it is stored server-side where consuming it is atomic and a replay
 * is a lookup that finds nothing. The token itself is random and meaningless, so
 * there is nothing in it to forge.
 *
 * ## Expiry, and why it is short
 *
 * Five minutes by default. The confirmation is about a price that was true a
 * moment ago; the longer it is honoured the more likely the catalogue has moved
 * underneath it. An expired confirmation is not an error the customer has to
 * understand — it is re-issued, with the new figure, and asked again.
 */
class PriceChangeQuote
{
    /**
     * The cache namespace, per product family.
     *
     * One class serves data bundles and social-boost orders because the contract
     * is identical — an opaque, single-use, expiring, user-bound record of "this
     * user was shown this price for this product" — and a second implementation
     * would be a second place for a confirmation to be honoured when it should
     * not be. The namespace is what stops the two families resolving each other's
     * tokens, and it is a *parameter* rather than a second class so the security
     * properties cannot drift apart.
     */
    public const NAMESPACE_DATA = 'data';

    public const NAMESPACE_SMM = 'smm';

    private const PREFIX = 'price_quote:';

    /** Why a confirmation was refused. */
    public const REASON_MISSING = 'missing';

    public const REASON_UNKNOWN = 'unknown';

    public const REASON_EXPIRED = 'expired';

    public const REASON_WRONG_USER = 'wrong_user';

    public const REASON_WRONG_PRODUCT = 'wrong_product';

    public const REASON_PRICE_MOVED = 'price_moved';

    public const REASON_RULE_CHANGED = 'rule_changed';

    public const REASON_NO_LONGER_SELLABLE = 'no_longer_sellable';

    /**
     * Issue a confirmation for one user, product and price.
     *
     * @param  array<string,mixed>  $product  descriptor of what was shown; see `descriptor()`
     * @return array{token:string,issued_at:string,expires_at:string,expires_at_unix:int,ttl_seconds:int}
     */
    public function issue(
        User $user,
        array $product,
        int $priceMinor,
        ?int $ruleId,
        ?string $idempotencyKey = null,
        string $namespace = self::NAMESPACE_DATA
    ): array {
        $ttl = $this->ttlSeconds($namespace);
        $token = Str::random(48);
        $expiresAt = now()->addSeconds($ttl);

        Cache::put($this->key($token, $namespace), [
            'namespace' => $namespace,
            'user_id' => (int) $user->getKey(),
            /*
             * The identity of what was shown. Every value here is supplied by the
             * caller from the *server-side* catalogue row — never from the
             * request — so a confirmation cannot be made to describe a product the
             * customer was not looking at.
             */
            'product_identifier' => (string) ($product['identifier'] ?? ''),
            'product_label' => (string) ($product['label'] ?? ''),
            'product_meta' => (array) ($product['meta'] ?? []),
            'provider_cost_minor' => isset($product['cost_minor']) && is_numeric($product['cost_minor'])
                ? (int) $product['cost_minor']
                : null,
            'price_minor' => $priceMinor,
            'pricing_rule_id' => $ruleId,
            /*
             * The key the refused submission used. Carried so the accepted
             * submission can reuse it: if the first request had already reached
             * the debit before it decided the price had moved, the replay
             * protection answers from that row instead of charging again.
             */
            'idempotency_key' => $idempotencyKey,
            'issued_at' => now()->toDateTimeString(),
        ], $expiresAt);

        return [
            'token' => $token,
            'issued_at' => now()->toDateTimeString(),
            'expires_at' => $expiresAt->toDateTimeString(),
            'expires_at_unix' => $expiresAt->getTimestamp(),
            'ttl_seconds' => $ttl,
        ];
    }

    /**
     * Build a product descriptor from a data-catalogue row.
     *
     * Kept here so the data path's identifiers resolve through the same
     * `ClubKonnectCatalogue::planIdentifier()` the purchase uses; a caller
     * assembling its own would eventually address a different row.
     *
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public static function descriptorForPlan(array $plan): array
    {
        $cost = $plan['clubkonnect_price'] ?? null;

        return [
            'identifier' => (string) (ClubKonnectCatalogue::planIdentifier($plan) ?? ''),
            'label' => (string) ($plan['plan_name'] ?? ''),
            'cost_minor' => is_numeric($cost) ? (int) round(((float) $cost) * 100) : null,
            'meta' => [
                'plan_code' => (string) ($plan['plan_code'] ?? ''),
                'plan_id' => (string) ($plan['plan_id'] ?? ''),
                'network_code' => (string) ($plan['network_code'] ?? ''),
            ],
        ];
    }

    /**
     * Read a confirmation without consuming it.
     *
     * Used to decide *how* to answer: a confirmation for somebody else's session
     * is a different response from one that expired, and the caller needs to know
     * which before it re-prices. It never mutates, so calling it twice is safe.
     *
     * @return array{ok:bool,reason:?string,payload:?array<string,mixed>}
     */
    public function inspect(?string $token, User $user, string $namespace = self::NAMESPACE_DATA): array
    {
        if ($token === null || trim($token) === '') {
            return ['ok' => false, 'reason' => self::REASON_MISSING, 'payload' => null];
        }

        $payload = Cache::get($this->key($token, $namespace));

        if (! is_array($payload)) {
            /*
             * Unknown covers both "never issued" and "expired and evicted",
             * because a cache TTL eviction and a forged token are deliberately
             * indistinguishable from here — the cache entry *is* the expiry, so
             * there is nothing else to compare against.
             */
            return ['ok' => false, 'reason' => self::REASON_UNKNOWN, 'payload' => null];
        }

        if ((int) ($payload['user_id'] ?? 0) !== (int) $user->getKey()) {
            /*
             * Reported rather than silently ignored. A confirmation issued to one
             * account and presented by another is not a mistake a legitimate
             * client makes, so it is worth a distinguishable answer even though
             * both end in a re-quote.
             */
            return ['ok' => false, 'reason' => self::REASON_WRONG_USER, 'payload' => $payload];
        }

        return ['ok' => true, 'reason' => null, 'payload' => $payload];
    }

    /**
     * Consume a confirmation, atomically.
     *
     * The entry is **forgotten before the caller acts on it**, so two submissions
     * of the same confirmation cannot both pass this check — the second finds
     * nothing and is answered with a fresh quote instead of a second purchase.
     * That is what makes the confirmation single-use without a database round
     * trip in the money path.
     *
     * This is a guard against a double submit, not the financial control: the
     * control remains `SecurityService`'s idempotency key, whose uniqueness is
     * enforced by a database index. A cache is advisory — it can be flushed, it
     * is per-store, and two application servers have two of them — so nothing
     * that matters rests on this alone.
     *
     * @return array{ok:bool,reason:?string,payload:?array<string,mixed>}
     */
    public function consume(?string $token, User $user, string $namespace = self::NAMESPACE_DATA): array
    {
        $result = $this->inspect($token, $user, $namespace);

        if (! $result['ok'] || $token === null) {
            return $result;
        }

        Cache::forget($this->key($token, $namespace));

        return $result;
    }

    /**
     * Whether the world has moved since this confirmation was issued.
     *
     * @param  array<string,mixed>  $payload  what `inspect()`/`consume()` returned
     * @param  array<string,mixed>  $descriptor  the product descriptor resolved *now*
     */
    public function stillValid(array $payload, array $descriptor, PriceQuote $quote): ?string
    {
        $identifier = (string) ($descriptor['identifier'] ?? '');

        if ($identifier === '' || $identifier !== ($payload['product_identifier'] ?? null)) {
            return self::REASON_WRONG_PRODUCT;
        }

        /*
         * A product that can no longer be priced at all is a different answer from
         * one whose price moved: there is nothing to confirm, so the customer is
         * told it is unavailable rather than offered a new figure.
         */
        if (! $quote->isSellable()) {
            return self::REASON_NO_LONGER_SELLABLE;
        }

        if ($quote->customerPriceMinor !== (int) ($payload['price_minor'] ?? -1)) {
            return self::REASON_PRICE_MOVED;
        }

        /*
         * The rule check is separate from the amount check on purpose. A rule
         * edited between the two calls can produce the same amount — a rounding
         * step, a fee removed and a markup raised can cancel out — and the
         * confirmation was given for the *old* rule, which is no longer the rule
         * that priced it. Treating that as unchanged would honour an agreement to
         * something the customer was never shown.
         */
        $ruleId = $quote->pricingRuleId;

        if ($ruleId !== ($payload['pricing_rule_id'] ?? null)) {
            return self::REASON_RULE_CHANGED;
        }

        return null;
    }

    /**
     * How long a confirmation stays valid.
     *
     * Bounded below at 30 seconds so a misconfiguration cannot make the modal
     * unusable, and above at an hour so it cannot become a standing price
     * guarantee that outlives the cost it was based on.
     */
    public function ttlSeconds(string $namespace = self::NAMESPACE_DATA): int
    {
        $ttl = $namespace === self::NAMESPACE_SMM
            ? (int) config('pricing.smm.quote_ttl_seconds', 300)
            : (int) config('pricing.catalogue.quote_ttl_seconds', 300);

        return max(30, min(3600, $ttl));
    }

    /**
     * The cache key a token is stored under.
     *
     * Public because a test — and, during an incident, an operator — needs to be
     * able to expire one specific confirmation without knowing how the key is
     * built. That knowledge living only in a private method is how a test ends up
     * asserting against a key the application never uses, and passing while
     * proving nothing.
     */
    public static function cacheKey(string $token, string $namespace = self::NAMESPACE_DATA): string
    {
        return self::PREFIX . $namespace . ':' . $token;
    }

    /**
     * The cache key for a token.
     *
     * Namespaced so a data confirmation cannot be presented as a social-boost
     * one. The token is already unguessable, but two product families sharing a
     * keyspace would mean a bug in one caller could accept the other's token —
     * and a confirmation is a decision about money.
     */
    private function key(string $token, string $namespace): string
    {
        return self::cacheKey($token, $namespace);
    }
}
