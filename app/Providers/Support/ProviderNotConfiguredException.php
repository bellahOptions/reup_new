<?php

namespace App\Providers\Support;

use RuntimeException;

/**
 * Raised when a provider cannot be called because its credential is absent.
 *
 * ## Why this is its own exception type
 *
 * It is thrown **before** any HTTP request is made, and that timing is the whole
 * reason the class exists. Every other failure in the adapter layer is ambiguous:
 * a timeout, a 5xx or a malformed body all leave open the possibility that the
 * provider received the request and acted on it, which is why they are UNKNOWN
 * and are never retried or failed over.
 *
 * A missing credential is not ambiguous. Nothing left this process, so nothing can
 * have been charged, and failing over to another configured provider is provably
 * safe. Collapsing this case into the generic UNKNOWN handling would hold a
 * customer's money for a request that was never made and force a manual
 * reconciliation to release it.
 *
 * `AbstractProviderAdapter::send()` catches this type specifically and converts it
 * to a RETRYABLE result, so the distinction survives all the way to the routing
 * decision without any adapter having to remember it.
 *
 * It extends `RuntimeException` so that existing callers — and the order service's
 * blanket `catch (Throwable)` — keep working unchanged.
 */
class ProviderNotConfiguredException extends RuntimeException
{
}
