<?php

namespace App\Services\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Payvessel adapter — the alternative upstream to ClubKonnect.
 *
 * This is the failover path: when ClubKonnect rejects a request or its float is
 * short, BillPaymentService retries the *same* transaction here before deciding
 * whether to refund. Having a second vending route matters most for the
 * products that are least forgiving when one provider is down — electricity
 * tokens and exam PINs, which customers are waiting on.
 *
 * Configure via PAYVESSEL_* in .env. Until those are set the provider reports
 * itself unconfigured and is skipped, so behaviour falls back to ClubKonnect
 * alone.
 */
class PayvesselProvider implements BillProvider
{
    private const BASE = 'https://api.payvessel.com/api/v1';

    public function name(): string
    {
        return 'payvessel';
    }

    public function label(): string
    {
        return 'Payvessel';
    }

    public function isConfigured(): bool
    {
        return ! empty(config('services.payvessel.secret_key'));
    }

    public function supports(string $product): bool
    {
        return in_array($product, (array) config('bills.providers.payvessel.products', []), true);
    }

    private function client()
    {
        $key = config('services.payvessel.secret_key');

        if (empty($key)) {
            throw new RuntimeException('Payvessel is not configured.');
        }

        return Http::withToken($key)
            ->acceptJson()
            ->timeout(45)
            ->retry(2, 400, throw: false);
    }

    public function balance(): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'balance' => 0.0, 'currency' => 'NGN', 'message' => 'Not configured'];
        }

        return Cache::remember('provider.balance.payvessel', 120, function () {
            try {
                $response = $this->client()->get(self::BASE . '/wallet/balance');
                $body = $response->json() ?? [];

                $balance = data_get($body, 'data.balance')
                    ?? data_get($body, 'data.available_balance')
                    ?? $body['balance']
                    ?? null;

                if (! $response->successful() || ! is_numeric($balance)) {
                    return [
                        'success' => false,
                        'balance' => 0.0,
                        'currency' => 'NGN',
                        'message' => $body['message'] ?? 'Balance unavailable',
                    ];
                }

                return [
                    'success' => true,
                    'balance' => (float) $balance,
                    'currency' => $body['data']['currency'] ?? 'NGN',
                    'checked_at' => now()->toDateTimeString(),
                ];
            } catch (\Throwable $e) {
                Log::warning('Payvessel balance check failed', ['error' => $e->getMessage()]);

                return ['success' => false, 'balance' => 0.0, 'currency' => 'NGN', 'message' => $e->getMessage()];
            }
        });
    }

    /**
     * Payvessel posts rather than uses query strings, so every call funnels
     * through here.
     *
     * @return array<string,mixed>|null
     */
    private function post(string $path, array $payload): ?array
    {
        try {
            $response = $this->client()->post(self::BASE . $path, $payload);

            $body = $response->json();

            if (! is_array($body)) {
                return ['status' => 'INVALID_RESPONSE', 'message' => 'Malformed provider response'];
            }

            // Normalise so isSuccess()/errorMessage() behave like ClubKonnect's.
            $body['status'] = $this->normaliseStatus($body, $response->successful());

            Log::debug('Payvessel response', ['path' => $path, 'status' => $body['status']]);

            return $body;
        } catch (\Throwable $e) {
            Log::error('Payvessel request failed', ['path' => $path, 'error' => $e->getMessage()]);

            return ['status' => 'EXCEPTION', 'message' => $e->getMessage()];
        }
    }

    /**
     * Payvessel reports success through several shapes depending on endpoint.
     * Collapse them onto the status vocabulary the pipeline understands.
     */
    private function normaliseStatus(array $body, bool $httpOk): string
    {
        if (! $httpOk) {
            return (string) ($body['status'] ?? 'API_ERROR');
        }

        $flag = $body['success'] ?? $body['status'] ?? null;

        if ($flag === true || $flag === 'success' || $flag === 'SUCCESS' || $flag === 'successful') {
            return 'ORDER_RECEIVED';
        }

        return is_string($flag) ? strtoupper($flag) : 'FAILED';
    }

    public function verifyCustomer(string $product, array $params): ?array
    {
        return match ($product) {
            'cable_tv' => $this->post('/cabletv/verify', [
                'provider' => $params['provider'],
                'smartcard_number' => $params['smartcard_number'],
                'reference' => $params['request_id'],
            ]),
            'electricity' => $this->post('/electricity/verify', [
                'disco' => $params['disco'],
                'meter_number' => $params['meter_number'],
                'meter_type' => $params['meter_type'],
                'reference' => $params['request_id'],
            ]),
            'betting' => $this->post('/betting/verify', [
                'provider' => $params['betting_code'],
                'customer_id' => $params['customer_id'],
                'reference' => $params['request_id'],
            ]),
            'jamb' => $this->post('/education/jamb/verify', [
                'profile_id' => $params['profile_id'],
                'exam_type' => $params['exam_type'],
                'reference' => $params['request_id'],
            ]),
            default => null,
        };
    }

    public function purchase(string $product, array $params, string $reference): ?array
    {
        return match ($product) {
            'airtime' => $this->post('/airtime', [
                'network' => $params['network'],
                'phone' => $params['phone'],
                'amount' => (float) $params['amount'],
                'reference' => $reference,
            ]),
            'data' => $this->post('/data', [
                'network' => $params['network'],
                'plan' => $params['plan'],
                'phone' => $params['phone'],
                'reference' => $reference,
            ]),
            'cable_tv' => $this->post('/cabletv', [
                'provider' => $params['provider'],
                'package' => $params['package'],
                'smartcard_number' => $params['smartcard_number'],
                'amount' => (float) $params['amount'],
                'phone' => $params['phone'],
                'reference' => $reference,
            ]),
            'electricity' => $this->post('/electricity', [
                'disco' => $params['disco'],
                'meter_number' => $params['meter_number'],
                'meter_type' => $params['meter_type'],
                'amount' => (float) $params['amount'],
                'phone' => $params['phone'],
                'reference' => $reference,
            ]),
            'waec', 'jamb' => $this->post('/education/pin', [
                'exam_type' => $params['exam_type'],
                'quantity' => (int) $params['quantity'],
                'phone' => $params['phone'],
                'reference' => $reference,
            ]),
            'betting' => $this->post('/betting', [
                'provider' => $params['betting_code'],
                'customer_id' => $params['customer_id'],
                'amount' => (float) $params['amount'],
                'phone' => $params['phone'],
                'reference' => $reference,
            ]),
            default => null,
        };
    }

    public function isSuccess(?array $response): bool
    {
        return strtoupper((string) ($response['status'] ?? '')) === 'ORDER_RECEIVED';
    }

    public function errorMessage(?array $response): string
    {
        if (! $response) {
            return 'No response from the alternative provider.';
        }

        $messages = [
            'INSUFFICIENT_BALANCE' => 'Provider float is insufficient.',
            'INVALID_RECIPIENT' => 'That account number could not be validated.',
            'API_ERROR' => 'The alternative provider is unavailable.',
            'EXCEPTION' => 'Could not reach the alternative provider.',
            'INVALID_RESPONSE' => 'The alternative provider returned an unexpected response.',
        ];

        $status = strtoupper((string) ($response['status'] ?? 'FAILED'));

        return $messages[$status] ?? ($response['message'] ?? 'Transaction failed: ' . $status);
    }

    public function orderReference(?array $response): ?string
    {
        return data_get($response, 'data.reference')
            ?? data_get($response, 'data.transaction_reference')
            ?? ($response['reference'] ?? null);
    }

    public function issuedToken(?array $response): ?string
    {
        return data_get($response, 'data.token')
            ?? data_get($response, 'data.pin')
            ?? data_get($response, 'data.units')
            ?? null;
    }
}
