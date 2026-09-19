<?php

namespace Database\Factories;

use App\Models\User;
use App\Support\RecoveryCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * シークレットを発行しただけの状態（認証アプリへの登録待ち）。この間 MFA は無効。
     *
     * シークレットは encrypted キャストで保存され、読み出すと復号される。テスト側は
     * $user->two_factor_secret を Google2FA::getCurrentOtp() に渡して正しいコードを作る。
     */
    public function twoFactorPending(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(32),
        ]);
    }

    /**
     * MFA が有効なユーザー。
     */
    public function twoFactorConfirmed(): static
    {
        return $this->twoFactorPending()->state(fn (array $attributes) => [
            'two_factor_recovery_codes' => RecoveryCode::generateSet(),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
