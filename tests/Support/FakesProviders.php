<?php

namespace Tests\Support;

use App\Services\ProviderManager;
use App\Services\Providers\BillProvider;
use Tests\TestCase;

/**
 * Swaps the real provider set for scripted ones.
 *
 * Airtime and data purchases reach the wallet, the pricing engine and the provider
 * adapters. Testing them end-to-end means pinning only the last of those — the
 * upstream call — so the debit, the snapshot and the receipt are all exercised for
 * real rather than mocked away. Mocking the pipeline would test the mock.
 *
 * Shared by the airtime-pricing tests rather than duplicated, so the two files
 * cannot drift in how they stand up a provider.
 */
trait FakesProviders
{
    /** @var array<int,string> Every purchase call, in order, for failover assertions. */
    protected array $providerCalls = [];

    public function recordProviderCall(string $provider): void
    {
        $this->providerCalls[] = $provider;
    }

    /**
     * A provider whose `purchase()` response the test scripts.
     *
     * @param  array<string,mixed>|null  $response
     */
    protected function fakeProvider(string $name, ?array $response, string $orderStatus = 'unknown'): BillProvider
    {
        return new FakeBillProvider($name, $response, $orderStatus, $this);
    }

    /** Swap the real provider set for the fakes, preserving everything else. */
    protected function withProviders(BillProvider ...$providers): void
    {
        $this->providerCalls = [];

        /** @var TestCase&FakesProviders $test */
        $test = $this;

        $this->app->bind(ProviderManager::class, function ($app) use ($providers, $test) {
            return new class($providers, $app, $test) extends ProviderManager {
                public function __construct(
                    private array $fakes,
                    private $app,
                    private $test,
                ) {
                    parent::__construct(
                        $app->make(\App\Services\Providers\ClubKonnectProvider::class),
                        $app->make(\App\Services\Providers\PairgateProvider::class),
                        $app->make(\App\Services\ProviderBalanceService::class),
                        $app->make(\App\Services\ProviderHealthService::class),
                    );
                }

                public function all(): array
                {
                    return array_values($this->fakes);
                }

                public function byName(string $name): ?BillProvider
                {
                    foreach ($this->fakes as $fake) {
                        if ($fake->name() === $name) {
                            return $fake;
                        }
                    }

                    return null;
                }

                public function candidates(string $product): array
                {
                    return array_values($this->fakes);
                }

                public function affordable(string $product, float $amount, array $params = []): array
                {
                    return array_values($this->fakes);
                }

                public function verifier(string $product): BillProvider
                {
                    return $this->fakes[0];
                }
            };
        });
    }
}
