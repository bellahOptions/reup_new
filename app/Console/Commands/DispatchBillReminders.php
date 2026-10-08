<?php

namespace App\Console\Commands;

use App\Retention\RetentionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Emit bill reminders that are due.
 *
 * ## What this command does, and what it deliberately does not
 *
 * It writes a notification for each reminder whose time has come, exactly once
 * per occurrence. It **never** debits a wallet: there is no call to
 * `WalletService` on this path, no reference to a transaction, and no column on
 * `bill_reminders` that could be read as permission to pay.
 *
 * AutoPay is a separate, future feature. When it arrives it will need its own
 * explicit customer consent record, its own command and its own review — not a
 * flag bolted onto this one.
 *
 * ## Duplicate prevention
 *
 * `RetentionService::emitDueReminders()` claims each reminder for its due date
 * before writing anything, so running this every minute produces one
 * notification per reminder per due date. Overlapping runs are therefore safe,
 * which is why the schedule does not need `withoutOverlapping` as a correctness
 * measure (it is still set, to bound concurrency).
 *
 * ## Usage
 *
 * ```
 * php artisan reminders:dispatch
 * php artisan reminders:dispatch --dry-run
 * ```
 */
class DispatchBillReminders extends Command
{
    protected $signature = 'reminders:dispatch
                            {--dry-run : Report what would be sent without writing anything}';

    protected $description = 'Send due bill reminders. Reminders never debit a wallet.';

    public function handle(RetentionService $retention): int
    {
        if ($this->option('dry-run')) {
            $due = \App\Models\BillReminder::due()->count();

            $this->info("[dry run] {$due} reminder(s) are due. Nothing was written.");

            return self::SUCCESS;
        }

        $result = $retention->emitDueReminders();

        $this->info("Sent {$result['emitted']} reminder(s); {$result['skipped']} were already claimed.");

        if ($result['emitted'] > 0) {
            /*
             * Logged so an operator can see reminder volume, and so a spike — a
             * reminder misconfigured to fire every minute, say — is visible
             * rather than silent.
             */
            Log::info('Bill reminders dispatched', [
                'event' => 'reminders_dispatched',
                'emitted' => $result['emitted'],
                'skipped' => $result['skipped'],
                'debits' => 0,
            ]);
        }

        return self::SUCCESS;
    }
}
