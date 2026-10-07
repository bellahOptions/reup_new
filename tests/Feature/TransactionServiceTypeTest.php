<?php

namespace Tests\Feature;

use App\Services\BillPaymentService;
use ReflectionClass;
use Tests\TestCase;

/**
 * `transactions.service_type` is an ENUM, so the value written has to be one the
 * column actually carries. It was not: the vending pipeline stored its product
 * key (`cable_tv`, `waec`, `jamb`) while the rest of the application reads
 * `cable-tv` and `exam`, and `refund` / `manual_adjustment` were not in the ENUM
 * at all.
 *
 * The consequence was not cosmetic. On a server running the default strict SQL
 * mode those inserts fail with error 1265 — no transaction row for cable, WAEC,
 * JAMB or betting, and no refund row for a failed purchase. On a lax server the
 * value is stored as an empty string, and every history page and admin counter
 * for those services silently matches nothing.
 *
 * These tests are database-free: they check the mapping and the ENUM definition
 * agree, which is the part that has to hold before any row is written.
 */
class TransactionServiceTypeTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_02_12_000000_add_bill_service_types_to_transactions_table.php';

    public function test_product_keys_are_mapped_onto_the_vocabulary_the_application_reads(): void
    {
        $this->assertSame('cable-tv', BillPaymentService::serviceType('cable_tv'));
        $this->assertSame('exam', BillPaymentService::serviceType('waec'));
        $this->assertSame('exam', BillPaymentService::serviceType('jamb'));

        // Everything else is stored under its own name.
        $this->assertSame('airtime', BillPaymentService::serviceType('airtime'));
        $this->assertSame('data', BillPaymentService::serviceType('data'));
        $this->assertSame('electricity', BillPaymentService::serviceType('electricity'));
        $this->assertSame('betting', BillPaymentService::serviceType('betting'));
    }

    public function test_every_product_in_the_catalogue_writes_a_value_the_column_accepts(): void
    {
        $enum = $this->serviceTypeEnum();

        foreach (array_keys((array) config('bills.products')) as $product) {
            $this->assertContains(
                BillPaymentService::serviceType($product),
                $enum,
                "Product [{$product}] would be written with a service_type the column rejects."
            );
        }
    }

    public function test_the_values_written_outside_the_pipeline_are_accepted_too(): void
    {
        $enum = $this->serviceTypeEnum();

        // BillPaymentService::refund(), AffiliateService, PaystackService,
        // WalletController and the admin adjustment path.
        foreach (['refund', 'transfer', 'funding', 'manual_adjustment', 'other'] as $value) {
            $this->assertContains($value, $enum);
        }
    }

    public function test_the_widening_migration_keeps_every_original_value(): void
    {
        $constants = $this->migrationConstants();

        // Narrowing the list would silently break rows and readers that already
        // depend on these.
        $this->assertSame([], array_diff($constants['ORIGINAL_VALUES'], $constants['VALUES']));
    }

    /**
     * The values the widening migration puts in the column's ENUM.
     *
     * Read from the migration itself rather than copied, so the schema and this
     * test cannot drift apart.
     *
     * @return array<int, string>
     */
    private function serviceTypeEnum(): array
    {
        return $this->migrationConstants()['VALUES'];
    }

    /** @return array<string, array<int, string>> */
    private function migrationConstants(): array
    {
        $migration = require database_path(self::MIGRATION);

        return (new ReflectionClass($migration))->getConstants();
    }
}
