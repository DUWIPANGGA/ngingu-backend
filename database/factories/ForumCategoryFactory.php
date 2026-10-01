<?php

namespace Database\Factories;

use App\Models\ForumCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ForumCategory>
 */
class ForumCategoryFactory extends Factory
{
    protected $model = ForumCategory::class;

    public function definition(): array
    {
        $name = fake()->unique()->randomElement([
            'Identifikasi Penyakit',
            'Pengobatan & Perawatan',
            'Kualitas Air',
            'Pakan & Nutrisi',
            'Kolam & Pemeliharaan',
            'Budidaya Pemula',
        ]);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'icon' => fake()->randomElement(['🦠', '💊', '💧', '🐟', '🏞️']),
            'description' => fake()->sentence(8),
            'sort_order' => fake()->numberBetween(1, 20),
            'post_count' => 0,
        ];
    }
}
