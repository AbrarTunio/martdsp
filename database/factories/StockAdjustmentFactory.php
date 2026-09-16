<?php

namespace Database\Factories;

use App\Enums\AdjustmentReason;
use App\Enums\AdjustmentStatus;
use App\Models\StockAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAdjustment>
 */
class StockAdjustmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reason' => AdjustmentReason::Damage,
            'note' => null,
            'status' => AdjustmentStatus::Draft,
            'user_id' => User::factory()->owner(),
            'adjusted_at' => now(),
        ];
    }

    public function reason(AdjustmentReason $reason): static
    {
        return $this->state(['reason' => $reason]);
    }

    public function opening(): static
    {
        return $this->state(['reason' => AdjustmentReason::Opening]);
    }

    public function recount(): static
    {
        return $this->state(['reason' => AdjustmentReason::Recount]);
    }

    /**
     * A posted adjustment written straight to the table, for tests that need
     * one to exist rather than to prove posting works.
     */
    public function posted(): static
    {
        return $this->state([
            'status' => AdjustmentStatus::Posted,
            'posted_at' => now(),
        ]);
    }
}
