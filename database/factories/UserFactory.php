<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 *
 * Restored because the feature suite depends on it: AuthenticationTest,
 * EmailVerificationTest, PasswordConfirmationTest and PasswordResetTest all call
 * User::factory(). Without it the whole auth suite errored with
 * "Class Database\Factories\UserFactory not found".
 *
 * `password` is pre-hashed rather than passed to Hash::make() at call time. The
 * User model sets no `password` mutator, so the value is stored verbatim — which
 * is what lets the tests POST the literal string 'password' and have it match.
 * Hashing here rather than in the model keeps that contract in one place.
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'phone' => $this->faker->numerify('080########'),
            'email_verified_at' => now(),
            'status' => 'active',
            'is_admin' => false,
            'is_super_admin' => false,
            'is_blocked' => false,
            'remember_token' => Str::random(10),
        ];
    }

    /** An account that has not confirmed its email address. */
    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }

    /** An account in the given lifecycle state. */
    public function status(string $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** A suspended account: cannot sign in by password or code. */
    public function blocked(): static
    {
        return $this->state(fn () => ['is_blocked' => true]);
    }

    /** An admin-console account with an explicit permission set. */
    public function admin(array $permissions = [], string $role = 'admin'): static
    {
        return $this->state(fn () => [
            'is_admin' => true,
            'is_super_admin' => false,
            'admin_role' => $role,
            'admin_permissions' => $permissions,
        ]);
    }

    /** A super admin, which bypasses every permission check. */
    public function superAdmin(): static
    {
        return $this->state(fn () => [
            'is_admin' => true,
            'is_super_admin' => true,
            'admin_role' => 'admin',
            'admin_permissions' => \App\Support\Permissions::ALL,
        ]);
    }
}
