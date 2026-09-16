<?php

namespace Database\Factories;

use App\Enums\UnitType;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->word());

        return [
            'name' => $name,
            'short_name' => Str::lower(Str::substr($name, 0, 3)),
            'type' => UnitType::Count,
        ];
    }

    public function named(string $name, string $shortName): static
    {
        return $this->state(['name' => $name, 'short_name' => $shortName]);
    }

    public function sachet(): static
    {
        return $this->named('Sachet', 'sct');
    }

    public function box(): static
    {
        return $this->named('Box', 'box');
    }

    public function carton(): static
    {
        return $this->named('Carton', 'ctn');
    }

    public function weight(): static
    {
        return $this->state(['type' => UnitType::Weight]);
    }
}
