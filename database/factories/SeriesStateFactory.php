<?php

namespace Database\Factories;

use App\Models\SeriesState;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeriesState>
 */
class SeriesStateFactory extends Factory
{
    protected $model = SeriesState::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'suwayomi_manga_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'in_library' => true,
            'added_at' => now(),
        ];
    }

    public function notInLibrary(): static
    {
        return $this->state(fn (): array => ['in_library' => false, 'added_at' => null]);
    }

    public function favourite(): static
    {
        return $this->state(fn (): array => ['favourited_at' => now()]);
    }
}
