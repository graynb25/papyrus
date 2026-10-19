<?php

namespace Database\Factories;

use App\Models\ReadingSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadingSession>
 */
class ReadingSessionFactory extends Factory
{
    protected $model = ReadingSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'suwayomi_manga_id' => fake()->numberBetween(1, 100_000),
            'suwayomi_chapter_id' => fake()->numberBetween(1, 1_000_000),
            'started_at' => now(),
            'last_active_at' => now(),
            'ended_at' => null,
            'read_seconds' => 0,
            'pages_turned' => 0,
            'last_page_seen' => null,
        ];
    }

    /** Open rows accrue and close; states re-shape a definition into a past visit. */
    public function ended(int $seconds = 0, int $pages = 0): static
    {
        return $this->state(fn (): array => [
            'ended_at' => now(),
            'read_seconds' => $seconds,
            'pages_turned' => $pages,
        ]);
    }

    public function inSeries(int $mangaId, int $chapterId): static
    {
        return $this->state(fn (): array => [
            'suwayomi_manga_id' => $mangaId,
            'suwayomi_chapter_id' => $chapterId,
        ]);
    }
}
