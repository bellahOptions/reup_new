<?php

namespace Tests\Feature;

use App\Models\ProfitRecord;
use App\Models\Transactions;
use App\Models\User;
use App\Orders\ProfitRecorder;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Profit, recorded from the purchases the platform actually sells.
 *
 * ## What was broken
 *
 * `ProfitRecorder::record()` is driven by a `ServiceOrder`, and no live route
 * creates one — airtime and data go through `BillPaymentService`, which writes a
 * `pricing_snapshots` row and stops. So the Revenue & Profit dashboard read a table
 * nothing populated and reported zero while the platform was selling. That is the
 * defect these tests exist to keep closed.
 *
 * ## The claims, which fail independently
 *
 *   1. a settled purchase produces exactly one profit row, with the figures the
 *      pricing snapshot recorded;
 *   2. it is never recorded twice — the `transaction_id` unique index is the guard,
 *      not the existence check;
 *   3. an unearned purchase records nothing: a `processing` or `failed` sale must
 *      not appear as profit;
 *   4. a refund takes the profit off the books without erasing the sale;
 *   5. the per-service breakdown adds up to the overall total, and profit measured
 *      against an assumed provider cost is reported separately from profit measured
 *      against a confirmed one.
 *
 * The purchases here are built directly rather than driven through HTTP, because
 * what is under test is the accounting, not the checkout. `AirtimePricingTest`
 * already covers the checkout and asserts the snapshot; this starts from a settled
 * transaction and asserts what the revenue report makes of it.
 */
class ProfitRecordingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A settled purchase, priced through the real snapshot writer.
     *
     * `attachPricingSnapshot()` is the same method the purchase pipeline calls, so
     * the record under test is built from a genuine snapshot rather than a
     * hand-written row that could differ from the real shape.
     */
    private function settledPurchase(array $overrides = [], array $snapshotOverrides = []): Transactions
    {
        $user = User::factory()->create();

        $transaction = Transactions::create(array_merge([
            'user_id' => $user->id,
            'reference' => 'TXN-' . strtoupper(bin2hex(random_bytes(4))),
            'type' => 'debit',
            'service_type' => 'airtime',
            'description' => 'Airtime — MTN',
            'amount' => '1000.00',
            'service_fee' => '0.00',
            'total_amount' => '1000.00',
            'recipient' => '08031234567',
            'provider' => 'ClubKonnect',
            'network_code' => 'mtn',
            'network_name' => 'MTN',
            'payment_method' => 'wallet',
            'payment_status' => 'success',
            'status' => 'success',
            'paid_at' => now(),
            'completed_at' => now(),
        ], $overrides));

        $quote = $this->quote(
            customerPriceMinor: (int) ($snapshotOverrides['customer_price_minor'] ?? 100000),
            providerCostMinor: (int) ($snapshotOverrides['provider_cost_minor'] ?? 97000),
            costIsEstimated: (bool) ($snapshotOverrides['cost_is_estimated'] ?? false)
        );

        $transaction->attachPricingSnapshot($quote, [
            'product' => $transaction->service_type,
            'recipient' => $transaction->recipient,
            'customer_amount_minor' => (int) round($transaction->amount * 100),
        ]);

        $transaction = $transaction->fresh();

        return $transaction;
    }

    /**
     * A quote built through the engine, so the snapshot is the real thing.
     *
     * A pricing rule is required because the engine refuses to price anything no
     * rule covers — "no active pricing rule covers this product" — and a refused
     * quote has no snapshot. That refusal is the engine working correctly: it is
     * why an unpriced product cannot be sold at all. So the rule is created here,
     * as the console would, and the quote is then a genuine priced sale.
     */
    private function quote(int $customerPriceMinor, int $providerCostMinor, bool $costIsEstimated): \App\Pricing\PriceQuote
    {
        $provider = \App\Models\Provider::firstOrCreate(
            ['slug' => 'clubkonnect'],
            [
                'name' => 'ClubKonnect',
                'driver' => 'clubkonnect',
                'is_active' => true,
                'is_primary' => true,
                'priority' => 10,
            ]
        );

        \App\Models\PricingRule::firstOrCreate(
            ['name' => 'Airtime — mtn'],
            [
                'scope' => \App\Models\PricingRule::SCOPE_NETWORK,
                'network' => 'mtn',
                'markup_type' => \App\Models\PricingRule::MARKUP_FACE_VALUE,
                'markup_percentage_bps' => 0,
                'markup_fixed_minor' => 0,
                'minimum_profit_minor' => 0,
                'minimum_margin_bps' => 0,
                'customer_fee_enabled' => false,
                'allow_negative_margin' => false,
                'rounding_step_minor' => 0,
                'on_unprofitable' => \App\Models\PricingRule::ON_UNPROFITABLE_UNAVAILABLE,
                'priority' => 100,
                'is_active' => true,
            ]
        );

        $engine = app(\App\Pricing\PricingEngine::class);

        return $engine->quote(
            providerCost: $providerCostMinor,
            quantity: 1,
            context: ['network' => 'mtn', 'capability' => 'airtime'],
            priceBasisMinor: $customerPriceMinor,
            /*
             * The key names are `ProviderCost::toEngineMetadata()`'s, because that
             * is what the real callers pass: `source`, `verified_at` and `estimated`.
             * Getting them wrong here would silently produce a snapshot that claims a
             * confirmed cost, which is exactly the distinction under test.
             */
            costMeta: [
                'source' => $costIsEstimated ? 'configured' : 'verified',
                'estimated' => $costIsEstimated,
            ],
        );
    }

    private function recorder(): ProfitRecorder
    {
        return app(ProfitRecorder::class);
    }

    /* =====================================================================
     | A settled purchase is recorded
     =================================================================== */

    public function test_a_settled_purchase_records_profit(): void
    {
        $transaction = $this->settledPurchase();

        $record = $this->recorder()->recordFromBillPurchase($transaction);

        $this->assertNotNull($record, 'A settled purchase must produce a profit record.');
        $this->assertTrue($record->is_realized);
        $this->assertSame('airtime', $record->service_type);
        $this->assertSame($transaction->id, $record->transaction_id);
        $this->assertSame(100000, (int) $record->revenue_minor, '₦1,000 of airtime');
        $this->assertSame(97000, (int) $record->provider_cost_minor, '₦970 provider cost');
        $this->assertSame(3000, (int) $record->gross_profit_minor, '₦30 gross profit');
        $this->assertSame(300, (int) $record->gross_margin_bps, '3.00% margin');
    }

    public function test_the_recorded_profit_matches_the_snapshot_that_priced_the_sale(): void
    {
        /*
         * The link between the two tables, asserted rather than assumed. A
         * `profit_records` row that disagreed with the immutable snapshot it came
         * from would be a second, different answer to "what did we make on this
         * sale" — and there is no way to tell after the fact which one is right.
         *
         * The recorder itself is what the purchase pipeline calls from
         * `markSuccess()`; `AirtimePricingTest` drives that path end to end.
         */
        $transaction = $this->settledPurchase();
        $snapshot = $transaction->pricingSnapshot;

        $record = $this->recorder()->recordFromBillPurchase($transaction);

        $this->assertNotNull($record);
        $this->assertSame((int) $snapshot->customer_price_minor, (int) $record->revenue_minor);
        $this->assertSame((int) $snapshot->provider_cost_minor, (int) $record->provider_cost_minor);
        $this->assertSame((int) $snapshot->gross_profit_minor, (int) $record->gross_profit_minor);
        $this->assertSame((int) $snapshot->profit_margin_bps, (int) $record->gross_margin_bps);
    }

    public function test_profit_is_never_recorded_twice_for_one_purchase(): void
    {
        /*
         * A webhook, the browser callback and the two-minute auto-resolve sweep can
         * all settle the same purchase. The existence check is the friendly path;
         * the unique index on `transaction_id` is what holds under concurrency, and
         * this asserts the outcome rather than which guard won.
         */
        $transaction = $this->settledPurchase();

        $first = $this->recorder()->recordFromBillPurchase($transaction);
        $second = $this->recorder()->recordFromBillPurchase($transaction);
        $third = $this->recorder()->recordFromBillPurchase($transaction->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->id, $third->id);
        $this->assertSame(1, ProfitRecord::where('transaction_id', $transaction->id)->count());

        // And the total is the single sale, not three times it.
        $this->assertSame(3000, (int) ProfitRecord::realized()->sum('gross_profit_minor'));
    }

    public function test_the_unique_index_is_what_makes_it_idempotent(): void
    {
        /*
         * Proven at the database rather than through the recorder, because the
         * whole reason the index was added is that a check-then-insert is not safe
         * under concurrency. If this insert ever succeeds, the guard is gone.
         */
        $transaction = $this->settledPurchase();

        $this->recorder()->recordFromBillPurchase($transaction);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ProfitRecord::create([
            'transaction_id' => $transaction->id,
            'user_id' => $transaction->user_id,
            'product_key' => 'airtime',
            'revenue_minor' => 100000,
            'provider_cost_minor' => 97000,
            'gross_profit_minor' => 3000,
            'gross_margin_bps' => 300,
            'currency' => 'NGN',
            'is_realized' => true,
        ]);
    }

    /* =====================================================================
     | Only earned profit is recorded
     =================================================================== */

    public function test_an_unsettled_purchase_records_nothing(): void
    {
        /*
         * The money-losing bug if it went the other way: profit reported on a sale
         * that may still fail and be refunded. A `processing` airtime purchase is
         * exactly the state a provider timeout leaves behind.
         */
        foreach (['processing', 'unknown', 'pending', 'failed'] as $status) {
            $transaction = $this->settledPurchase(['status' => $status]);

            $this->assertNull(
                $this->recorder()->recordFromBillPurchase($transaction),
                "A {$status} purchase must not be recorded as earned profit."
            );
        }

        $this->assertSame(0, ProfitRecord::count());
    }

    public function test_a_purchase_with_no_snapshot_records_nothing_rather_than_zero(): void
    {
        /*
         * A wallet top-up, a reversal, or a purchase priced before the pricing
         * engine existed. Reporting zero profit for one of those would present
         * "unmeasured" as "measured and zero" — the confusion the snapshot's own
         * documentation warns about.
         */
        $user = User::factory()->create();

        $transaction = Transactions::create([
            'user_id' => $user->id,
            'reference' => 'TXN-NOSNAP',
            'type' => 'debit',
            'service_type' => 'airtime',
            'description' => 'Unpriced purchase',
            'amount' => '500.00',
            'service_fee' => '0.00',
            'total_amount' => '500.00',
            'payment_method' => 'wallet',
            'payment_status' => 'success',
            'status' => 'success',
        ]);

        $this->assertNull($this->recorder()->recordFromBillPurchase($transaction));
        $this->assertSame(0, ProfitRecord::count());
    }

    /* =====================================================================
     | Refunds
     =================================================================== */

    public function test_a_refund_stops_the_profit_being_counted(): void
    {
        $transaction = $this->settledPurchase();

        $record = $this->recorder()->recordFromBillPurchase($transaction);

        $this->assertSame(3000, (int) ProfitRecord::realized()->sum('gross_profit_minor'));

        $this->recorder()->markBillPurchaseRefunded($transaction);

        $this->assertSame(0, ProfitRecord::realized()->count(), 'A refunded sale is not realized profit.');

        /*
         * The row is kept and the refund is *subtracted*, which is the semantics
         * `ProfitRecord::recalculate()` already had:
         *
         *     gross_profit = revenue + customer_fee - provider_cost - provider_fee - refunded
         *
         * So a fully refunded sale settles at *minus the provider cost* — the loss
         * the platform actually took, because the provider was still paid. Asserting
         * zero here would have been asserting that a refunded sale is free, which is
         * exactly the kind of comfortable arithmetic a profit report must not do.
         */
        $record->refresh();
        $this->assertFalse($record->is_realized);
        $this->assertSame($record->revenue_minor, $record->refunded_minor);
        $this->assertSame(
            -1 * (int) $record->provider_cost_minor,
            (int) $record->gross_profit_minor,
            'A refunded sale should carry the provider cost as a loss, not zero.'
        );
    }

    public function test_marking_a_purchase_refunded_that_was_never_recorded_is_a_no_op(): void
    {
        // A purchase that failed before settlement never had profit booked, so this
        // must not create a row out of nothing.
        $transaction = $this->settledPurchase(['status' => 'failed']);

        $this->recorder()->markBillPurchaseRefunded($transaction);

        $this->assertSame(0, ProfitRecord::count());
    }

    /* =====================================================================
     | The cost basis
     =================================================================== */

    public function test_profit_measured_against_an_assumed_cost_is_marked_estimated(): void
    {
        /*
         * The distinction the whole figure depends on. A provider discount that
         * somebody typed into the console is an assumption; a margin computed from
         * it is a forecast, and presenting it as earned is the failure the brief
         * warns about.
         */
        $estimated = $this->settledPurchase(snapshotOverrides: ['cost_is_estimated' => true]);
        $verified = $this->settledPurchase(snapshotOverrides: ['cost_is_estimated' => false]);

        $this->recorder()->recordFromBillPurchase($estimated);
        $this->recorder()->recordFromBillPurchase($verified);

        $this->assertTrue((bool) ProfitRecord::where('transaction_id', $estimated->id)->firstOrFail()->cost_is_estimated);
        $this->assertFalse((bool) ProfitRecord::where('transaction_id', $verified->id)->firstOrFail()->cost_is_estimated);
    }

    public function test_the_summary_separates_estimated_from_verified_profit(): void
    {
        $estimated = $this->settledPurchase(snapshotOverrides: ['cost_is_estimated' => true]);
        $verified = $this->settledPurchase(snapshotOverrides: ['cost_is_estimated' => false]);

        $this->recorder()->recordFromBillPurchase($estimated);
        $this->recorder()->recordFromBillPurchase($verified);

        $summary = $this->recorder()->summary(now()->subDay(), now()->addDay());

        $this->assertSame(2, $summary['transactions']);
        $this->assertSame(6000, $summary['gross_profit_minor']);
        $this->assertSame(3000, $summary['estimated_profit_minor']);
        $this->assertSame(3000, $summary['verified_profit_minor']);
        $this->assertSame(1, $summary['estimated_transactions']);

        /*
         * And the two halves always add up to the total. Derived by subtraction
         * rather than selected separately, so a rounding difference or a missing
         * CASE branch cannot produce a split that disagrees with its own total.
         */
        $this->assertSame(
            $summary['gross_profit_minor'],
            $summary['estimated_profit_minor'] + $summary['verified_profit_minor']
        );
    }

    /* =====================================================================
     | Per-service reporting
     =================================================================== */

    public function test_profit_is_broken_down_by_service(): void
    {
        $airtime = $this->settledPurchase(['service_type' => 'airtime']);
        $data = $this->settledPurchase(['service_type' => 'data'], ['provider_cost_minor' => 90000]);

        $this->recorder()->recordFromBillPurchase($airtime);
        $this->recorder()->recordFromBillPurchase($data);

        $rows = collect($this->recorder()->breakdown('service_type', now()->subDay(), now()->addDay()))
            ->keyBy('key');

        $this->assertCount(2, $rows);

        $this->assertSame(100000, $rows['airtime']['revenue_minor']);
        $this->assertSame(3000, $rows['airtime']['gross_profit_minor']);
        $this->assertSame(100000, $rows['data']['revenue_minor']);
        $this->assertSame(10000, $rows['data']['gross_profit_minor'], 'A ₦900 cost on a ₦1,000 sale is ₦100');
    }

    public function test_the_per_service_breakdown_adds_up_to_the_overall_total(): void
    {
        /*
         * The claim that makes a breakdown trustworthy. A per-service report that
         * does not sum to the headline figure is worse than no breakdown, because
         * the two numbers are read as different facts about the same money.
         */
        foreach ([['airtime', 97000], ['data', 90000], ['electricity', 95000], ['cable-tv', 80000]] as [$service, $cost]) {
            $this->recorder()->recordFromBillPurchase(
                $this->settledPurchase(['service_type' => $service], ['provider_cost_minor' => $cost])
            );
        }

        $summary = $this->recorder()->summary(now()->subDay(), now()->addDay());
        $rows = $this->recorder()->breakdown('service_type', now()->subDay(), now()->addDay());

        $this->assertSame(
            $summary['gross_profit_minor'],
            array_sum(array_column($rows, 'gross_profit_minor')),
            'The per-service breakdown must add up to the overall profit.'
        );

        $this->assertSame(
            $summary['revenue_minor'],
            array_sum(array_column($rows, 'revenue_minor'))
        );

        $this->assertSame(
            $summary['transactions'],
            array_sum(array_column($rows, 'transactions'))
        );
    }

    public function test_an_unknown_breakdown_column_is_refused(): void
    {
        // The column name reaches a group-by, so an unvalidated one is an injection
        // point as well as a nonsense report.
        $this->expectException(\InvalidArgumentException::class);

        $this->recorder()->breakdown('service_type); DROP TABLE profit_records; --', now()->subDay(), now());
    }

    public function test_a_refunded_sale_leaves_the_service_breakdown(): void
    {
        $airtime = $this->settledPurchase(['service_type' => 'airtime']);
        $data = $this->settledPurchase(['service_type' => 'data'], ['provider_cost_minor' => 90000]);

        $this->recorder()->recordFromBillPurchase($airtime);
        $this->recorder()->recordFromBillPurchase($data);
        $this->recorder()->markBillPurchaseRefunded($data);

        $rows = collect($this->recorder()->breakdown('service_type', now()->subDay(), now()->addDay()))
            ->keyBy('key');

        $this->assertArrayHasKey('airtime', $rows);
        $this->assertArrayNotHasKey('data', $rows, 'A refunded sale must leave the realized report.');
    }

    /* =====================================================================
     | The console
     =================================================================== */

    public function test_the_dashboard_reports_the_sales_that_were_made(): void
    {
        $this->recorder()->recordFromBillPurchase($this->settledPurchase());

        $admin = User::factory()->superAdmin()->create();

        $this->actingAs($admin)
            ->get(route('admin.profit.index'))
            ->assertOk()
            ->assertSee('Today by service', false)
            ->assertSee('Airtime', false);

        $this->actingAs($admin)
            ->getJson(route('admin.profit.data'))
            ->assertOk()
            ->assertJsonPath('summary.transactions', 1)
            ->assertJsonPath('summary.gross_profit_minor', 3000)
            ->assertJsonPath('today.gross_profit_minor', 3000)
            ->assertJsonStructure(['by_service', 'generated_at']);
    }

    public function test_the_live_endpoint_reports_profit_in_the_currency_the_customer_paid(): void
    {
        $this->recorder()->recordFromBillPurchase($this->settledPurchase());

        $admin = User::factory()->superAdmin()->create();

        $response = $this->actingAs($admin)->getJson(route('admin.profit.data'))->assertOk();

        // Formatted on the server, so the browser never does money arithmetic.
        $this->assertSame('₦30.00', $response->json('today.formatted.gross_profit'));
        $this->assertSame('₦1,000.00', $response->json('today.formatted.revenue'));
        $this->assertSame('3.00%', $response->json('today.formatted.gross_margin'));
    }

    public function test_the_dashboard_is_not_reachable_by_a_customer(): void
    {
        // Margin is internal. The route carries `admin:view_profit`, which is
        // super-admin only.
        $customer = User::factory()->create();

        $this->actingAs($customer)
            ->get(route('admin.profit.index'))
            ->assertForbidden();
    }

    public function test_money_is_never_moved_by_profit_recording(): void
    {
        /*
         * The invariant the whole design rests on. Profit is an accounting figure
         * derived from the immutable snapshot; it is not a wallet adjustment, and
         * recording it must leave every balance exactly where it was.
         */
        $transaction = $this->settledPurchase();

        $wallet = $transaction->user->wallet;
        $before = Money::fromDatabase($wallet->balance);

        $this->recorder()->recordFromBillPurchase($transaction);
        $this->recorder()->markBillPurchaseRefunded($transaction);

        $this->assertSame(
            $before->toDecimalString(),
            Money::fromDatabase($wallet->fresh()->balance)->toDecimalString(),
            'Profit recording must never touch a wallet balance.'
        );

        $this->assertSame(
            0,
            \App\Models\WalletLedger::where('user_id', $transaction->user_id)->count(),
            'Profit recording must never write a ledger entry.'
        );
    }
}
