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
transfer, USSD funding). Bill purchases are resolved by ClubKonnect/Payvessel and
are deliberately excluded — Paystack has never heard of them, so polling one
would return "not found" and could wrongly close a delivered order.


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
