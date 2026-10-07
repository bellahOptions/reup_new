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
