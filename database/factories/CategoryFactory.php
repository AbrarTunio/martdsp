<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => Str::title(fake()->unique()->words(2, true)),
            'name_ur' => null,
            'parent_id' => null,
            'is_active' => true,
        ];
    }

    public function childOf(Category $parent): static
    {
        return $this->state(['parent_id' => $parent->id]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
