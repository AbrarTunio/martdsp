<?php

namespace Database\Factories;

use App\Enums\DrawerStatus;
use App\Models\DrawerSession;
use App\Models\Register;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Shifts made here skip App\Services\DrawerService, so they carry no ledger
 * rows. Tests that care about the cash in the drawer should open one through
 * the service instead.
 *
 * @extends Factory<DrawerSession>
 */
class DrawerSessionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'register_id' => Register::factory(),
            'status' => DrawerStatus::Closed,
            'opened_by' => User::factory(),
            'opened_at' => now()->subHours(8),
            'opening_float_paisa' => 500_000,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DrawerStatus::Open,
            'open_register_id' => $attributes['register_id'],
            'closed_by' => null,
            'closed_at' => null,
        ]);
    }

    /**
     * Counted exactly what was expected, and left the float for the next shift.
     */
    public function closed(int $expectedPaisa = 500_000, ?int $countedPaisa = null): static
    {
        $countedPaisa ??= $expectedPaisa;

        return $this->state(fn (array $attributes): array => [
            'status' => DrawerStatus::Closed,
            'open_register_id' => null,
            'closed_by' => $attributes['opened_by'],
            'closed_at' => now(),
            'expected_cash_paisa' => $expectedPaisa,
            'counted_cash_paisa' => $countedPaisa,
            'variance_paisa' => $countedPaisa - $expectedPaisa,
            'left_in_drawer_paisa' => min($countedPaisa, 500_000),
        ]);
    }
}
