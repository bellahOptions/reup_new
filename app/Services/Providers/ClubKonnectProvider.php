<?php

namespace App\Services\Providers;

use App\Services\ClubKonnectCatalogue;
use App\Services\ClubKonnectService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ClubKonnect / NelloBytes adapter.
 *
 * Thin wrapper over the existing ClubKonnectService so the rest of the
 * application only ever talks to BillProvider. Behaviour is unchanged.
 */
class ClubKonnectProvider implements BillProvider
{
    /** Seconds a liveness probe may take before it counts as "not available". */
    private const PROBE_TIMEOUT = 10;

    public function __construct(
        private readonly ClubKonnectService $client,
        private readonly ClubKonnectCatalogue $catalogue,
    ) {
    }

    public function name(): string
    {
        return 'clubkonnect';
    }

    public function label(): string
    {
        return 'ClubKonnect';
    }

    public function isConfigured(): bool
    {
        return $this->client->isConfigured();
    }

    public function supports(string $product): bool
    {
        return in_array($product, (array) config('bills.providers.clubkonnect.products', []), true);
    }

    /**
     * A wallet enquiry is the cheapest call that proves reachability *and*
     * credentials, and it is the same endpoint the float check uses. It is
     * deliberately not cached here: the caller decides how fresh an answer it
     * needs (see ProviderHealthService).
     */
    public function ping(): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = $this->client->checkBalance(self::PROBE_TIMEOUT);

            return is_array($response) && is_numeric($response['balance'] ?? null);
        } catch (\Throwable $e) {
            Log::warning('ClubKonnect health probe failed', ['error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * What NelloBytes charges our float for this purchase.
     *
     * Data is the only product where that number is on file: the pricelist
     * catalogue carries `clubkonnect_price` (the wholesale amount it bills for a
     * plan) next to the marked-up price the customer pays. Everything else
     * returns null on purpose:
     *
     *   * airtime is billed at a network-specific discount that is not
     *     published anywhere we can read before the sale;
     *   * cable bouquets expose both a package amount and a discount amount and
     *     nothing in this codebase establishes which one is wholesale — guessing
     *     would silently misroute traffic, which is worse than not routing;
     *   * exam pins are priced per unit with no catalogue endpoint.
     *
     * null means "unknown", and ProviderManager only reorders when *every*
     * candidate has a price, so a gap here can never redirect a sale.
     *
     * @param  array<string,mixed>  $params
     */
    public function cost(string $product, array $params): ?float
    {
        return match ($product) {
            // Passed straight through, so both providers cost the same and the
            // configured order decides.
            'electricity', 'betting' => isset($params['amount']) ? (float) $params['amount'] : null,

            'data' => $this->catalogue->planPrice((string) ($params['plan'] ?? '')),

            default => null,
        };
    }

    public function balance(): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'balance' => 0.0, 'currency' => 'NGN', 'message' => 'Not configured'];
        }

        return Cache::remember('provider.balance.clubkonnect', 120, function () {
            try {
                $response = $this->client->checkBalance();

                // NelloBytes returns { balance, ... } on success.
                $balance = $response['balance'] ?? null;

                if ($balance === null || ! is_numeric($balance)) {
                    return [
                        'success' => false,
                        'balance' => 0.0,
                        'currency' => 'NGN',
                        'message' => $this->client->getErrorMessage($response),
                    ];
                }

                return [
                    'success' => true,
                    'balance' => (float) $balance,
                    'currency' => 'NGN',
                    'checked_at' => now()->toDateTimeString(),
                ];
            } catch (\Throwable $e) {
                Log::warning('ClubKonnect balance check failed', ['error' => $e->getMessage()]);

                return ['success' => false, 'balance' => 0.0, 'currency' => 'NGN', 'message' => $e->getMessage()];
            }
        });
    }

    public function verifyCustomer(string $product, array $params): ?array
    {
        return match ($product) {
            'cable_tv' => $this->client->verifyCableTvCustomer(
                $params['provider'], $params['smartcard_number'], $params['request_id']
            ),
            'electricity' => $this->client->verifyMeter(
                $params['disco'], $params['meter_number'], $params['meter_type'], $params['request_id']
            ),
            'jamb' => $this->client->verifyJambProfile(
                $params['profile_id'], $params['exam_type'], $params['request_id']
            ),
            'betting' => $this->client->verifyBettingCustomer(
                $params['betting_code'], $params['customer_id'], $params['request_id']
            ),
            default => null,
        };
    }

    public function purchase(string $product, array $params, string $reference): ?array
    {
        return match ($product) {
            'airtime' => $this->client->purchaseAirtime(
                $params['network'], $params['phone'], (float) $params['amount'], $reference
            ),
            'data' => $this->client->purchaseData(
                $params['network'], $params['plan'], $params['phone'], $reference
            ),
            'cable_tv' => $this->client->purchaseCableTv(
                $params['provider'], $params['package'], $params['smartcard_number'],
                (float) $params['amount'], $params['phone'], $reference
            ),
            'electricity' => $this->client->purchaseElectricity(
                $params['disco'], $params['meter_number'], $params['meter_type'],
                (float) $params['amount'], $params['phone'], $reference
            ),
            'waec', 'jamb' => $this->client->purchaseExamPin(
                $params['exam_type'], (int) $params['quantity'], $params['phone'], $reference
            ),
            'betting' => $this->client->purchaseBetting(
                $params['betting_code'], $params['customer_id'],
                (float) $params['amount'], $params['phone'], $reference
            ),
            default => null,
        };
    }

    public function isSuccess(?array $response): bool
    {
        return $this->client->isSuccessResponse($response);
    }

    public function errorMessage(?array $response): string
    {
        return $this->client->getErrorMessage($response);
    }

    public function orderReference(?array $response): ?string
    {
        return $response['orderid'] ?? $response['OrderID'] ?? $response['order_id'] ?? null;
    }

    public function issuedToken(?array $response): ?string
    {
        return $response['token'] ?? $response['Token'] ?? data_get($response, 'data.token') ?? null;
    }

    /**
     * Ask NelloBytes what happened to an order.
     *
     * `APIStatusQueryV1` is keyed on the RequestID we sent, which is the
     * transaction reference this application generated — so the query is exact
     * and needs no stored provider id.
     *
     * ## Vocabulary mapping
     *
     * NelloBytes reports its own statuses. They are mapped conservatively:
     *
     *   * a completed/reversed/refunded order is `success` or `failed`
     *     accordingly — both are terminal verdicts;
     *   * anything still in progress is `pending`;
     *   * **anything unrecognised is `unknown`, never `failed`.**
     *
     * The last rule is the one that matters. A status string this adapter has
     * not seen before is not evidence that the customer was not vended, and
     * treating it as a failure would trigger a refund for goods already
     * delivered.
     */
    public function orderStatus(string $reference, array $params = []): string
    {
        if (! $this->isConfigured()) {
            return 'unknown';
        }

        try {
            $response = $this->client->verifyTransaction($reference);
        } catch (\Throwable $e) {
            // A transport failure leaves the outcome exactly as unknown as it
            // was before the query.
            Log::warning('ClubKonnect status query failed', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return 'unknown';
        }

        if (! is_array($response)) {
            return 'unknown';
        }

        $status = strtoupper(trim((string) ($response['status'] ?? '')));

        return match ($status) {
            'ORDER_COMPLETED', 'COMPLETED', 'SUCCESS', 'SUCCESSFUL' => 'success',
            'ORDER_FAILED', 'FAILED', 'ORDER_CANCELLED', 'CANCELLED', 'REFUNDED' => 'failed',
            'ORDER_RECEIVED', 'PENDING', 'PROCESSING', 'IN_PROGRESS', 'ORDER_PENDING' => 'pending',
            // Includes the adapter's own transport statuses (EXCEPTION,
            // API_ERROR, INVALID_RESPONSE) and anything unrecognised.
            default => 'unknown',
        };
    }
}
