<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Transactions;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Broken object-level authorisation (IDOR) across the customer-facing surface.
 *
 * Every route below receives an identifier the caller controls — a transaction
 * reference, a `?ref=`, a chat session id, an admin URL — and every test proves
 * that an authenticated stranger cannot turn someone else's identifier into
 * someone else's data. Status codes alone are not enough: a 200 with an empty
 * body and a 200 with a stranger's amount look identical to `assertOk()`, so
 * each test also asserts on the response body and on the database row that must
 * not have moved.
 *
 * References and UUIDs are treated as guessable. Ownership has to come from
 * `Auth::id()`, not from the caller not knowing the value.
 */
class AuthorizationIdorTest extends TestCase
{
    use RefreshDatabase;

    /** Values unique to the victim that must never reach another session. */
    private const VICTIM_AMOUNT = '5,432.10';

    private const VICTIM_RECIPIENT = '08099887766';

    private const VICTIM_FUNDING_AMOUNT = '9,182.55';

    private const VICTIM_NARRATION = 'REUP-VICTIM01';

    private const VICTIM_CHAT_SECRET = 'VICTIM-ONLY-MESSAGE-9f3c';

    private function customer(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    /** A settled airtime purchase belonging to $owner. */
    private function airtimeTransaction(User $owner, array $attributes = []): Transactions
    {
        return Transactions::create(array_merge([
            'user_id' => $owner->id,
            'reference' => 'IDOR-' . strtoupper(Str::random(10)),
            'type' => 'debit',
            'service_type' => 'airtime',
            'description' => 'Airtime purchase for the victim',
            'amount' => 5432.10,
            'service_fee' => 0,
            'total_amount' => 5432.10,
            'recipient' => self::VICTIM_RECIPIENT,
            'provider' => 'MTN',
            'status' => 'success',
            'payment_status' => 'success',
            'completed_at' => now(),
        ], $attributes));
    }

    /** A pending bank-transfer funding row belonging to $owner. */
    private function bankTransferFunding(User $owner, array $attributes = []): Transactions
    {
        return Transactions::create(array_merge([
            'user_id' => $owner->id,
            'reference' => 'IDOR-FND-' . strtoupper(Str::random(8)),
            'type' => 'credit',
            'service_type' => 'funding',
            'description' => 'Wallet funding — Bank transfer',
            'amount' => 9182.55,
            'service_fee' => 0,
            'total_amount' => 9182.55,
            'recipient' => $owner->email,
            'provider' => 'bank_transfer',
            'payment_method' => 'bank_transfer',
            'payment_status' => 'pending',
            'status' => 'pending',
            'meta' => ['narration' => self::VICTIM_NARRATION],
        ], $attributes));
    }

    /** An active chat session for $owner holding one unread customer message. */
    private function chatSession(User $owner, string $message = self::VICTIM_CHAT_SECRET): ChatSession
    {
        $session = ChatSession::create([
            'user_id' => $owner->id,
            'status' => 'active',
            'subject' => 'Private support chat',
            'last_message_at' => now(),
        ]);

        ChatMessage::create([
            'chat_session_id' => $session->id,
            'sender_id' => $owner->id,
            'sender_type' => ChatMessage::SENDER_USER,
            'message' => $message,
            'is_read' => false,
        ]);

        return $session;
    }

    /* =====================================================================
     | Transaction outcome pages (AirtimeDataController::success / failed)
     |=================================================================== */

    public function test_a_customer_cannot_read_another_customers_successful_transaction(): void
    {
        $attacker = $this->customer();
        $victim = $this->customer();
        $transaction = $this->airtimeTransaction($victim);

        $response = $this->actingAs($attacker)
            ->get(route('transactions.success', $transaction->reference));

        $response->assertNotFound();
        $response->assertDontSee(self::VICTIM_AMOUNT);
        $response->assertDontSee(self::VICTIM_RECIPIENT);

        // The owner is unaffected: same URL, full receipt.
        $this->actingAs($victim)
            ->get(route('transactions.success', $transaction->reference))
            ->assertOk()
            ->assertSee(self::VICTIM_AMOUNT)
            ->assertSee(self::VICTIM_RECIPIENT);
    }

    public function test_a_customer_cannot_read_another_customers_failed_transaction(): void
    {
        $attacker = $this->customer();
        $victim = $this->customer();
        $transaction = $this->airtimeTransaction($victim, [
            'status' => 'failed',
            'payment_status' => 'failed',
            'completed_at' => null,
            'status_message' => 'Provider declined',
        ]);

        $response = $this->actingAs($attacker)
            ->get(route('transactions.failed', $transaction->reference));

        $response->assertNotFound();
        $response->assertDontSee(self::VICTIM_AMOUNT);
        $response->assertDontSee(self::VICTIM_RECIPIENT);

        $this->actingAs($victim)
            ->get(route('transactions.failed', $transaction->reference))
            ->assertOk()
            ->assertSee(self::VICTIM_AMOUNT)
            ->assertSee(self::VICTIM_RECIPIENT);
    }

    /* =====================================================================
     | Bank transfer details + proof upload (WalletController)
     |=================================================================== */

    public function test_a_customer_cannot_read_another_customers_bank_transfer_details(): void
    {
        $attacker = $this->customer();
        $victim = $this->customer();
        $funding = $this->bankTransferFunding($victim);

        $response = $this->actingAs($attacker)
            ->get(route('wallet.bank-transfer.details', ['ref' => $funding->reference]));

        $response->assertNotFound();
        $response->assertDontSee(self::VICTIM_NARRATION);
        $response->assertDontSee(self::VICTIM_FUNDING_AMOUNT);

        $this->actingAs($victim)
            ->get(route('wallet.bank-transfer.details', ['ref' => $funding->reference]))
            ->assertOk()
            ->assertSee(self::VICTIM_NARRATION)
            ->assertSee(self::VICTIM_FUNDING_AMOUNT);
    }

    public function test_a_customer_cannot_attach_a_proof_to_another_customers_funding(): void
    {
        Storage::fake('local');

        $attacker = $this->customer();
        $victim = $this->customer();
        $victimFunding = $this->bankTransferFunding($victim);
        $victimMetaBefore = $victimFunding->meta;

        $this->actingAs($attacker)
            ->post(route('wallet.bank-transfer.submit-proof'), [
                'transaction_reference' => $victimFunding->reference,
                'proof' => UploadedFile::fake()->create('proof.png', 20, 'image/png'),
                'remarks' => 'attacker supplied proof',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('transaction_reference');

        // Nothing on the victim's row moved: no proof, no status transition.
        $victimFunding->refresh();
        $this->assertSame('pending', $victimFunding->status);
        $this->assertSame('pending', $victimFunding->payment_status);
        $this->assertSame($victimMetaBefore, $victimFunding->meta);
        $this->assertArrayNotHasKey('proof_path', $victimFunding->meta);
        $this->assertEmpty(Storage::disk('local')->allFiles());

        // The same request against the attacker's own funding row still works.
        $ownFunding = $this->bankTransferFunding($attacker);

        $this->actingAs($attacker)
            ->post(route('wallet.bank-transfer.submit-proof'), [
                'transaction_reference' => $ownFunding->reference,
                'proof' => UploadedFile::fake()->create('proof.png', 20, 'image/png'),
                'remarks' => 'mine',
            ])
            ->assertRedirect(route('wallet.history'));

        $ownFunding->refresh();
        $this->assertSame('verifying', $ownFunding->status);
        $this->assertSame('verifying', $ownFunding->payment_status);
        $this->assertNotEmpty($ownFunding->meta['proof_path']);
    }

    /* =====================================================================
     | Payment status (WalletController::paymentStatus / checkPaymentStatus)
     |=================================================================== */

    public function test_a_customer_cannot_read_another_customers_payment_status_json(): void
    {
        $attacker = $this->customer();
        $victim = $this->customer();
        $funding = $this->bankTransferFunding($victim);

        $this->actingAs($attacker)
            ->postJson(route('wallet.payment.check-status'), ['reference' => $funding->reference])
            ->assertNotFound()
            ->assertJson(['status' => 'not_found'])
            ->assertDontSee(self::VICTIM_FUNDING_AMOUNT);

        $this->assertSame(0.0, (float) $victim->wallet->fresh()->balance);

        // The owner still gets the real status and amount.
        $this->actingAs($victim)
            ->postJson(route('wallet.payment.check-status'), ['reference' => $funding->reference])
            ->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertSee('9182.55');
    }

    public function test_a_customer_cannot_read_another_customers_payment_status_page(): void
    {
        $attacker = $this->customer();
        $victim = $this->customer();
        $funding = $this->bankTransferFunding($victim);

        // The page renders for any reference, but a stranger's reference yields
        // no transaction at all — only the value they themselves supplied.
        $this->actingAs($attacker)
            ->get(route('wallet.payment.status', ['reference' => $funding->reference]))
            ->assertOk()
            ->assertDontSee(self::VICTIM_FUNDING_AMOUNT);

        $this->actingAs($victim)
            ->get(route('wallet.payment.status', ['reference' => $funding->reference]))
            ->assertOk()
            ->assertSee(self::VICTIM_FUNDING_AMOUNT);
    }

    public function test_a_customer_cannot_replay_another_customers_paystack_callback(): void
    {
        $attacker = $this->customer();
        $victim = $this->customer();
        $funding = $this->bankTransferFunding($victim, [
            'payment_method' => 'paystack',
            'provider' => 'paystack',
        ]);

        $this->actingAs($attacker)
            ->get(route('wallet.paystack.callback', ['reference' => $funding->reference]))
            ->assertNotFound();

        // The callback must not have settled, credited or otherwise touched it.
        $funding->refresh();
        $this->assertSame('pending', $funding->status);
        $this->assertSame('pending', $funding->payment_status);
        $this->assertSame(0.0, (float) $victim->wallet->fresh()->balance);
    }

    /* =====================================================================
     | Live chat (ChatController)
     |=================================================================== */

    public function test_a_customer_cannot_read_another_customers_chat_messages(): void
    {
        $attacker = $this->customer();
        $victim = $this->customer();
        $session = $this->chatSession($victim);
        $message = $session->messages()->firstOrFail();

        $response = $this->actingAs($attacker)
            ->get(route('chat.messages', ['session_id' => $session->id]));

        $response->assertNotFound();
        $response->assertDontSee(self::VICTIM_CHAT_SECRET);

        // The read-marking side effect must not have run either.
        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
            'is_read' => false,
        ]);
    }

    public function test_a_customer_cannot_write_to_or_close_another_customers_chat(): void
    {
        $attacker = $this->customer();
        $victim = $this->customer();
        $session = $this->chatSession($victim);

        $messagesBefore = ChatMessage::where('chat_session_id', $session->id)->count();

        $this->actingAs($attacker)
            ->postJson(route('chat.send'), [
                'session_id' => $session->id,
                'message' => 'attacker was here',
            ])
            ->assertNotFound();

        $this->actingAs($attacker)
            ->postJson(route('chat.typing'), [
                'session_id' => $session->id,
                'is_typing' => true,
            ])
            ->assertNotFound();

        $this->actingAs($attacker)
            ->postJson(route('chat.close'), ['session_id' => $session->id])
            ->assertNotFound();

        $this->assertSame(
            $messagesBefore,
            ChatMessage::where('chat_session_id', $session->id)->count()
        );
        $this->assertDatabaseHas('chat_sessions', ['id' => $session->id, 'status' => 'active']);
        $this->assertDatabaseMissing('chat_messages', [
            'chat_session_id' => $session->id,
            'sender_id' => $attacker->id,
        ]);
    }

    public function test_the_owner_can_still_use_their_own_chat(): void
    {
        $victim = $this->customer();
        $session = $this->chatSession($victim);

        $this->actingAs($victim)
            ->get(route('chat.messages', ['session_id' => $session->id]))
            ->assertOk()
            ->assertSee(self::VICTIM_CHAT_SECRET);

        $this->actingAs($victim)
            ->postJson(route('chat.send'), ['session_id' => $session->id, 'message' => 'hello'])
            ->assertOk();

        $this->actingAs($victim)
            ->postJson(route('chat.close'), ['session_id' => $session->id])
            ->assertOk();

        $this->assertDatabaseHas('chat_sessions', ['id' => $session->id, 'status' => 'closed']);
    }

    /* =====================================================================
     | Pricelist order polling (PricelistController::queryTransaction)
     |=================================================================== */

    public function test_a_customer_cannot_query_another_customers_pricelist_order(): void
    {
        $attacker = $this->customer();
        $victim = $this->customer();
        $order = $this->airtimeTransaction($victim, [
            'service_type' => 'data',
            'description' => 'Data — 5GB victim bundle',
        ]);

        $this->actingAs($attacker)
            ->getJson(route('pricelist.query', ['orderId' => $order->reference]))
            ->assertNotFound()
            ->assertJson(['success' => false])
            ->assertDontSee('5GB victim bundle');

        $this->actingAs($victim)
            ->getJson(route('pricelist.query', ['orderId' => $order->reference]))
            ->assertOk()
            ->assertJsonPath('data.reference', $order->reference)
            ->assertJsonPath('data.description', 'Data — 5GB victim bundle');
    }

    /* =====================================================================
     | Admin console
     |=================================================================== */

    public function test_a_customer_cannot_reach_the_admin_console(): void
    {
        $attacker = $this->customer();
        $victim = $this->customer();
        $pending = $this->airtimeTransaction($victim, [
            'status' => 'pending',
            'payment_status' => 'pending',
            'completed_at' => null,
        ]);

        $this->actingAs($attacker)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($attacker)->get(route('admin.transactions.index'))->assertForbidden();
        $this->actingAs($attacker)->get(route('admin.bank-transfers.index'))->assertForbidden();

        // Wallet adjustment.
        $this->actingAs($attacker)
            ->post(route('admin.users.wallet.update', $victim), [
                'action' => 'add',
                'amount' => 500000,
                'reason' => 'idor probe',
            ])
            ->assertForbidden();

        $this->assertSame(0.0, (float) $victim->wallet->fresh()->balance);
        $this->assertDatabaseMissing('transactions', [
            'user_id' => $victim->id,
            'service_type' => 'manual_adjustment',
        ]);

        // Transaction status change.
        $this->actingAs($attacker)
            ->put(route('admin.transactions.force-success', $pending))
            ->assertForbidden();

        $pending->refresh();
        $this->assertSame('pending', $pending->status);
        $this->assertSame('pending', $pending->payment_status);
    }

    public function test_a_super_admin_can_still_reach_the_admin_console(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->get(route('admin.users.index'))
            ->assertOk();
    }

    public function test_an_admin_missing_the_permission_is_refused(): void
    {
        $moderator = User::factory()->admin(['chat'])->create();

        $this->actingAs($moderator)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($moderator)->get(route('admin.transactions.index'))->assertForbidden();
        $this->actingAs($moderator)->get(route('admin.bank-transfers.index'))->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $victim = $this->customer();
        $transaction = $this->airtimeTransaction($victim);

        foreach ([
            route('transactions.success', $transaction->reference),
            route('transactions.failed', $transaction->reference),
            route('wallet.payment.status', ['reference' => $transaction->reference]),
            route('wallet.bank-transfer.details', ['ref' => $transaction->reference]),
            route('chat.messages'),
            route('pricelist.query', ['orderId' => $transaction->reference]),
            route('admin.users.index'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        foreach ([
            route('wallet.payment.check-status'),
            route('chat.close'),
        ] as $url) {
            $this->post($url)->assertRedirect(route('login'));
        }

        $this->post(route('admin.users.wallet.update', $victim), [
            'action' => 'add',
            'amount' => 10,
            'reason' => 'guest probe',
        ])->assertRedirect(route('login'));

        $this->put(route('admin.transactions.force-success', $transaction))
            ->assertRedirect(route('login'));
    }

    /* =====================================================================
     | Profile (no identifier in the request: writes follow the session)
     |=================================================================== */

    public function test_profile_writes_are_scoped_to_the_authenticated_user(): void
    {
        $attacker = $this->customer();
        $victim = $this->customer();

        $this->actingAs($attacker)
            ->put(route('profile.update'), [
                'name' => 'Attacker Renamed',
                'email' => $attacker->email,
                'phone' => '08033333333',
                'whatsapp' => '08033333333',
            ])
            ->assertRedirect(route('profile.index'));

        $this->assertDatabaseHas('users', [
            'id' => $attacker->id,
            'name' => 'Attacker Renamed',
            'phone' => '08033333333',
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $victim->id,
            'name' => $victim->name,
            'phone' => $victim->phone,
            'email' => $victim->email,
        ]);
    }

    public function test_a_customer_cannot_claim_another_customers_phone_number(): void
    {
        $attacker = $this->customer();
        $victim = $this->customer(['phone' => '08022222222']);

        $this->actingAs($attacker)
            ->postJson(route('profile.request-verification'), ['phone' => $victim->phone])
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        // The victim's number is untouched, and the attacker did not take it.
        $this->assertDatabaseHas('users', ['id' => $victim->id, 'phone' => '08022222222']);
        $this->assertNotSame('08022222222', $attacker->fresh()->phone);
    }
}
