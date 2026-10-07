<?php

namespace App\Services\Providers;

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
    public function __construct(private readonly ClubKonnectService $client)
    {
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
}
