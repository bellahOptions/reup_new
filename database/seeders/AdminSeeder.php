<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * The administrator account.
 *
 * One account, one purpose: sign in to the console. It is a super admin, so
 * every permission check in AdminMiddleware passes and there is nothing to
 * remember about which login can do what.
 *
 * The previous version created three near-identical accounts
 * (`super@reup.test` / `admin@reup.test` / `support@reup.test`, all named
 * "Test ..."), which was ambiguous in two ways: the addresses differed by a
 * single word, and `.test` is not a routable domain, so a password reset or
 * notification sent to one silently went nowhere. If you need to exercise the
 * restricted roles, create them in the console under Administrators — that is
 * the path real staff accounts take anyway.
 *
 * ## Credentials
 *
 * Usable as-is locally. ADMIN_SEED_PASSWORD overrides the password when set.
 * The seeder refuses to run in production without an explicit
 * ADMIN_SEED_ALLOW_PRODUCTION, because a known password on a live console is a
 * total compromise — the console can move money.
 *
 * ## Safety
 *
 * `updateOrCreate` on email, so re-running updates the existing row instead of
 * failing on the unique index.
 */
class AdminSeeder extends Seeder
{
    /** The seeded login. */
    private const EMAIL = 'superadmin@reup.com.ng';

    private const NAME = 'ReUp Superadmin';

    /** Development password. Must satisfy Rules\Password::min(12). */
    private const DEFAULT_PASSWORD = 'Admin@123456';

    public function run(): void
    {
        $password = $this->resolvePassword();

        $admin = User::updateOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => self::NAME,
                'password' => Hash::make($password),
                'is_admin' => true,
                'is_super_admin' => true,
                // Stored explicitly (rather than left null) so the console's
                // administrator table renders a sensible role and permission
                // count. A super admin bypasses the checks regardless.
                'admin_role' => 'admin',
                'admin_permissions' => Permissions::ALL,
                // AdminMiddleware does not require this, but the verification
                // middleware and profile screens do.
                'email_verified_at' => now(),
                // Read by AdminMiddleware and the maintenance-mode check; a
                // non-active admin is locked out with no visible reason.
                'status' => 'active',
                'is_blocked' => false,
            ]
        );

        // Only read by the console's "online admins" widget.
        $admin->forceFill(['is_online' => false])->saveQuietly();

        $this->command?->info('  Administrator ready:');
        $this->command?->line('    Email:    ' . self::EMAIL);
        $this->command?->line('    Password: ' . $password);
        $this->command?->line('    Role:     Super admin (all permissions)');

        if (app()->environment('production')) {
            $this->command?->warn('  Change this password before using the console.');
        }
    }

    /**
     * A known password in production would be a backdoor into an account that
     * can move money, so it takes a second explicit opt-in beyond running the
     * seeder at all.
     */
    private function resolvePassword(): string
    {
        $configured = (string) env('ADMIN_SEED_PASSWORD', '');
        $password = $configured !== '' ? $configured : self::DEFAULT_PASSWORD;

        if (strlen($password) < 12) {
            // Matches Rules\Password::min(12) on the console's create-admin form.
            throw new RuntimeException('ADMIN_SEED_PASSWORD must be at least 12 characters.');
        }

        if (app()->environment('production')
            && $configured === ''
            && ! filter_var(env('ADMIN_SEED_ALLOW_PRODUCTION', false), FILTER_VALIDATE_BOOLEAN)) {
            throw new RuntimeException(
                'Refusing to seed the default admin password in production. '
                . 'Set ADMIN_SEED_PASSWORD to something secret, or set '
                . 'ADMIN_SEED_ALLOW_PRODUCTION=true if you truly mean it.'
            );
        }

        return $password;
    }
}
