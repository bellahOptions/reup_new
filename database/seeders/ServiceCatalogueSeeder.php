<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

/**
 * The approved category structure (§51.2).
 *
 * ## What this seeds, and what it deliberately does not
 *
 * It seeds **categories** — the navigation the approved copy names: Pay Bills, then
 * Digital, then Social, with the services listed under each.
 *
 * It seeds **no products**. A `service_products` row is a decision that ReUp sells a
 * particular thing at a particular price, and that decision belongs to a Super Admin
 * after they have seen a provider's cost. A seeder that created products would fill
 * the storefront with unpriced offerings the moment it ran, and every one of them
 * would reach checkout only to be refused.
 *
 * So it is safe to run in production, repeatedly: `firstOrCreate` keyed on the slug
 * means an existing category an operator has renamed or reordered is left exactly as
 * they left it.
 *
 * ## Why categories are seedable at all
 *
 * Everything dynamic is database-driven, and the *taxonomy* is part of the approved
 * product structure rather than customer data. Seeding it makes a fresh deployment
 * show the intended navigation immediately, instead of an empty catalogue that looks
 * like a broken storefront while somebody re-keys the copy from a document.
 */
class ServiceCatalogueSeeder extends Seeder
{
    /**
     * Category slug → the group it belongs to, its name, and its position.
     *
     * Order is meaningful: it is the order a customer browses, and it comes from the
     * approved copy in §51.2 rather than from this class's preference.
     *
     * @var array<int, array{group: string, slug: string, name: string, description: string, icon: string}>
     */
    private const CATEGORIES = [
        /* ---- Pay Bills ------------------------------------------------- */
        [
            'group' => ServiceCategory::GROUP_PAY_BILLS,
            'slug' => 'airtime',
            'name' => 'Airtime',
            'description' => 'Top up any Nigerian network instantly.',
            'icon' => 'device-phone-mobile',
        ],
        [
            'group' => ServiceCategory::GROUP_PAY_BILLS,
            'slug' => 'data',
            'name' => 'Data',
            'description' => 'Buy data bundles for every network.',
            'icon' => 'wifi',
        ],
        [
            'group' => ServiceCategory::GROUP_PAY_BILLS,
            'slug' => 'electricity',
            'name' => 'Electricity',
            'description' => 'Pay your electricity bill quickly and securely.',
            'icon' => 'bolt',
        ],
        [
            'group' => ServiceCategory::GROUP_PAY_BILLS,
            'slug' => 'cable-tv',
            'name' => 'Cable TV',
            'description' => 'Keep your entertainment active without leaving ReUp.',
            'icon' => 'tv',
        ],
        [
            'group' => ServiceCategory::GROUP_PAY_BILLS,
            'slug' => 'internet',
            'name' => 'Internet',
            'description' => 'Renew your internet subscription and stay connected.',
            'icon' => 'globe-alt',
        ],
        [
            'group' => ServiceCategory::GROUP_PAY_BILLS,
            'slug' => 'education',
            'name' => 'Education',
            'description' => 'Pay for supported education services and registrations.',
            'icon' => 'academic-cap',
        ],

        /* ---- Digital --------------------------------------------------- */
        [
            'group' => ServiceCategory::GROUP_DIGITAL,
            'slug' => 'gift-cards',
            'name' => 'Gift Cards',
            'description' => 'Buy digital gift cards from supported brands.',
            'icon' => 'gift',
        ],
        [
            'group' => ServiceCategory::GROUP_DIGITAL,
            'slug' => 'esim',
            'name' => 'eSIM',
            'description' => 'Get supported eSIM plans for travel and international connectivity.',
            'icon' => 'sim-card',
        ],
        [
            'group' => ServiceCategory::GROUP_DIGITAL,
            'slug' => 'international-topup',
            'name' => 'International Top-Up',
            'description' => 'Send airtime and supported data bundles around the world.',
            'icon' => 'globe-americas',
        ],
        [
            'group' => ServiceCategory::GROUP_DIGITAL,
            'slug' => 'epin',
            'name' => 'ePINs & Recharge Cards',
            'description' => 'Get digital recharge PINs instantly and securely.',
            'icon' => 'key',
        ],

        /* ---- Social ---------------------------------------------------- */
        [
            'group' => ServiceCategory::GROUP_SOCIAL,
            'slug' => 'social-boost',
            'name' => 'Social Boost',
            'description' => 'Boost your social media presence with simple, fast and trackable campaigns.',
            'icon' => 'sparkles',
        ],
    ];

    public function run(): void
    {
        // Position within the whole taxonomy, so ordering survives a re-run and a
        // category added later can be placed deliberately.
        $position = 0;

        foreach (self::CATEGORIES as $definition) {
            $position += 10;

            ServiceCategory::firstOrCreate(
                ['slug' => $definition['slug']],
                [
                    'group' => $definition['group'],
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'icon' => $definition['icon'],
                    'sort_order' => $position,
                    'is_active' => true,
                ],
            );
        }
    }
}
