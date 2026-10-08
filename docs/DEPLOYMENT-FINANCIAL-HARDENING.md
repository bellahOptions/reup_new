# Deployment runbook — financial hardening release

This release changes how money is stored, moved and recorded. It is safe to
deploy, but it is **not** a deploy-and-forget: step 3 is a mandatory gate.

Legend: **[GATE]** = do not proceed if it fails. **[VERIFY]** = confirm the
expected output.

---

## 1. Back up first

The single most important step. Two things are being changed on live data:
`transactions.status` / `transactions.payment_status` (their allowed values) and
the addition of UNIQUE indexes.

```bash
# Structure and data, consistent snapshot, no locking of writes
mysqldump --single-transaction --routines --triggers \
  -u "$DB_USERNAME" -p "$DB_DATABASE" > "reup-$(date +%Y%m%d-%H%M).sql"

# Verify the dump is readable and non-empty before going further
ls -lh "reup-$(date +%Y%m%d-%H%M).sql"
```

**Rollback strategy.** The two enum widenings are the only changes that alter
existing rows' *permitted* values, and neither rewrites a row. Rolling back
means restoring the dump above, or — if no row has been written with a new
status (`refunded`, `reversed`, `verifying`, `unknown`) — simply running
`php artisan migrate:rollback --step=4`, which drops `wallet_ledger`,
`idempotency_keys` and the new indexes and re-narrows the enums. The rollback
**fails deliberately** if a row now carries one of the new statuses, because
narrowing the column would silently falsify that transaction's state.

---

## 2. Pre-flight check **[GATE]**

The migrations that add UNIQUE indexes to `transactions.api_reference`,
`transactions.payment_reference` and `wallets.user_id` will abort if duplicates
exist. Run this **against a copy of production data** before the window:

```bash
php tools/preflight-financial-integrity.php
echo "exit code: $?"
```

**[VERIFY]** `RESULT: no blockers. Safe to migrate.`

If it reports `BLOCK`, the migration will fail and the deploy must stop. Do not
de-duplicate the rows automatically — each duplicate is a real financial record
and a human has to decide which row is authoritative. The script prints the
offending values with their row ids.

It also reports wallet balances that disagree with their transaction history.
Those are **informational**: historical rows predate the wallet service, and the
ledger is introduced with a per-wallet opening entry that records each existing
balance as a known starting point rather than recalculating it. No historical
row is rewritten.

---

## 3. Deploy code, then migrate

```bash
git pull                       # or whatever your release mechanism is
composer install --no-dev --optimize-autoloader
php artisan down --render="errors::503"   # optional; the migrations are fast
php artisan migrate --force
php artisan up
```

**[VERIFY]** `php artisan migrate:status` shows the four new migrations as `Yes`:

| Migration | Effect |
| --- | --- |
| `2026_02_20_000000_create_wallet_ledger_table` | Creates the immutable ledger and writes one opening entry per non-zero wallet |
| `2026_02_20_000001_add_financial_idempotency_constraints` | Creates `idempotency_keys`; adds UNIQUE on `transactions.api_reference`, `transactions.payment_reference`, `wallets.user_id` |
| `2026_02_20_000002_widen_transaction_status_columns` | Adds `verifying`, `refunded`, `reversed` to `payment_status` and `verifying`, `unknown` to `status` |
| `2026_02_20_000003_add_transaction_indexes_and_idempotency_key` | Adds `transactions.idempotency_key` and three indexes |

**[VERIFY]** the opening entries were written — one per wallet that holds a
balance, and none for a zero wallet:

```sql
SELECT COUNT(*) FROM wallet_ledger WHERE reference LIKE 'OPENING-%';
SELECT COUNT(*) FROM wallets WHERE balance <> 0;
-- These two numbers must match.
```

Confirm the ledger agrees with the balances it just recorded:

```sql
SELECT w.id, w.balance,
       SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE -l.amount END) AS from_ledger
FROM wallets w
JOIN wallet_ledger l ON l.wallet_id = w.id
GROUP BY w.id, w.balance
HAVING ABS(w.balance - SUM(CASE WHEN l.direction = 'credit' THEN l.amount ELSE -l.amount END)) > 0.01;
-- Must return zero rows.
```

---

## 4. Clear caches

```bash
php artisan config:clear
php artisan cache:clear
php artisan view:clear        # compiled Blade caches the @vite output
php artisan route:clear
```

`cache:clear` is safe here, and that is the point of this release: financial
idempotency no longer lives in the cache. Flushing it used to reset every replay
guard in the platform.

---

## 5. Start the scheduler **[GATE — this is the one that silently does nothing]**

`payments:reconcile` is the safety net for payments the Paystack webhook never
confirmed. It is registered in the schedule and **does nothing at all** unless
something invokes `schedule:run` every minute. Nothing about the application
fails when that is missing: payments stay `pending`, no error is logged, and the
first symptom is a customer saying they paid and were not credited.

Pick **one** of these, then prove it works.

**Linux — cron (recommended):**

```bash
crontab -e
# add:
* * * * * cd /path/to/reup && php artisan schedule:run >> /dev/null 2>&1
```

**Linux — supervisor, if you already run workers** (`/etc/supervisor/conf.d/reup-scheduler.conf`):

```ini
[program:reup-scheduler]
command=php /path/to/reup/artisan schedule:work
directory=/path/to/reup
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/reup-scheduler.log
stopwaitsecs=3600
```

**Windows — Task Scheduler:**

```powershell
schtasks /create /tn "ReUp Scheduler" /sc minute /mo 1 /ru SYSTEM `
  /tr "php C:\path\to\reup\artisan schedule:run"
```

**Container** — add a second process, or make the scheduler the container command:

```dockerfile
CMD ["php", "artisan", "schedule:work"]
```

### Prove it

```bash
# Give the schedule at least two minutes to tick, then:
php artisan schedule:health --check
echo "exit code: $?"      # must be 0
```

**[VERIFY]** `The scheduler is running. payments:reconcile is executing every minute.`

`--check` exits non-zero when it is not, so this works as a deploy gate, a
monitoring probe or a health check. Wire it into whatever alerts you:

```bash
# cron, every 5 minutes, alert on failure
*/5 * * * * cd /path/to/reup && php artisan schedule:health --check || /usr/bin/notify-ops "scheduler down"
```

**[VERIFY]** the schedule contains all three entries:

```bash
php artisan schedule:list
# payments:reconcile .............. Every minute
# payments:review-unconfirmed ..... Hourly
# scheduler-heartbeat ............. Every minute
```

---

## 6. Post-deploy verification

```bash
# 1. Reconciliation runs end to end against the live gateway, changing nothing.
php artisan payments:reconcile --dry-run

# 2. Unconfirmed purchases are visible. If this lists anything, someone has to
#    check each reference in the provider dashboard — that is the point.
php artisan payments:review-unconfirmed --list

# 3. No wallet's ledger disagrees with its balance (see the SQL in step 3).

# 4. The application boots and routes resolve.
php artisan route:list | wc -l
php artisan env:doctor
```

Then walk these by hand, on production, with a real account:

| Check | Expected |
| --- | --- |
| Fund ₦100 by card | Wallet increases by exactly ₦100.00; one `CREDIT` letter entry |
| Reload the Paystack callback URL | Balance unchanged; "already settled" |
| Buy ₦50 airtime | Balance falls by exactly ₦50.00 plus the fee; one `DEBIT` entry |
| Buy the same thing twice quickly | Second submission is refused as a duplicate, not charged twice |
| Admin: view a customer's wallet | Balances and the ledger statement agree |
| Admin: adjust a wallet by ₦1 | Balance moves by ₦1.00; an `ADMIN_ADJUSTMENT` entry appears with the admin's id |
| Admin: refund a settled purchase twice | First succeeds, second is refused as already refunded |

---

## 7. Monitoring to put in place

These log lines are new and each means money needs attention. Alert on all of
them.

| Log line | Meaning | Action |
| --- | --- | --- |
| `purchase_outcome_unknown` | A provider call timed out; the order may have vended | Check the reference upstream, then resolve it in the console |
| `unconfirmed_purchases_remain` | The review sweep found purchases still unresolved | Same |
| `Refund failed — manual intervention required` | A credit could not be written | The customer is out of pocket; credit manually |
| `Paystack amount mismatch` / `currency mismatch` / `reference mismatch` | A payment did not match the transaction | Investigate; nothing was credited |
| `SECURITY: transaction PIN locked` | Repeated wrong PINs on one account | Contact the customer if it is unusual |
| `Platform daily payout ceiling reached` | The platform-wide daily cap was hit | Check for abuse |

```bash
grep -E 'purchase_outcome_unknown|unconfirmed_purchases_remain|Refund failed|mismatch|pin_lockout' \
  storage/logs/laravel.log | tail -50
```

---

## 8. Rollback

1. **If the scheduler misbehaves:** stop the cron/supervisor entry. Payments stay
   pending but nothing is lost — the webhook path is independent.
2. **If a code bug is found:** `git revert` the release commit and redeploy. The
   ledger and idempotency tables can stay in place; the previous code simply does
   not read them.
3. **If the migrations must be undone:** `php artisan migrate:rollback --step=4`.
   Read the warning in step 1 first — this fails if new status values have been
   written, and that failure is protecting your data.
4. **Worst case:** restore the dump from step 1. Nothing in this release deletes
   or rewrites a historical financial row, so a restore is a complete recovery.

---

## 9. What this release deliberately does NOT do

- **No Laravel upgrade.** The framework stays at 8.83.29. See
  `docs/LARAVEL-UPGRADE-PLAN.md` for the staged plan.
- **No historical recalculation.** Wallet balances are taken as given. Where they
  disagree with transaction history, that is reported and recorded as an opening
  ledger entry, never corrected silently.
- **No change to transaction amounts.** Every decimal column keeps its precision
  and scale; the integer-kobo discipline lives in the application layer, and
  `Money` writes the exact same `decimal(15,2)` values the schema already holds.
- **No new dependencies.**
