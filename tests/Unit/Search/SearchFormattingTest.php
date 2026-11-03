<?php

namespace Tests\Unit\Search;

use App\Live\Search\SearchFormatting;
use App\Models\SearchRun;
use App\Models\Source;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The search page's pure rules, exercised without mounting the page.
 *
 * Each case is a boundary the page used to carry inside the Livewire component:
 * the language name, the eight second stalled-worker boundary, the chooser heading
 * and the two language buckets.
 */
class SearchFormattingTest extends TestCase
{
    private SearchFormatting $formatting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatting = new SearchFormatting;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_language_code_is_named_and_an_unknown_code_falls_back_to_itself(): void
    {
        $this->assertSame('English', $this->formatting->languageName('en'));
        $this->assertSame('Portuguese (Brazil)', $this->formatting->languageName('pt-BR'));
        $this->assertSame('qq-ZZ', $this->formatting->languageName('qq-ZZ'));
        $this->assertSame('', $this->formatting->languageName('  '));
    }

    public function test_a_finished_or_moving_run_is_not_stalled(): void
    {
        $this->assertFalse($this->formatting->queueStalled(null));

        $finished = new SearchRun(['status' => SearchRun::COMPLETE, 'sources_done' => 0]);
        $this->assertFalse($this->formatting->queueStalled($finished));

        $moving = new SearchRun(['status' => SearchRun::RUNNING, 'sources_done' => 1]);
        $moving->created_at = now()->subMinutes(2);
        $this->assertFalse($this->formatting->queueStalled($moving));
    }

    public function test_the_stalled_boundary_is_eight_seconds(): void
    {
        Carbon::setTestNow('2026-11-03 12:00:00');

        $seven = new SearchRun(['status' => SearchRun::RUNNING, 'sources_done' => 0]);
        $seven->created_at = now()->subSeconds(7);
        $this->assertFalse($this->formatting->queueStalled($seven));

        $eight = new SearchRun(['status' => SearchRun::RUNNING, 'sources_done' => 0]);
        $eight->created_at = now()->subSeconds(8);
        $this->assertFalse($this->formatting->queueStalled($eight), 'exactly eight seconds is not more than eight');

        $nine = new SearchRun(['status' => SearchRun::RUNNING, 'sources_done' => 0]);
        $nine->created_at = now()->subSeconds(9);
        $this->assertTrue($this->formatting->queueStalled($nine));
    }

    public function test_the_chooser_heading_is_the_first_source_title_or_null(): void
    {
        $this->assertSame('One Piece', $this->formatting->chosenTitle([
            ['manga' => 1, 'source' => 'Alpha', 'title' => 'One Piece', 'inLibrary' => false, 'cover' => null],
            ['manga' => 2, 'source' => 'Beta', 'title' => 'One Piece', 'inLibrary' => false, 'cover' => null],
        ]));

        $this->assertNull($this->formatting->chosenTitle([]));
    }

    public function test_the_language_buckets_keep_more_than_one_and_exactly_one(): void
    {
        $counts = $this->counts([
            ['lang' => 'en', 'total' => 3],
            ['lang' => 'ja', 'total' => 1],
            ['lang' => 'de', 'total' => 2],
            ['lang' => 'fr', 'total' => 1],
        ]);

        $this->assertSame(['en', 'de'], $this->formatting->languages($counts)->pluck('lang')->all());
        $this->assertSame(['ja', 'fr'], $this->formatting->rareLanguages($counts)->pluck('lang')->all());
    }

    /**
     * @param  array<int, array{lang: string, total: int}>  $rows
     * @return Collection<int, Source>
     */
    private function counts(array $rows): Collection
    {
        return new Collection(array_map(static function (array $row): Source {
            $source = new Source(['lang' => $row['lang']]);
            $source->setAttribute('total', $row['total']);

            return $source;
        }, $rows));
    }
}
