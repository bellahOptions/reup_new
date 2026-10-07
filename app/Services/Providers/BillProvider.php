<?php

namespace App\Services\Providers;

/**
 * A bill-payment / vending upstream.
 *
 * Two are supported: ClubKonnect (NelloBytes) as primary, Payvessel as the
 * alternative. The point of the interface is that no controller knows which
 * one served a request, and that a failure on one can be retried on the other
 * without duplicating the wallet logic.
 */
interface BillProvider
{
    /** Machine name, e.g. "clubkonnect". */
    public function name(): string;

    /** Human label for logs and the admin console. */
    public function label(): string;

    /** Whether credentials are present. */
    public function isConfigured(): bool;

    /** Which products this provider can serve. @see config('bills.products') */
    public function supports(string $product): bool;

    /**
     * Provider float, in naira.
     *
     * @return array{success:bool,balance:float,currency:string,message?:string}
     */
    public function balance(): array;

    /** @return array<string,mixed>|null Raw provider response. */
    public function verifyCustomer(string $product, array $params): ?array;

    /** @return array<string,mixed>|null Raw provider response. */
    public function purchase(string $product, array $params, string $reference): ?array;

    /** Whether a raw response represents success. */
    public function isSuccess(?array $response): bool;

    /** A human-readable failure reason from a raw response. */
    public function errorMessage(?array $response): string;

    /** Provider-side order reference extracted from a successful response. */
    public function orderReference(?array $response): ?string;

    /** A token or PIN returned by the provider, when the product issues one. */
    public function issuedToken(?array $response): ?string;
}
