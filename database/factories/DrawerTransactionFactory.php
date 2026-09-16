<?php

namespace Database\Factories;

use App\Enums\DrawerEntryType;
use App\Models\DrawerSession;
use App\Models\DrawerTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DrawerTransaction>
 */
class DrawerTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'drawer_session_id' => DrawerSession::factory()->open(),
            'type' => DrawerEntryType::PayIn,
            'amount_paisa' => fake()->numberBetween(1, 50) * 10_000,
            'note' => null,
        ];
    }
}
