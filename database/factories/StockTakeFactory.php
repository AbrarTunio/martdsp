<?php

namespace Database\Factories;

use App\Enums\StockTakeStatus;
use App\Models\Category;
use App\Models\StockTake;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTake>
 */
class StockTakeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => null,
            'status' => StockTakeStatus::Draft,
            'category_id' => null,
            'missing_are_zero' => false,
            'note' => null,
            'user_id' => User::factory()->owner(),
            'started_at' => now(),
        ];
    }

    /**
     * One section of the shop, with anything not scanned in it taken as gone.
     */
    public function sweeping(Category $category): static
    {
        return $this->state([
            'category_id' => $category->id,
            'missing_are_zero' => true,
        ]);
    }

    /**
     * A posted count written straight to the table, for tests that need one
     * to exist rather than to prove posting works.
     */
    public function posted(): static
    {
        return $this->state([
            'status' => StockTakeStatus::Posted,
            'posted_at' => now(),
        ]);
    }
}
