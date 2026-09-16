<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'name_ur' => null,
            'phone' => '+923'.fake()->unique()->numerify('#########'),
            'address' => fake()->optional()->streetAddress(),
            'credit_limit_paisa' => 0,
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function limit(int $paisa): static
    {
        return $this->state(['credit_limit_paisa' => $paisa]);
    }

    /**
     * A balance written straight to the cache, for tests that need a
     * customer to owe money rather than to prove how they came to owe it.
     */
    public function owing(int $paisa): static
    {
        return $this->afterCreating(function (Customer $customer) use ($paisa): void {
            $customer->forceFill(['balance_paisa' => $paisa])->save();
        });
    }
}
