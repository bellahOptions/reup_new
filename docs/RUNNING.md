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

`app/Console/Kernel.php` schedules `payments:reconcile` every minute. It resolves
Paystack payments that the webhook never confirmed, and expires payments that
were abandoned. **Without a scheduler runner this never executes and pending
payments stay pending forever.**

Development, one terminal:

```
php artisan schedule:work
```

Production, one cron entry (or the Windows equivalent):

```
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

`php artisan schedule:list` shows what is registered. To run the sweep by hand:

```
php artisan payments:reconcile --dry-run   # report only, changes nothing
php artisan payments:reconcile             # poll and resolve
```

The sweep only ever *polls* payments where Paystack is the authority (card, bank
transfer, USSD funding). Bill purchases are resolved by ClubKonnect/Pairgate and
are deliberately excluded — Paystack has never heard of them, so polling one
would return "not found" and could wrongly close a delivered order.


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
