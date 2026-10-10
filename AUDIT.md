# ReUp Codebase Audit & Remediation

Date: 2026-02-10
Scope: `app/`, `routes/`, `config/`, `resources/views/`, `public/`

Legend: **C** = critical, **H** = high, **M** = medium, **L** = low

Every finding was verified against the running application. Verification is in §6.

---

## 1. Security Findings

### C1 — SQL injection via unvalidated `orderBy` column
`Admin/UserController::index()` (line 62), `Admin/UserController::searchUsers()` (line 403), and
`Admin/BankTransferController::index()` (line 69) passed user-controlled `sort` / `order` straight
into `$query->orderBy($sort, $order)`. `orderBy` does not bind the column name, so
`?sort=id,(select ...)` and `?sort=1 and extractvalue(...)` are injectable.

**Fix:** whitelisted sortable columns and normalised the direction. Applied everywhere a sort
parameter is accepted.

### C2 — IDOR / cross-account wallet credit on the Paystack callback
`WalletController::handlePaystackCallback()` looked the transaction up by `reference` **without**
scoping to `Auth::id()`, so any authenticated user who learned another user's reference could have
that funding credited. `wallet.history` renders references, so they were discoverable.

Related: `Transactions::generateReference()` used `uniqid()` — time-based and guessable.

**Fix:** every transaction lookup is scoped to the authenticated user, with an ownership assertion
before settlement. References are generated with `Str::random(12)` plus a uniqueness retry.

### C3 — Admin self-registration bypass
`Admin/AuthController::register()` gated on
`Auth::check() && !Auth::user()->isSuperAdmin() && User::admins()->exists()`. For an **anonymous**
visitor the first clause is false, so the whole guard short-circuits: anyone could `POST
/admin/register` and mint an admin account. `showRegisterForm()` had the identical inverted guard.

**Fix:** registration is open only while the platform has **zero** administrators — the bootstrap
case — enforced inside a locked transaction so concurrent requests cannot both succeed. The view
that route renders did not exist either; it has been written.

### C4 — Paystack webhook route had no handler and was CSRF-blocked
`routes/web.php:154` mapped `POST /wallet/paystack/webhook` to
`WalletController::handlePaystackWebhook`, **which does not exist**. The route also sat inside the
`['auth','verified']` group, so Paystack's server-to-server POST could never reach it, and
`VerifyCsrfToken::$except` was empty so it would have been rejected anyway.

`PaystackController::webhook()` — the real implementation — referenced `\App\Models\Transaction`, a
class that does not exist (the model is `Transactions`), and marked rows `completed` while the rest
of the application uses `success`.

**Fix:** one canonical endpoint at `POST /paystack/webhook`, outside `auth`, exempt from CSRF,
throttled, HMAC-verified against the raw body, idempotent, and writing the correct status
vocabulary. Settlement refuses to credit on an amount mismatch.

### C5 — Broken access control on the admin area
`routes/admin.php` guarded the admin area with `['auth','admin']`, but `AdminMiddleware` accepted a
`$permission` argument that **no route ever passed**, so `moderator` and `support` accounts reached
every admin screen including wallet mutation and admin management. The middleware also performed an
unconditional `users` UPDATE plus an `AdminLog` INSERT on every admin request, including background
polling.

**Fix:** `App\Support\Permissions` is the single registry; every admin route group carries an
`admin:<permission>` guard; the activity write and access log are throttled and limited to real
navigations. `moderator`/`support` no longer hold `manage_wallets`, `manage_transactions`,
`manage_settings` or `manage_admins`.

### C6 — Secret material written to logs
`PaystackController::__construct` logged the first 10 characters of the secret key on **every**
instantiation. `WalletController::processFunding` logged `$request->all()` and gateway response
bodies. `ClubKonnectService` logged full request params (including `APIKey`) and raw responses.

**Fix:** no key material is logged; bodies are logged at `debug` only and credentials redacted.

### H1 — Provider API key published to the browser
`resources/views/cable-tv/index.blade.php` called the upstream biller API **directly from the
browser**, interpolating the provider client id into the page. `jamb-pin/index.blade.php` did the
same for JAMB verification.

**Fix:** all upstream calls moved server-side behind `ClubKonnectService`, which caches the public
catalogues. No credential or provider URL appears in any view.

### H2 — Client-controlled pricing
`PricelistController` had no `purchaseData` implementation, and the pricelist markup carried
`plan_price` in a data attribute that was posted back. Any client could buy an arbitrary bundle for
an arbitrary price.

**Fix:** the plan **and its price** are resolved server-side from the cached catalogue by plan code;
client-supplied prices are ignored entirely.

### H3 — Unverifiable inbound callback
`PricelistController::callback()` accepted unauthenticated input that could mark transactions
successful.

**Fix:** requires an HMAC of the raw body in `X-Reup-Signature` and, even then, does not assert
settlement from an inbound claim.

### M1 — Proof-of-payment files on a public, guessable URL
`WalletController::submitBankTransferProof()` stored to the **public** disk, so bank documents were
reachable at a predictable unauthenticated path. The admin viewer then read via the nonexistent
`Transaction` class and 500'd.

**Fix:** proofs are stored on the private disk and streamed only through an authorised,
existence-checked admin action that sets `X-Content-Type-Options: nosniff`.

### M2 — CSV formula injection in exports
Both CSV exports wrote user-controlled text unescaped, so a description beginning `=`, `+`, `-` or
`@` executed as a formula when an administrator opened the export.

**Fix:** exported text fields are prefixed when they begin with a formula character.

### M3 — Unvalidated legal-document type
`Admin/AdminController::getDocument()` / `previewTerms()` / `termsHistory()` took `{type}` from the
URL. **Fix:** constrained to `terms|privacy` via `TermsPrivacy::assertValidType()`.

### M4 — Weak password handling
Admin registration required only `min:8`. **Fix:** 12-character minimum for administrator accounts,
consistent `min:8` for customers, and terms acceptance is now validated server-side on registration.

### M5 — Missing rate limiting
No auth endpoint was throttled. **Fix:** sign-in 5/min, registration 5/min, password reset 5/min,
verification resend 6/min, plus per-endpoint throttles on chat, contact, purchases, meter/smartcard
verification and the webhook.

### L1 — Version and backup artefacts in the webroot
`PaystackController.php.backup` shipped a full controller copy; `Transaction.php` declared a second
`TransactionController` with invalid code; `Marquee.php` declared `App\View\Components\Marquee` in
the wrong namespace (so it could never autoload); two orphaned `.html` email templates were
committed. **Fix:** all removed.

---

## 2. Correctness Findings

### B1 — Application could not boot
```php
protected string $clientId;                                    // non-nullable
$this->clientId = config('services.clubkonnect.client_id');    // null when unset
```
`CLUBKONNECT_*` is absent from `.env`, so container resolution threw
`TypeError: Cannot assign null to property ... of type string`. Because the service is
constructor-injected into `AirtimeDataController`, `php artisan route:list` failed and every
airtime/data request was a 500. **Fixed** (nullable properties, `isConfigured()` guard, clear
"temporarily unavailable" message).

### B2 — Missing columns the application assumed
`users.status`, `users.last_activity`, `users.last_activity_at`, `users.requires_phone_update`,
`users.is_blocked` and `transactions.is_fraudulent` were referenced (and written) but never
migrated, and `phone_verified_at` had been dropped. **Fixed** with four migrations, including
version history for legal documents.

### B3 — Nonexistent model / missing import / wrong column
| Location | Problem |
| --- | --- |
| `Admin/UserController::updateWallet()` | `Transaction::create()` — the class is `Transactions` |
| `Admin/UserController` | `AdminLog::log(Auth::id(), …)` — `Auth` facade not imported |
| `Admin/UserController::updateWallet()` | `$wallet->total_withdrawn` — the column is `total_spent` |
| `Admin/UserController::toggleStatus()` | `$user->status` — no such column |
| `Admin/BankTransferController` | `viewProof()` used `Transaction::findOrFail`; `Log` not imported |
| `PaystackController` | `App\Models\Transaction` |
| `ChatController` (3 sites) | `User::where('role', 'admin')` — the column is `is_admin` |
| `DashboardController` | `$user->balance`, `$user->status` — nonexistent |
| `AdminLog::user()` | `belongsTo(..., 'admin_id')` on a table whose key is `user_id` |
| `Admin/TransactionController::export()` | `\League\Csv\Writer` — package not installed |
| `JambPinController::verifyProfile()` | `Log` and `Http` used without import |

### B4 — Wallet balance written to a column nothing else read
Four controllers did `$user->update(['wallet_balance' => …])`. `wallet_balance` is not fillable, so
the write was silently discarded, while the `wallets` row was mutated by hand in a second statement.
Balances drifted and `total_spent` was never incremented on most debit paths.

**Fix:** `App\Services\WalletService` is the single source of truth: every mutation runs inside a
transaction with a row lock, `User::wallet_balance` resolves from the `wallets` table, and the
legacy column was backfilled once and is never used for a decision.

### B5 — Money moved before or after the provider call, never reliably
`AirtimeDataController` opened a transaction, called the provider, then `DB::commit()`ed — twice on
the success path (the entire success branch was duplicated verbatim, so receipts were mailed twice
and the failure record was written *after* a rollback, into a rolled-back connection). Airtime and
data worked by accident; cable TV, electricity, WAEC and JAMB had **no implementation at all** — they
redirected with a success message and moved no money.

**Fix:** one pipeline in `BillPaymentService`: debit under a lock, call the provider outside the
transaction, then either mark success or issue an explicit compensating refund. All five products
use it. A failed refund is logged at `critical`.

### B6 — Redirects to a route that did not exist
`redirect()->route('transaction.success', …)` on every successful airtime/data purchase. **Fixed:**
`transactions.success` / `transactions.failed`, both ownership-checked.

### B7 — Chat messages were invisible to every query
A `creating` hook on `ChatMessage` rewrote `sender_type` from `'user'`/`'admin'` to
`'App\Models\User'`. The column is a plain string, so the rewrite persisted — and every
`where('sender_type', 'user')` in the codebase matched nothing. Unread counts, the admin
notification bell, mark-as-read and two dashboard stats were all silently dead.

**Fix:** hook removed, constants introduced, a migration normalised existing rows, and the
read-marking logic (which marked the wrong party's messages) corrected.

### B8 — `SitemapController` was a PHP parse error
The heredoc sentinel `LLM` collided with the content line `LLM Access:`, so the lexer failed:
`syntax error, unexpected token ":"`. **Fixed**; the unreachable sitemap-rendering methods (which
called an uninstalled package binding) were removed.

### B9 — Laravel 9 API used on Laravel 8
`$request->string()`, `->float()` and `->integer()` were called throughout the admin controllers,
but `Illuminate\Http\Request` gains those accessors in Laravel 9. Every admin list threw
`Method Illuminate\Http\Request::string does not exist`. **Fixed.**

### B10 — Missing views behind live routes
Ten routes pointed at view files that did not exist, so each was a guaranteed 500:
`airtime-data.history`, `electricity.index`, `waec-pin.index`, `cable-tv.history`,
`electricity.history`, `jamb-pin.history`, `waec-pin.history`, `wallet.payment-status`,
`admin.users.edit`, `admin.auth.register`. **All written.**

### B11 — Admin view crashes
`admin/admins/index.blade.php` crashed on `substr($log->user_id->name, 0, 1)`;
`admin/chat/index.blade.php` compiled an Alpine expression inside a Blade component attribute
(`<x-icon :name="available ? … : …">`) and threw `Undefined constant "available"`;
`admin/dashboard` and `layouts/app` destructured array rows with a missing trailing element;
`admin/edit-terms` dereferenced a null `$terms`. **All fixed.**

### B12 — Emoji used as UI iconography
1,040 emoji glyphs across 54 Blade views, plus emoji in mail subject lines and log messages. They
render differently per platform, are invisible to screen readers, and cannot be styled. **Fixed:
zero remaining** — verified by codepoint scan over 187 files.

### B13 — The `@vite` directive was never compiled, so no page was styled
Laravel **8.83** has no `@vite` Blade directive — it was introduced in 9.19 — and no installed
package registers one. Every layout called `@vite([...])`, which Blade therefore emitted into the
HTML **verbatim**. The browser received a literal
`@vite(['resources/css/app.css', 'resources/js/app.js'])` text node, no stylesheet and no
JavaScript: the whole application rendered unstyled.

The failure was silent. The page returned **HTTP 200**, and because Vite injects CSS from JavaScript
rather than a `<link>`, there was no 404 to notice. It was also invisible to the earlier
verification, which asserted status codes and that views *rendered*, but never inspected the markup
they produced.

Found alongside it: a stale `public/hot` file pointing at a dev server that was no longer answering.
While that file exists, Laravel resolves assets to the dev server, so even a correctly compiled page
would have loaded nothing.

Contributing PSR-4 violations meant `composer dump-autoload` could not finish: two classes in
`database/factories` were misnamed (`TransactionsFactory` in `TransactionFactory.php`, a
`UserSeeder` in `UserFactory.php`) and a duplicate `SettingsController` sat in
`app/Http/Controllers` while declaring the `Admin` namespace.

**Fix:** `App\Support\Vite` resolves the tags (dev server when it is *answering*, otherwise the build
manifest) and `AppServiceProvider` registers the directive, so the existing `@vite([...])` calls work
unchanged. A CSS entry is emitted as a `<link>`, not a module script. `vite.config.js` binds to
`127.0.0.1` instead of `localhost`, which resolved to IPv6-only here while `artisan serve` is reached
over IPv4. The PSR-4 violations were removed, which also repaired the un-autoloaded
`settings_helper.php` — its `files` key had been nested *inside* `autoload` rather than sitting
alongside it, so `setting()` and `settings()` were never loaded.

`tools/check-assets.php` now asserts on rendered markup and fetches the referenced files, so this
class of silent failure cannot recur.

### B14 — `composer run dev` did not exist
`composer.json` had no `dev` script. `dev`, `serve`, `vite`, `test` and `fresh` were added, and
`serve` disables Composer's process timeout so the server is not killed after 300 seconds.
`docs/RUNNING.md` documents the workflow.

---

## 3. UI / UX Findings

Reference: <https://grey.co/> — restrained near-monochrome canvas, generous whitespace, editorial
type scale, a single accent colour, hairline borders instead of heavy shadows.

| # | Finding | Resolution |
| --- | --- | --- |
| U1 | No design system: every view hardcoded its own `green-400…900` / `emerald` / `purple` / `blue` shades. | `@theme` tokens (brand ramp anchored on `#2AB70D`, neutral scale, semantic aliases) plus component classes in `app.css`. |
| U2 | Tailwind loaded from `cdn.jsdelivr.net` at runtime in 6 layouts — render-blocking, supply-chain risk, flash of unstyled content. | Removed. Styles compile via Vite and load once from `partials/head.blade.php`. |
| U3 | `tailwind.config.js` used v3 syntax while Tailwind **4.3.3** was installed; `app.css` used v3 `@tailwind` directives, which v4 rejects. The CSS pipeline was inert. | Migrated to Tailwind 4 via `@tailwindcss/vite` with explicit `@source` globs, so output no longer depends on the Blade cache. |
| U4 | Home page: hand-rotated gradient "sticker" headings, six bouncing emoji, hotlinked images from nairaland/wikipedia. | Rebuilt as an editorial layout with a product-surface mock; no emoji, no third-party hotlinks. |
| U5 | Three interchangeable announcement renderers plus a modal. | Single `<x-marquee>` component driven by real data; renders nothing when empty. |
| U6 | Two divergent navigation partials, many `href="#"`. | One navbar for the whole product; every link is a real route. |
| U7 | jQuery loaded in 4 layouts for three `toggle()` calls. | Removed; Alpine (from the existing bundle) owns all interactivity. |
| U8 | Font Awesome 7 full CSS for ~6 glyphs. | Removed; inline `x-icon` component. |
| U9 | Dashboard read `$transaction->external_reference` (not a column) and printed a nullable `balance_after` unguarded. | Both fixed and guarded. |
| U10 | Admin area used a different visual language (gradient sidebar, `shadow-2xl`, hover-translate). | Restyled to the shadcn/ui idiom: muted tokens, hairline `border`, `rounded-lg`, collapsible sidebar, data-dense tables, semantic focus rings. |
| U11 | Three icon systems in use (Font Awesome, hand-inlined Heroicons, emoji). | One `x-icon` component with 112 verified names. |
| U12 | Accessibility: emoji-only controls had no `aria-label`; the admin mobile scrim had no z-index and did not block interaction; `green-100` on `green-600` was below AA. | Labelled icon-only controls, fixed the scrim, added visible focus rings, replaced low-contrast pairings with tokens. |
| U13 | Email templates had no shared base, hardcoded addresses, emoji in subjects, and CSS that clients strip inconsistently. | One `<x-emails.base>` table-based layout; addresses from config; no emoji. |

---

## 4. What Was Built

**Backend**
- `App\Services\WalletService` — the only place wallet value changes; locked transactions.
- `App\Services\PaystackService` — initialisation, verification, exactly-once settlement, plus dedicated virtual accounts.
- `App\Services\BillPaymentService` — one purchase pipeline for all five products.
- `App\Services\OtpLoginService` — one-time email sign-in codes (hashed, single-use, rate limited).
- `App\Console\Commands\ReconcilePaystackPayments` — resolves webhook-missed payments and expires abandoned ones.
- `App\Support\Permissions` — permission registry shared by routes and the admin UI.
- `App\Support\Vite` — asset resolution for Laravel 8 (see B13).
- `config/bills.php` — product catalogue and fees, so the picker and the `in:` rule cannot disagree.
- Migrations; `ChatMessage`/`ChatSession`/`User`/`TermsPrivacy`/`AdminLog` model corrections.
- `database/factories/UserFactory.php`, `database/seeders/AdminSeeder.php` — test fixtures.

**Frontend**
- Tailwind 4 design system (brand tokens, component classes, `.legal-prose`, utilities).
- `x-icon` (112 names), `x-error-state`, `x-emails.base`, `x-marquee`, page/flash/support partials.
- Rewritten shells: customer navbar + footer, admin shell, guest/auth, error layout.
- Every customer page, every admin page, and all mail templates restyled.

**Tooling**
- `tools/check-assets.php` — front-end regression check (also asserts the JS bundle is
  browser-executable, after a CommonJS `require()` shipped and killed Alpine).
- `tools/browser-audit.js` — renders pages in headless Chromium and reports whether
  Alpine booted, whether any `x-cloak` element is still hidden, and every console error.
- `docs/RUNNING.md` — local development workflow and known pitfalls.
- `docs/UI_RUNBOOK.md` — design-system reference.
- Composer scripts: `dev`, `serve`, `vite`, `test`, `fresh`.

---

## 5. Known Remaining Items

| Item | Status |
| --- | --- |
| **Quill rich-text editor** (`cdn.quilljs.com`, admin legal editor only) | Kept deliberately: it is a functional editor, not decoration, and loads only for admins with `manage_settings`. To remove it, vendor the files into `public/vendor/quill/` — an SRI hash cannot be added safely while it is remote. |
| **No SMS gateway** | Phone verification issues a code and, outside production, returns it in the response and logs it. In production `debug_code` is always `null`; delivery must be wired up before the flow is useful. |
| **`paystack` and `clubkonnect` credentials** | Absent from `.env`, so funding returns 503 and airtime/data return "temporarily unavailable". Intended, explicit behaviour rather than a crash. |
| **`public/sitemap.xml`** | Served statically. The controller that generated it used an uninstalled package; those methods were removed. Regenerating it needs a package decision. |
| **Client-side admin filters** | The administrator list filters the current page; the controller takes no search parameters. The UI states this. |
| **No `users` factory or seeder** | Resolved: `database/factories/UserFactory.php` restored (its absence was breaking the whole auth suite) and `database/seeders/AdminSeeder.php` added for test admins. |
| **`phpunit.xml` pointed at the development database** | Fixed. `DB_DATABASE` was commented out, so `php artisan test` ran `migrate:fresh` against `.env`'s database and destroyed local data. Now pinned to `reup_test`; create it once before running the suite. |
| **`promotion_notifications` is not in the migration history** | Its migration file exists and runs, but a *later* migration in the same fresh run uses MySQL-only `UPDATE ... LEFT JOIN`, so on SQLite the run aborts before that table is created. The suite therefore runs on MySQL. `HomeController` and `ViewServiceProvider` read this table on the public landing page; both are now guarded (an absent table used to 500 the home page and every composed view). |
| **The scheduler is not installed** | `payments:reconcile` runs every minute in `Kernel::schedule`, but nothing invokes `schedule:run` on this machine. Until a cron entry or `schedule:work` exists, webhook-missed payments are never reconciled. Documented in `docs/RUNNING.md`. |
| **Reconciliation is Paystack-only** | Bill purchases (airtime, data, cable, electricity, exam) cannot be auto-resolved: the vendors expose no status endpoint we use, so a purchase that never returns is left `processing` for manual review rather than guessed at. |
| **Transaction IP/device capture is web-only** | `AirtimeDataController` records `request_ip` and `request_user_agent` for the receipt's security block. Funding via Paystack carries the gateway's own `channel` instead, and API-initiated purchases would record the caller's IP. |
| **`storage/framework/views` cache** | Compiled Blade caches `@vite` output. Run `php artisan view:clear` after changing the manifest in production. |

---

## 6. Verification

Run against the live database with a real user authenticated:

```
php -l app, routes, config, database        → 0 syntax errors
php artisan view:cache / view:clear         → all Blade templates compile
npm run build                               → builds clean
php artisan route:list                      → 166 routes register
php tools/check-assets.php                  → stylesheet and script load
composer run dev                            → serves the app on 127.0.0.1:8000
```

| Check | Result |
| --- | --- |
| Schema columns present | all OK |
| Routed controller methods exist | all present |
| `view()` targets exist | all present |
| GET routes returning < 500 (79 reached) | **0 server errors** |
| `<x-icon>` names resolving | 82 used, **0 unresolved** |
| Emoji across 187 files | **0** |
| `@vite` compiled; assets referenced and fetchable | **0 failures** |
| `composer run dev` serves a stylesheet | HTTP 200, CSS 77.7 kB `text/css` |
| Vite dev server reachable from an IPv4 browser | HTTP 200 on `127.0.0.1:5173` |
| `composer dump-autoload` | completes, 0 PSR-4 warnings |

The only non-2xx responses are intended: `POST`-only guards, signed-URL routes, and
`/admin/paystack/balance` returning an explicit 503 because the gateway is not configured locally.

> **Note on the earlier verification.** It reported "ALL GREEN" while every page was in fact
> unstyled, because it asserted status codes and that views *rendered* — not what they rendered.
> `tools/check-assets.php` closes that gap for asset loading. The lesson generalises and is worth
> applying to any future check: assert on the output, not on the absence of an exception.

---

## 7. Airtime pricing, internal profit, and network display (2026-02-27)

A second remediation pass, on three defects that were live in the customer-facing purchase path.

### A1 — An unconditional 2% airtime service fee
`AirtimeDataController` charged `round($amount * 0.02, 2)` on every airtime purchase, so ₦200 of
airtime cost ₦204. `Transactions::calculateServiceFee()` carried the same 2% (and a flat ₦50 for
data), and both the pricelist controller and the airtime page's client-side JavaScript applied the
same rates — three copies of a fee that was never a configured commercial decision, plus a
Super-Admin settings field (`airtime_service_fee`) that the purchase path never read.

**Fix:** the fee is gone, not replaced. Airtime prices under a new `FACE_VALUE` strategy — the
customer pays the airtime they asked for — and a customer fee appears only if a Super Admin enables
one on the applicable rule. The client-side fee, the legacy model method and the dead setting are
all removed or corrected. The settings screen now says plainly that it does not apply the fee.

### A2 — Airtime and data did not use the pricing engine at all
`PricingEngine`, `PricingRuleService`, `PriceQuote` and `pricing_snapshots` existed and were used by
the Wave 1 catalogue pipeline, but the five bill products went through `BillPaymentService` with a
float `fee` argument and no recorded cost. So the margin on an airtime sale was unknowable, and data
bundle prices came from a hard-coded `1.5%` inside `ClubKonnectCatalogue` — one margin for every
bundle on every network, changeable only by deploying code.

**Fix:** both products now price through the existing engine, and the quote is authoritative for the
wallet debit. Airtime's profit comes from the provider discount (3% by assumption, held as a
configurable per-network term: ₦1,000 face → ₦970 cost → ₦30 gross profit). Data is cost-plus off
the catalogue's verified per-bundle cost. `pricing_snapshots` gained a nullable `transaction_id` so a
bill purchase records the same immutable snapshot a Wave 1 order does, rather than a parallel one.

### A3 — The customer receipt printed the provider's name as their network
`BillPaymentService::markSuccess()` stamps `transactions.provider` with the *adapter's* label, and
the receipt read that column under the heading "Network". So a customer's receipt said
**"Network: ClubKonnect"** — the upstream API ReUp buys from, not their mobile operator. The real
network survived only inside `meta` JSON, which no template read.

**Fix:** `App\Services\NetworkResolver` owns canonical network identity, and `transactions` gained
`network_code` + `network_name`, written when the purchase is made. Every customer-facing surface now
reads `$transaction->network_display`, which never returns a provider name under any combination of
stored values and falls back to "Network unavailable". Historical rows resolve from their `meta`; a
stored label wins over current configuration so a past receipt cannot be rewritten. The provider name
remains on the transaction for administration, reconciliation and support.

### Defects found in the shared pricing engine while doing the above

| # | Defect | Effect |
| --- | --- | --- |
| E1 | `quote()` read a bare integer cost as **naira**, while `quoteWithRule()` read one as **kobo** | Every caller passing kobo had its cost multiplied by 100, which then read as a catastrophic loss and refused the sale. It silently corrupted `ServiceOrderService` and both admin pricing screens. |
| E2 | Rounding was applied to a `FACE_VALUE` price | A ₦100 rounding step charged ₦100 for ₦101.50 of airtime, so ReUp absorbed the difference. Rounding now applies only to calculated prices. |
| E3 | `allow_negative_margin` was inert whenever a profit or margin floor was set | The loss check passed, then the floor check refused the sale through `on_unprofitable`. The one case the flag exists for — a deliberate loss leader — could never be sold. |

Each is covered by a regression test.

### New configuration

`config/networks.php` (canonical networks and provider code maps) and `config/pricing.php` (cost
policy, seed rates). Provider costs live in the `provider_network_costs` table, editable by a Super
Admin at **/admin/pricing/costs**, where a rate is either an *assumption* or *verified against a
provider statement* — and only a verified rate lets profit be recorded as realised rather than
estimated.

### Deployment

```
php artisan migrate            # additive; three new migrations
php artisan db:seed            # providers + default pricing rules (idempotent)
php artisan pricing:seed-airtime-costs   # records the 3% per-network assumption
```

Then confirm the real ClubKonnect rate against a statement and promote it with
`pricing:seed-airtime-costs --verify --note="…"`, or edit it in the console. Until that is done,
airtime margin is reported as **estimated**, not realised.

---

## 8. Paystack transaction split (2026-02-27)

Every card checkout is now initialised with the platform split
`SPL_YsS8nTY0UJ` as a `split_code` on `POST /transaction/initialize`
([Paystack Transaction API](https://paystack.com/docs/api/transaction/)), so Paystack applies the
split's percentage and subaccount at settlement.

**Configuration:** `PAYSTACK_SPLIT_CODE` (default `SPL_YsS8nTY0UJ` in `config/services.php`).
Set it to empty to disable splitting; settlements then land wholly in the main account.

**Why it is sent unconditionally rather than left to each payment:** a checkout that omits the split
settles entirely into the main account, and nothing in a successful checkout response says whether a
split was applied — the discrepancy only surfaces when somebody reconciles the bank. The request is
therefore asserted in `PaystackSplitTest`, and the code is recorded on the transaction as
`meta.paystack_split_code` so a later question ("why did this settle there?") is answerable from the
row, since Paystack's verify response does not reliably echo it.

**A malformed code is refused before it reaches the gateway.** `PaystackService::splitCode()`
requires `SPL_` followed by the identifier and throws otherwise. The dangerous failure is not a
*rejected* code — that produces a visible funding error — but an *accepted* one that is the wrong
split, which misroutes money silently. Whitespace is trimmed, because a trailing space pasted into an
env file would otherwise be sent verbatim.

### Two operational facts worth knowing

1. **A rejected or missing split does not surface as an error.** `WalletController` catches a
   Paystack initialisation failure and falls back to **Bachs**, a separate card gateway where this
   split does not apply. So a bad split code quietly changes which gateway settles the money rather
   than failing. This is pre-existing fallback behaviour, not something the split introduced, but it
   is what makes an unverified split code worth checking.
2. **Test and live Paystack objects are separate.** The code is well-formed and the command runs, but
   `GET /split/{id}` returns **404 against this install's test keys**, and `GET /split` lists zero
   splits in test mode. A live-mode code is not usable in test mode, and vice versa. Verification
   therefore has to happen with live credentials.

### Verification command

```
php artisan paystack:check-split
```

Asks Paystack directly whether the split exists for this account, and whether it is active, in NGN
and paying the expected subaccounts — the three things that make a split silently ineffective. Exits
non-zero on any of them, so it is usable as a deployment check. It prints the split's configuration
and never the secret key; `PaystackSplitTest` asserts the credential travels as a bearer header and
never in the URL.

---

## 9. Administrator/customer separation, white light mode, the pending sweep, and pay-in accounts (2026-03-01)

Four changes, each of which fixes something that was wrong rather than adding something new.

### S1 — An administrator could use the customer application

`is_admin` / `is_super_admin` are flags on an ordinary `users` row behind the ordinary `web` guard,
and nothing in the customer area read them. An administrator who signed in and opened `/dashboard`
landed on the **customer** dashboard — wallet, fund button, purchase flow — while the same account is
trusted in the console to approve refunds and move wallets.

That is a segregation-of-duties failure, not a cosmetic one: `WalletService::credit()` and the
purchase pipeline both *write* to that account.

**Fix:** `App\Http\Middleware\CustomerOnly` (alias `customer`) redirects an administrator to
`/admin/dashboard` from every customer route — the `auth`+`verified` group, the `auth`-only
authentication tail, and the customer registration form. JSON callers get a 403 with a `redirect`
field rather than a 302 to an HTML page, because the console polls with `fetch()`. Sign-in is
admin-aware via `App\Support\HomeRoute::for()`, so an administrator reaches the console in one hop
instead of being sent to `/dashboard` and bounced.

Three things are deliberately outside the boundary: `/profile/avatar/*` (the console renders these
too, and `SecurityHardeningTest` already asserted an administrator may read one), the public pages,
and `/admin/*` itself.

`AdminCustomerBoundaryTest` asserts both directions — including that a customer is still refused by
the console — and walks one route per customer area rather than a single route, because the guard
lives on the route group and a future route added outside it would not be covered.

### S2 — Light mode was an off-white page

`--color-background` and `--color-surface` resolved to `ink-50` (`#f7f8f7`) and `ink-100`
(`#eef0ee`). Both are near-white, so a card and the page it sat on were two barely-distinguishable
tints and the application read as washed out rather than layered.

**Fix:** light mode is drawn on true white. `--color-surface-page` is `#ffffff`;
`--color-background` and `--color-surface` both resolve to it, because this application has no outer
canvas — the page plane *is* the surface, and every customer page opens with `bg-surface`. Layering
is carried by the hairline border and the `shadow-subtle` the card already had. The three recessed
steps (`surface-subtle`, `surface-muted`, `surface-strong`) moved one step down the neutral ramp so
they remain visible against white, and `theme.theme_color.light` moved with the token.

### S2b — Dark mode rendered the headline in near-black on near-black

Moving the page to white exposed it, but the defect was older: **the ink ramp never inverted**.

`ink-50 … ink-950` are the palette's one set of *primitives* rather than semantic tokens, and the
views use them about 250 times — `text-ink-950` on the home hero, `bg-ink-100 text-ink-500` on every
empty-state circle, `text-ink-600`/`text-ink-700` on menu items and list labels. A primitive reads no
token, so re-pointing the semantic layer moved none of them. `text-ink-950` stayed `#0d100d` in both
themes. That was survivable only while the light page was itself an off-white tint; on a white page
the heading is invisible to anyone whose device prefers dark.

**Fix:** the ramp mirrors in dark mode — the same treatment Tailwind's own palette steps already get
in that block, for the same reason.

Getting it to *take effect* took three attempts, and each failure was silent. Recorded because the
next person will otherwise repeat them:

| Attempt | Why it failed |
| --- | --- |
| Values in `@theme` | Tailwind emits that block as `:root, :host`, and also re-emits the plain custom properties it found in the file as an **unlayered** `:root` near the bottom — so the light ramp became the value for every theme |
| Light ramp under `html:root` | (0,1,1) outranks the dark block's `html[data-theme="dark"]` (0,2,1) on the element count, pinning every theme to light |
| Light ramp under `:root:root` alone | (0,2,0) ties with Tailwind's generated copy and loses on source order, because that copy is emitted *after* the dark block |

What works: no ink values in `@theme` (inert self-references keep the utilities emitting), the light
ramp under `:root:root`, and the dark ramp marked `!important` so it cannot be outranked by a rule
Tailwind writes later.

Alongside it, three genuine contrast defects the probe surfaced:

| Defect | Measured | Fix |
| --- | --- | --- |
| `--color-inverse-foreground` pointed at `ink-900` — the hero card's balance on a near-black panel | 1.15:1 in **light** mode | Literal `#eef0ee`; the inverse panel's foreground has to name the opposite end of the ramp |
| `--color-inverse-subtle` resolved to `#6f7a6f` on the white panel dark mode flips to | 4.48:1, under AA | Literal tuned to clear AA on **both** panels, since the same token is read against `#0d100d` in one theme and `#ffffff` in the other |
| `white` label on the brand fill, which is a *light* green (`brand-400`) in dark mode | 1.79:1 | `--color-primary-foreground` becomes a literal dark value in dark mode; the label inverts with the fill |

Two supporting changes: modal scrims moved from `bg-ink-950/50` to a `--color-scrim` token (a
mirrored `ink-950` is near-white, which would turn every dimming overlay into a bright wash), and the
WhatsApp button's label moved to a fixed literal — it sits on a fixed brand fill, so a themeable
token is wrong there in both directions.

`tools/probe-theme-contrast.js` now measures computed colour against computed background for every
visible text element, because this class of failure is invisible to status codes, markup assertions
and the compiled-CSS checks the suite already had.

### S3 — Nothing swept the transactions `payments:reconcile` cannot see

`payments:reconcile` may only poll where Paystack is the authority, because asking Paystack about a
bill purchase returns "not found" and treating that as a verdict would close out a delivered order.
Correct — and it left a gap: an airtime or electricity row whose provider call timed out sat at
`pending`/`processing`/`unknown` until somebody pressed **Refresh status** on it in the console,
one row at a time. An hourly command cleared `unknown` rows; the rest had no path at all.

**Fix:** `payments:auto-resolve`, scheduled every two minutes, applies the *same* decision the
console's refresh button uses (`App\Services\PaymentStatusResolver`) to every open row. One
implementation, asked by a human about one row or by the schedule about all of them.

The refusals are the design, and each is enforced in the resolver rather than in the command:

| Refusal | Why |
| --- | --- |
| Writes nothing when a gateway is unreachable | A provider that cannot be reached has said nothing |
| Refunds a bill purchase only on the provider's word | Refunding a vend that then completes gives away goods |
| Never queries a `pending`/`processing` bill purchase | The provider may still be working; an operator asking about one row gets the wider appetite through the console instead |
| Never touches a bank transfer | A DVA has no per-transaction lookup |
| Skips rows younger than `--grace` | The webhook gets its chance first |

`PaymentStatusResolver::preview()` is the dry-run half: the same gateways and the same provider
status queries, with no writes. `BillPaymentService::resolveUnknown()` gained an explicit `$dryRun`
that stops before it settles or refunds. The command re-reads every row after a dry run and logs at
`critical` if anything moved, so the "changes nothing" guarantee is checked rather than asserted.

`AutoResolvePendingTransactionsTest` (24 cases) covers the refusals, double-run idempotency, the
limits, and each dry-run claim.

### S4 — The pay-in account had no customer-facing surface

`PaystackService::dedicatedAccount()` and the DVA branch of the webhook already existed: one
permanent NUBAN per customer, credited automatically. But the account was only ever rendered as a
step inside "fund a specific amount by transfer", so a customer who wanted an account number to save
in their banking app had no way to get one — the feature existed and was unreachable.

**Fix:** `/wallet/virtual-account`. The controller issues on the first GET (idempotent — a second
call is served from the stored row with no network call — and not a financial action) and renders on
every visit after that. Concurrency is handled with a row lock and a re-read *inside* the lock, so
two open tabs cannot produce Paystack's "already has a dedicated account" error for a customer who
did nothing wrong. A failure is explained rather than fatal: the raw provider message describes our
configuration, so it is logged and sanitised through the existing `dvaFallbackMessage()`, and the
page offers card funding and the shared account instead.

`VirtualAccountTest` (13 cases) covers issue-once, the failure path, and that one customer is never
shown another's account.



