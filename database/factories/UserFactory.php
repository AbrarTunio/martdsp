<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Hashing is slow by design, so every factory user shares one hash.
     */
    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::Cashier,
            'phone' => fake()->numerify('03## #######'),
            'password' => static::$password ??= Hash::make('password'),
            'pin_code' => null,
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function owner(): static
    {
        return $this->state(['role' => Role::Owner]);
    }

    public function manager(): static
    {
        return $this->state(['role' => Role::Manager]);
    }

    public function cashier(): static
    {
        return $this->state(['role' => Role::Cashier]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function withPin(string $pin = '1234'): static
    {
        return $this->state(['pin_code' => $pin]);
    }
}
