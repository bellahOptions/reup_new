<?php

namespace App\Providers\Adapters;

use App\Providers\AbstractProviderAdapter;
use App\Providers\Support\ProviderResult;
use App\Providers\Support\ProviderStatus;
use App\Support\Money;
use Illuminate\Http\Client\Response;

/**
 * Nitro NG — the SMM (social boost) provider.
 *
 * Contract: https://nitro.ng/resellers/docs
 *
 * ## Three properties of this API shape the whole adapter
 *
 * ### 1. Errors arrive with HTTP 200
 *
 * Nitro answers `{"error": "Not enough funds"}` with a 200 status, the SMM panel
 * convention. A naive `$response->successful()` check would therefore read a
 * refused order as a vended one. `normalise()` inspects the body for `error`
 * *before* it looks at anything else, and an unrecognised error string becomes
 * **UNKNOWN** rather than FAILED — because the strings are documentation rather
 * than constants, and a provider that rewords one should not cause a delivered
 * order to be refunded.
 *
 * ### 2. The charge is `rate × quantity ÷ 1000`
 *
 * The rate is a per-1,000-units price in NGN. A 1,000-unit order at a rate of
 * ₦2,720 costs ₦2,720 — not ₦2,720,000. An error of three orders of magnitude in
 * either direction is available here and both are catastrophic, so:
 *
 *   * the divisor is a named configuration constant, not an inline literal;
 *   * `expectedCost()` computes it with integer arithmetic only;
 *   * every order response's own `charge` field is compared against the
 *     calculation, and a mismatch is recorded as such rather than silently
 *     accepted. If they disagree, either the rate moved under us or the divisor is
 *     wrong, and in both cases the margin that was quoted is not the margin that
 *     was earned.
 *
 * ### 3. `description` must be read before a service is sold
 *
 * Nitro states this explicitly: the description carries setup the buyer must
 * complete (bot invitations, link formats, traffic targeting). It is stored on
 * `provider_products.description` by the catalogue sync and surfaced in the
 * admin review screen, and a service with a non-empty description is flagged
 * `REVIEW` rather than published automatically.
 *
 * ## Order lifecycle
 *
 * Nitro is asynchronous. `add` returns only an order id; the outcome arrives via
 * `status` polling. Every order therefore starts PENDING and is resolved by the
 * reconciliation job, which bulk-polls up to 100 ids per call — the documented
 * cap.
 */
class NitroSmmProvider extends AbstractProviderAdapter
{
    /** Nitro's canonical status strings, for reference and for tests. */
    public const STATUS_PENDING = 'Pending';
    public const STATUS_IN_PROGRESS = 'In progress';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_PARTIAL = 'Partial';
    public const STATUS_CANCELED = 'Canceled';
    public const STATUS_REFUNDED = 'Refunded';

    /**
     * The complete set of statuses Nitro's `status` action can return.
     *
     * `error` is deliberately NOT in this list. It appears in the status map for
     * completeness, but it is not a status the `status` action produces — it is
     * the marker the provider uses on a refused *request*, which arrives as a
     * body field and is handled before any status mapping happens. Keeping it out
     * means a status poll can never return FAILED on the strength of an
     * unrecognised string.
     *
     * @return array<int, string>
     */
    public static function documentedStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_PARTIAL,
            self::STATUS_CANCELED,
            self::STATUS_REFUNDED,
        ];
    }

    public function slug(): string
    {
        return 'nitro';
    }

    public function label(): string
    {
        return (string) $this->config('label', 'Nitro NG');
    }

    protected function configKey(): string
    {
        return 'nitro';
    }

    public function capabilities(): array
    {
        return (array) $this->config('capabilities', ['smm']);
    }

    protected function baseUrl(): string
    {
        return (string) $this->config('base_url');
    }

    /**
     * The rate divisor, from the documented charge formula.
     *
     * Exposed so the pricing path and the tests both read it from one place. A
     * hard-coded 1000 in two places is a hard-coded 1000 that will eventually
     * disagree with itself.
     */
    public function rateDivisor(): int
    {
        return (int) $this->config('rate_divisor', 1000);
    }

    /* =====================================================================
     | Provider cost
     * =================================================================== */

    /**
     * What Nitro will charge for a quantity at a given rate.
     *
     * `rate × quantity ÷ 1000`, in integer kobo, rounded half-up to the kobo
     * because a fraction of a kobo cannot be charged.
     *
     * @param  int  $rateMinor  the provider's rate, in kobo, per `rateDivisor()` units
     */
    public function expectedCost(int $rateMinor, int $quantity): Money
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        $divisor = $this->rateDivisor();

        $product = $rateMinor * $quantity;
        $quotient = intdiv($product, $divisor);
        $remainder = $product % $divisor;

        if ($remainder * 2 >= $divisor) {
            $quotient++;
        }

        return Money::fromMinor($quotient);
    }

    /* =====================================================================
     | Catalogue
     * =================================================================== */

    /**
     * The full service catalogue at this account's current reseller rates.
     *
     * Nitro documents exactly one catalogue per key — "there is no per-account
     * list and nothing to switch on" — so this is a single call, not a paged
     * walk.
     *
     * The response is a bare JSON array, not an object, which is why
     * `normalise()` handles that shape before it looks for an envelope.
     */
    public function services(): ProviderResult
    {
        /*
         * The service catalogue is the one Nitro call whose response a caller must
         * parse for identifiers (`service` ids, rates, quantities), so it is read
         * with the unredacted body attached. Every other action — a purchase, a
         * refill, a status poll — deliberately does not carry one.
         */
        return $this->action('services', [], true);
    }

    /** The provider wallet balance. */
    public function balance(): array
    {
        if (! $this->isConfigured()) {
            return $this->balanceFailure('Not configured.');
        }

        $result = $this->action('balance');

        if (! $result->isSuccess()) {
            return $this->balanceFailure($result->message ?? 'Balance unavailable.');
        }

        $raw = $result->payload['balance'] ?? null;

        if (! is_numeric($raw)) {
            return $this->balanceFailure('The provider did not return a balance.');
        }

        return [
            'success' => true,
            'balance_minor' => Money::fromNaira((string) $raw)->minor(),
            'currency' => (string) ($result->payload['currency'] ?? 'NGN'),
            'message' => null,
            'sandbox' => false,
        ];
    }

    /** @return array{success:bool,balance_minor:null,currency:null,message:string,sandbox:bool} */
    private function balanceFailure(string $message): array
    {
        return [
            'success' => false,
            'balance_minor' => null,
            'currency' => null,
            'message' => $message,
            'sandbox' => false,
        ];
    }

    /* =====================================================================
     | Orders
     | =================================================================== */

    /**
     * Place an order.
     *
     * Only the `service` id, `link` and `quantity` are required. The order
     * parameters (`comments`, `usernames`, `keywords`, `country`, `device`,
     * `keyword`, `referrer`) are passed through from the order payload, because
     * which of them apply is a property of the service's `type` — `Default`,
     * `Custom Comments`, `SEO`, `Package` — which the catalogue stores and the
     * form derives from.
     *
     * The service id must already have been validated against an ACTIVE
     * `provider_products` row by the caller: this adapter will happily send any
     * integer it is given, so the gate belongs upstream where the catalogue
     * status is known. See `SmmOrderService`.
     *
     * @param  array<string, mixed>  $params
     */
    public function addOrder(int $serviceId, string $link, int $quantity, array $params = []): ProviderResult
    {
        if ($quantity < 1) {
            /*
             * A zero or negative quantity would be charged as zero by
             * `rate × quantity ÷ 1000` and would still create a provider order
             * with an attacker-chosen target. Refused here as well as upstream,
             * because this is the last point before the provider is called.
             */
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        return $this->action('add', array_filter([
            'service' => $serviceId,
            'link' => $link,
            'quantity' => $quantity,
            'comments' => $params['comments'] ?? null,
            'usernames' => $params['usernames'] ?? null,
            'keywords' => $params['keywords'] ?? null,
            'country' => $params['country'] ?? null,
            'device' => $params['device'] ?? null,
            'keyword' => $params['keyword'] ?? null,
            'referrer' => $params['referrer'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Order status, for one id or up to 100 in bulk.
     *
     * Bulk polling is the documented way to check many orders without tripping
     * the 300 requests/minute limit, so `$orderIds` is the preferred form and
     * `$orderId` is the singular convenience.
     *
     * @param  array<int, int|string>  $orderIds
     */
    public function orderStatus(array $orderIds): ProviderResult
    {
        $orderIds = array_values(array_filter($orderIds, fn ($id) => $id !== null && $id !== ''));

        if ($orderIds === []) {
            throw new \InvalidArgumentException('At least one order id is required.');
        }

        $cap = (int) $this->config('max_bulk_status_ids', 100);

        if (count($orderIds) > $cap) {
            throw new \InvalidArgumentException("At most {$cap} order ids may be polled at once.");
        }

        return $this->action('status', ['orders' => implode(',', $orderIds)]);
    }

    /**
     * Trigger a refill on an eligible order.
     *
     * Eligibility is the service's `refill` flag, which the catalogue stores on
     * `provider_products.supports_refill`. Checked upstream rather than here,
     * because the caller has the row.
     */
    public function refill(int $orderId): ProviderResult
    {
        return $this->action('refill', ['order' => $orderId]);
    }

    /**
     * Request cancellation on eligible orders.
     *
     * Nitro returns a per-order result: `{"order": 4211, "cancel": 1}` on success
     * or `{"order": 4212, "cancel": {"error": "Order already completed"}}` on
     * refusal. A partial batch is therefore normal, not an error, and the caller
     * must read the per-order entries — which is why the raw payload is returned
     * rather than a single status.
     *
     * @param  array<int, int|string>  $orderIds
     */
    public function cancel(array $orderIds): ProviderResult
    {
        $orderIds = array_values(array_filter($orderIds, fn ($id) => $id !== null && $id !== ''));

        if ($orderIds === []) {
            throw new \InvalidArgumentException('At least one order id is required.');
        }

        $cap = (int) $this->config('max_bulk_status_ids', 100);

        if (count($orderIds) > $cap) {
            throw new \InvalidArgumentException("At most {$cap} order ids may be cancelled at once.");
        }

        return $this->action('cancel', ['orders' => implode(',', $orderIds)]);
    }

    /* =====================================================================
     | Transport
     =================================================================== */

    /**
     * Perform one Nitro action.
     *
     * Every call is a POST to the same base URL with `key` and `action` in the
     * body. The key is added here, at the last moment before the request, so it
     * is never held in a payload array that a caller might log.
     *
     * @param  array<string, mixed>  $params
     */
    private function action(string $actionName, array $params = [], bool $keepRaw = false): ProviderResult
    {
        $action = $this->config("actions.{$actionName}");

        if (! is_string($action) || $action === '') {
            throw new \RuntimeException("Nitro has no action named [{$actionName}].");
        }

        /*
         * A GET-style query string is appended to the base URL rather than being
         * put in the body: Nitro's documentation shows form-encoded parameters,
         * and this adapter sends them as a form body below. The distinction
         * matters for `orders=a,b,c`, which must survive as a single parameter.
         */
        $body = array_merge($params, [
            'key' => \App\Providers\Support\ProviderCredentials::resolve($this->provider),
            'action' => $action,
        ]);

        return $keepRaw
            ? $this->sendRead('POST', $this->baseUrl(), $body)
            : $this->send('POST', $this->baseUrl(), $body);
    }

    /* =====================================================================
     | Normalisation
     =================================================================== */

    /**
     * Decode a Nitro response.
     *
     * Handled shapes, in the order they are checked — and the order is the point:
     *
     *   1. **a bare JSON array** — the `services` catalogue;
     *   2. **an `error` field** — a refusal, even though the HTTP status is 200;
     *   3. **a `{balance, currency}` body** — a balance read;
     *   4. **a `{order: N}` body** — a newly created order, PENDING;
     *   5. **a `{status, charge, remains, …}` body** — a status poll;
     *   6. **a `{refill: N}` body** — a refill acknowledgement;
     *   7. anything else — UNKNOWN.
     *
     * @param  array<string, mixed>  $body
     */
    protected function normalise(array $body, Response $response): ProviderResult
    {
        /*
         * ---- 1. An error, with HTTP 200 ---------------------------------
         *
         * Checked FIRST, before the array-shape check, because a refused
         * cancellation is a JSON *array* of per-order results
         * (`[{"order":1,"cancel":{"error":"Order already completed"}}]`) as well
         * as a singular object. Inspecting the error field first means neither
         * shape can be mistaken for success.
         */
        if (array_key_exists('error', $body)) {
            return $this->errorResult($body, $response->status());
        }

        /*
         * ---- 2. A bare JSON array --------------------------------------
         *
         * The `services` catalogue, and the bulk `cancel` batch, both arrive this
         * way. A PHP array decoded from a JSON array has integer keys 0..n-1.
         */
        if ($this->isList($body)) {
            return new ProviderResult(
                status: ProviderStatus::SUCCESS,
                payload: $this->redactPayload($body),
                httpStatus: $response->status(),
            );
        }

        /* ---- 3. Balance ----------------------------------------------- */
        if (array_key_exists('balance', $body)) {
            return new ProviderResult(
                status: ProviderStatus::SUCCESS,
                payload: $this->redactPayload($body),
                httpStatus: $response->status(),
            );
        }

        /* ---- 4. A new order ------------------------------------------- */
        if (array_key_exists('order', $body)) {
            /*
             * `add` returns only an id. Nitro is asynchronous, so the order is
             * PENDING by definition — reporting SUCCESS here would tell the
             * customer their followers had arrived when nothing had started.
             */
            return new ProviderResult(
                status: ProviderStatus::PENDING,
                providerStatus: self::STATUS_PENDING,
                providerReference: (string) $body['order'],
                payload: $this->redactPayload($body),
                message: 'Your social boost order has been received.',
                httpStatus: $response->status(),
            );
        }

        /* ---- 5. A status poll ----------------------------------------- */
        if (array_key_exists('status', $body)) {
            $providerStatus = (string) $body['status'];

            return new ProviderResult(
                status: $this->mapStatus($providerStatus),
                providerStatus: $providerStatus,
                /*
                 * The charge Nitro actually applied. Compared against our own
                 * calculation by the caller; a mismatch means the rate moved or
                 * the divisor is wrong.
                 */
                providerCostMinor: is_numeric($body['charge'] ?? null)
                    ? Money::fromNaira((string) $body['charge'])->minor()
                    : null,
                providerCurrency: (string) ($body['currency'] ?? 'NGN'),
                /*
                 * `start_count` and `remains` are the only progress signal Nitro
                 * offers, and the detail view shows them rather than inventing a
                 * percentage from them.
                 */
                payload: $this->redactPayload($body),
                httpStatus: $response->status(),
            );
        }

        /* ---- 6. A refill ---------------------------------------------- */
        if (array_key_exists('refill', $body)) {
            $refill = $body['refill'];

            // `{"refill": 1}` is accepted; `{"refill": {"error": "..."}}` is not.
            $accepted = is_numeric($refill) && (int) $refill >= 1;

            return new ProviderResult(
                status: $accepted ? ProviderStatus::SUCCESS : ProviderStatus::RETRYABLE,
                providerStatus: $accepted ? 'refill_accepted' : 'refill_refused',
                payload: $this->redactPayload($body),
                message: $accepted
                    ? 'Refill requested.'
                    : $this->safeMessage(is_array($refill) ? ($refill['error'] ?? null) : null, 'The provider refused the refill.'),
                httpStatus: $response->status(),
            );
        }

        return ProviderResult::fromUnrecognised('unrecognised_body', $this->redactPayload($body), $response->status());
    }

    /**
     * Classify a Nitro error string.
     *
     * The mapping is on the *normalised* error text, because these strings are
     * documentation rather than constants. An unrecognised error becomes
     * `default_error_status` from configuration, which is UNKNOWN — never FAILED.
     */
    private function errorResult(array $body, int $httpStatus): ProviderResult
    {
        $raw = $body['error'];
        $message = is_string($raw) ? $raw : (is_array($raw) ? (string) ($raw['error'] ?? json_encode($raw)) : 'Unknown provider error.');

        $normalised = ProviderStatus::normalise($message);

        $classification = null;

        foreach ((array) $this->config('error_map', []) as $needle => $status) {
            if (ProviderStatus::normalise((string) $needle) === $normalised) {
                $classification = $status;

                break;
            }
        }

        /*
         * `Order not found` is a specific and important case: it means the order
         * id we hold does not exist at the provider, so nothing was queued and
         * nothing was charged. RETRYABLE, because the money is still ours to
         * spend. It is in the error map above, but the comment records why it
         * belongs there rather than being folded in with the generic errors.
         */
        $classification = $classification ?? (string) $this->config('default_error_status', ProviderStatus::UNKNOWN);

        return new ProviderResult(
            status: $classification,
            providerStatus: 'error',
            payload: $this->redactPayload($body),
            message: $this->safeMessage($message, 'The provider rejected the order.'),
            errorCode: $normalised,
            httpStatus: $httpStatus,
        );
    }

    /** @param array<mixed> $value */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }
}
