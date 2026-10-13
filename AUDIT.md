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

### S4b — "Customer phone number is required" reached the customer as a dead end

Paystack will not attach a dedicated virtual account to a customer record with no phone number.
`users.phone` is nullable and registration does not ask for one, so this was a routine state — but
nothing about the flow accounted for it, and the failure surfaced as:

```
production.ERROR: Dedicated virtual account could not be issued
  {"user_id":2,"error":"Could not generate a bank account: Customer phone number is required"}
```

…and, for the customer, **"We could not issue your personal account number"** — a dead end for a
problem they could fix in ten seconds.

Three separate defects, and fixing only the visible one would have left the others:

| # | Defect | Effect |
| --- | --- | --- |
| 1 | No phone check before the call | The gateway's internal wording was relayed as a generic failure, with no route to fixing it |
| 2 | The phone was never attached to an **existing** customer record | `ensureCustomer()` only sends a phone when *creating* a customer. A record created during an earlier card payment — which does not need a phone — kept that gap permanently, because the stored code short-circuits the function forever after. This is the actual shape of the reported bug. |
| 3 | Saving a phone number did not reach the provider | A customer who followed the prompt, saved, and returned would be told the same thing again — reading as the profile form not saving |

**Fix:** `PhoneNumberRequiredException` plus `PaystackService::hasPhoneForDedicatedAccount()`, asked
before any request is spent; the phone is attached to the existing customer record before the
assignment is requested; and `ProfileController::update()` pushes a saved number to the provider.

Both phone paths send the number in international form (`2348031234567`). The local form
(`08031234567`) is accepted when creating a customer but leaves a record the assignment endpoint
rejects, which is the kind of failure that is very hard to trace back to its cause.

The page now branches on a **code**, not on the message text — matching a sentence to decide whether
to offer the profile form would break the first time somebody reworded the copy.

One further defect was found while fixing the above, in the code that had just been written: the
warning and its code were returned as a bare string and a separate argument, so
`renderVirtualAccount($this->issueVirtualAccount())` left the second parameter at its default and the
prompt never rendered — the page said "add a phone number" in the body and "we could not issue your
account" in the heading. `issueVirtualAccount()` now returns one value carrying both.

`VirtualAccountTest` gained nine cases covering each of the three defects, that no request is made
when the phone is missing, and that the provider's wording never reaches the page.

### S4c — Personal account numbers cannot work on a test key, and said so as "wema-bank"

A second pass on the same page, after the phone fix.

**The report was "account generation is not working".** `paystack:diagnose-dva` — written for this —
asked Paystack directly and got the answer in one run: the deployment holds a `sk_test_` key, and
Paystack's dedicated virtual accounts are a **live-mode product**. Every partner bank refuses with the
same sentence:

```
wema-bank        HTTP 400 — wema-bank is not available in test mode
titan-paystack   HTTP 400 — titan-paystack is not available in test mode
first-bank       HTTP 400 — first-bank is not available in test mode
sterling-bank    HTTP 400 — sterling-bank is not available in test mode
gtbank           HTTP 400 — gtbank is not available in test mode
```

Nothing is misconfigured. The code was already honest with the customer — the raw message is
sanitised into "not available on this account yet" — but *not* honest enough to be actionable, and it
named a **bank** at log level, which sends whoever investigates looking for a bank setting that does
not exist.

**Fix:** `PaystackService::isTestMode()` plus a check before the call, so a test deployment says so in
one line and spends no request proving it. `paystack:diagnose-dva` exists for the general case, where
"it is the bank" and "it is everything" have to be told apart.

`PAYSTACK_ASSUME_LIVE_DVA` overrides the detection for the test suite. It is config rather than an
`app()->environment()` check inside the service, deliberately: a production guard that silently
behaves differently under test is the opposite of what a test is for.

### S5 — ClubKonnect was presented as a payment gateway

The admin dashboard had one card headed **"Gateway status"** listing Paystack *and* ClubKonnect, both
balances rendered identically, and the same ClubKonnect figure was *also* on the "Vending providers"
card below.

ClubKonnect is not a payment gateway. It is the upstream that **vends** airtime, data, cable and PINs.
Money never arrives through it — it is spent with it. A card that presents a vending float alongside a
gateway balance, under a heading that says gateway, invites exactly one reading: that the figure is
platform revenue.

**Fix:** the card is now "Payment gateways — where customer money arrives" and holds Paystack (and
Bachs, when the fallback is enabled, reporting configured-ness rather than a figure because it has no
balance endpoint). ClubKonnect stays on the vending card, whose description now says plainly that
these are not payment gateways.

The distinction the two cards draw: **a gateway takes money in, a vending provider pays money out.**
They fail differently, are topped up differently, and only one is revenue.

### S6 — Every non-ASCII character in eleven views was doubled

The dashboard showed `â‚¦240.0` where `₦240.00` belonged. This was not a font problem: files had been
rewritten through a tool that read UTF-8 as Windows-1252 and wrote it back as UTF-8, doubling every
non-ASCII character. Eleven views were affected, and the damage included `Loading…`, `—`, `•` and the
`₦` in every figure:

| File | Examples |
| --- | --- |
| `admin/dashboard/index.blade.php` | the `$money` closure and its JS twin — every figure on the page |
| `admin/transactions/show.blade.php` | five money fields |
| `home.blade.php` | the hero mock's amounts, the meta description |
| `betting`, `wallet/index`, `admin/users/edit`, and six more | assorted copy |

**Fix:** the damage is systematic and therefore reversible exactly — UTF-8 decoded as cp1252 can be
re-encoded to the bytes it came from — so `tools/find-mojibake.php` reports and repairs it, and
`SourceEncodingTest` keeps it from returning.

Two traps were hit while writing the detector, both recorded because either makes it silently
useless:

* the lead byte is not `\x{0080}-\x{00BF}`; the mangling decoded with **cp1252**, whose 0x80–0x9F band
  differs from latin-1 — byte 0x9A is `‚` (U+201A), not U+009A. A latin-1 range matched nothing and
  the detector called every file clean;
* a test that asserts on a literal damaged string contains the very thing it scans for. The sequence
  is built from codepoints at runtime instead, so the file stays under the guard's coverage.

`SourceEncodingTest` asserts its own detector against a known-bad string, because a guard that cannot
fail is worse than no guard — and this one was written twice in a form that could not.

---

## 10. ClubKonnect was out of service because of a comma (2026-03-01)

The dashboard reported ClubKonnect as unable to fetch its balance. Credentials were present, both
hosts answered, and the upstream returned `HTTP 200`:

```
{"date":"10th-Oct-2026","id":"…","phoneno":"…","balance":"4,985.28"}
```

The balance is a JSON **string** with a thousands separator. `is_numeric("4,985.28")` is `false`, and
three separate places asked exactly that question:

| Consumer | What `false` did |
| --- | --- |
| `ClubKonnectProvider::ping()` | `ProviderManager` treated the provider as down and skipped it for **every** purchase, so no airtime or data sale could route to it |
| `ClubKonnectProvider::balance()` | "Unable to fetch balance", and a zero on the dashboard |
| `ProviderBalanceService` float check | every purchase refused as unaffordable |

A funded, reachable, correctly configured primary upstream was therefore out of service — silently,
because nothing errored. It answered `HTTP 200` the whole time.

**Fix:** `ClubKonnectService::normaliseBalance()` parses the value once, where the provider's payload
becomes our data, and keeps the original string alongside as `balance_formatted`. An unparseable value
is left exactly as it arrived rather than zeroed, because a zero reads as an empty wallet and would
make the float check refuse sales for a reason that is not real.

Normalising at the boundary rather than at each of the four call sites *is* the fix: a rule applied in
three places is a rule that will eventually disagree with itself.

`ClubKonnectBalanceTest` (10 cases) covers the normalisation, the three consumers and routing — plus an
assertion that the provider's raw string is one `is_numeric` rejects, so a future format change fails
loudly instead of looking like a mystery.

### `bills:diagnose`

The dashboard's "Attention" is one word covering four independent failures. `php artisan bills:diagnose`
walks them in order — configuration, the balance enquiry, the health verdict, and what routing would
actually try — and prints credential lengths, never credentials.

Writing it produced two instructive failures of its own, both recorded because a diagnostic that
misreports is worse than none:

* it called the balance endpoint directly and so contradicted the application, reporting an error for
  the very response the service handles correctly. It now goes through `checkBalance()` and reports the
  normalised value, keeping a raw fetch only to display what the provider actually sent;
* its first reachability check probed the host with a bare request, which failed on a transient DNS
  miss while the real API call succeeded. Removed — the balance enquiry *is* the reachability test.

Two notes on trusting a check rather than a claim, both from this pass:

* **MySQL had stopped.** It was killed abruptly at 09:41:17 along with the session and never restarted,
  so ten tests failed with `SQLSTATE[HY000] [2002]` — an environment fault, not a code one. The data
  directory was intact; restarting `mysqld` from the Laragon configuration brought the suite green.
  It is now installed as an auto-start Windows service (`ReUpMySQL`), so a session reset cannot take
  the development database with it again.
* **The mojibake guard caught a file my own `Set-Content` had damaged minutes earlier.** A guard that
  fails within the hour of being written is the argument for having written it.

---

## 11. Wallet funding UX: the fee moves to the wallet (2026-03-01)

**The behaviour being fixed:** a customer who typed ₦1,000 was asked for ₦1,035. The service fee was
added to the checkout by `WalletController::calculateFee()`, so `total_amount` — the figure sent to the
gateway and shown on the funding page — was `amount + fee`.

**The behaviour now:** the customer pays exactly the amount they chose, is credited that amount in
full, and the fee is taken from the wallet afterwards as a separate, recorded debit.

```
checkout               ₦1,000
wallet credit         +₦1,000
service fee              ₦10       (its own transaction row, its own ledger entry)
net balance increase    ₦990
```

### What changed

| # | Change |
| --- | --- |
| 1 | `config/wallet.php`: `fees.paystack` (1.5% + ₦20) and `fees.bank_transfer` are **replaced** by `funding_fee` (`WALLET_FUNDING_FEE_*`), defaulting to zero |
| 2 | `WalletController::processFunding()`: `total = amount`. The fee is recorded in `meta`; the row's `service_fee` column stays `0.00`, because no fee is part of the payment |
| 3 | `WalletService::chargeFundingFee()` — idempotent, non-overdrafting, non-throwing — plus `fundingFeeFor()` and the deterministic `FEE-<reference>` key |
| 4 | The fee is charged from all four settlement paths: card/webhook, virtual-account transfer, manual bank-transfer approval, and admin "force success" |
| 5 | `payments:charge-funding-fees`, hourly, retries fees that could not be collected |
| 6 | Funding page: "You pay / We credit / Service fee / Net wallet increase", replacing "Amount to fund / Processing fee / Total to pay" |
| 7 | History: the fee is its own row with its own icon, and the funding row names the fee and the net credited |
| 8 | A migration adds `fee` to the `transactions.service_type` ENUM |

### The four guarantees, and how each is enforced

* **Once per funding** — the fee row's reference is derived from the funding reference and is unique. A
  replayed webhook, a second callback and the reconciliation sweep cannot each take one.
* **Never an overdraft** — it goes through the same locked movement path as every other debit.
* **Never fatal** — `chargeFundingFee` catches its own errors and never throws. It runs *inside* the
  settlement transaction, and an exception there would roll back a credit the customer has already paid
  for, which is the one outcome worse than an uncollected fee.
* **Never lost** — a refusal sets `meta.funding_fee_status = 'failed'`, which the sweep retries. It
  selects on an explicit `failed` status and never on a *missing* one: funding that settled before fees
  existed has no status at all, and charging that would bill a customer for something they were never
  told about.

### Gateway charges are not customer fees

Paystack's own charge is deducted by the gateway at settlement and is a cost of taking the payment. It
is deliberately not modelled as a customer fee and not passed on; `funding_fee` is ReUp's own charge,
and nothing derives one from the other.

### Defects found while doing it

| Defect | Effect |
| --- | --- |
| `service_type` is an `ENUM`, not a free string | Writing `funding_fee` produced `Data truncated for column 'service_type'` — a MySQL **warning**, not an error, so the row was written with a mangled type and only the ledger would have noticed. Fixed by widening the ENUM properly rather than by reaching for a `VARCHAR` |
| The fee's audit write was clobbered | `chargeFundingFee` wrote `meta.funding_fee_status`, then the settlement's own `forceFill` merged it away against a `meta` that predated it. The fee row was still created, so nothing failed — but the retry sweep, which selects on that key, could never have seen an outstanding fee. Only the *record* was lost, which is the version of this that reaches production |
| A test passed for the wrong reason | A drain of ₦9 left ₦981, so a ₦10 fee succeeded and the assertion proved nothing. Corrected to a drain that actually leaves the wallet short |

### `BachsFallbackTest` was asserting the old behaviour

It pinned the Bachs checkout amount as `'5095.00'` — ₦5,000 plus 1.5% plus ₦20 — with a comment
explaining the fee was payable on top. That assertion *is* the bug, so it now reads `'5000.00'`. A test
that encodes the defect is worth more than one that merely fails.

`FundingFeeTest` (18 cases) covers the checkout amount, the gateway figure in kobo, gross-credit-then-
fee, both ledger entries, the audit trail, double settlement, double webhook delivery, an uncollectable
fee, the retry sweep (including `--dry-run` and the "no fee status" case), and the fee arithmetic
including the cap and the never-more-than-funded rule.

### Phone number is now required at signup

Separately, and for a reason found two passes earlier: Paystack will not issue a dedicated virtual
account to a customer record with no phone number, so an account without one silently cannot fund by
transfer. `RegisteredUserController` now requires it, validated with the same rule
`ProfileController::update()` already used, and normalised first — including `+234 803 123 4567` →
`08031234567`, which a naive digits-only strip turns into `2348031234567` and the Nigerian regex then
rejects with a confusing message.

`RegistrationTest` gained 9 cases, including a data provider for five typed formats. It is a provider
rather than a loop inside one test because registration signs the account in, so a second pass in the
same test is correctly redirected away by `guest` and creates nothing — the loop version failed on its
second iteration for exactly that reason.

## 12. Names, dedicated virtual accounts, and funding receipts (2026-10-11)

Three changes, in the order they depend on each other: the customer's name is now collected as two parts
at sign-up; the dedicated-virtual-account module is rebuilt against Paystack's actual documentation; and a
scheduled job notifies the customer with a receipt when their wallet is funded.

### 12.1 First name and last name are collected, not guessed

The sign-up form had one "Full name" field, and the two halves Paystack needs were derived by splitting
it on the first space. That is wrong for a large fraction of real names: "Chidinma Nneka Okafor" became
first `Chidinma`, last `Nneka Okafor`; a single-word name had no surname to send at all.

It matters because those two values are what Paystack builds the **account name** of a customer's
dedicated virtual account from — the name a payer reads in their own banking app before sending money.

| # | Change |
| --- | --- |
| 1 | `resources/views/auth/register.blade.php`: "Full name" is replaced by First name (`autocomplete="given-name"`) and Last name (`autocomplete="family-name"`), side by side on wide screens and stacked on narrow ones |
| 2 | `RegisteredUserController` validates both with one shared pattern, `User::NAME_PATTERN` |
| 3 | The two halves are stored in new `users.first_name` / `users.last_name` columns; `users.name` is **kept** and derived from them on save |
| 4 | The same fields are added to the profile form, so a name can be corrected after sign-up — and the correction is pushed to Paystack |

**`name` is still authoritative for display.** Neither dropped nor renamed: it is read by the admin
console, the dashboard greeting, the chat widgets and dozens of views, and a rename would be a wide,
risky change for no benefit. `User`'s `saving` hook rebuilds it whenever the parts change, and the
`first_name` / `last_name` accessors fall back to splitting `name` when the columns are empty — which is
what keeps every factory, seeder and admin-console write (all of which set only `name`) working unchanged.

**Two names in, three values out, and a test for each.** `test_a_name_split_across_two_fields_is_stored_as_both_and_as_a_whole`
asserts all three, because getting one of them right and another wrong is the failure mode: the account is
issued in one name and the customer is greeted with another.

**The name rule is one constant, not two regexes.** `User::NAME_PATTERN` allows any Unicode letter, any
combining mark, and `'`, `-`, `.` and inner spaces. The combining-mark class is not decoration: `Ọ̀ṣun` is
spelled with a combining grave accent that is in neither `a-zA-Z` nor `\p{L}`, so the obvious pattern
rejects a real Nigerian name while accepting "John". `RegistrationTest` has a data provider for six real
names (apostrophe, hyphen, initial, accented, non-Latin script, combining diacritics).

**A request that carries only a whole `name` still registers.** Someone with the previous form open, or a
cached copy from a CDN, posts the old field set; the halves are derived from it. Rejecting them with "The
first name field is required" for a form this very site showed them is a hostile way to lose a sign-up.

### 12.2 The dedicated virtual account module, against the documentation

Read from Paystack's Developer Documentation (`/docs/api/dedicated-virtual-account/`,
`/docs/api/customer/` and `/docs/api/errors/dedicated-virtual-account/`). The findings that changed the
code:

| Finding | What it changed |
| --- | --- |
| "We currently support **Wema Bank and Titan Paystack**", and `preferred_bank` must come from the providers list | `PaystackService::dvaBank()` now checks `PAYSTACK_DVA_BANK` against `GET /dedicated_account/available_providers` (cached a day) and refuses with the available slugs named. An unreachable list does **not** block the attempt — the list is a diagnostic, and failing open keeps a transient gateway problem from becoming an outage |
| "`first_name`, `last_name` and `phone` … become compulsory" for Betting / Financial services / General Service when a DVA is to be assigned | Already sent on customer creation; now also sent on `POST /dedicated_account`, which accepts them as optional parameters, and pushed to the **existing** customer record before the assignment |
| Customer errors: "`first_name`, `last_name` are required in request body and must not be empty" | The setter (`ensureCustomer`) and the pusher (`syncCustomerDetails`) now send all three fields, not the phone alone |
| DVA errors: "**Access Denied** — you need to confirm that you've been activated for this feature … contact support" | Detected (403 or the wording) and raised as a message that names the *feature*, so `paystack:diagnose-dva` and the operator log say "ask Paystack to enable dedicated virtual accounts" instead of relaying a bank's name. The customer still gets the same sanitised sentence as every other provider failure |
| `POST /dedicated_account` returns `data.id`, and Fetch/Requery address the account by it | `dva_account_id` and `dva_bank_slug` are stored, so "re-query this account" is answerable from our row rather than from a Paystack dashboard search |
| List responses wrap accounts in `data.accounts[]`; Create and Fetch return the object directly | One `accountRows()` normaliser replaces three `??` chains that each had to know which shape they were looking at |

**The flow is now read-back first, then make the customer valid, then assign.** `GET
/dedicated_account?customer=…` answers "does this customer already have one?" *before* anything is
created, so a customer whose account exists at Paystack but not in our row — a rolled-back deploy, or an
account assigned by hand — is **recovered** instead of being refused as a duplicate. That is a new test
(`test_the_account_is_recovered_when_paystack_already_has_one`), and it also asserts that nothing was
created.

**The details sync no longer runs on every page load.** `users.paystack_details_synced_at` is stamped only
when Paystack *accepts* the update. Stamping it regardless would be the worse bug of the two: a refused
update recorded as done leaves the customer's record incomplete forever while every later page load skips
the call that would have fixed it. `test_a_customer_whose_details_are_already_synced_is_not_updated_again`
pins the saving, `test_a_failed_details_sync_does_not_break_the_page` pins the correctness.

### 12.3 Funding receipts on a schedule

`payments:send-funding-receipts`, every five minutes. It walks funding rows that are `success`, older
than a one-minute grace period and have no `meta.receipt_sent_at`, dispatches
`FundingReceiptNotification`, and stamps the row.

**Why a sweep and not a line in the settlement path.** Funding settles on three paths — the browser
callback, the gateway webhook, and the two-minute `payments:auto-resolve` fallback. Each is a place where
a mail server having a bad thirty seconds could take the *credit* down with it, and a fourth path added
later would silently not send receipts. Separating the two concerns also makes "did the customer get
their receipt?" a question about rows, answerable after the fact, and previewable with `--dry-run`.

**The claim is written after the send, deliberately.** Dispatching is the thing that can fail, so
stamping first would record a receipt that never went out and remove the row from every future sweep —
the customer's one chance, silently spent. The reverse is not perfectly atomic (a crash between the two
sends one duplicate on the next run), and that is the correct side to fail on: two receipts is a mild
annoyance, no receipt is a support ticket the customer has no way to anticipate.

**`--days=7` bounds the look-back.** This is deployed onto a database with settled funding rows that were
never receipted, because the feature did not exist. Without a window the first run would post a receipt
for every piece of funding in the history — a customer who funded in March getting one in October reads
as fraud or as a system that has lost track of time.

**The receipt itself** (`resources/views/emails/funding-receipt.blade.php`) is a document, not a
notification: amount, how the money arrived, who sent it and their note, the receiving account, both
balances, the references, and the duration. It deliberately **omits** the payment receipt's "Wasn't
you?" PIN panel — a card payment is authorised with the transaction PIN, money arriving in the wallet is
not, and telling a customer their PIN may have been seen would be alarming and false.

**Channels are `['database', 'mail']`, and the order is load-bearing.** Channels are processed in
sequence and a throwing channel stops the ones after it, so `['mail', 'database']` would mean a mail
outage also loses the *record* — the answer to "was this customer told?" disappearing exactly when
somebody is asking. `FundingReceiptTest` asserts the order, not the membership.

The `notifications` table did not exist. `User::markTermsNotificationSent()` had been calling
`notifications()->create()` against it and throwing `Base table or view not found` on every call; nothing
surfaced it because no caller was tested. The migration creates Laravel's standard table.

### Verification

```
php artisan test --filter="RegistrationTest|VirtualAccountTest|FundingReceiptTest|UiCopyTest"
php artisan migrate --force
php artisan payments:send-funding-receipts --dry-run
php artisan payments:send-funding-receipts
php tools/check-encoding.php          # the 22 files that carry multi-byte characters
php tools/show-funding.php            # which funding rows have been receipted
php tools/show-notifications.php      # what the customer was actually told
php tools/show-names.php              # first/last/whole name, per account
php tools/db-snapshot.php             # the columns this release adds
```

### Still open, and operator-visible

* **A `sk_test_` key cannot issue a dedicated virtual account at all.** Every partner bank answers
  `"<bank> is not available in test mode"`; `paystack:diagnose-dva` demonstrates it for all five. This
  deployment is configured with a test key, so the virtual-account page reports it plainly and the feature
  works the moment `PAYSTACK_SECRET_KEY` is a live key. `PAYSTACK_ASSUME_LIVE_DVA` exists only as a
  test-suite override.
* **The Paystack business may still need "Dedicated Virtual Accounts" enabled.** That is what the
  documented *Access Denied* error means, and it is the one failure we cannot fix from here. With a live
  key and the feature not enabled, the operator log and `paystack:diagnose-dva` now say so; the customer
  sees the generic unavailable message.
* **Bank selection is configuration, not a fallback.** If `PAYSTACK_DVA_BANK` names a bank this business
  does not have, the assignment is refused and the available slugs are printed. Silently moving to a
  different bank would change where customers' money settles, which is a commercial decision.
* **`name` is nullable and derived.** It is written on every real path, but a row created with neither a
  whole name nor the parts gets the model's placeholder ("ReUp Customer") rather than a database error.

## 13. Customer-name validation (2026-10-11)

Every endpoint that accepts a name now goes through one engine, an obvious placeholder is flagged for a
human rather than refused, and the admin console can clear the flag. Four entry points, one rule.

### The four entry points

| Endpoint | Where | Before |
| --- | --- | --- |
| `POST /register` | `App\Http\Requests\Auth\StoreRegistrationRequest` | Rules inline in the controller, with its own phone normalisation and its own regex |
| `PUT /profile` | `App\Http\Requests\UpdateProfileRequest` | ~40 lines of inline validation in front of the 4 lines that did the work |
| `PUT /admin/users/{user}` | `Admin\UserController::update()` | `'name' => 'required\|string\|max:255'` — no character rule at all, so an administrator could store a name the customer themselves would have been refused |
| The legacy whole-`name` field on sign-up and profile | both form requests | Already tolerated; now normalised too, rather than only split |

The admin editor is the one that mattered most. It is the only place a name can be *corrected*, so it is
the only place a name can be corrected into something invalid — and since the halves are derived from the
whole name, an invalid value there is what Paystack would print on the customer's next virtual account.

### One rule, three jobs, kept apart

`App\Support\Name` owns all three, and the separation is the design:

| Method | Job | Guarantee |
| --- | --- | --- |
| `normalise()` | Unicode NFC, remove invisible characters, collapse whitespace | Changes what is stored without changing what it means |
| `validate()` / `issues()` | Decide whether the value is a name at all | Rejects only digits, emoji, control characters, symbols and over-length values. **Never** rejects a name for being unfamiliar |
| `reviewReasons()` | Advice, not a verdict | Returns machine-readable reasons a name *might* be filler. Nothing blocks on it |

`User::NAME_PATTERN` is now re-exported from `Name::PART_PATTERN`, so there is one definition rather than
two that can drift — the previous arrangement had the model and the controllers each spelling out their
own copy of the same character class.

**Normalisation runs before validation, on the request.** `NormalisesNames::prepareNamesForValidation()`
rewrites the input in `prepareForValidation()`, so the value that is judged is the value that will be
stored: `"  Ada  "` is not refused for leading whitespace, it is stored as `Ada`. It also means a form
that fails validation *redisplays the cleaned name*, because the rewrite went into the request rather
than into a controller's local copy.

### What is accepted, and why the list is long

`\p{L}` (a letter, any script) and `\p{M}` (a combining mark), plus `'`, `-`, `.` and inner spaces. The
combining-mark class is not decoration: `Ọ̀ṣun` is spelled with a combining grave accent that is in
neither `a-zA-Z` nor `\p{L}`, so the obvious pattern rejects a real Nigerian name while accepting "John".

`NameTest::validNames()` is a data provider of **37 real names** — Nigerian, accented, Arabic, Chinese,
Hebrew, Icelandic, Vietnamese, particles ("van der Berg"), double-barrelled, apostrophised, hyphenated,
two-word given names, two-letter surnames and no-vowel surnames. The failure this guards against is not
"we accepted junk"; it is "we refused a real person's name", and only a wide list of real names can catch
that.

### The messages name the actual problem

```
Ada1        → Names cannot contain numbers.
Ada 😀      → Names cannot contain emoji.
🎉          → Enter your name using letters.
-Ada        → A name must start with a letter.
Ada_        → Use letters, spaces, apostrophes, full stops and hyphens only.
```

The overlap is real — `Ada1` holds a digit *and* fails the allowed-character class — so the order of the
checks decides the message, and the order is chosen for usefulness rather than severity. A name with no
letters in it at all is answered once, up front, instead of being told "names cannot contain numbers" when
it is not a name at all.

### Detection is conservative, and stated

`Name::reviewReasons()` returns reasons, never a refusal:

| Signal | Threshold, and what it deliberately does not catch |
| --- | --- |
| Placeholder word | Whole-word only, so "Testy", "Anas" and "Odemo" are untouched |
| Keyboard run | Prefix match only from 4 characters, so "Aban" is not "ab" |
| Repeated character | Four or more in a row, so "Annan", "Bobbie" and "Nalli" pass |
| Low variety | Exactly 3 **Latin** letters with fewer than 3 distinct — "Aaa" yes, "Xi"/"Ng" no, 张伟 exempt |
| No vowel | Eight or more **Latin** letters only, so "Nguyen", "Ng" and "Nkrumah" pass and Arabic/Chinese never reach it |
| Repeated word | Separator-spaced only, so "Test Test" and "asdf.asdf" are caught |

**The two known false positives are documented in the code, not hidden.** "Naa" and "Yaa" are Akan
day-names and both are flagged by the low-variety rule. That is the accepted cost of the only reliable
signal for "aaa": a false flag costs an administrator one glance because clearing it is a click and it
blocks nothing, while a database full of "aaa" is a set of accounts nobody can search. `NameTest` skips
those two cases by name, with the reason, rather than weakening the rule to accommodate them.

### Flagged, not refused — and not a suspension

`users.name_review` is `pending` / `approved` / `rejected`, nullable, **with no backfill**. Existing
accounts stay at `null`, which means "no opinion" rather than "passed": a migration that flagged a few
hundred customers on the strength of a rule they never had a chance to satisfy would produce a queue
nobody could clear.

The integration boundary is deliberate. The application already has three ways to act on a customer —
`status`, `is_blocked`, the wallet — and every one is a decision with a consequence. A suspicious name is
a *question*:

* the account is created and fully usable, with `status = active` and `is_blocked = false`;
* `POST /admin/users/{user}/name-review` records the decision and the reviewer, and touches nothing else;
* the flag appears on the customer list as a warning badge with the heuristic's own reason, and as its
  own panel on the customer page that says so explicitly;
* the reason text is the heuristic's finding *from the day it fired*, kept because the heuristic will
  change and a record that reinterprets itself is not a record.

### Three defects found while building it

| Defect | Effect |
| --- | --- |
| `parent::prepareForValidation()` did not reach the trait | The trait is composed into the *child* class, so `parent::` called `FormRequest`'s empty hook. Normalisation silently never ran: validation passed, the value was stored, and `"Chidinma   Nneka"` landed in a column that should have held `"Chidinma Nneka"`. Fixed by naming the method distinctly (`prepareNamesForValidation()`) so the call cannot be got wrong |
| A later `save()` wiped the flag | `ProfileController` re-saved the model for profile completion, and the second save persisted the whole in-memory attribute set — including a `name_review` that predated the flag being set. The flag vanished, the queue stayed empty and nothing errored. Fixed by ordering the saves, and by making `flagNameIfSuspicious()` idempotent through `forceFill()->saveQuietly()` |
| A test passed for the wrong reason | The escaping test asserted on `admin.users.index`, where the customer row was not on the first page at all — so it was asserting about a name that was never rendered. Rewritten to test the template layer directly, with a second assertion on the customer's own profile page |

### Verification

```
php artisan test --filter="NameTest|CustomerNameTest|RegistrationTest|VirtualAccountTest|SourceEncodingTest"
php tools/check-names.php        # real and junk names through the rule; every stored name re-checked
php tools/show-escaping.php      # how Blade encodes a name, in an attribute and as text
php tools/show-columns.php users # the new columns
```

`tools/check-names.php` also runs every name already in the database through the new rule and reports
any it would refuse. A rule that invalidates an account that already exists is a bug, not a tightening.

### Not done, deliberately

* **No backfill of the review flag**, as above.
* **No rejection on the heuristics.** They are guesses, and a guess that refuses a real person's name is
  worse than a placeholder in the database — only one of the two can be undone by an administrator.
* **No change to what `name` is for.** It is still the whole name the console, the greeting and the chat
  widgets read; the parts are still what Paystack is given.
* **No third account-state column.** "Flagged" is not a status, and `isSuspended()` does not consider it.

## 14. Paying for airtime by card, and profit that actually reports (2026-10-11)

Three requests, and one finding that changed the scope of the second.

### 14.1 The finding: profit was never recorded for anything the platform sold

The console has a Revenue & Profit dashboard reading `profit_records`. `ProfitRecorder` is called
**only** from `ServiceOrderService::finalize()`, and no live route creates a `ServiceOrder` — airtime
and data go through `BillPaymentService`, which writes a `pricing_snapshots` row and stops. So the
dashboard read a table nothing populated: **zero profit, for every sale, while the platform sold.**
`tools/profit-sources.php` demonstrates it and is the tool to re-run after any change here.

The fix is a second entry point on the recorder — `recordFromBillPurchase()` — called from
`BillPaymentService::markSuccess()`. `markSuccess()` is the single gate every successful settlement
passes (the purchase pipeline, the provider webhook, admin "force success", `payments:auto-resolve`),
so one call covers all four and a fifth added later inherits it. The pricing snapshot stays the
authority; nothing is recomputed.

Two things were missing and both needed a migration:

| Missing | Why it mattered |
| --- | --- |
| `service_type` on the record | The dashboard could group by product, provider and category but not by **service**, and "what do we make on airtime versus data" is the first question asked. A `group by` that has to join `transactions` is slower and easy to write wrongly |
| A UNIQUE index on `transaction_id` | `service_order_id` is UNIQUE, which is what makes the order path idempotent. The live path has no order, so `transaction_id` is the key — and it was only a plain index, so a webhook **plus** the auto-resolve sweep could each insert a profit row for one sale and the dashboard would report it twice |
| `service_order_id` was `NOT NULL` | The table was built for orders. A live purchase has no order, so the insert simply failed |

### 14.2 Paying for airtime with Paystack

The customer picks a product, sees the exact price, chooses "Card or transfer", and is sent to the
gateway for **that exact amount**. When the payment settles the wallet is credited and the purchase is
debited from it immediately, through the existing `BillPaymentService` pipeline.

**The wallet round-trip is the design, not a shortcut.** It means a card purchase is the *same* code
path as a wallet purchase in every respect — same provider selection, same float check, same pricing
snapshot, same profit recording, same refund. A second settlement path that never touched the wallet
would have needed a second implementation of the debit, the ledger entry, the snapshot and the refund,
and a second implementation of money handling is how two records of one sale start disagreeing.

**Profit is not a special case, and this is the answer to the brief's question.** The customer pays
₦1,000 for ₦1,000 of airtime; the provider charges ReUp ₦970; the ₦30 is ours the moment the payment
settles, because the debit that follows is the full ₦1,000 and the cost behind it is unchanged.
Paying by card does not move the margin, and nothing in the flow tries to.

| Piece | Where |
| --- | --- |
| The intent — product, recipient, provider payload, pricing context, quoted margin | `meta.funded_purchase` on a `funding` row |
| Sending the customer to the gateway for the pinned price | `AirtimeDataController::startCardPurchase()` |
| Vending on settlement | `PaystackService::creditFunding()` → `FundedPurchase::vend()` |
| The backstop for a payment that settled but was never vended | `payments:vendor-funded-purchases`, every two minutes |

Four decisions worth stating:

* **Nothing is vended before the money arrives.** The redirect creates a `pending` funding row and
  calls no provider, so a customer who closes the tab is charged nothing and receives nothing. The
  vend is triggered by *settlement*, and `vend()` refuses a row that is not `success`.
* **The funding service fee is not charged.** This money is already spent on goods before it rests in
  the wallet, so a percentage taken from it would leave the debit that follows unable to afford the
  purchase it was made for. `FundedPurchase` sets the flag; `PaystackService::isFeeExempt()` reads it.
  The skip is deliberately outside the vending call, because skipping a fee must never skip delivery.
* **No PIN on the card rail** — and the PIN field is disabled, not merely hidden, so the browser and the
  server's `required_unless:payment_method,paystack` agree. Asking for a PIN *and* sending the customer
  to their bank is two authorisations for one payment. The wallet rail still requires one; there is a
  test for each side.
* **A failed delivery keeps the customer's money.** A definite provider refusal goes through the
  existing refund path and lands back in their wallet. A provider *timeout* is held rather than guessed
  at, exactly as a wallet purchase is — the order may have been vended.

### 14.3 The admin menu

Pricing, Providers and Revenue & Profit were **absent** from the sidebar, not hidden from lesser roles:
the only way to reach them was to know the URL. They are now listed, each carrying the permission its
route already checks — `view_pricing`, `manage_providers`, `view_profit`, all in
`Permissions::SUPER_ADMIN_ONLY`.

Nothing about who may open them changes. What changes is that the menu now matches the routes: an item
renders for an account the route would accept and stays hidden for one it would refuse. A menu that
shows a link which then 403s teaches an administrator to distrust the menu; a menu that omits a module
teaches them it does not exist.

The Profit dashboard also gained the per-service breakdown, a live "profit today" figure the page polls
every 30 seconds (server-formatted, so the browser does no money arithmetic), and a
**verified-versus-estimated split** — because a margin measured against a rate somebody typed into the
console is a forecast, and a total shown without that split presents it as earned.

### Verification

```
php artisan test --filter="ProfitRecordingTest|PaystackAirtimeTest"
php tools/profit-sources.php          # is profit reaching the table the dashboard reads?
php tools/show-funded-purchases.php   # did a card customer get what they paid for?
php artisan payments:vendor-funded-purchases --dry-run
php artisan payments:vendor-funded-purchases
```

### Not done

* **Only airtime and data.** The card rail is wired for those two, which are the products with a cost
  resolver for re-pricing at vend time. Cable, electricity, WAEC and JAMB fall back to the wallet rail
  rather than recording an unmeasured margin.
* **No card reversal.** A failed delivery credits the wallet rather than reversing the gateway charge.
  That is a real, spendable, recorded outcome; a card refund is a separate money movement this platform
  has no path for.
* **The `profit_records` backfill is not retroactive.** Profit is recorded from the moment this
  deployment runs. Sales before it have snapshots and no profit rows, and are deliberately not
  back-filled: a migration inventing accounting records for history is how a report becomes fiction.

### 14.4 What rendering the pages then found

The UI was covered only by tests that posted to the endpoints, so nothing had actually rendered the new
component. `PaymentMethodPickerTest` renders the pages and pins the contract the JavaScript depends on.
Three things came out of it, all of them the same lesson — the code was right and the *test* was wrong:

| Assertion that failed | Why the code was right |
| --- | --- |
| `$this->blade('<x-transaction-pin />')` threw `Undefined variable $errors` | Laravel shares `$errors` with views rendered through the HTTP stack, not with one rendered in isolation. A component that assumes a shared variable is only really proved in place, so the assertions moved onto the page |
| A plain `admin` did not see "Payments" | `manage_payments` is not in that role's defaults, so the menu correctly hid it |
| A plain `admin` did not see the bare word "Transactions" | It is inside the sidebar link's `title` attribute, not visible text. Anchored on the route the link points at instead, which cannot drift with the markup |

The last test in the file is the one that makes the menu honest: it walks every route the sidebar offers
and asserts a super admin gets a 200 from each. A menu is a promise, and a typo in a route name or a
permission that does not match its route now fails there rather than in front of an administrator.

## 15. Four UI defects from §14, and one that hid the tests (2026-10-11)

§14 was verified by tests that posted to endpoints. None of them rendered a page, and four user-visible
defects were sitting behind that gap. Each is recorded with the reason it was invisible.

### 15.1 The card rail could not be selected at all

`<input type="hidden" name="payment_method" :value="method" value="wallet">` — a static `value` **and** a
bound one on the same element. **The static attribute wins in the DOM**, so the form always submitted
`wallet`. Choosing "Card or bank transfer" disabled the PIN (the `change` handler had run), then submitted
the wallet rail, and the submission was rejected for a missing PIN.

The feature was completely unusable and every endpoint test passed, because those tests posted the payload
a *working* form would have sent. The endpoint cannot see how a value was produced, so this class of bug is
only reachable by asserting on the rendered control. The rail is now a plain `<select>` — one control, one
value, `selected` marking the default in real HTML — and the regression test asserts that exactly one
control carries `name="payment_method"` and that no bound value sits beside a static one.

### 15.2 The wallet-balance gate blocked card purchases

`checkAirtimeBalance()` disabled the submit button and relabelled it "Insufficient Balance" whenever the
amount exceeded the wallet balance, regardless of the rail. A customer with an empty wallet could choose
"Card or bank transfer" and find the button dead: the card rail was broken for precisely the customers it
exists for. The data form had its own copy of the same gate, which is where a guard gets forgotten, so the
warning and button handling is now one shared `clearBalanceGate()` and both checks return early on the card
rail. The rail's `change` event re-evaluates, so switching back to the wallet restores the gate.

### 15.3 `required` had been removed from the PIN field

It was `:required="! cardRail"`, which is **absent from the served HTML**. With JavaScript disabled or
still loading on a slow connection the field was simply not required, so a wallet submission skipped the
browser's own validation and got a round trip and a page reload instead of being stopped in place. The
server still refused it, which is why no test noticed. Static `required` plus `x-bind` inverts the default:
the safe case is the one that does not depend on JavaScript having run.

### 15.4 Two sidebar links lit up at once

"Pricing" carried the pattern `admin.pricing.*` and a newly added "Provider costs" carried
`admin.pricing.costs`, so on the costs page **both** were marked active — the active state is
`routeIs($pattern)`, and a sub-page listed at the top level overlaps its own section by construction. The
margin watchlist was listed in two groups for the same reason. Both sub-pages are reached from their own
section, not from the top level, and a test now asserts exactly one `sidebar-link-active` per page.

### 15.5 The profit page's polling script was never emitted

`@push('scripts')` sat **inside** `@section('content')` while a comment claimed it was outside. A `@push`
inside a section is buffered with the section instead of registered against the stack, so the script never
reached the page and the live "profit today" figure never moved. No PHP error, no failed request, nothing
in the log — the page simply did less than it claimed.

That one is worth dwelling on, because the comment asserting the opposite is what made it invisible to
review. The fix moves the push after `@endsection`, and the test asserts the rendered page contains
`Alpine.data('profitLive'` — the only assertion that can catch a script that does not exist.

### 15.6 A component that could not be rendered in isolation

`@error` reads the `$errors` view variable, which Laravel shares only with views rendered through the HTTP
stack. Two components read it, so `$this->blade('<x-… />')` threw `Undefined variable $errors` — a fact
about the test harness rather than the component, and it pushed the assertions onto real pages where they
belong. The new picker has **no** `@error` block at all: its rule is `nullable|in:wallet,paystack` and a
two-option select cannot violate it, so the block was dead markup.

### 15.7 The environment, which hid the results rather than causing the defect

`php artisan test` began failing with `Access denied for user 'highway_fresh'` and `Table definition has
changed, please retry transaction`. Neither was this project's code. A **sibling checkout** running
`composer run dev` had exported `DB_DATABASE`, `DB_USERNAME`, `SESSION_DRIVER`, `QUEUE_CONNECTION`,
`MAIL_MAILER` and `APP_URL` **machine-wide**, and a PHPUnit `<server>` element does not override a variable
that already exists — it only sets one. So the suite ran against the wrong database, shared with the other
project, which then had its tables altered mid-run.

Pinning the test configuration with `force="true"` on every `<server>` is the fix, and it is right
independently of this machine: a test suite whose database can be changed by whatever the developer's shell
happens to export is not isolated. Verified with no environment overrides at all — 1139 passing.

### The tool this produced

`tools/check-inline-js.php` extracts every inline `<script>` from *rendered* pages and hands each to Node's
own parser. It exists because 15.2 was introduced by an edit that left a function unbalanced: the page still
rendered, every server-side test still passed, and the only symptom was a dead button partway down a page.
32 scripts across 11 pages check clean.

```
SESSION_DRIVER=file php tools/check-inline-js.php --all
```

### The lesson, stated plainly

Every defect in this section was found by rendering a page rather than by calling a service. The tests in
§14 were thorough about *behaviour* and blind to *presentation*, and the gap between the two is where all
four bugs lived. `PaymentMethodPickerTest` now asserts on rendered output for the contract the JavaScript
depends on — the submitted control, the attributes the browser enforces, the script that must exist — and
`tools/check-inline-js.php` covers what no assertion can read.

## 16. Customer lifecycle messaging (2026-10-12)

A Super Admin module that sends email across the life of an account: a welcome, funding and
first-transaction nudges, a referral invitation and a re-engagement after inactivity. Templates,
automations, a durable event outbox, per-message delivery tracking, suppressions and consent.

### 16.1 The sender identity

`LIFECYCLE_FROM_NAME` (default **"Olumuyiwa from ReUp"**) is the display name on engagement mail. The
*address* is never configured here — it is `MAIL_FROM_ADDRESS`, the one ReUp has verified with its mail
provider. Nothing invents an address: a send with an unverified From is rejected by most providers and
damages the sending domain every other email depends on, including password resets. A template may
override the display name per campaign, which keeps a named human sender a choice an author makes rather
than something applied to a receipt.

### 16.2 The five recommended automations, all created disabled

`php artisan db:seed --class=LifecycleSeeder` creates five templates and five automations, **every one a
draft**. A migration would run on every deploy, and an *activating* migration would start mailing customers
as a side effect of a deploy; a seeder is run deliberately, once, by a person who has read the copy.

| Automation | Trigger | Delay | Condition |
| --- | --- | --- | --- |
| Welcome | `user.registered` | none | — |
| Funding reminder | `user.registered` | 24h | not funded |
| First-transaction reminder | `user.registered` | 6h | no successful transaction |
| Referral invitation | `transaction.succeeded` | 1h | ≥1 successful transaction |
| Re-engagement | `user.became_inactive` | none | ≥14 inactive days **and** ≥1 transaction |

The welcome carries the brief's subject and preview text, introduces ReUp as a product of Bellah Options,
and has the three buttons: **Fund My Wallet** (primary), Explore ReUp and Invite Friends.

### 16.3 The outbox: why triggers write a row instead of dispatching a job

`user.registered`, `wallet.funded`, `transaction.succeeded` and `referral.reward_earned` all fire inside
money paths — inside database transactions, and for funding inside a row lock. Dispatching a queued job
from there lets a worker pick it up **before the commit**, so it reads a customer who has not been
credited and sends "you have funded your wallet" for a payment that then rolls back.

So the trigger writes one row to `lifecycle_events`, in the same transaction, and `lifecycle:process`
turns those rows into scheduled messages afterwards. A queue that is down when the event happens delays
the email instead of losing it. `(name, user_id, dedupe_key)` is unique, so a replayed webhook is one row.

Recording never throws: every trigger site is a customer-facing operation, and a messaging table problem
must not fail a payment.

### 16.4 Idempotency is a database constraint, not a cache lock

`UNIQUE (automation_id, user_id, dedupe_key)` on `lifecycle_messages`. A cache lock is advisory — it
expires, it is per-store, and two app servers with separate caches both acquire it. The brief asks for
database-enforced uniqueness and this is it; the index is asserted directly in the tests rather than
trusted.

The event is claimed with a conditional `UPDATE ... WHERE processed_at IS NULL`, which is atomic across
processes, and the message is claimed with a conditional status update — so two workers holding the same
job send once, despite the queue's at-least-once delivery.

### 16.5 Eligibility is re-checked immediately before sending

A delayed reminder is scheduled when the trigger fires and sent hours later, so the fact it depends on can
have changed. A customer who funds twenty minutes before the 24-hour reminder is due must not be told they
have not funded it — there is no recovering from having sent that.

`LifecycleEligibility` re-evaluates the automation's conditions, status, window and template at send time,
inside `LifecycleDispatcher::attempt()`, which is the only place a message is ever sent. If the answer is
no the message is **cancelled** — terminal, so the sweep does not re-enqueue it.

### 16.6 Accepted is not delivered

| State | Means |
| --- | --- |
| `scheduled` / `queued` / `sending` | Claimed or waiting. Nothing sent. |
| `accepted` | The transport took it. **Not** confirmation that a mailbox received it. |
| `delivered` / `bounced` | A provider said so. `delivered_at` has no other writer. |
| `suppressed` / `cancelled` | Must not send, or stopped being worth sending. |
| `failed` | Every attempt was refused. |

Laravel 8 sends through SwiftMailer and the transport returns no message id, so the dispatcher sets an
`X-ReUp-Message-Id` correlation header it controls and records it as `provider_message_id`.
`confirmDelivery()` and `confirmBounce()` match on that id, so a provider webhook cannot mark an unrelated
row as delivered. A bounce also suppresses the address: a hard bounce means the mailbox does not exist, and
the next send only costs sending reputation.

### 16.7 The security boundaries

* **Conditions are data, interpreted by an allowlist.** Fields and operators are looked up in fixed lists
  and values compared as scalars. Nothing builds a query, a string or a fragment; there is no `eval`, no
  dynamic method name, no path from a condition to application code. A condition that cannot be evaluated
  is refused **on save**, because the evaluator treats an unknown field as false and storing one would
  silently widen an audience.
* **Template HTML is sanitised on save, not on send** — an allowlist of tags and attributes, so what an
  author previews is what a customer receives and a dangerous tag never reaches the database. Script and
  style tags are removed *with their contents*; unwrapping them would leave the source visible.
* **Customer values are escaped on substitution.** A template is administrator markup and a customer's name
  goes into it; unescaped, a name becomes markup in an email client and in the admin preview.
* **CTA URLs are validated by scheme**, on save and at render: `javascript:` and `data:` are refused, and a
  button whose URL fails is dropped rather than rendered.
* **No wallet or transaction figure is printable.** There is no balance variable — a balance in an email is
  forwarded, screenshotted and read over a shoulder. Conditions may *read* a balance because that decision
  never leaves the server.
* **Four permissions**, re-checked in the controller: `view_lifecycle` (the log), `manage_lifecycle`
  (templates, automations, the kill switch — super-admin only), `send_lifecycle` (test sends and retries).
  Activation, template changes, kill-switch changes and manual suppressions are audited via `AdminLog`.

### 16.8 Marketing and transactional are separate, and the kill switch proves it

Pausing marketing must never disable a security or payment email. The check lives in the dispatcher, where
the message's category is known, so it cannot catch a receipt. Tests assert both directions: with marketing
paused and with a customer opted out, a marketing message is blocked and a transactional one is sent.

An unsubscribe blocks marketing only; a bounce or complaint suppresses everything, because the address
itself is unusable. The unsubscribe link is signed and expires, so an opt-out cannot be forged for somebody
else. Marketing mail carries `List-Unsubscribe` and transactional mail does not.

### 16.9 Two defects found by these tests

| Defect | Effect |
| --- | --- |
| The condition cache was not keyed to the automation | Evaluating a *second* automation through the same instance returned the **first** automation's conditions, so a message was scheduled on somebody else's criteria. Invisible in production (one instance per resolution, one automation per event) and not invisible to any caller that loops — and "mostly invisible" is not a property a rule about who gets emailed should have |
| The value cache was keyed by field alone | The same instance answering for a second customer returned the *first* customer's balance and counts. The consequence is a wallet reminder going to somebody who has funded, on the strength of a stranger's balance |

Both are keyed now, and the second is why there is a test that evaluates two customers through one
instance in the order that would have leaked.

One test-only finding: `LifecycleEligibility` briefly checked suppression as well as the dispatcher, which
made a suppressed address report `cancelled` rather than `suppressed`. `suppressed` means the platform must
not mail the address; `cancelled` means the message stopped being worth sending. One owner each, because
conflating them makes the delivery log unable to distinguish "we chose not to" from "we are not allowed to".

### 16.10 Verification

```
php artisan test --filter=LifecycleMessagingTest      # 55 cases
php artisan test                                      # 1194 passed, 1 skipped
php artisan db:seed --class=LifecycleSeeder --force
php artisan lifecycle:process --dry-run
php tools/check-inline-js.php                         # lifecycle screens included
php tools/check-encoding.php
```

### 16.11 Not done

* **No provider webhook endpoint.** `confirmDelivery()` and `confirmBounce()` are implemented, tested and
  matched on our own correlation id, but nothing calls them: the platform's `MAIL_MAILER` is SMTP, which
  emits no delivery report. `delivered` therefore stays unset in production until an API-based mailer or a
  provider webhook is wired up. `accepted` is what the log will show, and that is the truthful answer.
* **Only the five recommended triggers.** No drag-and-drop builder, no branching journeys, no A/B testing.
* **Email only.** SMS and push are not implemented; the schema would carry a channel column.
* **The `lifecycle` queue needs a worker.** Until `queue:work --queue=lifecycle,default` runs, jobs the tick
  enqueues are never consumed. See docs/RUNNING.md.

## 17. Data pricing: the mismatch, the synchronisation, and the decision (2026-10-13)

### 17.1 The mismatch, stated exactly

A customer was shown ₦940.90, submitted, and was told *"the price for that bundle is now ₦864.90.
Please confirm the new price and submit again — no money has left your wallet."*

Two implementations of one calculation:

| Surface | Priced by | When |
| --- | --- | --- |
| the price list and the page's bundle dropdown | `ClubKonnectCatalogue::customerPriceFor()`, frozen into a cached payload | whenever a request found the cache cold or expired |
| the purchase (`AirtimeDataController::quoteData()`, `PricelistController::purchaseData()`) | `PricingEngine::quote()`, live | every submission |

Between those two moments **anything that moved the answer moved it only on the second one**: the
provider's cost after a synchronisation, a Super Admin editing the rule, or the cache's ten-minute TTL
expiring. The customer's page held the first answer; the purchase computed the second.

Two things made it worse than a mismatch:

* the two routes answered *differently*. `PricelistController` ignored the posted price and charged the
  new figure silently. `AirtimeDataController` refused and asked the customer to submit again — and
  there was nothing to submit *with*: no token, no record, nothing that said they had seen and accepted
  the new amount. The retry posted a figure the customer had produced themselves.
* `plan_price` was `required|numeric` on the data form and read as the baseline, so the *client's*
  number decided whether the server thought its own price had moved.

**Fix.** One method decides what a bundle costs a customer — `DataPriceService::quote()` — and both
surfaces call it. Display and charge cannot drift because they are no longer two implementations.

### 17.2 The catalogue nothing was refreshing

The bundles customers buy come from ClubKonnect/NelloBytes' `APIDatabundlePlansV2.asp` price list,
which carries both the bundles and what each costs ReUp. `providers:sync-catalogues` does **not** touch
it: that command walks the Wave-1 adapter catalogues (Sogo, VTpass, VTUGate, Nitro) into
`provider_products`. The data catalogue was re-filled by whichever customer request happened to arrive
after its ten-minute cache expired.

That has two consequences, and both are the kind that look like nothing:

* the provider's rate limit became a function of traffic — a quiet hour meant no refresh at all;
* there was **no record of the last successful synchronisation anywhere**, so "is our price list
  current" had no answer, and a stale price was indistinguishable from a fresh one.

`data:sync-catalogue` now does it, scheduled every minute with the *interval* enforced inside the
command (`CATALOGUE_SYNC_INTERVAL_SECONDS`, default 600). A tick that is not due prints "Skipped" and
exits 0 without touching the network, so a per-minute entry costs one cache read and the refresh lands
within a minute of becoming due.

**A failed fetch never replaces a working catalogue.** `fetchOrFail()` returns a processed catalogue or
throws; every cache write happens after. A timeout, a rate limit, an HTTP error, a malformed body, and
a well-formed body with no usable bundles are all failures, all recorded, and all leave the previous
catalogue exactly as it was. The empty case is the one that matters most: parsed naively it produces an
empty catalogue, and an empty catalogue written over a working one takes every data bundle off sale.

Three consecutive failures raise one `catalogue_unavailable` alert on `/admin/providers`, resolved by
the next success. The freshness — last success, bundle count, age, last error and its HTTP status — is
on that page and on `GET /pricelist/api`.

### 17.3 The confirmation, and what makes it safe

`PriceChangeQuote` issues an opaque, single-use, expiring, user-bound token. Confirming validates five
things — that we issued it, that it is this user's, that it names this bundle, that the price it
recorded is still the price, and that the *rule* that produced it is still the rule producing it. Only
then is the token consumed and the purchase made, from a **fresh** quote rather than from the figures
the token carries. The token proves the customer agreed to this bundle at this price; it never supplies
the amount.

The rule check is separate from the amount check on purpose. A rule edited between the two calls can
produce the same amount — a rounding step, a fee removed and a markup raised can cancel out — and the
confirmation was given for the old rule. Treating that as unchanged honours an agreement to something
the customer was never shown.

If the price moves again while the modal is open, the response carries a *new* token and asks again.
That is the loop the brief is explicit about, and the alternative is worse in both directions: charging
the second figure is charging an amount nobody agreed to, and erroring is the dead end this replaced.

### 17.4 The markup, and the two numbers that are not the same

Default 3%, bounded to the approved 2–3% band. Every figure is integer kobo and every percentage is
integer basis points, and the profit is measured from the **rounded** price.

    selling price = provider cost × (1 + markup)
    gross margin  = profit ÷ price

On ₦1,000 a 3% markup charges ₦1,030 — ₦30 of gross profit, which is a **2.91% margin**. The band is
enforced when a global or network-scoped percentage rule is saved, and the refusal says so in those
terms. Two things it deliberately does not do: it does not re-price rules that already exist outside
it, and it does not refuse an edit that leaves the markup alone.

**Which provider field is the cost.** ClubKonnect's payload publishes `PRODUCT_AMOUNT` per bundle,
which the catalogue carries as `clubkonnect_price` and `ProviderCostResolver::forDataBundle()` reads as
the effective cost. There is no second candidate: this endpoint publishes no discount rate, no
commission and no separate list-versus-net pair, so nothing has to guess. Airtime is the product with
two fields — a face value and a discounted cost — and it is priced `FACE_VALUE`, which the band excludes
by type. `DataPriceMarkupTest` pins both directions.

### 17.5 Five defects found while doing it

| Defect | Effect |
| --- | --- |
| **A network-scoped `FACE_VALUE` airtime rule priced every data bundle at cost** | `PricingDefaultsSeeder` creates one `FACE_VALUE` rule per network for airtime, and a data quote carries a network — so the network-scoped lookup in `resolveRule()` matched the *airtime* rule on every bundle. With no face value to price, the strategy falls back to the base cost, so **every data bundle was sold at exactly what the provider charges: zero margin, on every sale, with nothing failing.** Found by pricing the live catalogue rather than by reading the code: a ₦25.00 bundle came out at ₦25.00. A `FACE_VALUE` rule is now ineligible for a product with no face value, so the lookup continues to the rule that can price it. Three tests in `DataPriceMarkupTest`, including that airtime is still priced by the same rule |
| `$plan['your_price']` was a *float* recomputed per cache fill, and `plan_price` was compared to it with `abs(...) > 0.01` | Two rounding paths for one price. Replaced with `Money`/kobo and one `priceHasMoved()` threshold of one kobo — the smallest representable difference, so it cannot fire on representation noise |
| `PricelistController::purchaseData` and `AirtimeDataController::purchaseData` disagreed about a moved price | The same bundle bought from two pages behaved differently at the same moment. One pricing path now, one answer |
| The dashboard's recent-transactions widget printed `$transaction->provider` | "ClubKonnect" beside the customer's own phone number — the §5 defect, still live on a customer-facing surface |
| `Http::fake()` in Laravel 8 **merges** stub callbacks and the first match wins | Re-faking mid-test silently keeps serving the first response, so a test that appeared to prove "the second catalogue is applied" proved nothing. Recorded in `DataCatalogueSyncTest::providerSequence()` |

### 17.6 Network branding

`NetworkBrand` is now the only thing that decides a network's label, asset and aliases, reading
`config/networks.php`; `x-network-logo` is the only thing that renders one. The brand images
(`mtn.jpeg`, `airtel.jpeg`, `glo.png`, `t2.png`) had been sitting unused in `public/` while placeholder
monogram SVGs were rendered from a `config/bills.networks` map that each view re-keyed its own way —
one by ClubKonnect's numeric code, one by canonical key.

9mobile now displays as **T2mobile**. The *key* stays `9mobile`, because it is what a transaction
stores and what a pricing rule is scoped to; `9mobile`, `9 Mobile`, `T2`, `T2mobile` and `etisalat` all
resolve to it. Historical rows are untouched: the stored `network_name` wins for display, so a receipt
printed before the rebrand still says what it said — pinned by
`test_a_pre_rebrand_receipt_keeps_its_original_label`.

Marks are now shown on the purchase page, the price list, airtime history, wallet history, the
transaction success and failed pages, the dashboard widget, and the email receipt.

## 18. Social Boost (SMM): the asynchronous order (2026-10-13)

### 18.1 What existed, and what did not

The Nitro integration was already built and already correct: `NitroSmmProvider` implements all
six documented actions (`services`, `add`, `status`, `refill`, `balance`, `cancel`), parses the
documented response shapes, maps Nitro's statuses onto ReUp's canonical set, and computes the
charge with the documented `rate × quantity ÷ 1000` formula through one named divisor.
`CatalogueSync::readNitro()` read the catalogue and `ServiceOrderService` was the money path.

What did not exist was everything a customer or an operator touches. There was no way to see a
service, price one, place an order, or watch it. And three defects were sitting in the seams:

| Defect | Effect |
| --- | --- |
| `ServiceOrderService` read `$adapter->provider`, a **protected** property | A fatal error *after* the wallet was debited — the worst possible moment. `provider()` and `providerId()` exist precisely so this cannot be done by accident, and nothing had exercised the path |
| `ServiceOrderService` never persisted `providerReference` for a PENDING order | `markProcessing()` does not take it, so the reference stayed null. `SmmOrders::reconcile()` refuses to ask about an order with no reference — so a PENDING order could **never** be resolved: it held the customer's money forever while the sweep reported "nothing to poll". Silent, and exactly the state this module exists to prevent |
| No path credited the customer when Nitro refunded | Nitro refunds to **ReUp's wallet** and reports the order `Refunded`. That is the provider settling with us; it is not the customer being made whole, and nothing made the second movement |

`ServiceOrderService::refundUnfulfilled()` also refused `REFUNDED` outright, on the reasonable
reading that a refund had already happened. It had — to us, not to the customer. It now accepts
it, and the customer's transaction's own `payment_status` is what makes a repeated sweep credit
once.

### 18.2 The charge, and the floor

    provider_cost = rate × quantity ÷ 1000        (Nitro's documented model)
    selling price = provider_cost × (1 + markup)  (through PricingEngine)

Every social-boost order must earn at least **10% markup** over the verified provider cost. It is
enforced twice on purpose: `PricingRuleService` refuses to *save* a social-boost rule below the
floor, and `SmmPricing` refuses to *charge* below it — immediately before the wallet is debited.
The second is the control; the first keeps the console from being configured into a state that
only fails in front of a customer.

It is a floor, not a band. Data bundles have an approved 2–3% band because the market sets the
ceiling; social boost is the opposite, and a higher margin is a better price rather than a worse
one.

**Markup is not margin**, and both are reported: a 10% markup on ₦2,720 charges ₦2,992 and earns
₦272, which is a **9.09% gross margin**. Reporting the markup as the margin would overstate the
business by a tenth of its margin on every order.

A rate that is missing, zero, or older than `SMM_RATE_MAX_AGE_HOURS` is treated as **unknown**,
not as truth. Nitro improves rates automatically as monthly volume climbs, so the error is
one-directional: a stale rate sells at yesterday's cost and buys at today's.

### 18.3 The three things that make an async order safe

**Accepted is not completed.** `add` returns an order id; every order starts unresolved, holds
the customer's money, and is scheduled for reconciliation. Nothing in the module marks an order
successful on the strength of the provider accepting it.

**A timeout is UNKNOWN, and unknown is held.** It is not refunded — the order may have been
delivered — and it is never re-submitted, because sending it twice is how a customer is charged
twice for one order. Reconciliation asks what happened; only a terminal status the provider
itself reported leads to a refund.

**A double submit is one order.** `SmmOrders::idempotencyKey()` is *deterministic* — a hash of
the user, service, target and quantity — so two clicks present the same key and the database's
unique index turns the second into a replay of the first. A random key would make every double
submit a new order. The short-window duplicate guard sits in front of it as a courtesy; the
reservation is the control, and there is a test that clears the guard to prove it.

### 18.4 What the customer can break, and why they cannot

**The target.** A boost is the only purchase here that cannot be corrected: a mistyped meter
number is rejected before delivery, a mistyped Instagram username is followed on somebody else's
account. Targets are validated against per-platform shapes *before* the order is created, with
`config/smm.php` supplying both the pattern and the example the form shows — so the help page
cannot contradict the form. The patterns are permissive on purpose: too strict refuses a
legitimate target and the customer cannot argue with a regex, while too loose lets the provider
reject it, which is the behaviour without the check.

**The price.** No price is accepted from the client. `plan_price`-style fields are compared
against the server's own quote and never charged from, and a service the engine cannot price is
not listed at all rather than listed and refused at checkout.

**The provider.** No provider name, service id, order id or status string reaches a customer
surface. The provider's own vocabulary is on the admin order screen, where it is needed to answer
a support question.

### 18.5 The pages

Customer: landing (`/social-boost`), catalogue and configure (`/services`, `/services/{key}`),
review (`POST /review`), purchase (`POST /purchase`), orders, order detail, and a help page whose
per-platform link guidance is generated from the same config the form validates against.

Admin (`Admin → Commercial → Social Boost`): overview (volume, revenue, provider cost, gross
profit, margin, uncertain/failed/refunded counts, provider balance and connection), catalogue
(rates, limits, markup and margin per service, publish/pause, last sync), a read-only pricing
preview that reports cost, price, markup, margin and profit side by side, orders (search by ReUp
reference, provider reference, customer or target), and provider health.

The module is gated by one new permission, `view_social_boost`, in `Permissions::SUPER_ADMIN_ONLY`
— it exposes provider cost and margin on every screen. The **write** actions keep their existing
gates (`manage_catalogue` to publish, `manage_transactions` to reconcile or refund), so holding
it opens the screens and not the money.

### 18.6 The bug the tests found, and what it looked like

`SmmOrders::place()` writes the offering onto the order — which provider service this order is
for — but `ServiceOrderService::place()` creates the order and dispatches *inside one call*, and
it is capability-agnostic, so it knows nothing about offerings. A closure that read
`$order->providerProduct` therefore found `null` and sent Nitro **no service id at all**.

The symptom is worth recording because it is the module's worst failure mode and it looks like
nothing: the adapter returned UNKNOWN for a request that was never sent, so the order was held
for reconciliation, the customer's money stayed debited, and the sweep would have polled a
provider reference that did not exist. It was found because a test asserted the order's
`provider_reference` rather than only its status. The offering is now captured by the closure and
written to the order *before* the request goes out.

Two smaller ones: `SyncSmmCatalogue` and the admin controller called
`$provider->providerCatalogueItems()`, a relation that does not exist on `Provider` — the same
class of mistake, caught the same way. And a third that was a test rather than the application:
`Cache::forget('data_price_quote:' . $token)` stopped matching when the key became namespaced, so
the "expired confirmation" test would have gone on passing while expiring nothing. The key is
now obtained from `PriceChangeQuote::cacheKey()` instead of spelled out.














