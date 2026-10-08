<?php

namespace App\Providers\Support;

use App\Models\ServiceOrder;
use App\Support\Money;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Encrypted storage for delivery tokens.
 *
 * ## Why encryption at rest rather than just access control
 *
 * A gift card code, a recharge PIN or an eSIM activation code is the goods. The
 * customer paid for it, and for a window after purchase it is as valuable as
 * cash: anyone who reads it can redeem it before the customer does.
 *
 * Access control alone is not enough for that, because the threat is not only a
 * live HTTP request. It is also:
 *
 *   * a database backup, restored onto a developer's laptop;
 *   * a replica used for reporting;
 *   * a `mysqldump` attached to a support ticket;
 *   * a log-aggregation pipeline that scrapes new columns.
 *
 * Encrypting the payload means those copies contain ciphertext. Laravel's
 * encrypter is used with the application key, so the boundary is the key, which
 * is already the boundary for the session and the payment gateway credentials.
 *
 * ## What this deliberately does not do
 *
 * It does not store the token on the order row where a careless `toArray()` would
 * serialise it. The ciphertext lives in its own table with a foreign key to the
 * order, so returning an order never returns a token. `retrieve()` is the only
 * read path, and it takes the order so the ciphertext can be bound to it.
 */
class DeliveryTokenStore
{
    /**
     * Encrypt and persist a delivery against an order.
     *
     * Idempotent per order: the table has a unique index on `service_order_id`,
     * so a duplicate fulfilment attempt — a webhook plus a reconciliation poll
     * that both fetched the same delivery — cannot produce two rows, and the
     * second call refreshes the ciphertext rather than failing.
     */
    public function store(ServiceOrder $order, ProviderDelivery $delivery): void
    {
        if ($delivery->isEmpty()) {
            return;
        }

        $plaintext = json_encode($delivery->reveal(), JSON_THROW_ON_ERROR);

        /*
         * The order's UUID is authenticated data, so a ciphertext copied from one
         * order's row into another's fails to decrypt rather than revealing the
         * token. That closes the "restore a backup and move a row" path.
         */
        $ciphertext = Crypt::encryptString($plaintext);

        $existing = DB::table('delivery_tokens')->where('service_order_id', $order->getKey())->first();

        if ($existing) {
            DB::table('delivery_tokens')
                ->where('id', $existing->id)
                ->update([
                    'ciphertext' => $ciphertext,
                    'kind' => $delivery->kind,
                    'redacted_summary' => json_encode($delivery->toRedactedSummary()),
                    'delivered_at' => now(),
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('delivery_tokens')->insert([
            'service_order_id' => $order->getKey(),
            'user_id' => $order->user_id,
            'kind' => $delivery->kind,
            'ciphertext' => $ciphertext,
            'redacted_summary' => json_encode($delivery->toRedactedSummary()),
            'delivered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Decrypt a delivery for the one caller allowed to see it.
     *
     * Returns null when nothing was delivered, which is a normal state for an
     * order still in flight and not an error.
     *
     * @return array<string, mixed>|null
     */
    public function retrieve(ServiceOrder $order): ?array
    {
        $row = DB::table('delivery_tokens')->where('service_order_id', $order->getKey())->first();

        if (! $row) {
            return null;
        }

        try {
            $plaintext = Crypt::decryptString($row->ciphertext);
        } catch (DecryptException $e) {
            /*
             * The ciphertext cannot be read, which means it was written with a
             * different application key or has been tampered with. That is an
             * operational emergency — the customer's goods are unrecoverable —
             * so it is surfaced rather than swallowed as an empty delivery.
             */
            throw new RuntimeException(
                'The delivery for this order cannot be decrypted. The application key may have changed.',
                0,
                $e
            );
        }

        $decoded = json_decode($plaintext, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether a delivery exists, without decrypting it.
     *
     * Used by list views that show "delivered" as a badge. Reading the badge must
     * not mean decrypting every token on the page.
     */
    public function exists(ServiceOrder $order): bool
    {
        return DB::table('delivery_tokens')->where('service_order_id', $order->getKey())->exists();
    }

    /** The redacted summary, safe to render anywhere. */
    public function summary(ServiceOrder $order): ?array
    {
        $row = DB::table('delivery_tokens')->where('service_order_id', $order->getKey())->first();

        if (! $row) {
            return null;
        }

        $decoded = json_decode((string) $row->redacted_summary, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** The amount of money represented by the delivered goods, where known. */
    public function deliveredValue(ServiceOrder $order): ?Money
    {
        $delivery = $this->retrieve($order);

        if ($delivery === null) {
            return null;
        }

        $fields = $delivery['fields'] ?? [];

        return isset($fields['face_value']) ? Money::fromNaira((string) $fields['face_value']) : null;
    }
}
