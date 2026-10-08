<?php

namespace App\Services\Providers;

/**
 * A bill-payment / vending upstream.
 *
 * Two are supported: ClubKonnect (NelloBytes) as primary, Pairgate as the
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
     * Liveness and credential check against the provider's cheapest endpoint.
     *
     * Used to decide whether a purchase should be sent here *at all*: a
     * provider that cannot answer a balance enquiry will not vend either, and
     * finding that out before the customer is debited is the whole point. It
     * must therefore be cheap, must never be cached inside the adapter (the
     * caller decides how fresh it needs to be), and must not throw — a
     * transport failure is simply "not available".
     */
    public function ping(): bool;

    /**
     * What this provider will take from our float for this exact purchase, in
     * naira — not what the customer pays.
     *
     * Returns null when the price cannot be known without performing the
     * purchase (an unpublished airtime discount, a plan that is not in a mapped
     * catalogue). Callers must treat null as "unknown", never as zero:
     * ProviderManager only reorders by price when every candidate has one, so a
     * gap in the pricing data can never silently redirect traffic.
     *
     * @param  array<string,mixed>  $params  The same parameters purchase() receives.
     */
    public function cost(string $product, array $params): ?float;

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

    /**
     * Ask the provider what happened to an order we already sent.
     *
     * This exists for exactly one situation: a purchase call that timed out or
     * whose response could not be parsed. In that case ReUp does not know
     * whether the customer was vended, and **guessing is not acceptable** —
     * refunding a vended order gives away goods, and re-sending an unvended one
     * charges twice. The only correct move is to ask.
     *
     * @param  string  $reference  the reference ReUp sent, and any provider
     *                             order reference already known
     * @param  array<string,mixed>  $params
     * @return string one of: 'success' | 'failed' | 'pending' | 'unknown'
     *                `unknown` means the provider could not tell us either —
     *                not that the order failed.
     */
    public function orderStatus(string $reference, array $params = []): string;
}
