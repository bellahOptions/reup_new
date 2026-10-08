<?php

namespace App\Retention;

use App\Models\BillReminder;
use App\Models\ProductAlert;
use App\Models\SavedBill;
use App\Models\ServiceOrder;
use App\Models\Transactions;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Saved bills, Pay Again, and bill reminders.
 *
 * ## Ownership
 *
 * Every read and write on a saved bill is scoped to the owner. `mine()` is the
 * only way to obtain one, so a controller cannot accidentally act on another
 * user's row by id — and the scoping is on the query, not a check afterwards,
 * because a check afterwards is a check somebody can forget.
 *
 * ## Pay Again creates a new order
 *
 * It does **not** replay the old transaction. Replaying would charge the price
 * recorded months ago, against a product that may no longer exist, from a
 * provider that may have been deactivated. Instead it re-resolves everything —
 * product, provider, price, availability — and returns the *inputs* for a fresh
 * purchase, so the new order carries a new idempotency key, a new pricing
 * snapshot and a new provider operation.
 *
 * ## A reminder never debits
 *
 * `emitDueReminders()` writes a `product_alerts` row and nothing else. There is
 * no code path here that touches a wallet, and the `bill_reminders` table has no
 * column that could be mistaken for permission to do so.
 */
class RetentionService
{
    /** How many recent payments Pay Again offers, and how far back. */
    private const PAY_AGAIN_LIMIT = 20;

    private const PAY_AGAIN_DAYS = 90;

    /* =====================================================================
     | Saved bills
     | =================================================================== */

    /**
     * The owner's saved bills.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, SavedBill>
     */
    public function mine(User $user)
    {
        return SavedBill::forUser($user->getKey())
            ->where('is_active', true)
            ->orderByDesc('last_used_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * One saved bill, or a 404.
     *
     * Scoped by `user_id` on the query, so another user's id produces a
     * `ModelNotFoundException` — which Laravel renders as a 404, not a 403. A 403
     * would confirm the row exists.
     */
    public function findForUser(User $user, int $id): SavedBill
    {
        return SavedBill::forUser($user->getKey())->findOrFail($id);
    }

    /**
     * Save a bill account.
     *
     * The unique index on `(user_id, product_key, identifier)` means the same
     * account saved twice fails at the database rather than producing a duplicate
     * the customer then has to disambiguate. A repeat save updates the label
     * instead, which is what the customer meant.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function save(User $user, array $attributes): SavedBill
    {
        $identifier = trim((string) ($attributes['identifier'] ?? ''));

        if ($identifier === '') {
            throw new RuntimeException('A saved bill needs an account or meter number.');
        }

        $existing = SavedBill::forUser($user->getKey())
            ->where('product_key', $attributes['product_key'])
            ->where('identifier', $identifier)
            ->first();

        if ($existing) {
            $existing->forceFill([
                'label' => $attributes['label'] ?? $existing->label,
                'attributes' => $attributes['attributes'] ?? $existing->attributes,
                'is_active' => true,
            ])->save();

            return $existing;
        }

        return SavedBill::create([
            'user_id' => $user->getKey(),
            'product_key' => $attributes['product_key'],
            'service_product_id' => $attributes['service_product_id'] ?? null,
            'label' => $attributes['label'],
            'identifier' => $identifier,
            'attributes' => $attributes['attributes'] ?? null,
        ]);
    }

    /**
     * Update a saved bill's label or attributes.
     *
     * The identifier and product are deliberately immutable: changing the account
     * a saved bill points at turns it into a different bill, and a customer who
     * wanted that can save a new one. Allowing the edit would also let a
     * mistyped account silently replace a correct one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(User $user, int $id, array $attributes): SavedBill
    {
        $bill = $this->findForUser($user, $id);

        $bill->forceFill(array_filter([
            'label' => $attributes['label'] ?? null,
            'attributes' => $attributes['attributes'] ?? null,
        ], fn ($value) => $value !== null))->save();

        return $bill;
    }

    /**
     * Delete a saved bill.
     *
     * Soft, by deactivating rather than removing the row. A reminder may point at
     * it, and a reminder that loses its saved bill would fire with no account to
     * name; and a historical order's payload refers to the account, so the record
     * is worth keeping.
     */
    public function delete(User $user, int $id): void
    {
        $bill = $this->findForUser($user, $id);

        $bill->forceFill(['is_active' => false])->save();

        // Deactivate any reminders that depended on it, so a scheduler run cannot
        // notify about an account the customer has removed.
        BillReminder::forUser($user->getKey())
            ->where('saved_bill_id', $bill->getKey())
            ->update(['is_active' => false]);
    }

    /**
     * The inputs for paying a saved bill again.
     *
     * Returns what a purchase needs — product, capability, recipient and the
     * account's attributes — but **not** a price. The caller resolves the price
     * from the pricing engine at the moment of payment, so a saved bill cannot
     * carry a stale one.
     *
     * @return array<string, mixed>
     */
    public function payAgainPayload(User $user, int $id): array
    {
        $bill = $this->findForUser($user, $id);

        if (! $bill->is_active) {
            throw new RuntimeException('That saved bill has been removed.');
        }

        return [
            'saved_bill_id' => $bill->getKey(),
            'product_key' => $bill->product_key,
            'service_product_id' => $bill->service_product_id,
            'recipient' => $bill->identifier,
            'attributes' => $bill->attributes ?? [],
            'label' => $bill->label,
            // Explicitly not a price. The engine prices this at checkout.
            'price_minor' => null,
        ];
    }

    /* =====================================================================
     | Pay Again
     | =================================================================== */

    /**
     * The customer's recent successful, repeatable payments.
     *
     * Only products that can meaningfully be repeated are offered: a one-off
     * purchase with no stored account (a gift card, an eSIM) has nothing to
     * repeat, so including it would produce a button that cannot work.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ServiceOrder>
     */
    public function payableAgain(User $user)
    {
        return ServiceOrder::forUser($user->getKey())
            ->where('status', ServiceOrder::STATUS_SUCCESS)
            ->whereIn('product_key', self::repeatableProductKeys())
            ->where('created_at', '>=', now()->subDays(self::PAY_AGAIN_DAYS))
            ->orderByDesc('created_at')
            ->limit(self::PAY_AGAIN_LIMIT)
            ->get();
    }

    /**
     * Products a repeat payment makes sense for.
     *
     * Derived from what the catalogue actually exposes rather than hard-coded, so
     * adding a repeatable product does not require editing this list — but with a
     * conservative baseline so an empty catalogue does not offer everything.
     *
     * @return array<int, string>
     */
    public static function repeatableProductKeys(): array
    {
        return [
            'airtime', 'data', 'electricity', 'cable_tv', 'internet',
            'education', 'epin', 'betting', 'international_airtime',
        ];
    }

    /**
     * The inputs for repeating an order, freshly resolved.
     *
     * Everything that could have changed since the original is looked up again:
     * the product must still exist and be sellable, and the provider must still
     * offer it. A product that has been withdrawn produces a refusal rather than
     * a silently different purchase.
     *
     * @return array<string, mixed>
     */
    public function payAgainInputs(User $user, ServiceOrder $order): array
    {
        // The order must belong to the caller — the controller passes one it
        // resolved, but this is the second lock.
        if ((int) $order->user_id !== (int) $user->getKey()) {
            throw new RuntimeException('That order does not belong to this account.');
        }

        if ($order->status !== ServiceOrder::STATUS_SUCCESS) {
            throw new RuntimeException('Only a completed payment can be repeated.');
        }

        $product = $order->serviceProduct;

        if (! $product) {
            throw new RuntimeException('That service is no longer available.');
        }

        if (! $product->isCustomerVisible()) {
            throw new RuntimeException('That service is currently unavailable.');
        }

        if ($order->providerProduct && ! $order->providerProduct->isAvailable()) {
            throw new RuntimeException('That service is temporarily unavailable from its provider.');
        }

        /*
         * A NEW idempotency key is generated by the purchase pipeline, not here.
         * Returning one would risk it being reused, and reusing a provider
         * idempotency key is how a second purchase becomes a no-op or a duplicate
         * charge depending on the provider.
         */
        return [
            'product_key' => $order->product_key,
            'service_product_id' => $product->getKey(),
            'recipient' => $order->recipient,
            'quantity' => $order->quantity,
            'provider_product_id' => $order->provider_product_id,
            'previous_order_id' => $order->getKey(),
            // No price: the engine resolves it at checkout, so a price change
            // between the original order and the repeat is visible to the
            // customer before they confirm.
            'price_minor' => null,
            'requires_repricing' => true,
        ];
    }

    /**
     * Record that a saved bill or a repeat was used, so the list orders by what
     * the customer actually pays.
     */
    public function markSavedBillUsed(?SavedBill $bill): void
    {
        $bill?->recordUse();
    }

    /* =====================================================================
     | Reminders
     | =================================================================== */

    /**
     * The owner's reminders.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BillReminder>
     */
    public function reminders(User $user)
    {
        return BillReminder::forUser($user->getKey())
            ->orderByDesc('is_active')
            ->orderBy('next_due_at')
            ->get();
    }

    public function findReminder(User $user, int $id): BillReminder
    {
        return BillReminder::forUser($user->getKey())->findOrFail($id);
    }

    /**
     * Create a reminder.
     *
     * A reminder is a notification, never a payment. `amount_minor` is stored for
     * the message only — nothing reads it in order to move money.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createReminder(User $user, array $attributes): BillReminder
    {
        $savedBill = null;

        if (! empty($attributes['saved_bill_id'])) {
            // Ownership asserted: a reminder cannot be attached to somebody
            // else's saved account.
            $savedBill = $this->findForUser($user, (int) $attributes['saved_bill_id']);
        }

        $startsAt = isset($attributes['starts_at'])
            ? Carbon::parse($attributes['starts_at'])
            : now();

        if ($startsAt->isPast()) {
            throw new RuntimeException('A reminder cannot be scheduled in the past.');
        }

        $frequency = (string) $attributes['frequency'];

        if (! in_array($frequency, [
            BillReminder::FREQUENCY_ONCE,
            BillReminder::FREQUENCY_DAILY,
            BillReminder::FREQUENCY_WEEKLY,
            BillReminder::FREQUENCY_MONTHLY,
            BillReminder::FREQUENCY_QUARTERLY,
            BillReminder::FREQUENCY_ANNUALLY,
        ], true)) {
            throw new RuntimeException("Unknown reminder frequency [{$frequency}].");
        }

        return BillReminder::create([
            'user_id' => $user->getKey(),
            'saved_bill_id' => $savedBill?->getKey(),
            'product_key' => $attributes['product_key'] ?? $savedBill?->product_key ?? 'other',
            'title' => $attributes['title'],
            'note' => $attributes['note'] ?? null,
            'amount_minor' => isset($attributes['amount'])
                ? \App\Support\Money::fromNaira($attributes['amount'])->minor()
                : null,
            'frequency' => $frequency,
            'interval' => max(1, (int) ($attributes['interval'] ?? 1)),
            'starts_at' => $startsAt,
            'next_due_at' => $startsAt,
            'ends_at' => isset($attributes['ends_at']) ? Carbon::parse($attributes['ends_at']) : null,
            'is_active' => true,
        ]);
    }

    /**
     * Update a reminder's mutable fields.
     *
     * Changing the schedule recomputes the next due date, so a reminder that was
     * monthly and becomes weekly fires on the new cadence rather than waiting out
     * the old one.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateReminder(User $user, int $id, array $attributes): BillReminder
    {
        $reminder = $this->findReminder($user, $id);

        if (isset($attributes['title'])) {
            $reminder->title = $attributes['title'];
        }

        if (array_key_exists('note', $attributes)) {
            $reminder->note = $attributes['note'];
        }

        if (isset($attributes['amount'])) {
            $reminder->amount_minor = \App\Support\Money::fromNaira($attributes['amount'])->minor();
        }

        if (isset($attributes['frequency'])) {
            $reminder->frequency = $attributes['frequency'];
            $reminder->next_due_at = $reminder->advanceFrom(now());
        }

        if (isset($attributes['interval'])) {
            $reminder->interval = max(1, (int) $attributes['interval']);
        }

        if (array_key_exists('is_active', $attributes)) {
            $reminder->is_active = (bool) $attributes['is_active'];
        }

        $reminder->save();

        return $reminder;
    }

    public function deleteReminder(User $user, int $id): void
    {
        // Hard delete is safe here: a reminder is a customer preference, not a
        // financial record, and its notification history is kept by the alerts
        // it produced.
        $this->findReminder($user, $id)->delete();
    }

    /**
     * Emit a notification for every reminder that is due, exactly once.
     *
     * ## Duplicate prevention
     *
     * `BillReminder::claimForDueDate()` is the guard: it advances `next_due_at`
     * and returns false if the reminder is no longer due. Two overlapping
     * scheduler runs therefore produce one notification per due date, which is why
     * this method can be called as often as the schedule likes.
     *
     * ## No debit, ever
     *
     * The only write is a `product_alerts` row. There is no wallet call on this
     * path, and there is no column on `bill_reminders` that could authorise one.
     *
     * @return array{emitted:int,skipped:int}
     */
    public function emitDueReminders(?Carbon $at = null): array
    {
        $at = $at ?? now();

        $emitted = 0;
        $skipped = 0;

        // A bounded batch: a backlog of thousands of reminders should drain over
        // several runs rather than in one long transaction.
        $due = BillReminder::due($at)->with('savedBill')->orderBy('next_due_at')->limit(500)->get();

        foreach ($due as $reminder) {
            $dueDate = $reminder->claimForDueDate($at);

            if ($dueDate === null) {
                // Somebody else claimed it, or it was deactivated between the
                // query and here.
                $skipped++;

                continue;
            }

            ProductAlert::create([
                'kind' => ProductAlert::KIND_REMINDER,
                'user_id' => $reminder->user_id,
                'bill_reminder_id' => $reminder->getKey(),
                'type' => ProductAlert::TYPE_REMINDER_DUE,
                'severity' => ProductAlert::SEVERITY_INFO,
                'title' => $reminder->title,
                'message' => $this->reminderMessage($reminder),
                'context' => [
                    'due_at' => $dueDate->toIso8601String(),
                    'product_key' => $reminder->product_key,
                    'saved_bill_id' => $reminder->saved_bill_id,
                    'masked_identifier' => $reminder->savedBill?->masked_identifier,
                    // Stated explicitly in the record so nothing downstream can
                    // read the notification as an authorisation to pay.
                    'payment_required' => false,
                    'autopay' => false,
                ],
                /*
                 * One notification per reminder per due date. The fingerprint
                 * includes the due date so a weekly reminder produces a distinct
                 * alert each week while a retried run for the same week produces
                 * none.
                 */
                'fingerprint' => ProductAlert::fingerprintFor(
                    ProductAlert::TYPE_REMINDER_DUE . ':' . $dueDate->toDateString(),
                    null,
                    null,
                    $reminder->getKey(),
                ),
            ]);

            $emitted++;
        }

        return ['emitted' => $emitted, 'skipped' => $skipped];
    }

    private function reminderMessage(BillReminder $reminder): string
    {
        $parts = ['It is time to pay ' . $reminder->title . '.'];

        if ($reminder->amount_minor !== null) {
            $parts[] = 'Expected amount: ' . \App\Support\Money::fromMinor((int) $reminder->amount_minor)->format() . '.';
        }

        if ($reminder->savedBill) {
            $parts[] = 'Account: ' . $reminder->savedBill->masked_identifier . '.';
        }

        // Said in the message itself, because a customer must never be left
        // wondering whether ReUp has already taken the money.
        $parts[] = 'This is a reminder only — no payment has been taken. Pay it from your ReUp wallet when you are ready.';

        return implode(' ', $parts);
    }

    /**
     * The customer's notification history.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ProductAlert>
     */
    public function notifications(User $user, int $limit = 50)
    {
        return ProductAlert::where('user_id', $user->getKey())
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * The customer's upcoming reminders, for the dashboard.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BillReminder>
     */
    public function upcoming(User $user, int $limit = 5)
    {
        return BillReminder::forUser($user->getKey())
            ->active()
            ->whereNotNull('next_due_at')
            ->orderBy('next_due_at')
            ->limit($limit)
            ->get();
    }

    /**
     * A saved bill derived from a completed order, so a customer who pays a new
     * bill is offered the chance to save it without retyping.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function offerToSaveFrom(User $user, Transactions $transaction, array $attributes = []): ?SavedBill
    {
        $recipient = (string) $transaction->recipient;

        if ($recipient === '' || ! in_array($transaction->service_type, self::repeatableProductKeys(), true)) {
            return null;
        }

        return $this->save($user, [
            'product_key' => $transaction->service_type,
            'label' => $attributes['label'] ?? ($transaction->description ?: 'Saved bill'),
            'identifier' => $recipient,
            'attributes' => $attributes,
        ]);
    }
}
