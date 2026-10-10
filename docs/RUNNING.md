# Running the app locally

## One command

```
composer run dev
```

Serves the application at **http://127.0.0.1:8000** using the **built** assets in
`public/build`. Nothing else is needed — this is the mode to use if you are not
editing CSS or JavaScript.

## Live reload (editing front-end files)

Asset resolution has two modes and picks one automatically:

| Condition | Mode | Where assets come from |
| --- | --- | --- |
| `public/hot` exists **and** the dev server answers | dev server | `http://127.0.0.1:5173` (HMR) |
| otherwise | built | `public/build/assets` via the manifest |

So run two terminals:

```
# terminal 1
composer run dev      # or: composer run serve

# terminal 2
npm run dev           # Vite dev server on 127.0.0.1:5173
```

Edit a Blade file or `resources/css/app.css` and the browser updates without a
reload. When you stop Vite, `public/hot` is removed and the next page load falls
back to the built assets.

`composer run vite` is a shorthand for `npm run dev` if you would rather keep
everything under Composer.

## Other scripts

| Command | Does |
| --- | --- |
| `composer run serve` | `php artisan serve` only |
| `composer run vite` | `npm run dev` only |
| `composer run test` | `php artisan test` |
| `composer run fresh` | `php artisan migrate:fresh --seed` |
| `npm run build` | Production asset build |
| `php tools/check-assets.php` | Asserts the page loads its stylesheet and scripts |
| `php tools/browser-audit.js` | Renders pages in headless Chromium and reports JS failures |

---

## The scheduler must be running

`app/Console/Kernel.php` schedules these entries:

| Schedule | Command | Why |
| --- | --- | --- |
| every minute | `payments:reconcile` | Resolves **Paystack** payments a webhook never confirmed, and fails payments that were abandoned or never reached a gateway |
| every two minutes | `payments:auto-resolve` | Resolves **everything else still pending** — card funding through either gateway, and provider-backed purchases whose outcome is `unknown` |
| hourly | `payments:review-unconfirmed` | Asks providers about purchases whose outcome is unknown, and reports the ones that still are |
| every minute | *(heartbeat)* | Writes `scheduler:last_run`, which `schedule:health` reads |

**Without a scheduler runner none of these executes** and pending payments stay
pending forever. Nothing in the application fails when that happens — no error is
logged and no page breaks — so this is checked explicitly rather than assumed.

Development, one terminal:

```
php artisan schedule:work
```

Production, one cron entry (or the Windows equivalent):

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan schedule:list` shows what is registered.

### Prove it is running

```
php artisan schedule:health            # report
php artisan schedule:health --check    # exit 1 when the scheduler is not running
```

`--check` is a deploy gate and a monitoring probe. Wire it into whatever alerts
you — a silently dead scheduler is the single most damaging deploy mistake
available in this application, because payments simply stop being reconciled:

```
*/5 * * * * cd /path/to/app && php artisan schedule:health --check || notify-ops "scheduler down"
```

To run the sweeps by hand:

```
php artisan payments:reconcile --dry-run                # report only, changes nothing
php artisan payments:reconcile                          # poll and resolve
php artisan payments:auto-resolve --dry-run             # what the two-minute sweep would do
php artisan payments:auto-resolve                       # do it
php artisan payments:review-unconfirmed --list           # what is still unconfirmed
php artisan payments:review-unconfirmed                  # ask the providers
```

The reconciliation sweep only ever *polls* payments where Paystack is the
authority (card, bank transfer, USSD funding). Bill purchases are resolved by
ClubKonnect/Pairgate and are deliberately excluded — Paystack has never heard of
them, so polling one would return "not found" and could wrongly close a delivered
order.

#### `payments:auto-resolve` — the sweep that covers the whole set

`payments:reconcile` being Paystack-only left a gap: an airtime or electricity
row whose provider call timed out sat at `pending`/`processing`/`unknown` until
somebody pressed **Refresh status** on it in the console. This command applies
that same decision — `App\Services\PaymentStatusResolver`, one implementation,
asked by a human about one row or by the schedule about all of them — to every
open row, every two minutes.

```
php artisan payments:auto-resolve                        # all open rows
php artisan payments:auto-resolve --dry-run              # ask everything, write nothing
php artisan payments:auto-resolve --limit=25             # bound one run's API traffic
php artisan payments:auto-resolve --grace=5              # ignore rows younger than 5 minutes
php artisan payments:auto-resolve --type=funding         # narrow to a service type (repeatable)
```

What it will not do, and why each refusal is load-bearing:

| Refusal | Why |
| --- | --- |
| Writes nothing when a gateway is unreachable | A provider that cannot be reached has said nothing. Writing a live payment off on that basis is how a customer pays and receives nothing. |
| Refunds a bill purchase only on the provider's word | A purchase the provider is still working on can answer "received" one minute and "failed" the next. Refunding a vend that then completes gives away goods. |
| Never queries a `pending`/`processing` bill purchase | The narrow default. `unknown` is the state that needs resolving; the others belong to the provider and its webhook. An operator looking at one row they doubt gets the wider appetite through the console's **Refresh status**. |
| Never touches a bank transfer | A dedicated virtual account has no per-transaction lookup, so the inbound transfer is matched by the gateway's webhook. |
| Skips rows younger than `--grace` (default 1 minute) | The webhook gets its chance first. This is the fallback, not the primary path. |

`--dry-run` asks every question the real run would ask — the same gateways, the
same provider status queries — and writes nothing. It re-reads each row
afterwards and logs at `critical` if anything moved, so the guarantee is checked
rather than asserted. Exit code is non-zero only when a row could not be
processed at all; "nothing to resolve" and "a provider was unreachable" are both
success, because neither is a fault in the command.

#### A funding attempt that never reached Paystack fails itself

An attempt that dies before the customer is handed to the gateway — a missing
key, an unreachable API, a rejected initialise call — is written off
automatically on a **short** window (`--unreached`, default 30 minutes) rather
than the 24-hour `--lookback` used for a checkout that was actually opened. The
distinguishing signal is the Paystack access code stored on the transaction
during `initialize()`: no access code means checkout was never reached, so there
is no session to complete and no charge in flight. `initiated_at` is *not* used
for this — it is stamped before Paystack is called.

Two other things close a row out immediately, without waiting for any window:
a `failed`, `abandoned` or `reversed` verdict from the gateway itself.

This is safe in the direction that matters: `settle()` still credits a row the
gateway later confirms as paid, even one this sweep has already written off, so
an early failure can never cost a customer money.

### Unknown outcomes are not failures

A bill purchase whose provider call timed out is left in the `unknown` state:
the money stays debited, and it is **not** refunded, retried or marked failed,
because the order may have been vended and every one of those actions is wrong
if it was. It is resolved by a provider status query, by the provider's webhook,
or by a human in the admin review — and `payments:review-unconfirmed` is what
makes sure it cannot be forgotten.

When one of these logs appears, a person has to look at it:

```
grep 'purchase_outcome_unknown\|unconfirmed_purchases_remain' storage/logs/laravel.log
```

### Verifying wallet balances

```
php artisan wallet:verify                   # every wallet; non-zero exit on drift
php artisan wallet:verify --user=42         # one customer
php artisan wallet:verify --statement=42    # that customer's ledger statement
```

Every wallet balance must equal the sum of its immutable ledger. A wallet that
fails is one that has been mutated outside `App\Services\WalletService`, which is
the one thing the architecture forbids. Nothing is changed by the command — it
reports, so an operator can decide.


## Bill-payment providers

Airtime, data, cable, electricity, exam pins and betting funding are vended by
one of two upstreams. `ProviderManager` picks per purchase, in this order:

1. configured providers that serve the product, in `BILL_PROVIDER_ORDER`
   (default `clubkonnect,pairgate`);
2. **only ones that answer a liveness probe** (`BILL_CHECK_PROVIDER_HEALTH`) —
   a wallet enquiry on each candidate. If none answer, the sale is refused and
   **no money leaves the customer's wallet**;
3. only ones whose float covers the charge (`BILL_CHECK_PROVIDER_BALANCE`);
4. of those, **the cheapest for this exact purchase** (`BILL_OPTIMISE_COST`) —
   see "Cheapest-provider routing" below;
5. the first provider that accepts the request wins.

| Provider | Env | Role |
| --- | --- | --- |
| ClubKonnect (NelloBytes) | `CLUBKONNECT_CLIENT_ID`, `CLUBKONNECT_API_KEY` | primary |
| Pairgate | `PAIRGATE_API_KEY`, `PAIRGATE_WEBHOOK_SECRET` | failover |

`php artisan env:doctor` prints which of them are configured and the failover
order. A provider with no credentials is skipped, so leaving `PAIRGATE_API_KEY`
blank means ClubKonnect serves everything — that is a valid configuration, just
without a second route when ClubKonnect is down or short of float.

### When a provider is not working

```
php artisan bills:diagnose                       # ClubKonnect by default
php artisan bills:diagnose --provider=pairgate
php artisan bills:diagnose --raw                 # show the upstream body, redacted
```

It walks the four layers that can each fail while producing the same one-word
"Attention" on the dashboard: configuration, the balance enquiry, the health
probe's verdict, and whether routing would actually try the provider. It prints
credential *lengths* and never a credential.

#### ClubKonnect sends its balance as a formatted string

```
{"date":"10th-Oct-2026","id":"…","phoneno":"…","balance":"4,985.28"}
```

A JSON **string**, with a thousands separator — so `is_numeric($balance)` is
`false`, and three separate consumers asked exactly that question:

| Consumer | Consequence of `false` |
| --- | --- |
| `ClubKonnectProvider::ping()` | the provider is reported down, so `ProviderManager` skips it for every sale |
| `ClubKonnectProvider::balance()` | "Unable to fetch balance" on the dashboard |
| the float check | every purchase refused as unaffordable |

A funded, reachable, correctly configured upstream was therefore **out of service
because of one comma** — and it stayed out of service silently, because the
provider was answering `HTTP 200` the whole time.

`ClubKonnectService::normaliseBalance()` parses it once, at the boundary where the
provider's payload becomes our data, and keeps the original as
`balance_formatted`. It deliberately does *not* zero an unparseable value: a zero
reads as an empty wallet, so a caller would refuse sales for a reason that is not
real. `ClubKonnectBalanceTest` pins all of it, including that the raw string is one
`is_numeric` rejects — so if the provider ever changes format, the failure says so
instead of looking like a mystery.

### Availability, and why a purchase can be refused

Each candidate is probed on its cheapest authenticated endpoint (a wallet
balance) before anything is debited. The answer is cached for a minute and
discarded the moment a real request fails, so a provider that has just gone down
is not trusted for long.

Probes are believed on purpose. If neither upstream can answer a balance
enquiry, the customer sees *"This service is temporarily unavailable. No refund
has been made and no money has left your wallet."* — which is strictly better
than debiting them for a vend that cannot happen and cleaning it up with a
refund. Set `BILL_CHECK_PROVIDER_HEALTH=false` only if the probes themselves
misbehave; that reverts to attempting every purchase on the configured order.

### Cheapest-provider routing

Before the wallet is debited, each candidate is asked what this exact purchase
costs *us* — not what the customer pays:

| Product | ClubKonnect price from | Pairgate price from |
| --- | --- | --- |
| data | the pricelist catalogue (`clubkonnect_price`) | `pairgate.data_plans` mapping, or the live catalogue when a `plan_type` is mapped |
| cable | *unknown* — both a package amount and a discount amount are published and nothing establishes which is wholesale | `pairgate.cable_packages` mapping, or `GET /cable-plans` |
| electricity, betting | the amount itself (face value) | the amount itself (face value) |
| airtime, exam pins | *unknown* — discounts are not published per network | *unknown* |

The rule that matters: **the cheapest wins only when every candidate has a
price.** One unknown price and `BILL_PROVIDER_ORDER` stands, so a gap in the
pricing data can never quietly redirect traffic (and margin). Electricity and
betting always tie, so nothing changes for them; data is where this earns money,
and only once `pairgate.data_plans` is filled in.

Set `BILL_OPTIMISE_COST=false` to disable it entirely.

### Pairgate

Docs: <https://pairgate.com/developers/introduction>. The key is sent as
`Authorization: Bearer …`; base URL and test mode come from
`PAIRGATE_BASE_URL` and `PAIRGATE_TEST_MODE`. Test mode prefixes every path with
`/test`, which Pairgate answers without debiting the wallet — use it once after a
deploy, then turn it off, because in that mode nothing is actually delivered.

Two things need attention before Pairgate can serve everything:

1. **Identifiers are translated in `config/bills.php` under `pairgate`.**
   Pairgate uses its own slugs (`mtn`, `ikedc`, `bet9ja`) where this application
   uses ClubKonnect's numeric codes. Networks, discos, cable providers,
   bookmakers and exam bodies are mapped there already. **`data_plans` and
   `cable_packages` are empty by design** — plan ids are provider-specific and
   unrelated, so they have to be filled from `GET /data-plans` and
   `GET /cable-plans` before Pairgate can sell data or cable. Until then those
   two products are refused locally and failed over, rather than sent upstream
   with a guessed plan id.

   Each entry may also carry the price, which is what switches cheapest-provider
   routing on for that bundle:

   ```php
   'data_plans' => [
       '1234' => '45',                                        // sellable, price unknown
       '1235' => ['plan_id' => '46', 'plan_type' => 'SME'],   // price read from the live catalogue
       '1236' => ['plan_id' => '47', 'price' => 500.0],       // price recorded here
   ],
   ```

2. **Electricity tokens and exam PINs arrive asynchronously.** The purchase call
   only acknowledges the order; the token/PIN is delivered on Pairgate's
   webhook (`pin` field, one webhook per exam pin). The receiver is
   `POST /pairgate/webhook`, so the URL to paste into the Pairgate dashboard is
   this application's public domain plus that path:

   ```
   https://<your-domain>/pairgate/webhook
   ```

   It is authenticated by HMAC, so **PAIRGATE_WEBHOOK_SECRET must be set** — the
   value shown once when signing is enabled for the API key in the Pairgate
   dashboard — and the endpoint answers 503 until it is. Without the webhook a
   purchase vended by Pairgate is recorded as successful but never carries its
   token, and an order Pairgate accepts and later fails is never refunded.
   Individual transactions can also be polled at
   `GET /transaction/status?reference_code=…`.

   The same URL answers the Pairgate dashboard's test delivery, but that test
   carries no valid signature, so expect a 401 in the server log — 401 there
   means the endpoint is wired and the secret is protecting it.

Pairgate also has floors that differ from ours — electricity ₦1,000 (we allow
from ₦500), and ₦50 on airtime, data and betting. An amount below a floor is
rejected upstream and failed over; raise `bills.ranges` if that shows up in the
logs as repeated `INVALID_AMOUNT` failovers.


## Before committing

```
npm run build
php tools/check-assets.php
php artisan env:doctor
php artisan test
```

`check-assets.php` exists because the failure it guards against is **silent**: if
asset tags are missing the page still returns HTTP 200, just completely
unstyled. It asserts on the rendered markup and fetches the referenced files,
rather than trusting a status code.

`env:doctor` reports environment variables that shadow `.env`, plus mail and
provider misconfiguration. See below for why that matters.

---

## Tests run against a separate database

`phpunit.xml` pins `DB_DATABASE=reup_test`. **Do not remove that line.**

It was previously commented out, so the suite fell through to `.env` and ran
against the **development** database. Because the tests use `RefreshDatabase`
(which is `migrate:fresh`), a single `php artisan test` silently erased every
user, wallet and transaction in development. Create the throwaway database once:

```sql
CREATE DATABASE reup_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

MySQL rather than SQLite on purpose: the migrations are not engine-portable —
`2026_02_10_000001` uses `UPDATE ... LEFT JOIN`, which SQLite rejects — so a
SQLite run would fail for reasons that have nothing to do with the code under
test.

---

## Indexed strings are capped at 191 characters

`AppServiceProvider::boot()` calls `Schema::defaultStringLength(191)`. Do not
remove it: on MySQL/MariaDB builds whose InnoDB index key limit is 1000 bytes
rather than 3072, `php artisan migrate` dies on the very first run with

```
SQLSTATE[42000]: ... 1071 Specified key was too long; max key length is 1000 bytes
(SQL: alter table `personal_access_tokens` add index
      `personal_access_tokens_tokenable_type_tokenable_id_index`
      (`tokenable_type`, `tokenable_id`))
```

`morphs('tokenable')` creates a `VARCHAR(255)`, and under `utf8mb4` that is
255 × 4 = 1020 bytes; adding the `tokenable_id` bigint puts the composite index
over the limit. 191 × 4 = 764 bytes, which leaves room. The same cap protects
every other indexed string column (`users.email`, `transactions.reference`,
`site_settings.key`, `failed_jobs.uuid`).

It only applies where no length is given, so the explicitly-sized indexed columns
(`string('token', 64)`, `string('purpose', 32)`, `string('type', 20)`) are
unaffected, and the widest composite index left in the schema is
`tokenable_type` + `tokenable_id` at 772 bytes.

**Recovering from the failed first run.** `Schema::create` issues the `CREATE
TABLE` first and the index as a separate `ALTER`, so the failed migration leaves
`personal_access_tokens` behind *without* its index — and because the migration
was never recorded, re-running `migrate` stops with "table already exists"
instead of the original error. On a fresh install, start clean:

```
php artisan migrate:fresh
```

If the database already holds data you need, drop only the orphan table and
migrate again:

```
php artisan tinker --execute="Schema::dropIfExists('personal_access_tokens');"
php artisan migrate
```

---

## The administrator account

There are no seeded admins by default. To create one — needed for anything
under `/admin`, since bootstrap registration closes the moment any admin exists:
```
php artisan db:seed --class=AdminSeeder
```

It creates a single super admin and prints the credentials:

```
Email:    superadmin@reup.com.ng
Password: Admin@123456
Role:     Super admin (all permissions)
```

One account, one purpose. A super admin passes every permission check, so there
is nothing to remember about which login can reach which screen. If you want to
test that the restricted roles are actually restricted, create `admin` or
`support` accounts in the console under **Administrators** — the same path real
staff accounts take, and the only path that exercises the permission UI.

Override the password when you need to:

```bash
ADMIN_SEED_PASSWORD='something-long-enough' php artisan db:seed --class=AdminSeeder
```

In `production` the seeder **refuses to run** with the built-in default unless
`ADMIN_SEED_ALLOW_PRODUCTION=true` is also set: the console can move money, so a
published password there is a total compromise rather than an inconvenience.

The seeder is idempotent — it matches on email, so re-running updates the one row
instead of failing on the unique index or creating duplicates. It is deliberately
**not** wired into `DatabaseSeeder`, so a bare `migrate --seed` never silently
creates an administrator.

### An administrator is not a customer

Administrators and customers are rows in the same `users` table behind the same
`web` guard — `is_admin` / `is_super_admin` are flags on a customer account, not
a separate identity. Nothing used to check them in the customer area, so an
administrator who signed in and opened `/dashboard` landed on the **customer**
dashboard: a wallet, a fund button, a purchase flow.

That is a segregation-of-duties failure, not a cosmetic one: the customer area
*credits* the account, while the same account is trusted in the console to
approve refunds and move wallets.

`App\Http\Middleware\CustomerOnly` (alias `customer`, registered in
`app/Http/Kernel.php`) is the guard. It is applied to:

* the customer `auth`+`verified` group in `routes/web.php` — every customer page;
* the `auth`-only tail in `routes/auth.php` — e-mail verification, password
  confirmation;
* the customer registration form.

An administrator reaching any of them is redirected to `/admin/dashboard`
(HTTP 403 with a `redirect` field for JSON callers, since the console polls with
`fetch()`). Sign-in itself is admin-aware — `App\Support\HomeRoute::for($user)` —
so an administrator lands on the console in **one** hop rather than being sent to
`/dashboard` and bounced.

Three things are deliberately **outside** the boundary:

| Route | Why |
| --- | --- |
| `/profile/avatar/{user}/{file}` | The console renders these too (customer view, administrators list, live chat). Ownership is asserted in the controller and by `SecurityHardeningTest`. |
| The public pages — `/`, `/pricelist`, terms, privacy, contact | The boundary is the authenticated customer application, not the public web. |
| `/admin/*` | Already guarded by `admin:<permission>`. A customer is refused with 403. |

`AdminCustomerBoundaryTest` asserts each direction, including that tightening one
has not loosened the other. Because the guard sits on the route group, a new
customer route added **outside** that group would not be covered — hence the test
walks one route from each area rather than a single route.


---

## Support / WhatsApp

The customer support entry point is a WhatsApp click-to-chat bubble, included in
`layouts/app`, `layouts/main` and `layouts/guest`. It is configured by:

```
SUPPORT_WHATSAPP=2347074217206
SUPPORT_WHATSAPP_MESSAGE="Hello ReUp, I need help with my account."
```

The number **must be in international format with no `+` and no leading zero**.
`AppServiceProvider` normalises it anyway — `07074217206`, `+234 707 421 7206`
and `2347074217206` all resolve to `wa.me/2347074217206` — but the `.env` value
should be written correctly.

This matters more than it looks: a malformed number does not raise an error. It
produces a `wa.me` link that opens WhatsApp with an invalid number, so the first
symptom is a customer telling you the chat button does not work. The bubble is
hidden entirely when the number is blank, rather than rendering a dead link.

`SUPPORT_WHATSAPP` is deliberately separate from `SUPPORT_PHONE` — the number
customers message is not necessarily the one printed on invoices.

**Note:** the in-app live chat page still exists at `/live-chat`, and the admin
console keeps its Live chat section so existing conversations stay readable.
Only the customer-facing entry points were replaced.

---

## Mail

Local development expects **Mailpit** on `127.0.0.1:1025`. It was already
running on this machine, and the app is configured to use it:

```
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_ENCRYPTION=null     # bare null, not the string "null"
```

Read captured mail at **http://127.0.0.1:8025** (Mailpit's web UI).

If you have no local mail server, set `MAIL_MAILER=log` and verification links
are written to `storage/logs/laravel.log` instead — nothing is sent, and the
flow is fully testable.

### `MAIL_ENCRYPTION=null` is required

`config/mail.php` defaults `encryption` to `'tls'`. Swift therefore issues
STARTTLS, and Mailpit/Mailhog **do not implement STARTTLS** — they answer:

```
Expected response code 220 but got code "502", with message "502 5.5.1 Command not implemented"
```

`MAIL_ENCRYPTION=null` disables the encrypter. Use `tls` for a real provider on
port 587, and `ssl` for port 465.

`MAIL_SCHEME` does nothing here — it is a Laravel 9 key and this is Laravel 8.

## A stale shell can override `.env`

Dotenv does **not** overwrite variables that already exist in the process
environment. If a shell, supervisor unit or Docker config exports `MAIL_*`,
`APP_*` or `DB_*` from an earlier `.env`, those values win and `.env` appears to
be ignored.

This is not hypothetical: it produced `Connection could not be established with
host mailhog` while `.env` clearly said `127.0.0.1`. The old host had been
exported into the shell before `.env` was updated.

Detect it with:

```
php artisan env:doctor
```

It prints every variable where the process environment disagrees with `.env`.
The fix is to restart whatever exported them — `unset` alone only affects the
current shell.

---

## Known pitfalls

### Never leave a stale `public/hot`

`public/hot` is written by Vite while it runs and is **not** removed if the
process is killed. While it exists, Laravel points every page at the dev server
— and if that server is gone, the stylesheet never loads and the page renders
unstyled with no error in the console, because Vite injects CSS from
JavaScript rather than a `<link>`.

`App\Support\Vite` defends against this: it probes the dev server before
trusting the file. If you ever see an unstyled page, delete it anyway:

```
rm public/hot
```

### The dev server must bind to IPv4

`vite.config.js` sets `host: '127.0.0.1'` deliberately. Vite's default of
`localhost` resolves to IPv6-only `[::1]` on some Windows configurations, while
`php artisan serve` is reached over IPv4 — so the browser cannot fetch the dev
assets. The config also sets `server.hmr.host` to match.

### `APP_URL` must match the origin in the address bar, or CSP blocks the forms

`SecurityHeaders` sends `form-action 'self'`, which means a form may only submit
to the *same origin* as the page. Browsers treat host and port as part of the
origin, so `localhost:8000`, `127.0.0.1:8000` and `127.0.0.1:8001` are three
different origins even though they all reach the same `php artisan serve`.

This used to bite when `APP_URL` (`.env`) disagreed with the URL actually being
browsed: Laravel 8 generates **absolute** URLs from `APP_URL`, so the rendered
form posted to `http://127.0.0.1:8000/wallet/fund` while the page was on another
origin, and the browser refused it with:

```
Sending form data to 'http://127.0.0.1:8000/wallet/fund' violates the
following Content Security Policy directive: "form-action 'self'". The request
has been blocked.
```

Two rules keep this from coming back:

1. `.env`'s `APP_URL` is `http://127.0.0.1:8000`, matching `docs/RUNNING.md`.
   If you serve on another port or host, change it, or browse the exact URL it
   names.
2. **Every `<form action>` in `resources/views` is origin-relative**, written as
   `route('name', $params, false)` — the `false` is `$absolute = false`. A
   relative action cannot cross the origin, so the page always submits to
   itself no matter which host/port you reached it on. Keep new forms in that
   shape; `SecurityHeadersTest` asserts it.

Note `route('name', [], false)` is *not* a relative-URL switch for the whole
app: it is per call site. Redirects and the Paystack `callback_url` still need
absolute URLs, so leave those alone.

### Cloudflare injects a script the repository does not contain

When the production domain is proxied through Cloudflare with **Web Analytics**
enabled, Cloudflare rewrites every HTML response to append

```html
<script type="module" crossorigin
        src="https://static.cloudflareinsights.com/beacon.min.js/v4bc70e2c..."></script>
```

That tag exists nowhere in this repository — not in the Blade views, not in the
built assets — so a `grep` for it returns nothing and it only ever appears in
production. `curl` does not show it either unless you send
`Accept: text/html` (the User-Agent is irrelevant). CSP applies to the HTML the
browser actually receives, edge-injected tags included, so before this was
allowed every production page logged:

```
Loading the script 'https://static.cloudflareinsights.com/beacon.min.js/v4bc70e2c...'
violates the following Content Security Policy directive: "script-src ...".
The action has been blocked.
```

`SecurityHeaders` therefore lists `https://static.cloudflareinsights.com` in
`script-src`. Two things worth knowing:

- **`connect-src` needs nothing.** For a proxied domain the beacon sends its
  data to *your own* origin at `/cdn-cgi/rum`, which `connect-src 'self'`
  already permits. Adding `cloudflareinsights.com` to `connect-src` is a common
  but useless fix — verify the blocked URL in the console rather than guessing
  the destination.
- The entry is dead weight if Web Analytics is switched off in the Cloudflare
  dashboard. Remove it then.

To check a live response, ask for HTML as a browser would:

```
curl -s -H "Accept: text/html" https://reup.com.ng/ | findstr cloudflareinsights
curl -sI https://reup.com.ng/ | findstr /i content-security-policy
```

### Card payments fall back to Bachs when Paystack will not start

The funding page offers one "Card" option, but there are two card gateways
behind it:

1. **Paystack** — always tried first. It is the rail the rest of this
   application reconciles against (`payments:reconcile`, the admin balance
   card, the bank-transfer DVA flow).
2. **Bachs** — used **only** when Paystack fails to produce a checkout URL: a
   missing or invalid key, their API down, a rejected initialise call.

The customer is never asked to choose. Paying through the fallback happens under
a merchant name they will not recognise, so the gateway is recorded on the
transaction (`meta.gateway = bachs`, plus `meta.bachs_checkout_id`) and logged
(`Card funding fell back to Bachs after Paystack refused`) — that log line is how
support explains an unfamiliar descriptor on a customer's statement.

It is off until deliberately switched on, and fails closed:

```
BACHS_ENABLED=false          # unset = the fallback never runs
BACHS_SECRET_KEY=            # sk_sandbox_... / sk_live_...
BACHS_BASE_URL=https://sandbox-api.bachs.io
BACHS_WEBHOOK_SECRET=        # POST /bachs/webhook returns 503 without it
BACHS_PAYMENT_METHOD_TYPE=NGN_CARD
```

Three things are worth knowing before enabling it:

* **The corridor must be live on the account.** `NGN_CARD` is the naira card
  rail. If it is not enabled, Bachs refuses the checkout
  (`CHECKOUT_RESTRICTION_LEAVES_NO_PAYMENT_METHOD` or `ACCOUNT_NOT_ACTIVATED`)
  and the customer gets the funding form back with an error rather than an
  unpayable page. Confirm the corridor with support@bachs.io first; a sandbox
  key has every method, so a sandbox pass does not prove production will work.
* **Register the webhook** in the Bachs developer portal against
  `https://<your-domain>/bachs/webhook`, subscribed to `collection.succeeded`,
  `collection.failed` and `collection.underpaid`. The webhook is the
  authoritative settlement path; `POST /bachs/webhook` answers **503** until
  `BACHS_WEBHOOK_SECRET` is set, so a forgotten secret is loud rather than
  silently uncredited.
* **Money is a decimal string here, kobo is not.** Bachs takes `"5000.00"`
  where Paystack takes `500000`. `Money` bridges the two, and the conversion is
  asserted in `BachsFallbackTest`.

The fallback is additive in one direction only: Paystack's own behaviour is
unchanged, because both gateways hand their validated result to the *same*
crediting routine (`PaystackService::creditVerifiedFunding`) rather than each
having their own copy. If Bachs is ever removed, delete `BachsService`,
`BachsController`, the two routes and the config block — nothing in the Paystack
path depends on them.

#### Refreshing one transaction by hand

The admin console has a **Refresh status** action on every transaction, for the
case the sweeps cannot cover: an operator looking at one row and needing an
answer now. It asks the same two questions the sweep does, in the same order
(`App\Services\PaymentStatusResolver`):

1. **Did it ever reach a gateway?** Read from our own record of the gateway's
   reply — the Paystack access code or the Bachs checkout id. If not, the
   attempt is marked failed with **no network call at all**: the customer was
   never handed to a payment page, so no charge can exist. This is the "user or
   device network interrupted it" case.
2. **If it did, ask the gateway** using the reference we gave it (the UUID).
   `success` settles and credits; `failed` / `abandoned` / `reversed` marks it
   failed; anything still live leaves the row untouched.

The three outcomes are distinguished deliberately, and the UI colours them by
whether anything *changed* rather than by whether the request succeeded:

| Outcome | Meaning |
| --- | --- |
| `settled` / `failed` | The row was resolved. |
| `pending` | A real status was read and it is still in flight. Nothing written. |
| `unreachable` | The gateway could not be reached, or does not know the reference. **Nothing written** — this is not a verdict. |

It is safe to press repeatedly: it goes through `settle()`, so a payment is
credited at most once. Bill purchases are answered by the vending provider
instead (`BillPaymentService::resolveUnknown`), and bank transfers are reported
as not queryable — a dedicated virtual account has no per-transaction lookup,
so the inbound transfer is matched by the webhook.

### Funding by bank transfer without fixing an amount first

`/wallet/virtual-account` shows the customer their own Paystack dedicated virtual
account (DVA) — one permanent NUBAN per customer. It exists because the funding
form's "Bank transfer" option requires an amount up front, which is the wrong
shape for "save my account number in my banking app and send whatever I have".

Nothing per-transfer is arranged. The customer transfers any amount to the
number; Paystack sends `charge.success` with `channel = dedicated_nuban`;
`PaystackController::webhook()` recognises it before the reference guard (a DVA
payload carries no reference at all) and hands it to
`PaystackService::creditDedicatedAccountTransfer()`, which writes the funding row
and the ledger entry, idempotently, on the provider's reference.

The controller issues the account on the **first GET** and renders it on every
visit after that. Issuing on a GET is deliberate:

* it is **idempotent** — `dedicatedAccount()` returns the stored account with no
  network call once it exists, so a second visit costs nothing;
* it is **not a financial action** — no charge, no transaction row, no balance
  change;
* the alternative is a page whose only content is a button, which asks the
  customer to make a decision they have no information to make.

Concurrency is handled with a row lock: two open tabs would otherwise race, the
loser getting Paystack's "customer already has a dedicated account" error shown
to a customer who did nothing wrong. The row is re-read *inside* the lock, since
the instance loaded before it may be stale.

A failure to issue is **not** an error page. The realistic cause is that personal
accounts are not enabled on the Paystack account being used (in test mode
`wema-bank` is refused outright), and the raw provider message describes our
configuration rather than anything the customer did — it is logged, sanitised
through `WalletController::dvaFallbackMessage()`, and the page offers card
funding and the shared account instead.

#### Personal account numbers need a **live** Paystack key

Dedicated virtual accounts are a live-mode product. With a `sk_test_` key
Paystack refuses **every** partner bank, each with the same sentence —
`"<bank> is not available in test mode"` — which reads like a bank problem and
is not one.

```
php artisan paystack:diagnose-dva            # ask Paystack, one question at a time
php artisan paystack:diagnose-dva --user=42  # test against a specific customer
```

`paystack:diagnose-dva` reports the key mode, which partner banks the account
offers, whether the customer record has the phone number Paystack requires, and
then attempts a real assignment — trying the other partner banks when the
configured one is refused, so "it is the bank" and "it is everything" are
distinguishable. It never prints the secret key, only its prefix.

The application does **not** need this command to behave correctly: with a test
key it says so plainly instead of relaying the bank name, and spends no request
doing it. `PAYSTACK_ASSUME_LIVE_DVA=true` overrides that detection — it exists for
the test suite and for an operator Paystack has told can issue accounts in test
mode. Leave it unset in production.

#### A phone number is required, and the page asks for it
Paystack will not attach a dedicated account to a customer record with no phone
number. Our own `users.phone` is nullable, so this is a normal state rather than
an error — and it needs handling in three places, because handling it in fewer
produces a bug that looks like something else:

| Place | Why |
| --- | --- |
| `PaystackService::hasPhoneForDedicatedAccount()`, checked before the API call | Turns the gateway's internal wording into an actionable prompt, and avoids spending a request on a condition we can already see |
| `PaystackService::dedicatedAccount()` — attach the phone to the **existing** customer first | A record created during an earlier card payment has no phone (card funding does not need one) and `ensureCustomer()` returns the stored code without revisiting it, so the gap was permanent |
| `ProfileController::update()` — push a saved phone to the provider | Otherwise the customer follows the prompt, saves, returns, and is told the same thing again |

The number is sent in international form (`2348031234567`). The local form
(`08031234567`) is accepted when *creating* a customer but leaves a record the
assignment endpoint rejects, which is a confusing failure a long way from its
cause; `User::formatted_phone` is the one conversion rule, used by both paths.

`VirtualAccountTest` covers each of the three, plus that no request is made when
the phone is missing and that the provider's wording never reaches the page.

```
PAYSTACK_SECRET_KEY=sk_...        # required; without it the page explains itself
PAYSTACK_DVA_BANK=wema-bank       # the partner bank Paystack assigns from
```

`VirtualAccountTest` covers the three claims that matter: the account is issued
exactly once, a failure is explained rather than fatal, and one customer is never
shown another's account.

### `@vite` is provided by this application, not the framework

Laravel 8 has no `@vite` directive; it arrived in Laravel 9.19. `App\Support\Vite`
resolves the tags and `AppServiceProvider` registers the directive. If you ever
upgrade to Laravel 9+, remove that registration — the framework will provide its
own, and having both would be ambiguous.

### Composer scripts can appear to hang

`composer dump-autoload` runs `php artisan package:discover` as a post script.
A leftover `php artisan serve` process holds `bootstrap/cache` locks and makes
that step stall indefinitely. If a Composer command seems to hang:

```
# stop anything still holding the app
Get-Process -Name php | Stop-Process -Force     # PowerShell
pkill -f 'artisan serve'                        # bash
```

Then re-run. `composer run dev` itself disables Composer's process timeout, so
it is expected to run until you stop it.
