# Wave 1 — Providers, Pricing and Operations

This is the operator's document for the Wave 1 services: the providers behind them,
how their costs become our prices, how to keep them healthy, and what to do when one
stops working.

It covers the second brief. The first (financial hardening) is in
`docs/DEPLOYMENT-FINANCIAL-HARDENING.md`, and the two are independent: nothing here
changes how a wallet is debited, and nothing there changes how a provider is chosen.

---

## 1. What was added, in one page

**Providers.** Four adapters behind `App\Providers\ProviderRegistry`:

| Provider | Serves | Auth | Contract |
|---|---|---|---|
| Sogo | Nigerian bills, ePIN, education, gift cards (buy) | `Authorization: Bearer` | [docs](https://developer.sogo.africa/docs) |
| VTUGate | International airtime and data | `Authorization: Bearer` | [docs](https://vtugate.com/docs) |
| VTpass | International airtime and data (fallback) | `api-key` + `public-key` (GET) / `secret-key` (POST) | [docs](https://vtpass.com/documentation/foreign-airtime/) |
| Nitro NG | SMM / social boost | key as a form field | [docs](https://nitro.ng/resellers/docs) |

`App\Services\ProviderManager` is **untouched** and still serves airtime, data, cable,
electricity, exam PINs and betting through ClubKonnect and Pairgate. That was
deliberate: rewriting the live vending path to add new products is exactly the trade
the brief forbids.

**Pricing.** `App\Pricing\PricingEngine` turns a provider cost into a customer price
using the rule hierarchy in the `pricing_rules` table. Every quote records the cost,
the rule and the rate it used on an immutable `pricing_snapshots` row.

**Monitoring.** `App\Providers\ProviderMonitor` probes each provider's health and float,
records the history, and raises alerts.

**The storefront.** `App\Catalogue\ServiceCatalogue` decides what a customer can buy,
from the database. The copy is in `config/copy.php` and resolved by
`App\Support\UiCopy`.

---

## 2. Configuration

Everything is environment-driven; `.env.example` documents each variable with its
reasoning. The short version:

```dotenv
# Sogo
SOGO_API_KEY=...            # or SOGO_READ_KEY / SOGO_WRITE_KEY for scoped keys

# VTUGate — see §5 before enabling
VTUGATE_FIELDS_VERIFIED=false
VTUGATE_KEY=...

# VTpass — all three, because the credential differs by HTTP method
VTPASS_API_KEY=...
VTPASS_PUBLIC_KEY=...
VTPASS_SECRET_KEY=...

# Nitro
NITRO_KEY=...
```

**Credentials are never written to configuration or the database.**
`ProviderCredentials` reads the process environment at call time, so a `config:cache`
artefact can never contain a secret and a debug endpoint can never dump one. A
provider row records only the *prefix* (`SOGO`, `VTPASS`), which is what lets the admin
console show "configured / not configured" without handling a value.

**Leaving a credential blank disables that provider**, and that is a supported state,
not an error: the router skips it and the next candidate serves the order.

---

## 3. Routing

Which provider serves a capability is the `providers` table:

* `capabilities` — the tokens a provider can serve;
* `is_active` — switched off providers are never selected;
* `is_primary` — preferred over any other provider at the same priority;
* `priority` — ascending; lower is tried first.

`ProviderRegistry::candidatesFor()` applies all four, and then two further filters that
are not configuration:

* **the adapter must declare the capability**, so a row claiming `gift_cards` for an SMM
  panel is not honoured;
* **the adapter must be operational**, which is how an integration whose request field
  names are unconfirmed is kept out of the call path rather than sending a misaddressed
  request.

There is no second routing table. A separate `primary_provider` / `fallback_provider`
table would be a second answer to the same question, and the disagreement would show up
as an order routed somewhere nobody chose.

Edit it at **`/admin/providers`** (Super Admin only). The screen shows the resolved
order for every capability, so you can see what the router will do rather than infer it.

**Failover happens only on `RETRYABLE`** — a state that means the provider explicitly
did not fulfil the request. It never happens on `UNKNOWN`, `PENDING` or `FAILED`:

* `UNKNOWN` — the request may have been accepted. Retrying is how a customer is charged
  twice.
* `FAILED` — the provider rejected it deterministically. Another provider would reject
  it identically.
* `RETRYABLE` — our float is short, or the account is suspended, or a requery proved the
  transaction was never created. Nothing was vended, so trying elsewhere is safe.

A missing credential is `RETRYABLE`, not `UNKNOWN`, because the request was provably
never sent.

---

## 4. Cost, price and profit

```
provider cost (kobo, integer)
        │
        ▼
PricingEngine ── resolves the narrowest applicable rule
        │         provider_product → product → provider → category → global
        ▼
customer price (kobo)  ── recorded on an immutable pricing_snapshots row
        │
        ▼
WalletService  ── the only path that moves money
```

Three rules worth stating plainly:

1. **Markup is not margin.** Markup is profit ÷ cost; margin is profit ÷ price. Both are
   stored and neither is used as the other.
2. **Profit is recomputed from the final rounded price**, never from the pre-rounding
   figure. A price rounded down by a kobo is a kobo less profit.
3. **Profitability is checked on the rounded price.** If the result cannot meet the
   configured floor, `ServiceProduct::availability` becomes `TEMPORARILY_UNAVAILABLE`
   automatically. The product is withheld and the customer sees
   *"This service is temporarily unavailable while we update its pricing."* — never a
   cost, a margin or a provider name.

Review the margin watchlist at **`/admin/profit/watchlist`**, which recomputes live
rather than reading a stored flag, so a cost that rose this morning appears without
waiting for a job.

---

## 5. VTUGate is configured but not routed to

VTUGate's published reference is a client-rendered page. Its endpoint paths and bearer
scheme are in the served document and are used verbatim. The request **body field names**
for the international group are not in the document, are not in any published OpenAPI
definition, and are not indexed publicly — that was checked rather than assumed.

This application does not invent the field names of a financial request. Sending
`recipient` where the API expects `phone` does not fail loudly: it sends a top-up to the
wrong number, or to nobody, and the money leaves either way.

So:

* every field name is read from `providers.vtugate.fields` / `response_fields`;
* `VtugateProvider::isOperational()` returns false while `VTUGATE_FIELDS_VERIFIED` is
  false, and the registry skips it;
* the international route therefore uses **VTpass**, whose contract is fully published —
  so the product works today;
* `/admin/providers` shows exactly this: *"Not routable: integration unverified."*

**To bring VTUGate live:** confirm every name in `providers.vtugate.fields` against the
vendor's own dashboard or a Test-API-Key call, correct any that differ, set
`VTUGATE_FIELDS_VERIFIED=true`, and verify with a sandbox top-up. It then becomes the
primary international route automatically, with VTpass behind it.

Nothing else needs to change: the field names are configuration, not code.

---

## 6. Catalogue synchronisation

```bash
php artisan providers:sync-catalogues              # all providers, hourly
php artisan providers:sync-prices --since=7        # what moved, by size
php artisan providers:sync-gift-cards              # gift card buy catalogue
php artisan providers:sync-international-products  # includes the FX rate
php artisan providers:sync-esim-products           # no-op by design; see §9
php artisan providers:check-balances               # health and float
php artisan providers:prune-health                 # retention
```

Scheduled in `app/Console/Kernel.php`: balances every 5 minutes, catalogues hourly,
international every 6 hours, gift cards daily, retention daily.

### What the sync does, and what it refuses to do

It **updates costs** for products already mapped to a ReUp product. It **never creates a
sellable product.** Anything it does not recognise goes to `provider_catalogue_items` for
an operator to map, and there is no path from that table to an order.

The reason is not purity. Matching `MTN 1GB Monthly` to our own product by name would
work on the day it was written and sell the wrong bundle the first time the provider
reworded a plan — and the customer is charged for something they did not choose.

### Properties you can rely on

* **Idempotent.** Every write is keyed by a unique index
  (`provider_products`, `international_products` and `provider_catalogue_items` all key on
  `(provider_id, provider_product_id)`), so an overlapping or retried run changes
  `last_seen_at` and nothing else.
* **History is append-only.** A cost change writes a `provider_price_snapshots` row.
  Nothing updates one.
* **A completed sale is never repriced.** `pricing_snapshots` is not touched by any sync.
  Repricing affects the future.
* **A failed read changes nothing.** If a catalogue cannot be read, existing costs stay
  as they are and no product is marked unavailable — an API hiccup is not evidence that a
  product was withdrawn. An alert is raised, and the staleness is visible.
* **A vanished product is flagged, not deleted.** `unavailable_at` takes it out of
  routing immediately, and the row stays so historical orders and cost snapshots remain
  resolvable.
* **A cost the provider stops stating becomes null**, which makes the product unsellable
  rather than carrying the last known figure forward.

---

## 7. Monitoring and alerts

`providers:check-balances` probes each provider's `balance()`, because it is the cheapest
authenticated read any adapter offers. Anything else would either cost money or be too
weak to distinguish a wrong key from a slow morning.

Run it manually at any time from `/admin/providers` with **Check now** — an operator who
has just topped up a wallet should not have to wait five minutes to find out.

### Alert conditions

| Type | Raised when |
|---|---|
| `provider_low_balance` | float is below the provider's configured threshold |
| `provider_unavailable` | three consecutive failed probes |
| `provider_auth_failure` | the provider rejected our credential |
| `provider_suspended` | the provider suspended our account |
| `catalogue_unavailable` | a catalogue read failed |
| `unusual_price_change` | a cost moved more than `PROVIDER_COST_CHANGE_BPS` |
| `high_failure_rate` | failures exceed `PROVIDER_FAILURE_RATE_BPS` |

### One condition, one open alert

Alerts are deduplicated by a fingerprint, and the database enforces **at most one open
alert per condition** through a unique index on a generated column that is NULL once
resolved. Two consequences that matter:

* a condition that persists updates its existing alert rather than creating one every
  five minutes — 288 identical alerts a day is how a monitor gets ignored;
* a **resolved** condition can be raised again if it recurs. A globally unique
  fingerprint would have made resolution permanent, so a provider that ran low on float
  once could never alert on it again — a silent failure with a healthy-looking dashboard.

Acknowledging means "I have seen this" and does **not** resolve. Only **Resolve** clears
the condition and re-arms the monitor.

---

## 8. Products that need provider approval

These are documented or advertised but are **NOT production-available** until the named
approval or confirmation is obtained. They are reported here rather than enabled
silently.

| Product | Provider | Blocker |
|---|---|---|
| International airtime/data (primary route) | VTUGate | Request field names not published; needs vendor confirmation (§5) |
| eSIM | — | No integrated provider publishes an eSIM endpoint (§9) |
| Gift cards | Sogo | Catalogue is read and staged; each variant needs an operator mapping and a price before it can be sold. Confirm the buy API is enabled on the Sogo account |
| SMM / social boost | Nitro | Confirm the account is approved for the services being published; the panel's `description` must be read before a service is sold, because it carries purchaser pre-requisites |
| Education (WAEC/JAMB) | Sogo | Product-specific whitelisting may be required on the Sogo account |
| International airtime/data (fallback) | VTpass | Product whitelisting (`028 PRODUCT IS NOT WHITELISTED`) is per account; enable the products before selling |

---

## 9. Products intentionally not enabled

**eSIM.** No integrated provider publishes an eSIM catalogue or purchase endpoint. Sogo's
marketing pages list eSIM among the products its bill API covers, but its published API
reference documents no eSIM endpoint; VTUGate, VTpass and Nitro publish none either. An
eSIM is issued against a carrier rather than bought as a bill, so it needs a contract
none of these providers currently offers.

`providers:sync-esim-products` therefore runs as a no-op that explains itself and exits 0.
It exists because a scheduled command that does not exist is indistinguishable from one
that is broken. It **fails loudly** if an adapter ever declares the `esim` capability, so
the capability cannot be switched on without a real integration behind it.

**Gift card selling / trading.** Not built, not planned. Only the buy side exists: ReUp
purchasing a card to deliver to a customer. No endpoint is called and no table is written
that would represent a customer selling a card to us.

**B2B / reseller / white-label / API marketplace, per-service wallets, consumer
lending.** Not built.

---

## 10. Reconciliation

An `UNKNOWN` outcome is never guessed at. `ServiceOrderService::reconcile()` asks the
provider what happened, and what it can do depends on the provider:

| Provider | Reconciliation | A "never created" answer |
|---|---|---|
| Sogo | `GET /transactions/{idempotency-key}` | `404` → RETRYABLE |
| VTpass | `POST /requery` with our `request_id` | code `015` → RETRYABLE |
| VTUGate | `POST /international/topupstatus` | — |
| Nitro | `status` action | — |

The idempotency key is generated **once** per order, stored on
`service_orders.provider_idempotency_key`, and reused verbatim. It is never regenerated
while the outcome is unresolved — regenerating it is precisely how a retry becomes a
second charge.

`payments:review-unconfirmed` reports unresolved orders hourly, and the reconciliation
sweep runs every minute for anything due.

---

## 11. Webhooks

No Wave 1 provider's webhook is trusted to finalise a financial state on its own.

VTpass documents a transaction-update webhook with no signature scheme, so a
callback-shaped request cannot be distinguished from a forged one. The webhook is
therefore treated as a **hint that something changed** and the transaction is requeried
before any state changes. A webhook that could move money on its own would be an
unauthenticated write to the ledger.

Sogo's webhook is bound to the `Idempotency-Key` we sent, and Sogo omits gift card codes
from it deliberately — the codes are fetched over the authenticated API instead and stored
through `DeliveryTokenStore`.

---

## 12. Routine operations

```bash
# Is the scheduler alive? Run after every deploy.
php artisan schedule:health --check

# What is the state of every provider?
php artisan providers:check-balances

# What moved in costs this week?
php artisan providers:sync-prices --since=7

# Which products are waiting to be mapped?
php artisan providers:check-balances --json    # and see /admin/providers

# Wallet integrity (financial hardening)
php artisan wallet:verify
```

### When an order fails

1. **`UNKNOWN`** — do nothing to the order. Run the reconciliation sweep or wait for it.
   Never refund and never retry: the customer may already have been served.
2. **`RETRYABLE`** — the order was already failed over automatically if another provider
   could serve it. If none could, check `/admin/providers` for a suspended account or an
   empty float.
3. **`FAILED`** — the refund path handles it. Check whether the failure is systematic
   (one product, one provider) rather than a single bad request.

### When a provider stops responding

1. `/admin/providers` → the provider's screen → **Check now**.
2. Read the probe history: when did it start, and is the balance or the credential the
   problem?
3. If the credential is the problem, rotate it in the environment and re-check. A
   rejected credential is classified `RETRYABLE`, so orders are *failing over* rather
   than being held — which hides the problem behind a more expensive route.
4. If it will be down for a while, switch it off in routing. Orders then go straight to
   the next candidate instead of waiting for a probe to fail.

---

## 13. Deployment checklist

```bash
php artisan down                     # optional, but a migration is not instant

php artisan migrate --force
php artisan db:seed --class=ServiceCatalogueSeeder   # categories only; idempotent

php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan schedule:health --check
php artisan providers:check-balances

php artisan up
```

Then, in the admin console:

1. `/admin/providers` — every provider you intend to use shows **configured** and a
   balance; anything showing *Not routable: integration unverified* is expected for
   VTUGate until §5 is done.
2. `/admin/pricing` — a pricing rule exists for every product you intend to sell.
   Without one, nothing can be quoted and nothing is sellable.
3. `/admin/providers/{provider}` — map the staged catalogue variants you want to sell,
   and publish the resulting products. A product is `DISCOVERED` and
   `TEMPORARILY_UNAVAILABLE` until you actively publish it.
4. `/admin/profit/watchlist` — confirm nothing is withheld for pricing that you expected
   to be on sale.
5. Place one sandbox purchase end to end with `PROVIDER_SANDBOX=true`, then switch it
   back off and sync the real catalogue.

**Production safety.** No migration resets data. Nothing rewrites a historical balance,
a `pricing_snapshots` row or a `provider_price_snapshots` row. Costs and availability are
current state and are updated; history is append-only.
