# Staged Laravel upgrade plan

**Status: NOT started, and deliberately so.**

This document is a plan, not a change. Nothing in the financial-hardening release
touches the framework version.

---

## Why not now

The application runs **Laravel 8.83.29 on PHP 8.3**, which is a supported
combination and is not, by itself, a security problem: Laravel 8 receives
security fixes on its security-support track, and PHP 8.3 is current.

The upgrade is worth doing — Laravel 8 is the oldest version that runs on PHP
8.3, and v9/v10/v11 bring real benefits — but it is a **large, orthogonal
change** that touches every request, every model and every Blade template. Doing
it in the same release as a rewrite of the wallet ledger, the idempotency
mechanism and the payment state machine would mean that when something breaks,
there is no way to tell which change broke it. Financial correctness comes
first, so the financial work ships on a stable framework and the framework moves
afterwards, on its own.

There is also a concrete known-good reason to wait: `App\Support\Vite`
implements the `@vite` Blade directive *because* Laravel 8 has none, and
`AppServiceProvider` registers it. On Laravel 9.19+ the framework provides its
own, and having both registered is ambiguous. That interaction is documented
below as a required step, and it is exactly the kind of thing that is easy to
miss while also chasing enum changes.

---

## The realistic blockers, in order

| # | Blocker | Where | Why it matters |
| --- | --- | --- | --- |
| 1 | `@vite` is registered by this application | `App\Support\Vite`, `AppServiceProvider::boot()` | Double registration on 9.19+; must be removed, and the framework's version must be verified to resolve the same manifest |
| 2 | `$request->string()` / `->integer()` / `->float()` used on Laravel 8 | Admin controllers | These are Laravel 9 additions that were *removed* during the last audit. If any reintroduced call remains, it works on 9+ and never did on 8 — check both directions |
| 3 | `Schema::defaultStringLength(191)` | `AppServiceProvider::boot()` | Not a blocker, but re-verify the index-key-limit reasoning still holds on the target MySQL version, and that no new index exceeds it |
| 4 | `fruitcake/laravel-cors ^2.0` | `composer.json` | Replaced by the framework's own CORS handling in v9. Config must migrate to `config/cors.php` (already present) and the package removed |
| 5 | `facade/ignition ^2.5` | `composer.json` | Dev-only; replaced by `spatie/laravel-ignition` ^1 on v9. Ignition cannot be skipped on a `--no-dev` deploy, but local debugging matters |
| 6 | `laravel/sanctum ^2.11` | `composer.json` | v3 for Laravel 9. `config/sanctum.php` needs republishing and diffing |
| 7 | `nunomaduro/collision ^5.10`, `phpunit/phpunit ^9.5` | `composer.json` | v6 / v9.5+ respectively. PHPUnit 10 changes the test-runner API and `phpunit.xml` schema |
| 8 | `laravel/breeze ^1.10`, `laravel/sail ^1.0.1` | dev deps | Version constraints must move together |
| 9 | Enum-to-string migrations | `2026_02_20_000002_widen_transaction_status_columns` | Written as raw `ALTER TABLE ... MODIFY` for MySQL. It is version-agnostic, but re-run it on a scratch database after each upgrade step |
| 10 | `doctrine/dbal ^3.10` | `composer.json` | Needed by `->change()` on Laravel 8; from v11 Laravel has its own column-modification support. Do not remove it before the version that provides a replacement is in place |
| 11 | `Str::of()` / helper deprecations | throughout | Run the deprecation log in step 2 of each stage; do not upgrade on a guess |

---

## The staged plan

One Laravel major version per stage. **Never skip a major.** Each stage is a
separate release with its own deploy window and its own soak period.

### Stage 0 — prerequisites (no version change)

Do this first, on the current version, and let it soak for at least a week.

1. `composer update --with-all-dependencies` within the existing constraints, and
   commit the resulting `composer.lock`. This clears patch-level drift before the
   major jump.
2. Ship the financial-hardening release. **Everything in the test suite must be
   green on 8.83.29 before the framework moves**, so that a later failure has one
   possible cause instead of two.
3. Add `docs/RUNNING.md` notes for anything version-sensitive discovered.
4. Inventory every `illuminate/*` API the application calls that is documented as
   deprecated in 8.x. `grep -rn 'Str::of\|->tap(\|optional(' app/ | wc -l` is a
   useful starting count.
5. Tag the release (`git tag pre-laravel-9`) so the rollback point is explicit.

### Stage 1 — Laravel 9

```
composer require laravel/framework:^9.0 --with-all-dependencies
composer require laravel/sanctum:^3.0 --with-all-dependencies
composer remove fruitcake/laravel-cors
composer require --dev spatie/laravel-ignition:^1.0 nunomaduro/collision:^6.0
```

Then, in order:

1. Remove `App\Support\Vite` and its `@vite` registration from
   `AppServiceProvider`. Set `vite.config.js`'s manifest output to the location
   the framework's directive expects. **Verify with `php tools/check-assets.php`**
   — a missing stylesheet is silent, the page still returns 200.
2. `php artisan vendor:publish --tag=laravel-*` for `cors`, `sanctum`,
   `mail`, `filesystems` and diff each published file against the committed one.
   Take the framework default unless the application's version has a deliberate
   change — `config/mail.php`'s encryption default and `config/filesystems.php`'s
   private disk are both deliberate.
3. `php artisan migrate` on a scratch copy and confirm the raw `ALTER TABLE` in
   the status-widening migration still applies cleanly.
4. Search for Laravel 9 API that the codebase was *fixed away from* on 8:
   `grep -rn '\->string(\|\->integer(\|\->float(\|\->dateTime(' app/` — those calls
   now work, and any that were half-introduced should be reviewed rather than
   left inconsistent.
5. Full test suite green. Manual walkthrough of: registration, sign-in, OTP
   sign-in, funding by card, funding by bank transfer, one purchase per product,
   admin wallet adjustment, admin refund.
6. Soak in production for a week with the reconciliation log watched.

### Stage 2 — Laravel 10

```
composer require laravel/framework:^10.0 --with-all-dependencies
composer require --dev phpunit/phpunit:^10.0 nunomaduro/collision:^7.0
```

1. `phpunit.xml` needs the PHPUnit 10 schema; `--migrate-configuration` will
   rewrite it, **then re-add the `DB_DATABASE=reup_test` override by hand** —
   losing that line makes `php artisan test` run `migrate:fresh` against the
   development database and erase it. That has happened once in this project's
   history.
2. Laravel 10 drops PHP 8.0 support; confirm the deployment target is 8.1+.
3. `doctrine/dbal` may still be required for `->change()`. Check whether any
   migration uses it and either keep the dependency or convert those migrations
   to the framework's own column modification.
4. Test suite green; same manual walkthrough.

### Stage 3 — Laravel 11+

Laravel 11 restructures the skeleton (`bootstrap/app.php` owns middleware,
exceptions and routing; `app/Http/Kernel.php` and `app/Console/Kernel.php` go
away). That is the largest mechanical change in the sequence and it interacts
with this application's schedule.

1. **The scheduler registration** currently lives in `app/Console/Kernel.php`.
   It must move to `bootstrap/app.php` via
   `->withSchedule(function (Schedule $schedule) { ... })`, preserving all three
   entries (`payments:reconcile` every minute, `payments:review-unconfirmed`
   hourly, `scheduler-heartbeat` every minute). **`php artisan schedule:health
   --check` must still pass after the move** — losing the reconciliation
   registration is the single most damaging possible outcome of this stage,
   because it fails silently.
2. Middleware registration moves to `bootstrap/app.php`. The security-headers
   middleware added in this release must be carried across into the global stack.
3. `routes/api.php` requires an explicit opt-in. Confirm whether anything uses it
   before dropping it.
4. Laravel 11 requires PHP 8.2+.
5. Test suite green; **repeat the full manual walkthrough plus the scheduler
   proof**, then soak.

---

## Verification that applies to every stage

```bash
php artisan test                      # all green, zero skips added
php artisan route:list | wc -l        # route count unchanged (or explained)
php artisan schedule:list             # all three entries present
php artisan schedule:health --check   # exit 0
php tools/check-assets.php            # stylesheet and script load
php tools/preflight-financial-integrity.php
php artisan payments:reconcile --dry-run
```

Plus, after each stage, these are the invariants that must hold on production
data — they are the reason the upgrade is worth doing carefully rather than
quickly:

```sql
-- The ledger agrees with every wallet balance.
SELECT w.id, w.balance,
       SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE -l.amount END) AS from_ledger
FROM wallets w JOIN wallet_ledger l ON l.wallet_id = w.id
GROUP BY w.id, w.balance
HAVING ABS(w.balance - SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE -l.amount END)) > 0.01;
-- Zero rows.

-- No settled funding row is missing its gateway reference.
SELECT COUNT(*) FROM transactions
WHERE service_type = 'funding' AND status = 'success' AND payment_reference IS NULL;
-- Should be zero for every row settled after this release.
```

---

## What would make this urgent enough to reorder

The plan above assumes the upgrade is worthwhile but not urgent. Reorder it if
any of these becomes true:

- a security advisory is published for Laravel 8 that has no backport;
- a required dependency (a payment gateway SDK, a PHP extension integration)
  stops supporting Laravel 8;
- the deployment target moves to a PHP version Laravel 8 does not run on.

In those cases Stage 1 is the minimum viable move and the financial work should
already be shipped, which is exactly why it goes first.
