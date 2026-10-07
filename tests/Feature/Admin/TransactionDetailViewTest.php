<?php

namespace Tests\Feature\Admin;

use App\Models\Transactions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The admin transaction and bank-transfer detail partials.
 *
 * These are loaded over AJAX into a review modal, which is why a broken one is
 * easy to miss: the list page still renders, and the failure only appears after
 * an admin clicks a row.
 *
 * Both partials had a long-standing bug of the same shape — they treated a
 * column as a JSON string when the model already casts it to an array:
 *
 *   - `json_decode($transfer->meta)`  → TypeError, array given
 *   - `{{ $transaction->api_response }}` → htmlspecialchars() on an array
 *
 * The second was the nastier of the two: it only failed for rows that actually
 * had a gateway response, so it looked intermittent and row-dependent rather
 * than like a broken template. Both are now rendered here for the cases that
 * trigger them.
 */
class TransactionDetailViewTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->superAdmin()->create();
    }

    /** A funding row with a populated API response, as Paystack leaves it. */
    private function transactionWithPayloads(array $attributes = []): Transactions
    {
        return Transactions::create(array_merge([
            'user_id' => User::factory()->create()->id,
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding',
            'amount' => 1000,
            'service_fee' => 0,
            'total_amount' => 1000,
            'payment_method' => 'paystack',
            'status' => 'success',
            'payment_status' => 'success',
            // Both cast to 'array' on the model.
            'meta' => ['channel' => 'card', 'settled_at' => now()->toDateTimeString()],
            'api_response' => [
                'status' => 'success',
                'channel' => 'card',
                'amount' => 100000,
                'authorization' => ['brand' => 'visa', 'last4' => '4081'],
            ],
        ], $attributes));
    }

    public function test_the_transaction_detail_renders_with_meta_and_api_response(): void
    {
        $transaction = $this->transactionWithPayloads();

        $this->actingAs($this->admin())
            ->get(route('admin.transactions.show', $transaction), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Metadata')
            ->assertSee('API Response')
            // escape:false because Blade HTML-escapes the encoded JSON, so the
            // raw haystack contains &quot;channel&quot; rather than "channel".
            // These assertions are what prove the arrays were *encoded* rather
            // than echoed — the bug being guarded against.
            ->assertSee('channel', false)
            ->assertSee('last4', false)
            ->assertSee('&quot;channel&quot;', false);
    }

    public function test_the_transaction_detail_renders_without_any_payloads(): void
    {
        // The null case: no meta, no api_response. Both blocks are conditional,
        // so this guards the surrounding template rather than the casts.
        $transaction = $this->transactionWithPayloads([
            'meta' => null,
            'api_response' => null,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.transactions.show', $transaction), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();
    }

    public function test_the_bank_transfer_review_partial_renders(): void
    {
        $transaction = $this->transactionWithPayloads([
            'payment_method' => 'bank_transfer',
            'status' => 'pending',
            'payment_status' => 'pending',
            'meta' => [
                'narration' => 'REUP-ABC123',
                'proof_path' => null,
                'total_payable' => 1000,
            ],
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.bank-transfers.show', $transaction), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Transfer details')
            ->assertSee('REUP-ABC123');
    }

    public function test_the_bank_transfer_partial_renders_with_a_null_meta_column(): void
    {
        $transaction = $this->transactionWithPayloads([
            'payment_method' => 'bank_transfer',
            'status' => 'pending',
            'meta' => null,
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.bank-transfers.show', $transaction), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();
    }
}
