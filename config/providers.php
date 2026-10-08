<?php

/**
 * Wave 1 provider configuration.
 *
 * ## What belongs here, and what does not
 *
 * URLs, timeouts, backoff policy and capability defaults belong here: they are
 * the same for every deployment and an operator has no reason to change them.
 *
 * **Credentials do not belong here.** They are read at call time by
 * `App\Providers\Support\ProviderCredentials` directly from the environment, so
 * that a `config:cache` artefact can never contain a secret and a debug endpoint
 * can never dump one. This file names the environment *variables* a provider
 * uses; it never holds their values.
 *
 * ## Sandbox
 *
 * Every provider here has a sandbox, and `PROVIDER_SANDBOX=true` switches all of
 * them at once. That is deliberate: an integration tested against one provider's
 * sandbox and another's production is far more dangerous than testing both
 * against sandboxes, and a single switch makes the state unambiguous. The admin
 * console displays the current mode.
 */
return [

    /* ---------------------------------------------------------------------
     | Transport policy
     |------------------------------------------------------------------- */
    'http' => [
        /* Seconds before a request is abandoned. */
        'timeout' => (int) env('PROVIDER_HTTP_TIMEOUT', 30),

        /*
         * A shorter timeout for read-only calls that gate a purchase decision
         * (a balance probe, a catalogue fetch). A probe that hangs is worse than
         * a probe that fails, because the customer is waiting on it.
         */
        'probe_timeout' => (int) env('PROVIDER_PROBE_TIMEOUT', 10),

        /*
         * Retry policy for RATE LIMITS ONLY (HTTP 429).
         *
         * Nothing else is retried automatically. A timeout is not retried
         * because the request may have been accepted; a 5xx is not retried
         * because it may mean the operation partially applied. Only a 429 is
         * safe, because the provider has explicitly told us it did not process
         * the request.
         */
        'rate_limit' => [
            'max_attempts' => (int) env('PROVIDER_RATE_LIMIT_ATTEMPTS', 4),
            /* Base delay in milliseconds; doubled per attempt. */
            'base_delay_ms' => (int) env('PROVIDER_RATE_LIMIT_BASE_MS', 500),
            'max_delay_ms' => (int) env('PROVIDER_RATE_LIMIT_MAX_MS', 8000),
            /*
             * Jitter, as a percentage of the delay. Without it, several workers
             * that were rate-limited by the same response retry in lockstep and
             * are rate-limited together again.
             */
            'jitter_percent' => (int) env('PROVIDER_RATE_LIMIT_JITTER', 20),
        ],

        /* Seconds to cache a provider catalogue fetch. */
        'catalogue_ttl' => (int) env('PROVIDER_CATALOGUE_TTL', 900),

        /* Seconds to cache a provider balance lookup. */
        'balance_ttl' => (int) env('PROVIDER_BALANCE_TTL', 120),
    ],

    /* Whether every provider is pointed at its sandbox. Displayed in the admin console. */
    'sandbox' => (bool) env('PROVIDER_SANDBOX', false),

    /* ---------------------------------------------------------------------
     | Catalogue synchronisation
     |--------------------------------------------------------------------
     | The sync reads each provider's catalogue on a schedule and updates our cost
     | figures. Two things here are operational rather than financial:
     |
     |   * `countries` bounds an international sync. VTpass's catalogue is a
     |     country → product type → operator → variation walk, so a full sync is
     |     hundreds of requests against a rate-limited API. Naming the markets we
     |     actually sell into keeps the sync cheap and the rate limit untouched.
     |   * `vtpass_countries` is the fallback cap when no countries are named.
     */
    'sync' => [
        'countries' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('PROVIDER_SYNC_COUNTRIES', 'GH,KE,ZA,UG,TZ'))
        ))),

        'vtpass_countries' => (int) env('PROVIDER_SYNC_VTPASS_COUNTRIES', 5),

        /*
         * Days of provider probe history to keep. Long enough to cover a
         * slow-burning provider problem and a month-end reconciliation; bounded so
         * the table does not need an outage to prune.
         */
        'health_history_days' => (int) env('PROVIDER_SYNC_HEALTH_DAYS', 90),
    ],

    /* ---------------------------------------------------------------------
     | Monitoring and alerting
     |--------------------------------------------------------------------
     | Every threshold here decides whether an operator is interrupted, and every
     | one is a judgement rather than a fact, so each is named and configurable
     | instead of appearing as a literal in the middle of a query.
     |
     | The general principle: alert on a *pattern*, not on a single observation. A
     | monitor that raises an alert for one dropped connection teaches an operator
     | to ignore it, and an ignored monitor is worse than none because it looks
     | like coverage.
     */
    'alerts' => [
        /*
         * A provider cost that moves by this much, in basis points, is worth
         * review. 1000 = 10%. Expressed as a proportion rather than an amount
         * because ₦10 means something different on a ₦100 product and a ₦100,000
         * one.
         */
        'cost_change_bps' => (int) env('PROVIDER_COST_CHANGE_BPS', 1000),

        /* Failure or refund rate, in basis points, worth reviewing. 1000 = 10%. */
        'failure_rate_bps' => (int) env('PROVIDER_FAILURE_RATE_BPS', 1000),

        /*
         * How many consecutive failed probes make a provider "down" rather than
         * "degraded". Three at the scheduled interval is long enough to be a real
         * pattern and short enough to act on.
         */
        'failures_before_down' => (int) env('PROVIDER_FAILURES_BEFORE_DOWN', 3),
    ],

    /* ---------------------------------------------------------------------
     | Sogo — Nigerian digital and bill services
     |--------------------------------------------------------------------
     | Contract: https://developer.sogo.africa/docs
     |
     | One unified bills API covering airtime, data, electricity, cable TV,
     | education, ePIN, eSIM and bet funding, plus a gift card purchase API.
     |
     | Idempotency: every write requires an `Idempotency-Key` header, and Sogo
     | binds the key permanently to its transaction. Reconciliation is done by
     | asking `GET /v1/transactions/{idempotency-key}?type=…`, where a 404 means
     | the transaction was never created (and therefore retrying is safe) and a
     | 200 means it was (and therefore retrying is not).
     */
    'sogo' => [
        'label' => 'Sogo',
        'docs_url' => 'https://developer.sogo.africa/docs',
        'env_prefix' => 'SOGO',

        'base_url' => env('SOGO_BASE_URL', 'https://api.sogo.africa/v1'),
        'sandbox_base_url' => env('SOGO_SANDBOX_BASE_URL', 'https://sandbox.sogo.africa/v1'),

        /*
         * Endpoints, named once. An adapter method references a name from this
         * map rather than a literal path, so a provider URL change is a one-line
         * edit and the set of calls this application can make is enumerable —
         * which is what makes "no arbitrary provider API calls" checkable.
         */
        'endpoints' => [
            'catalog' => '/bills/catalog',
            'data_plans' => '/bills/data-plans',
            'cable_packages' => '/bills/cable-packages',
            'education_plans' => '/bills/education-plans',
            'discount_rates' => '/bills/discount-rates',

            'verify_meter' => '/bills/electricity/verify-meter',
            'verify_smartcard' => '/bills/cable-tv/verify-smartcard',
            'verify_jamb' => '/bills/education/verify-jamb',
            'verify_bet_account' => '/bills/bet-funding/verify-account',

            'purchase_airtime' => '/bills/airtime',
            'purchase_data' => '/bills/data',
            'purchase_electricity' => '/bills/electricity',
            'purchase_cable_tv' => '/bills/cable-tv',
            'purchase_education' => '/bills/education',
            'purchase_epin' => '/bills/epin',
            'purchase_bet_funding' => '/bills/bet-funding',

            /* Gift cards (buy) — https://developer.sogo.africa/products/gift-cards/buy */
            'gift_card_catalog' => '/gift-cards/products',
            'gift_card_purchase' => '/gift-cards/buy',

            /* Reconciliation by our own idempotency key. */
            'transaction_by_idempotency_key' => '/transactions/{idempotency_key}',
            'transaction_by_reference' => '/transactions/{reference}',
            'verify_transaction' => '/transactions/verify',
            'wallet' => '/wallet',
        ],

        /*
         * Capabilities this adapter can actually serve, given the documented
         * API. `esim` is deliberately ABSENT: Sogo's marketing page lists eSIM
         * among the products the bill API covers, but the published endpoint
         * reference documents no eSIM catalogue or purchase endpoint, and this
         * application does not call endpoints a provider has not documented.
         *
         * Declaring the capability without an endpoint would route customer
         * orders into a guaranteed failure. It is enabled here the moment an
         * endpoint is published, by adding the path to `endpoints` and the token
         * to this list.
         */
        'capabilities' => [
            'airtime',
            'data',
            'electricity',
            'cable_tv',
            'education',
            'epin',
            'betting',
            'gift_cards',
        ],

        /*
         * Held back pending a documented endpoint. Recorded so the reason is
         * visible in the code rather than living in someone's memory.
         */
        'pending_capabilities' => [
            'esim' => 'No eSIM catalogue or purchase endpoint is published in the Sogo API reference.',
        ],

        /* Scoped credentials, where an operator has created them. */
        'scopes' => [
            'read' => 'bills:read',
            'write' => 'bills:write',
            'gift_cards_read' => 'gift_cards:read',
            'gift_cards_write' => 'gift_cards:write',
        ],

        /*
         * Business rules from the documentation. Recorded as data so a
         * validation message can be derived from the provider's own contract
         * rather than from a comment someone might not read.
         */
        'limits' => [
            'airtime' => ['min' => 50, 'max' => 50000],
        ],

        /* Provider status vocabulary → ReUp's canonical status. */
        'status_map' => [
            'completed' => 'SUCCESS',
            'success' => 'SUCCESS',
            'successful' => 'SUCCESS',
            'processing' => 'PROCESSING',
            'pending' => 'PENDING',
            'failed' => 'FAILED',
            'refunded' => 'REFUNDED',
            'cancelled' => 'CANCELLED',
            'canceled' => 'CANCELLED',
            'reversed' => 'REFUNDED',
        ],

        /*
         * Error codes that mean "the request provably did not result in a
         * charge", so another provider may safely be tried.
         *
         * Note what is absent:
         *
         *   * `processing`/`pending` are not retryable — Sogo is explicit that the
         *     HTTP response for an accepted bill purchase is 201 and the outcome
         *     arrives by webhook or poll;
         *   * a timeout is not retryable, because the request may have been
         *     accepted before the connection dropped;
         *   * `validation_failed` and `verification_failed` are NOT retryable.
         *     They are deterministic rejections — a bad meter number, an invalid
         *     recipient — that another provider rejects identically, so failing
         *     over wastes the request and risks a second charge.
         */
        'retryable_errors' => [
            'insufficient_funds',
            'service_unavailable',
            'rate_limit_exceeded',
            'api_disabled',
            'sandbox_disabled',
        ],

        /*
         * Errors that leave the outcome genuinely unknown. A timeout is here
         * because the request may have been accepted before the connection
         * dropped — this is the single most important classification in the file.
         */
        'unknown_errors' => [
            'timeout',
            'connection_error',
            'idempotency_request_in_progress',
            'unknown',
        ],
    ],

    /* ---------------------------------------------------------------------
     | VTUGate — international airtime and data (primary)
     |--------------------------------------------------------------------
     | Contract: https://vtugate.com/docs
     |
     | Bearer API key, form-encoded POST bodies, JSON responses, 60 requests per
     | minute per key. The documented International Top-up group is:
     |
     |   POST /api/v1/international/countries
     |   POST /api/v1/international/operators
     |   POST /api/v1/international/detectoperator
     |   POST /api/v1/international/previewfx
     |   POST /api/v1/international/topup
     |   POST /api/v1/international/topupstatus
     |   POST /api/v1/international/history
     |
     | ## The one thing that is NOT documented, and how it is handled
     |
     | VTUGate's documentation site is a client-rendered application. The endpoint
     | *paths* and the authentication scheme are published and are used verbatim
     | below. The request *field names* for the international group are not
     | retrievable from the published reference, and this application does not
     | invent the field names of a financial request: guessing one would mean a
     | live top-up that never reaches the recipient, or worse, one that reaches
     | the wrong one.
     |
     | So every field name is configuration, listed under `fields` below with the
     | name this integration *expects*. `VtugateProvider` reads them from here and
     | sends exactly these keys. Before VTUGate is enabled for production, an
     | operator must confirm each name against the vendor's dashboard or a sandbox
     | call and correct this block if it differs. Until then the adapter reports
     | itself as unverified — `VtugateProvider::isOperational()` returns false —
     | and the registry skips it, so the international route uses VTpass, a
     | provider whose contract is fully published.
     */
    'vtugate' => [
        'label' => 'VTUGate',
        'docs_url' => 'https://vtugate.com/docs',
        'env_prefix' => 'VTUGATE',

        'base_url' => env('VTUGATE_BASE_URL', 'https://api.vtugate.com/api/v1'),
        /* A Test API Key returns mocked responses with no wallet impact. */
        'sandbox_base_url' => env('VTUGATE_SANDBOX_BASE_URL', 'https://api.vtugate.com/api/v1'),
        'sandbox_env_prefix' => 'VTUGATE_TEST',

        /*
         * VTUGate accepts only `application/x-www-form-urlencoded` bodies. A JSON
         * body is answered with a request-validation error, so the encoding is
         * declared rather than assumed by each call site.
         */
        'encoding' => 'form',

        'endpoints' => [
            'account' => '/accountdetails',

            'countries' => '/international/countries',
            'operators' => '/international/operators',
            'detect_operator' => '/international/detectoperator',
            'preview_fx' => '/international/previewfx',
            'topup' => '/international/topup',
            'topup_status' => '/international/topupstatus',
            'topup_history' => '/international/history',
        ],

        'capabilities' => ['international_airtime', 'international_data'],

        /*
         * Whether the international route may be used in production.
         *
         * False until an operator has confirmed the field names below against the
         * vendor. `VtugateProvider::isOperational()` returns false while this is
         * false, so the registry skips VTUGate and the international route uses
         * VTpass — a provider whose contract is fully published. Flipping this to
         * true is a deliberate, documented act, not a code change.
         */
        'fields_verified' => (bool) env('VTUGATE_FIELDS_VERIFIED', false),

        /*
         * Request field names, as this integration expects them.
         *
         * Every one is configurable so a correction is an environment change
         * rather than a deploy, and so the expectation is visible in one place
         * instead of scattered through the adapter.
         */
        'fields' => [
            'country' => 'country',
            'operator' => 'operator',
            'phone' => 'phone',
            'product' => 'product',
            'amount' => 'amount',
            'reference' => 'reference',
            'currency' => 'currency',
        ],

        /* Response field names, same reasoning. */
        'response_fields' => [
            'status' => 'status',
            'message' => 'message',
            'data' => 'data',
            'reference' => 'reference',
            'operator_id' => 'operator_id',
            'operator_name' => 'operator_name',
            'country_code' => 'country_code',
            'currency' => 'currency',
            'local_amount' => 'local_amount',
            'ngn_amount' => 'ngn_amount',
            'fx_rate' => 'fx_rate',
            'fee' => 'fee',
            'balance' => 'wallet_balance',
        ],

        /*
         * VTUGate answers with a boolean `status`, so the mapping is small. An
         * unrecognised value is UNKNOWN — never FAILED.
         */
        'status_map' => [
            'true' => 'SUCCESS',
            'success' => 'SUCCESS',
            'successful' => 'SUCCESS',
            'completed' => 'SUCCESS',
            'delivered' => 'SUCCESS',
            'false' => 'FAILED',
            'failed' => 'FAILED',
            'pending' => 'PENDING',
            'processing' => 'PROCESSING',
            'reversed' => 'REFUNDED',
            'refunded' => 'REFUNDED',
            'cancelled' => 'CANCELLED',
            'canceled' => 'CANCELLED',
        ],

        /* Documented rate limit, used to size the backoff and the sync batch. */
        'rate_limit_per_minute' => 60,

        'retryable_errors' => [
            'insufficient_balance', 'service_unavailable', 'rate_limit',
            'unauthorized', 'invalid_api_key', 'account_suspended',
        ],

        'unknown_errors' => ['timeout', 'connection_error', 'unknown'],
    ],

    /* ---------------------------------------------------------------------
     | VTpass — international airtime and data (controlled fallback)
     |--------------------------------------------------------------------
     | Contract: https://vtpass.com/documentation/foreign-airtime/
     |
     | A documented REST API: API key headers, form-encoded POST bodies, and
     | `serviceID=foreign-airtime` for the international product family.
     |
     | ## Cost model
     |
     | VTpass charges the wallet and reports the commission it paid back, so the
     | effective provider cost is `total_amount` minus `commission`. VTpass
     | documents a convenience fee as a percentage and a per-product commission,
     | and both are in the purchase response — the adapter reads them rather than
     | applying a hard-coded percentage, because a universal figure would be wrong
     | for every product whose rate differs.
     |
     | ## Requery
     |
     | `POST /api/requery` with the request_id is VTpass's status endpoint, and it
     | is why VTpass is a safe fallback: an UNKNOWN outcome can be resolved rather
     | than guessed at.
     */
    'vtpass' => [
        'label' => 'VTpass',
        'docs_url' => 'https://vtpass.com/documentation/foreign-airtime/',
        'env_prefix' => 'VTPASS',

        'base_url' => env('VTPASS_BASE_URL', 'https://vtpass.com/api'),
        'sandbox_base_url' => env('VTPASS_SANDBOX_BASE_URL', 'https://sandbox.vtpass.com/api'),

        /* VTpass documents form-encoded request bodies for every endpoint. */
        'encoding' => 'form',
        /*
         * VTpass authenticates with named headers rather than a bearer token, so
         * the adapter supplies them from `headers` below. The values come from the
         * environment at request time; only the names live here.
         */
        'auth' => 'headers',

        'endpoints' => [
            'countries' => '/get-international-airtime-countries',
            'product_types' => '/get-international-airtime-product-types',
            'operators' => '/get-international-airtime-operators',
            'variations' => '/service-variations',
            'purchase' => '/pay',
            'requery' => '/requery',
            'balance' => '/balance',
        ],

        'capabilities' => ['international_airtime', 'international_data'],

        /*
         * The documented service id for the international product family. Named
         * rather than inlined because it appears on both the catalogue and the
         * purchase call and the two must agree.
         */
        'service_id' => 'foreign-airtime',

        /*
         * Credential header names, as VTpass documents them.
         *
         * `ProviderCredentials` resolves the values; only the header *names* are
         * here, so no secret is ever written into configuration.
         */
        'headers' => [
            'api_key' => 'api-key',
            'public_key' => 'public-key',
            'secret_key' => 'secret-key',
        ],

        /*
         * VTpass returns a three-digit `code` plus a human `response_description`.
         *
         * Everything below is transcribed from the published response-code table
         * (https://vtpass.com/documentation/response-codes/), because these numbers
         * decide whether a customer is refunded, failed over to another provider, or
         * left to reconciliation — and each of those is wrong in a different,
         * expensive way.
         *
         * `000` is not success. It means "processed — now read
         * `content.transactions.status`", which is the only field that says
         * `delivered`, `pending` or `initiated`. `001` is the same shape on a
         * requery. Both are handled together, and neither is treated as an outcome
         * on its own.
         */
        'success_code' => '000',
        'query_code' => '001',

        /*
         * Still moving. PENDING rather than UNKNOWN because the state is genuinely
         * known to be "in flight", and PENDING holds funds and schedules a
         * reconciliation without permitting failover or a refund.
         */
        'pending_codes' => [
            '099',  // TRANSACTION IS PROCESSING
            '089',  // REQUEST IS PROCESSING, PLEASE WAIT BEFORE MAKING ANOTHER REQUEST
        ],

        /*
         * The request was not fulfilled, provably. `091` is VTpass stating in as
         * many words that we are not charged. The rest are our account or the
         * provider's service being unavailable — a configuration fault rather than
         * anything about this customer's request — so a differently-wired provider
         * is a better answer than refusing the sale. `015` is a requery answering
         * that our request id is unknown, which is VTpass's "this transaction was
         * never created" and therefore the one case where retrying is provably
         * safe.
         */
        'retryable_codes' => [
            '015',  // INVALID REQUEST ID — never used on the platform
            '018',  // LOW WALLET BALANCE
            '021',  // ACCOUNT LOCKED
            '022',  // ACCOUNT SUSPENDED
            '023',  // API ACCESS NOT ENABLED FOR USER
            '024',  // ACCOUNT INACTIVE
            '027',  // IP NOT WHITELISTED
            '028',  // PRODUCT NOT WHITELISTED ON YOUR ACCOUNT
            '030',  // BILLER NOT REACHABLE AT THIS POINT
            '034',  // SERVICE SUSPENDED
            '035',  // SERVICE INACTIVE
            '087',  // INVALID CREDENTIALS
            '091',  // TRANSACTION NOT PROCESSED — not charged
        ],

        /*
         * Deterministic rejections. Another provider would reject the request
         * identically, so failing over wastes a request and risks a second charge.
         * `016` is VTpass's own declaration that the transaction failed, which is a
         * statement about the outcome rather than an absence of information.
         */
        'fatal_codes' => [
            '010',  // VARIATION CODE DOES NOT EXIST
            '011',  // INVALID ARGUMENTS
            '012',  // PRODUCT DOES NOT EXIST
            '013',  // BELOW MINIMUM AMOUNT ALLOWED
            '016',  // TRANSACTION FAILED
            '017',  // ABOVE MAXIMUM AMOUNT ALLOWED
            '025',  // RECIPIENT BANK INVALID
            '026',  // RECIPIENT ACCOUNT COULD NOT BE VERIFIED
            '031',  // BELOW MINIMUM QUANTITY ALLOWED
            '032',  // ABOVE MAXIMUM QUANTITY ALLOWED
            '085',  // IMPROPER REQUEST ID — our own bug, and always ours to fix
        ],

        /*
         * Money coming back to the VTpass wallet. REFUNDED, and never treated as a
         * failure: the reversal payload carries the amount actually credited, which
         * is what a refund must be based on rather than the original face value.
         */
        'reversal_codes' => ['040'],

        /*
         * Codes that appear here but mean "requery and find out", or that should
         * not appear in a purchase response at all (`020` is a verification code).
         * UNKNOWN is the safe reading: it neither refunds a delivered order nor
         * retries one that may have been accepted.
         */
        'unknown_codes' => [
            '014',  // REQUEST ID ALREADY EXIST — that earlier transaction is the one to requery
            '019',  // LIKELY DUPLICATE TRANSACTION
            '020',  // BILLER CONFIRMED — a verification code, not a purchase outcome
            '044',  // TRANSACTION RESOLVED — VTpass asks to be contacted
            '083',  // SYSTEM ERROR
        ],

        'unknown_errors' => ['timeout', 'connection_error', 'unknown'],

        /*
         * `content.transactions.status` — the field that actually says whether the
         * customer was served, and only ever meaningful when `code` is `000` or
         * `001`.
         *
         * `initiated` and `pending` both mean "not finished". They are PENDING
         * rather than PROCESSING because VTpass updates the same transaction, and a
         * held-funds state that schedules a requery is exactly what is needed.
         * `reversed` is REFUNDED — the money is back in the VTpass wallet.
         */
        'status_map' => [
            'delivered' => 'SUCCESS',
            'successful' => 'SUCCESS',
            'success' => 'SUCCESS',
            'pending' => 'PENDING',
            'initiated' => 'PENDING',
            'processing' => 'PROCESSING',
            'failed' => 'FAILED',
            'reversed' => 'REFUNDED',
            'refunded' => 'REFUNDED',
        ],
    ],

    /* ---------------------------------------------------------------------
     | Nitro NG — SMM (social boost)
     |--------------------------------------------------------------------
     | Contract: https://nitro.ng/resellers/docs
     |
     | A single POST endpoint at the base URL with a `key` and an `action`
     | parameter, form-encoded, answering JSON. Six actions: services, add,
     | status, refill, balance, cancel.
     |
     | Three properties shape the adapter:
     |
     |   1. **Errors arrive with HTTP 200.** The body carries an `error` field
     |      instead of a status code, so a naive "2xx means success" check would
     |      treat `{"error":"Not enough funds"}` as a vended order. Every response
     |      is inspected for `error` before anything else.
     |   2. **The charge is `rate × quantity ÷ 1000`.** The rate is a per-1000
     |      price in NGN, so the provider cost of a 1,000-unit order at a rate of
     |      2,720 is ₦2,720 — not ₦2,720,000. Getting the divisor wrong by a factor
     |      of 1000 is the single most expensive mistake available in this file,
     |      so it is a configured, named constant and the calculated cost is
     |      compared against the provider's own `charge` response field.
     |   3. **The provider explicitly instructs that `description` be read before
     |      a service is sold** — it carries setup the buyer must complete. That is
     |      why the field is stored on `provider_products` and surfaced in the
     |      admin review screen.
     */
    'nitro' => [
        'label' => 'Nitro NG',
        'docs_url' => 'https://nitro.ng/resellers/docs',
        'env_prefix' => 'NITRO',

        'base_url' => env('NITRO_BASE_URL', 'https://nitro.ng/api/v2'),

        /*
         * Nitro reads form-encoded parameters, not JSON. This also matters for the
         * key: it is sent as the `key` form field, never as an authorization
         * header, so a Nitro request carries no credential in its headers at all.
         */
        'encoding' => 'form',
        'auth' => 'body',

        /* The action is a body parameter, not a path — there is one endpoint. */
        'actions' => [
            'services' => 'services',
            'add' => 'add',
            'status' => 'status',
            'refill' => 'refill',
            'balance' => 'balance',
            'cancel' => 'cancel',
        ],

        /*
         * The rate divisor, from the documented charge formula
         * `rate × quantity ÷ 1000`. Named rather than inlined because it is the
         * one number in this integration that is wrong by three orders of
         * magnitude if it is mistyped.
         */
        'rate_divisor' => 1000,

        'capabilities' => ['smm'],

        /*
         * Nitro's status vocabulary, including the two states most panels omit:
         * `Partial` (some of the order delivered) and `Refunded`. Both are final
         * for ReUp purposes but neither is a plain success or failure, so both map
         * to a distinct canonical status.
         *
         * `error` maps to UNKNOWN, not FAILED. It is the marker Nitro uses on a
         * refused *request*, and a refused request is one that never reached the
         * customer — but this map is also consulted for a status string, and a
         * status string of `error` is not evidence that an order was not
         * delivered. UNKNOWN forces reconciliation instead of a refund.
         */
        'status_map' => [
            'pending' => 'PENDING',
            'inprogress' => 'PROCESSING',
            'processing' => 'PROCESSING',
            'completed' => 'SUCCESS',
            'partial' => 'PARTIAL',
            'canceled' => 'CANCELLED',
            'cancelled' => 'CANCELLED',
            'refunded' => 'REFUNDED',
            'failed' => 'FAILED',
            'error' => 'UNKNOWN',
        ],

        /*
         * Error strings, normalised (lower-cased, punctuation stripped) →
         * classification.
         *
         * Nitro answers HTTP 200 with `{"error": "..."}`, so the body text is the
         * only signal available. Matching is done on a normalised form because
         * the strings are documentation, not constants, and an operator-facing
         * provider may reword them.
         */
        'error_map' => [
            'invalidapikey' => 'RETRYABLE',
            'incorrectserviceid' => 'RETRYABLE',
            'notenoughfunds' => 'RETRYABLE',
            'quantityoutofrange' => 'RETRYABLE',
            'invalidlink' => 'RETRYABLE',
            'ordernotfound' => 'RETRYABLE',
        ],

        /* Any unrecognised error string is treated as this. Never FAILED. */
        'default_error_status' => 'UNKNOWN',

        /* Bulk status polling: the documented cap. */
        'max_bulk_status_ids' => 100,

        'retryable_errors' => ['invalidapikey', 'incorrectserviceid', 'notenoughfunds', 'quantityoutofrange', 'invalidlink', 'ordernotfound'],
        'unknown_errors' => ['timeout', 'connection_error', 'unknown'],
    ],
];
