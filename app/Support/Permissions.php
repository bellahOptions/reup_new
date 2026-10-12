<?php

namespace App\Support;

/**
 * The single registry of admin permissions and the roles that grant them.
 *
 * Permissions were previously duplicated as a private array inside
 * Admin\AuthController, and the routes never checked any of them — so the
 * listing and the enforcement had drifted apart. Both now read from here.
 */
class Permissions
{
    /** Every permission the console understands. */
    public const ALL = [
        'view_dashboard',
        'view_users',
        'manage_users',
        'manage_wallets',
        'view_transactions',
        'manage_transactions',
        'manage_payments',
        'view_reports',
        'view_contacts',
        'manage_contacts',
        'chat',
        'manage_settings',
        'manage_admins',
        // Wave 1 — commercial controls
        'view_pricing',
        'manage_pricing',
        'view_profit',
        'manage_providers',
        'manage_catalogue',
        /*
         * Lifecycle messaging.
         *
         * `view_lifecycle` reads the overview and the delivery log; `manage_lifecycle`
         * writes templates, automations and the marketing kill switch; `send_lifecycle`
         * fires a test send and retries a failure.
         *
         * Split because they are different risks. Reading a delivery log is an
         * operational task a support lead can be trusted with. Writing a template is
         * authoring content that goes to every customer, and flipping the kill switch
         * changes what the platform sends to everybody — those belong with the people
         * who already control pricing and margins.
         */
        'view_lifecycle',
        'manage_lifecycle',
        'send_lifecycle',
    ];

    /**
     * Permissions only a super admin can effectively hold.
     *
     * These are the commercial controls: pricing, margin, provider
     * configuration, catalogue publication and profit analytics. They are
     * deliberately absent from the `admin` role's defaults, so promoting a
     * support agent to administrator does not silently hand them the ability to
     * reprice the platform or read its margins.
     *
     * `User::hasPermission()` already returns true for a super admin regardless,
     * so this list is what the admin-management screen uses to refuse granting
     * one of these to a lesser role in the first place.
     */
    public const SUPER_ADMIN_ONLY = [
        'view_pricing',
        'manage_pricing',
        'view_profit',
        'manage_providers',
        'manage_catalogue',
        /*
         * The kill switch and template authoring are super-admin only, for the same
         * reason pricing is: they change what the platform does for every customer at
         * once. `view_lifecycle` is deliberately *not* here — a support lead needs the
         * delivery log to answer "did this customer get their email?", and reading is
         * not the risky half.
         */
        'manage_lifecycle',
    ];

    /** Permissions that expose internal provider cost or margin figures. */
    public const FINANCIAL_INTERNAL = [
        'view_pricing',
        'manage_pricing',
        'view_profit',
        'manage_providers',
    ];

    /** Human-readable labels, for the admin management screens. */
    public const LABELS = [
        'view_dashboard' => 'View dashboard',
        'view_users' => 'View customers',
        'manage_users' => 'Create, edit and suspend customers',
        'manage_wallets' => 'Adjust wallet balances and approve bank transfers',
        'view_transactions' => 'View transactions',
        'manage_transactions' => 'Force status, cancel and refund transactions',
        'manage_payments' => 'View gateway balances and settlement totals',
        'view_reports' => 'View reports',
        'view_contacts' => 'View contact messages',
        'manage_contacts' => 'Reply to and delete contact messages',
        'chat' => 'Handle live chat',
        'manage_settings' => 'Change platform settings, announcements and legal documents',
        'manage_admins' => 'Create and manage administrator accounts',
        'view_pricing' => 'View pricing rules, provider cost and margins',
        'manage_pricing' => 'Create, change and deactivate pricing rules',
        'view_profit' => 'View revenue, provider cost and profit analytics',
        'manage_providers' => 'Configure providers, credentials and routing priority',
        'manage_catalogue' => 'Review, publish and withdraw services for sale',
        'view_lifecycle' => 'View lifecycle automations, templates and delivery logs',
        'manage_lifecycle' => 'Author templates and automations, and pause marketing platform-wide',
        'send_lifecycle' => 'Send test emails and retry failed deliveries',
    ];

    /**
     * Default permission set per role.
     *
     * Deliberately conservative: `moderator` and `support` can read and
     * respond, but cannot move money. Only `admin` gets the destructive
     * capabilities, and `manage_admins` is reserved for super admins.
     */
    public const ROLE_DEFAULTS = [
        'admin' => [
            'view_dashboard',
            'view_users',
            'manage_users',
            'manage_wallets',
            'view_transactions',
            'manage_transactions',
            'manage_payments',
            'view_reports',
            'view_contacts',
            'manage_contacts',
            'chat',
            'manage_settings',
        ],
        'moderator' => [
            'view_dashboard',
            'view_users',
            'view_transactions',
            'view_reports',
            'view_contacts',
            'chat',
        ],
        'support' => [
            'view_dashboard',
            'view_users',
            'view_transactions',
            'view_contacts',
            'manage_contacts',
            'chat',
        ],
    ];

    public static function defaultsForRole(string $role): array
    {
        return self::ROLE_DEFAULTS[$role] ?? [];
    }

    public static function roles(): array
    {
        return array_keys(self::ROLE_DEFAULTS);
    }
}
