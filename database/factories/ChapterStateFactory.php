<?php

namespace Database\Factories;

use App\Models\ChapterState;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChapterState>
 */
class ChapterStateFactory extends Factory
{
    protected $model = ChapterState::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'suwayomi_chapter_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'suwayomi_manga_id' => fake()->numberBetween(1, 100_000),
            'is_read' => false,
            'is_bookmarked' => false,
            'last_page_read' => 0,
            'read_seconds' => 0,
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['is_read' => true, 'last_read_at' => now()]);
    }

    public function atPage(int $page): static
    {
        return $this->state(fn (): array => ['last_page_read' => $page, 'last_read_at' => now()]);
    }
}
