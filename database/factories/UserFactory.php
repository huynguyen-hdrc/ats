<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => Str::lower(fake()->unique()->userName()).'@helidirect.com',
            'password' => static::$password ??= Hash::make('password-123'),
            'role_key' => 'staff',
            'status' => 'active',
            'remember_token' => Str::random(10),
        ];
    }

    public function role(string $key): static
    {
        return $this->state(fn () => ['role_key' => $key]);
    }

    /** Admin đã bật 2FA, để test các route API bình thường. */
    public function admin(): static
    {
        return $this->state(fn () => [
            'role_key' => 'admin',
            'two_factor_secret' => encrypt('TESTSECRET'),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function invited(): static
    {
        return $this->state(fn () => ['status' => 'invited', 'password' => null, 'invited_at' => now()]);
    }

    public function locked(): static
    {
        return $this->state(fn () => ['status' => 'locked', 'locked_at' => now()]);
    }
}
