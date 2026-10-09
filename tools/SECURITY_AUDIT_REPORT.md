# Malware & Backdoor Audit — `C:\Users\Administrator\reup_new`

Date: 2026-10-09
Scope: entire workspace (project root, `app/`, `routes/`, `config/`, `resources/`, `public/`, `dist/`,
`storage/`, `tools/`, `tests/`, `database/`), plus local web roots, persistence points and the
`reup_old` MySQL database.
Method: PHP-aware static analysis (comments and string literals stripped before matching), magic-byte
verification of every binary file, cryptographic hashing, timestamp-clustering, dependency-integrity
review, persistence review, and database/audit-log review.

## Verdict

**No malicious code was found in the website.** There is no webshell, backdoor, obfuscated payload,
planted admin account, or persistence mechanism in the source tree or its dependencies.

One genuine security defect **was** found and removed: eight leftover administrator accounts in the
development database, created by a headless-browser script exercising the admin self-registration
endpoint. Details in §3.

## 1. What was scanned, and how

| Check | Coverage | Result |
|---|---|---|
| PHP-aware sink analysis (eval, assert, `preg_replace /e`, `create_function`, dynamic callables, `base64_decode`/`gzinflate`/`str_rot13`, `system`/`exec`/`shell_exec`/`passthru`/`proc_open`, backticks, superglobal→`call_user_func`/`array_map`, variable includes, remote includes, `file_put_contents($_GET…)`, `extract($_GET…)`, `unserialize`, `chmod 0777`, `@eval`) | 506 PHP files (comments + strings stripped) | **0 malicious hits** |
| Naive signature scan of all project files | 4 869 files | 0 hits (all 1 508 raw matches were docblock backticks and JS template literals — verified individually) |
| Magic bytes vs extension for every image/font/archive | 47 binary files | all authentic (JPEG/PNG/WOFF/PDF); no polyglots, no script with an image extension |
| Uploaded-file inspection | `storage/app/public/profiles/*` (only 1 upload) | real JPEG; the two `<?=` byte sequences are inside compressed ICC-profile data, not a payload |
| `.htaccess` review | `public/`, `dist/` | stock Laravel front-controller rules only |
| Hardcoded-endpoint review of `config/` + `app/` | 25 distinct hosts | all legitimate: Paystack, VTpass, ClubKonnect, Sogo, Nitro, Pairgate, VTUgate, Bachs, nellobytesystems, Google, jsDelivr, Quill, Cloudflare. **No raw-IP, pastebin, tunnel or webhook.site endpoints** |
| Composer dependency integrity | 113 packages | all recognised vendors (Laravel, Symfony, Guzzle, PSR, PHPUnit, Mockery, Faker, Carbon, PsySH, Whoops…); `composer.json`/`composer.lock` untampered |
| `vendor/` and `node_modules/` modification times | 15 000+ files | **zero** files modified after 2026-10-08 install — no dropped files |
| Timestamp clustering of hand-written code | 1 269 files | coherent development history 2026-10-07 → 2026-10-08; no anomalous single-file drop |

## 2. Persistence / instance review

- **Processes:** only `mysqld` (Laragon, expected) and the DSH web GUI (`node … dsh web --port 3080`).
  **No PHP process, no Apache, no unexpected binary is running.** No Laravel dev server was live.
- **Apache `DocumentRoot`** is `C:/laragon/www`, which contains a single benign `index.php`. The ReUp
  app is not currently served from the web root, so `storage/`, `config/` and `.env` are not exposed.
- **Scheduled tasks / Run keys / Startup folders:** nothing app-related. No cron-style entry, no
  `schedule:work` daemon, no autostart row pointing at the project.
- **`php.ini`:** `auto_prepend_file`, `auto_append_file` and `disable_functions` are all empty;
  `allow_url_include=Off`. No injected loader.
- **`bootstrap/cache/`:** only the two framework caches (`packages.php`, `services.php`), regenerated
  by the framework — no injected provider or alias.

## 3. The one real finding — leftover admin accounts (REMOVED)

`reup_old.users` contained **eight active administrator accounts** with `is_admin = 1`:

```
19  Modal Auditor  modal-audit-6bd1c766@example.com   20 … 33  (8 accounts, ids 19–33 odd)
```

Evidence they were debris from a browser-driven test, not legitimate staff:

- `admin_logs` rows 20–31 show these ids authenticating from **`HeadlessChrome/153.0.0.0`** at
  `127.0.0.1` and reaching `admin.transactions.index` / `admin.settings.index` — an automated script
  was driving the real admin console;
- the name `Modal Auditor` and the `modal-audit-*` / `audit-*` address formats appear **nowhere in the
  source tree or any git revision** — they are orphaned database residue;
- they were created 15:31–15:51 on 2026-10-08, immediately after the `admin.register` route was
  committed, i.e. before `phpunit.xml` gained its test-database isolation.

**Why this mattered.** Eight admin logins with a single predictable fixture password are a standing
backdoor into a console that can move money, approve bank transfers and rewrite pricing — even in a
development database, since `reup_old` is writable and only one admin is legitimate.

**Removed:**

| Object | Rows removed |
|---|---|
| `users` (ids 19, 21, 23, 25, 27, 29, 31, 33) | 8 |
| `wallets` (their auto-created wallets) | 8 |
| `admin_logs` (their access log rows) | 11 |
| Session files authenticated as those ids | 8 |

Every removed row was backed up first to `tools/removed-test-admins-backup.sql` (re-runnable
`DELETE` statements plus the original `INSERT`s). The script is `tools/remove-test-admins.sql`.

**Deliberately preserved:** the real super admin (`superadmin@reup.com.ng`, id 14), the two real
customer accounts (ids 13 and 15 — id 15 holds ₦500 funded and 19 transactions), and the
faker-generated users that belong to the same test run but hold **no** admin rights.

**After removal:** exactly one privileged account remains, and `users` fell 22 → 14.

**Root cause is already fixed in source.** `Admin\AuthController::register()` now aborts with 404
unless `User::admins()->lockForUpdate()->exists()` is false, so the public self-registration path that
minted these admins can no longer be used once any admin exists. The `admin.register` route is
therefore guarded, and the leftover accounts were the last exploitable remnant of the old behaviour.

## 4. Hardening recommendations (no malware involved)

1. **Rotate the seeded super-admin password.** `AdminSeeder.php` ships `Admin@123456` as
   `DEFAULT_PASSWORD` (used only when `ADMIN_SEED_PASSWORD` is unset). Confirm `.env` sets a strong
   `ADMIN_SEED_PASSWORD` and that the live console password is not the default.
2. **The `audit-*@example.com` users (ids 16–18) are also test residue.** They hold no admin rights,
   so they are not a security issue; remove them with the same pattern if you want the dev database
   clean.
3. **Delete `storage/framework/lsp-*.php` when present.** The editor's PHP language server has
   written web-executable `.php` cache files into `storage/framework/` in this and sibling projects
   (see the git history: `storage/framework/lsp-0d23f210ccf1dcec.php` was committed and then deleted).
   They are not malicious, but the directory should never be web-served. This workspace has none
   today.
4. **Keep `APP_DEBUG=false` and `APP_ENV=production` on the live host.** `.env` here is `local` /
   `debug=true`; the framework returns `/storage/framework/views` paths in traces at that setting.
5. **Re-run the scanner after deployment changes:**
   `powershell -File tools\security_scan.ps1` and
   `php tools\php_malware_scan.php .` — the second is the accurate one.

## 5. Scanner tools added

| File | Purpose |
|---|---|
| `tools/php_malware_scan.php` | PHP-aware scanner; strips comments/strings before matching so docblocks cannot create false positives |
| `tools/security_scan.ps1` | Naive multi-signal sweep (names, dangerous calls, obfuscation markers, remote URLs, recent files) |
| `tools/triage_scan.ps1` | Timestamp clustering + magic-byte/extension verification for every binary |
| `tools/remove-test-admins.sql` | The verified removal script (idempotent) |
| `tools/removed-test-admins-backup.sql` | Backup of every removed row |
| `tools/php-scan-results.txt`, `tools/scan-results.txt`, `tools/triage-results.txt` | Raw evidence |

These are analysis artifacts, not application code; they are untracked by git and safe to delete.
