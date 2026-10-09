<?php

namespace Tests\Support;

use App\Services\Providers\BillProvider;

/**
 * A `BillProvider` whose response is scripted by the test.
 *
 * Implements the whole interface rather than extending a concrete adapter, so a
 * test exercises the real `BillPaymentService` pipeline — debit, idempotency,
 * pricing snapshot, receipt — against a provider that cannot reach the network.
 */
class FakeBillProvider implements BillProvider
{
    public function __construct(
        private string $name,
        private ?array $response,
        private string $orderStatus = 'unknown',
        private ?object $test = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return ucfirst($this->name);
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function supports(string $product): bool
    {
        return true;
    }

    public function ping(): bool
    {
        return true;
    }

    public function cost(string $product, array $params): ?float
    {
        return null;
    }

    public function balance(): array
    {
        return ['success' => true, 'balance' => 100000.0, 'currency' => 'NGN'];
    }

    public function verifyCustomer(string $product, array $params): ?array
    {
        return null;
    }

    public function purchase(string $product, array $params, string $reference): ?array
    {
        if ($this->test && method_exists($this->test, 'recordProviderCall')) {
            $this->test->recordProviderCall($this->name);
        }

        return $this->response;
    }

    public function isSuccess(?array $response): bool
    {
        return strtoupper((string) ($response['status'] ?? '')) === 'ORDER_RECEIVED';
    }

    public function errorMessage(?array $response): string
    {
        return (string) ($response['message'] ?? 'Transaction failed: ' . ($response['status'] ?? 'no response'));
    }

    public function orderReference(?array $response): ?string
    {
        return $response['order_id'] ?? null;
    }

    public function issuedToken(?array $response): ?string
    {
        return $response['token'] ?? null;
    }

    public function orderStatus(string $reference, array $params = []): string
    {
        return $this->orderStatus;
    }
}
