<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Builder;
use Tests\TestCase;

/**
 * The migrations must run on MySQL/MariaDB builds whose InnoDB index key limit
 * is 1000 bytes rather than 3072, where `php artisan migrate` dies on the very
 * first run:
 *
 *   1071 Specified key was too long; max key length is 1000 bytes
 *   (alter table `personal_access_tokens` add index
 *    `personal_access_tokens_tokenable_type_tokenable_id_index`
 *    (`tokenable_type`, `tokenable_id`))
 *
 * `morphs('tokenable')` is a VARCHAR(255) plus a bigint; under utf8mb4 the
 * string alone is 255 × 4 = 1020 bytes. These two tests pin the facts that make
 * the schema fit, so the failure cannot come back unnoticed: strings default to
 * 191 characters, and the connection really is utf8mb4.
 */
class MySqlIndexLengthTest extends TestCase
{
    /** The tightest index key limit the schema has to survive, in bytes. */
    private const LEGACY_KEY_LIMIT = 1000;

    /** Bytes per character for utf8mb4. */
    private const UTF8MB4_BYTES_PER_CHAR = 4;

    public function test_default_string_columns_are_short_enough_to_index(): void
    {
        $this->assertLessThanOrEqual(
            191,
            Builder::$defaultStringLength,
            'A default string() column longer than 191 characters cannot be indexed on a 1000-byte key limit.'
        );
    }

    public function test_the_widest_composite_index_in_the_schema_fits(): void
    {
        $this->assertSame('utf8mb4', config('database.connections.mysql.charset'));

        // `personal_access_tokens` indexes (tokenable_type, tokenable_id): the
        // widest indexed string in the schema, plus a bigint.
        $bytes = (Builder::$defaultStringLength * self::UTF8MB4_BYTES_PER_CHAR) + 8;

        $this->assertLessThanOrEqual(self::LEGACY_KEY_LIMIT, $bytes);
    }
}
