<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'company' => fake()->optional()->company(),
            'phone' => '+923'.fake()->unique()->numerify('#########'),
            'address' => fake()->optional()->streetAddress(),
            'payment_terms_days' => 0,
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function terms(int $days): static
    {
        return $this->state(['payment_terms_days' => $days]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    /**
     * A balance written straight to the cache, for tests that need a supplier
     * to be owed money rather than to prove how it came to be owed.
     */
    public function owed(int $paisa): static
    {
        return $this->afterCreating(function (Supplier $supplier) use ($paisa): void {
            $supplier->forceFill(['balance_paisa' => $paisa])->save();
        });
    }
}
