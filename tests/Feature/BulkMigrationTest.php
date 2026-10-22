<?php

namespace Tests\Feature;

use App\Jobs\BuildBulkMigration;
use App\Migration\BulkMover;
use App\Migration\BulkSourceMatcher;
use App\Migration\CandidateFinder;
use App\Migration\SeriesMigrator;
use App\Models\BulkMigration;
use App\Models\BulkMigrationItem;
use App\Models\BulkMigrationTarget;
use App\Models\MigrationSearch;
use App\Models\SeriesMigration;
use App\Models\SeriesState;
use App\Models\Source;
use App\Models\User;
use App\Support\ImageUrl;
use App\Support\Library;
use App\Support\ReadingState;
use App\Suwayomi\SeriesGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * Moving a whole shelf from one source to another.
 *
 * ## What is being asserted, and why each one earned a test
 *
 * 1. **Only the chosen destination is searched.** A reader who has picked both ends should
 *    not have 82 sources searched per series. Measured, that is 6,560 requests for eighty
 *    series where eighty will do, and the extras go to sites with no bearing on the decision.
 * 2. **A bulk match goes through the wizard's own gate.** `SeriesMigrator` refuses any
 *    destination that was not offered by a search this reader ran for that series. That is the
 *    boundary stopping a tampered request from moving a series anywhere, so the bulk flow has
 *    to satisfy it rather than grow a second way in.
 * 3. **Nothing moves without a tick.** A pre-tick is a suggestion; the move still needs the
 *    reader to press the button, and a run of eighty moves is a big thing to press once.
 * 4. **One refusal does not undo the others.** Each move is its own transaction, so a
 *    failure part way through leaves the good ones standing and reported.
 * 5. **A filed series with no source is not offered.** It cannot be, there is no source to
 *    move away from, and the library batch has to leave it out rather than cast its null.
 */
class BulkMigrationTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    private const FROM_SOURCE = '6247824327199706550';

    private const TO_SOURCE = '6247824327199706600';

    private const SECOND_TARGET = '6247824327199706700';

    /** Chapter count per manga id, on either source. */
    private array $chapterCounts = [];

    /** Title, author and source per manga id. */
    private array $meta = [];

    /** What each source's search returns, as manga id to title. */
    private array $searchHits = [];

    /** Manga the server does not know. */
    private array $unknown = [];

    /**
     * Manga the library batch can list but a single read cannot answer.
     *
     * Distinct from `$unknown` because {@see Library::entries()} drops an unknown
     * manga, so marking something unknown tests the filter rather than the matcher.
     */
    private array $failFind = [];

    /** Sources whose refresh throws a transport error. */
    private array $unreachable = [];

    private Source $from;

    private Source $to;

    /** @var array<int, string> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->from = $this->source('Asura Scans', 0, self::FROM_SOURCE);
        $this->to = $this->source('MangaFire', 900, self::TO_SOURCE);

        $this->fakeSuwayomi();
    }

    private function page(): Testable
    {
        return Livewire::test('pages::migrate-sources');
    }

    /*
     * ---------------------------------------------------------------------------
     * Reaching it, and not colliding with the wizard.
     * ---------------------------------------------------------------------------
     */

    public function test_it_requires_authentication(): void
    {
        auth()->logout();

        $this->get('/migrate/sources')->assertRedirect();
    }

    public function test_it_renders(): void
    {
        $this->get('/migrate/sources')->assertOk();
    }

    /**
     * The way back along the button that brought the reader here.
     *
     * The Sources page's "Move a whole source" button is the only way into this
     * page, and the page had no way back along it, so a reader who came to look
     * at the shelf they were holding had to go and find it again.
     *
     * The same shape the single series wizard carries, which is the house's way
     * of saying a page knows where it was reached from.
     */
    public function test_it_offers_a_way_back_to_the_sources_page(): void
    {
        $this->page()
            ->assertSee('Back to sources')
            ->assertSee(route('sources'), escape: false);
    }

    public function test_the_wizard_route_is_not_swallowed_by_it(): void
    {
        /*
         * Registration order, and it is load bearing.
         *
         * `migrate/{manga}` has no `whereNumber`, so it matches any segment. Registered after
         * the bulk route, this page would be unreachable: every request would be handed to the
         * wizard with `manga` set to the string "sources", and the wizard would try to read
         * series "sources" from the server.
         */
        $this->get('/migrate/sources')->assertOk();
        $this->get('/migrate/76')->assertOk();
    }

    public function test_the_sources_page_offers_it(): void
    {
        /*
         * An unlinked feature is not a feature. This is the only door to it other than typing
         * the URL, so if the link goes the page becomes unreachable without anyone noticing.
         */
        $this->get('/sources')
            ->assertOk()
            ->assertSee(route('migrate.sources'), escape: false);
    }

    public function test_the_target_list_offers_a_source_the_reader_holds_nothing_on(): void
    {
        /*
         * The destination is picked before anything has moved, so holding nothing on it is
         * the normal case, not a reason to hide it. When the list read the same tally as
         * the leaving list, the first move to a source with none of the reader's series
         * could not be picked at all.
         */
        $this->seriesOnFrom(76, 'One Piece', 2442);

        $this->page()
            ->assertSee('MangaFire')
            ->assertSee("toggleTarget('".self::TO_SOURCE."')", false);
    }

    /*
     * ---------------------------------------------------------------------------
     * Comparing.
     * ---------------------------------------------------------------------------
     */

    public function test_it_refuses_to_compare_with_nothing_chosen(): void
    {
        Queue::fake();

        $this->page()->call('compare');

        Queue::assertNothingPushed();
        $this->assertSame(0, BulkMigration::query()->count());
    }

    public function test_it_refuses_the_source_being_left_as_its_own_target(): void
    {
        Queue::fake();

        $this->page()
            ->set('from', self::FROM_SOURCE)
            ->call('toggleTarget', self::FROM_SOURCE)
            ->call('compare');

        Queue::assertNothingPushed();
        $this->assertSame(0, BulkMigration::query()->count());
    }

    public function test_it_refuses_to_compare_without_any_target(): void
    {
        Queue::fake();

        $this->page()
            ->set('from', self::FROM_SOURCE)
            ->call('compare');

        Queue::assertNothingPushed();
        $this->assertSame(0, BulkMigration::query()->count());
    }

    public function test_a_sixth_target_is_refused_with_its_cost(): void
    {
        /*
         * The still's own hint says fewer than five, and the refusal happens at the pick
         * rather than only at the press: the sixth click is what gets refused, so the
         * reader learns the cap's reason at the moment they hit it, and a run that does
         * happen honours exactly what the list allowed.
         */
        Queue::fake();

        foreach (['Target One', 'Target Two', 'Target Three', 'Target Four', 'Target Five', 'Target Six'] as $n => $name) {
            $this->source($name, $n + 200, '100000000000000'.($n + 1));
        }

        $this->page()
            ->set('from', self::FROM_SOURCE)
            ->call('toggleTarget', '1000000000000001')
            ->call('toggleTarget', '1000000000000002')
            ->call('toggleTarget', '1000000000000003')
            ->call('toggleTarget', '1000000000000004')
            ->call('toggleTarget', '1000000000000005')
            ->call('toggleTarget', '1000000000000006')
            ->call('compare');

        $run = BulkMigration::query()->latest('id')->first();

        $this->assertNotNull($run, 'five destinations is a legitimate run; only the sixth is refused');

        $targets = $run->targets()->orderBy('seq')->get();

        $this->assertCount(5, $targets, 'the cap is honoured in the run, not just in the toast');
        $this->assertSame('1000000000000005', $targets[4]->source_id, 'the sixth pick never entered the list');
    }

    public function test_a_run_of_two_targets_stamps_what_each_target_is(): void
    {
        /*
         * The run card names destinations from stamped rows, not the mirror table: a source
         * that leaves the mirror later must never turn a finished run into bare numbers.
         * Same reasoning as the leaving side of the same card, caught live as "Looking up
         * your series on 524110931382140732" instead of the source's name.
         */
        Queue::fake();

        $this->source('MangaTaro', 700, self::SECOND_TARGET);

        $this->page()
            ->set('from', self::FROM_SOURCE)
            ->call('toggleTarget', self::TO_SOURCE)
            ->call('toggleTarget', self::SECOND_TARGET)
            ->call('compare');

        $run = BulkMigration::query()->latest('id')->first();

        $this->assertSame('Asura Scans', $run->from_source_name);

        $targets = $run->targets()->orderBy('seq')->get();

        $this->assertCount(2, $targets);
        $this->assertSame(self::TO_SOURCE, $targets[0]->source_id);
        $this->assertSame('MangaFire', $targets[0]->source_name);
        $this->assertSame(self::SECOND_TARGET, $targets[1]->source_id);
        $this->assertSame('MangaTaro', $targets[1]->source_name);
    }

    public function test_a_target_with_no_mirror_row_stamps_the_id_instead(): void
    {
        /*
         * A leaving source can hold series with no source row at all (the numeric wart in
         * the leaving list) and a target can share it. The id it stamps is legible on its
         * own, and the run card then agrees with the row rather than inventing a name.
         */
        Queue::fake();

        $this->page()
            ->set('from', '7777777777777777')
            ->call('toggleTarget', self::TO_SOURCE)
            ->call('toggleTarget', '9876543210987654')
            ->call('compare');

        $run = BulkMigration::query()->latest('id')->first();

        $this->assertSame('7777777777777777', $run->from_source_name);

        $targets = $run->targets()->orderBy('seq')->get();

        $this->assertSame('MangaFire', $targets[0]->source_name);
        $this->assertSame('9876543210987654', $targets[1]->source_id, 'the id is the stamp when the mirror never carried a row for it');
        $this->assertNull($targets[1]->source_name);
    }

    public function test_comparing_dispatches_the_job_for_the_signed_in_reader(): void
    {
        Queue::fake();

        $this->page()
            ->set('from', self::FROM_SOURCE)
            ->call('toggleTarget', self::TO_SOURCE)
            ->call('compare');

        $this->assertSame(1, BulkMigration::query()->forUser($this->user->id)->count());

        Queue::assertPushed(BuildBulkMigration::class, function (BuildBulkMigration $job): bool {
            return $job->userId === (int) $this->user->id
                && $job->runId === (int) BulkMigration::query()->value('id');
        });
    }

    /**
     * The page as a reader arriving from the sources page sees it: composed from one source.
     */
    private function composePage(string $sourceId = self::FROM_SOURCE): Testable
    {
        return Livewire::withQueryParams(['compose' => $sourceId])
            ->test('pages::migrate-sources');
    }

    /*
     * -------------------------------------------------------------------------
     * Composing.
     * -------------------------------------------------------------------------
     */

    public function test_the_compose_param_that_the_mirror_never_carried_goes_unread(): void
    {
        /*
         * A query string is a reader's control, not a fact. An id the mirror never
         * carried cannot be honoured, and the page must not sit in a half composed
         * state: a picked shelf named "Series on" above a leaving picker it quietly
         * disagrees with is how a run ends up moving what nobody looked at.
         */
        $this->seriesOnFrom(76, 'Alpha Road', 30);

        $this->composePage('9876543210987654')
            ->assertDontSee('Tick the series you are moving', false);
    }

    public function test_a_compose_source_the_mirror_knows_takes_the_page_over(): void
    {
        $this->seriesOnFrom(76, 'Alpha Road', 30);

        $this->composePage()
            ->assertSee('Move series off Asura Scans.')
            ->assertSee('Every series you have saved from Asura Scans is listed below.')
            ->assertSee('Tick the series you are moving', false);
    }

    public function test_compose_lists_the_reader_s_series_on_that_source(): void
    {
        $this->seriesOnFrom(76, 'Alpha Road', 30);
        $this->seriesOnFrom(77, 'Beta Hill', 12);
        $this->seriesElsewhere(3000, 'Not Listed', 8, self::TO_SOURCE);

        $this->composePage()
            ->assertSee('Alpha Road')
            ->assertSee('Beta Hill')
            ->assertSee('togglePick(76)', false)
            ->assertDontSee('togglePick(3000)', false);
    }

    public function test_picking_counts_on_the_compose_panel(): void
    {
        $this->seriesOnFrom(76, 'Alpha Road', 30);
        $this->seriesOnFrom(77, 'Beta Hill', 12);

        $this->composePage()
            ->assertSee('0 of 2 picked')
            ->call('togglePick', 76)
            ->assertSee('1 of 2 picked')
            ->call('togglePick', 77)
            ->assertSee('2 of 2 picked')
            ->call('togglePick', 77)
            ->assertSee('1 of 2 picked');
    }

    public function test_a_composed_run_carries_the_picked_series_and_its_targets(): void
    {
        Queue::fake();

        $this->source('MangaTaro', 700, self::SECOND_TARGET);
        $this->seriesOnFrom(76, 'Alpha Road', 30);
        $this->seriesOnFrom(77, 'Beta Hill', 12);

        $this->composePage()
            ->call('togglePick', 76)
            ->call('toggleTarget', self::TO_SOURCE)
            ->call('compare');

        $run = BulkMigration::query()->latest('id')->first();

        $this->assertNotNull($run);
        $this->assertTrue($run->isPicked(), 'the run knows it owns a picked subset, not the shelf');
        $this->assertSame([76], $run->picked_manga_ids, 'ids stamped at pick time, not titles');
        $this->assertSame(self::TO_SOURCE, $run->targets()->first()?->source_id);
        $this->assertSame(0, BulkMigrationTarget::query()->where('bulk_migration_id', $run->id)->count() - 1);
    }

    public function test_a_composed_start_refuses_to_run_with_nothing_picked(): void
    {
        Queue::fake();

        $this->seriesOnFrom(76, 'Alpha Road', 30);

        $this->composePage()
            ->call('toggleTarget', self::TO_SOURCE)
            ->call('compare');

        Queue::assertNothingPushed();
        $this->assertSame(0, BulkMigration::query()->count());
    }

    /**
     * The stop value belongs to the finished run like every other page state, because a
     * reset that leaves it makes the field lie: in the browser one press rode a typed 1,
     * the field then showed 0 for the next press, and the next run still stopped at 1.
     */
    public function test_the_stop_value_resets_with_the_rest_of_the_page_state(): void
    {
        Queue::fake();

        $this->seriesOnFrom(76, 'Alpha Road', 30);

        $this->composePage()
            ->call('togglePick', 76)
            ->call('toggleTarget', self::TO_SOURCE)
            ->set('limit', 1)
            ->call('compare')
            ->assertSet('limit', 0);
    }

    public function test_a_picked_series_that_left_the_source_since_the_pick_is_not_compared(): void
    {
        /*
         * The pick happened at one moment; the run happens later. A series that left the
         * source between the two is simply not compared, and the run says what it owns
         * plainly rather than reporting the shelf's counts against a pick's name.
         */
        $this->seriesOnFrom(76, 'Alpha Road', 30);
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Alpha Road'];

        $run = BulkMigration::createFor($this->user->id, [
            'from_source_id' => self::FROM_SOURCE,
            'picked_manga_ids' => [777],
            'state' => BulkMigration::RUNNING,
            'started_at' => now(),
        ]);

        BulkMigrationTarget::createFor($this->user->id, [
            'bulk_migration_id' => $run->getKey(),
            'source_id' => self::TO_SOURCE,
            'source_name' => 'MangaFire',
            'lang' => 'en',
            'seq' => 0,
        ]);

        $this->matcher()->run($run);

        $fresh = $run->fresh();

        $this->assertSame(BulkMigration::READY, $fresh->state);
        $this->assertSame('None of the picked series is on that source any more.', $fresh->error);
        $this->assertSame([], $this->sourcesSearched(), 'nothing to compare, so no search leaves');
    }

    /*
     * -------------------------------------------------------------------------
     * The numbers a row carries.
     * -------------------------------------------------------------------------
     */

    public function test_a_comparison_row_stamps_the_read_progress_it_left_behind(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 44, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        /*
         * Ten of the 41 read, which is the number deciding per candidate would be: the
         * read rows are counted once the compare is about to write, not looked up again
         * for every row, and the stamp is what a later render reads so the number a
         * reader sees is the number the compare knew.
         */
        $state = app(ReadingState::class);

        for ($n = 1; $n <= 10; $n++) {
            $state->markRead($this->user->id, 76, 76 * 1000 + $n, true);
        }

        $this->matcher()->run($this->startRun());

        $item = BulkMigrationItem::query()->firstOrFail();

        $this->assertSame(31, $item->unread_here);
    }

    public function test_a_series_the_server_never_counted_stamps_no_number(): void
    {
        /*
         * Three states, not two: a chapter list the server never fetched is not zero
         * unread. Stamping 0 there would tell a reader their 400 chapter backlog is
         * caught up on a technicality.
         */
        $this->meta[78] = ['title' => 'Never Counted', 'author' => null, 'source_id' => self::FROM_SOURCE, 'source_name' => 'Asura Scans'];

        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => 78,
            'in_library' => true,
            'title' => 'Never Counted',
        ]);

        $this->destination(2088, 'Never Counted', 41, null);
        $this->searchHits[self::TO_SOURCE] = [2088 => 'Never Counted'];

        $this->matcher()->run($this->startRun());

        $item = BulkMigrationItem::query()->firstOrFail();

        $this->assertNull($item->unread_here);
    }

    public function test_an_offer_row_says_what_the_reader_leaves_behind(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 44, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $state = app(ReadingState::class);

        for ($n = 1; $n <= 10; $n++) {
            $state->markRead($this->user->id, 76, 76 * 1000 + $n, true);
        }

        $this->matcher()->run($this->startRun());

        $this->page()
            ->assertSee('31 unread chapters.')
            ->assertSee('Latest: Chapter 41');
    }

    public function test_the_compose_rows_say_how_much_is_read(): void
    {
        $state = app(ReadingState::class);

        $this->seriesOnFrom(76, 'Alpha Road', 30);

        for ($n = 1; $n <= 12; $n++) {
            $state->markRead($this->user->id, 76, 76 * 1000 + $n, true);
        }

        $this->composePage()
            ->assertSee('12 of 30 read');
    }

    /*
     * ---------------------------------------------------------------------------
     * Matching.
     * ---------------------------------------------------------------------------
     */

    public function test_it_finds_the_readers_series_on_the_source_they_are_leaving(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);
        $this->seriesOnFrom(77, 'The Berserkers Second Playthrough', 1);
        $this->seriesElsewhere(3000, 'Some Other Series', 10, '7777777777777777');

        $tally = $this->matcher()->run($this->startRun());

        $this->assertSame(2, $tally['total'], 'the series on another source is not counted');
    }

    public function test_it_compares_only_the_chosen_destination(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        // A third source with the same series on it, which must never be searched.
        $other = $this->source('Lagoon Scans', 800, '6247824327199706800');
        $this->searchHits[(string) $other->suwayomi_source_id] = [2034 => 'Hiding a Logistics Center'];

        $this->matcher()->run($this->startRun());

        $searched = $this->sourcesSearched();

        $this->assertContains(self::TO_SOURCE, $searched);
        $this->assertNotContains('6247824327199706800', $searched, 'a source nobody chose was searched');
    }

    public function test_it_records_the_measured_comparison_per_series(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $this->matcher()->run($this->startRun());

        $item = BulkMigrationItem::query()->firstOrFail();

        $this->assertSame(76, $item->from_manga_id);
        $this->assertSame(2033, $item->to_manga_id);
        $this->assertSame(41, $item->chapters_here);
        $this->assertSame(41, $item->chapters_there);
        $this->assertSame(0, $item->chapter_delta);
        $this->assertSame(1.0, $item->author_overlap);
        $this->assertSame(BulkMigrationItem::PENDING, $item->status);
    }

    public function test_a_targeted_run_searches_every_target_and_only_the_targets(): void
    {
        /*
         * The search fans out per target per series, which is exactly the cost the still's
         * own hint warns about. The boundary the run owes the reader is that exactly the
         * picked list is searched: no other installed source, and no mirror row that
         * merely exists.
         */
        $this->source('MangaTaro', 700, self::SECOND_TARGET);
        $this->source('Never Picked', 800, '1000000000000009');

        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];
        $this->searchHits[self::SECOND_TARGET] = [3007 => 'Hiding a Logistics Center'];

        $this->matcher()->run($this->startTargetedRun(self::TO_SOURCE, self::SECOND_TARGET));

        $searched = $this->sourcesSearched();

        $this->assertContains(self::TO_SOURCE, $searched);
        $this->assertContains(self::SECOND_TARGET, $searched);
        $this->assertNotContains('1000000000000009', $searched, 'an installed source nobody picked is not fan out noise');
    }

    public function test_a_targeted_run_ranks_candidates_across_targets_by_the_measured_ordering(): void
    {
        /*
         * Which target produced the offer decides which card the reader presses, so the
         * winner is not the order the boxes were ticked in. Both targets landed the same
         * title: one exact on chapters, one three ahead, sitting on the target that was
         * picked second. The item row stamps which source won, so a round through the
         * mover never depends on the mirror still carrying that row.
         */
        $this->source('MangaTaro', 700, self::SECOND_TARGET);

        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');

        $this->destination(2033, 'Hiding a Logistics Center', 44, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $this->meta[3007] = [
            'title' => 'Hiding a Logistics Center',
            'author' => 'Life Ruined',
            'source_id' => self::SECOND_TARGET,
            'source_name' => 'MangaTaro',
        ];
        $this->chapterCounts[3007] = 41;
        $this->searchHits[self::SECOND_TARGET] = [3007 => 'Hiding a Logistics Center'];

        $this->matcher()->run($this->startTargetedRun(self::SECOND_TARGET, self::TO_SOURCE));

        $item = BulkMigrationItem::query()->firstOrFail();

        $this->assertSame(3007, $item->to_manga_id, '41 of 41 outranks 44 of 41, whichever target it sat on');
        $this->assertSame(self::SECOND_TARGET, $item->to_source_id);
        $this->assertSame('MangaTaro', $item->to_source_name);
    }

    public function test_a_targeted_run_compared_by_a_worker_sees_its_targets_without_a_logged_in_reader(): void
    {
        /*
         * The queue worker is where these runs are compared, and it holds no authenticated
         * reader. OwnedByUser's answer to no reader is zero rows, so a read through the
         * relation inside the job returned every destination row as nothing and the run
         * searched none of its own picks: live, a list shaped run was refused naming the
         * leaving side and a replica skipped a series a target did hold, both without a
         * destination named. The job resolves the run by key through forUser, so every
         * read past that point is already one reader's row set, and the destinations ride
         * that same fact.
         */
        $this->source('MangaTaro', 700, self::SECOND_TARGET);

        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $run = $this->startTargetedRun(self::TO_SOURCE, self::SECOND_TARGET);

        Auth::logout();

        $this->matcher()->run($run);

        $searched = $this->sourcesSearched();
        $this->assertContains(self::TO_SOURCE, $searched, 'the picked destinations are searched even with no reader logged in');
        $this->assertContains(self::SECOND_TARGET, $searched);

        $item = BulkMigrationItem::forUser($this->user->id)->firstOrFail();

        $this->assertSame(BulkMigrationItem::PENDING, $item->status, 'a 41 of 41 match does not become a skip because the context went readerless');
        $this->assertSame(self::TO_SOURCE, $item->to_source_id);
        $this->assertSame('MangaFire', $item->to_source_name);

        $fresh = $run->fresh();
        $this->assertSame(BulkMigration::READY, $fresh->state);
        $this->assertNull($fresh->error);
    }

    public function test_a_target_the_catalogue_no_longer_lists_fails_naming_it(): void
    {
        /*
         * A pick can be gone from the mirror by the time the job reaches it: the reader
         * picked it on the box list, the server no longer serves it. The run refuses
         * naming that pick as itself rather than dying on a row that reads as the leaving
         * side's fault.
         */
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);

        $run = $this->startTargetedRun('9876543210987654');
        $this->matcher()->run($run);

        $fresh = $run->fresh();

        $this->assertSame(BulkMigration::FAILED, $fresh->state);
        $this->assertStringContainsString('9876543210987654', (string) $fresh->error, 'the vanished pick is named, rather than being read as the leaving side');
        $this->assertSame([], $this->sourcesSearched(), 'a vanished target is refused before any search leaves');
    }

    public function test_a_target_that_is_not_installed_fails_naming_it(): void
    {
        $ghost = $this->source('Ghost Scans', 750, '1000000000000008');
        $ghost->update(['is_installed' => false]);

        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);

        $run = $this->startTargetedRun('1000000000000008');
        $this->matcher()->run($run);

        $fresh = $run->fresh();

        $this->assertSame(BulkMigration::FAILED, $fresh->state);
        $this->assertStringContainsString('Ghost Scans', (string) $fresh->error, 'the stamp the target row carries is what the error names');
        $this->assertSame([], $this->sourcesSearched());
    }

    public function test_a_mixed_target_list_is_refused_before_any_series_is_searched(): void
    {
        /*
         * One vanished pick among five is not four searches' worth of a run: every series
         * would come back missing destinations the reader asked for by name. The run
         * fails outright, before any request leaves for any series.
         */
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $run = $this->startTargetedRun(self::TO_SOURCE, '9876543210987654');
        $this->matcher()->run($run);

        $this->assertSame(BulkMigration::FAILED, $run->fresh()->state);
        $this->assertSame(0, BulkMigrationItem::query()->where('bulk_migration_id', $run->id)->count());
        $this->assertSame([], $this->sourcesSearched());
    }

    public function test_an_older_run_compared_before_the_target_list_still_matches(): void
    {
        /*
         * Runs 1 to 7 carry one destination in two columns and no target rows at all. The
         * matcher reads the list when it exists and the columns when it does not, so an
         * old run reopened in this app still walks what the reader of its day picked.
         */
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 44, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $this->matcher()->run($this->startRun());

        $this->assertSame([self::TO_SOURCE], $this->sourcesSearched(), 'the legacy columns are the target list when no rows exist');

        $item = BulkMigrationItem::query()->firstOrFail();

        $this->assertSame(2033, $item->to_manga_id);
        $this->assertSame(self::TO_SOURCE, $item->to_source_id, 'the legacy column is what the fallback path stamps');
        $this->assertSame('MangaFire', $item->to_source_name);
    }

    public function test_a_series_the_destination_does_not_have_is_not_offered(): void
    {
        $this->seriesOnFrom(76, 'Something Obscure', 12);
        $this->searchHits[self::TO_SOURCE] = [];

        $this->matcher()->run($this->startRun());

        $item = BulkMigrationItem::query()->firstOrFail();

        $this->assertSame(BulkMigrationItem::SKIPPED, $item->status);
        $this->assertNull($item->to_manga_id, 'nothing to move to, so nothing is offered');
    }

    public function test_a_series_that_fails_to_read_is_recorded_and_the_run_continues(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $this->seriesOnFrom(78, 'Something That Breaks', 5);

        /*
         * Readable in the library batch, unreadable one at a time.
         *
         * `$unknown` would not do, and testing it first is what showed this: `Library::entries()`
         * leaves out a manga the server does not know, so such a series was never a candidate
         * and the run correctly had nothing to be resilient about. The failure has to arrive
         * somewhere the library can see.
         */
        $this->failFind[] = 78;

        $tally = $this->matcher()->run($this->startRun());

        $this->assertSame(2, $tally['checked'], 'the series that failed did not stop the rest');

        $failed = BulkMigrationItem::query()->where('status', BulkMigrationItem::FAILED)->firstOrFail();

        $this->assertSame(78, $failed->from_manga_id);
        $this->assertNotNull($failed->reason);
    }

    public function test_a_destination_that_is_not_installed_fails_before_searching(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);
        $this->to->update(['is_installed' => false]);

        $run = $this->startRun();
        $this->matcher()->run($run);

        $this->assertSame(BulkMigration::FAILED, $run->fresh()->state);
        $this->assertSame([], $this->sourcesSearched(), 'an uninstalled source is not searched');
    }

    public function test_a_source_the_library_has_nothing_on_says_so_rather_than_reporting_zero(): void
    {
        $this->seriesElsewhere(3000, 'Some Other Series', 10, '7777777777777777');

        $run = $this->startRun();
        $this->matcher()->run($run);

        $this->assertSame(BulkMigration::READY, $run->fresh()->state);
        $this->assertNotNull($run->error);
    }

    public function test_it_stops_at_the_limit_and_says_that_it_did(): void
    {
        foreach ([76, 77, 78, 79] as $id) {
            $this->seriesOnFrom($id, "Series {$id}", 10);
        }

        $run = $this->startRun();
        $this->matcher()->run($run, limit: 2);

        $this->assertSame(4, $run->fresh()->series_total, 'all four are on the source');
        $this->assertSame(2, $run->fresh()->series_checked, 'only two were compared');
        $this->assertStringContainsString('stopped there', (string) $run->fresh()->error);
    }

    public function test_the_run_is_left_reading_running_when_the_job_dies(): void
    {
        $run = $this->startRun();

        $this->assertFalse($run->isStalled());

        $run->forceFill(['updated_at' => now()->subHours(1)])->save();

        $this->assertTrue($run->fresh()->isStalled(), 'so a page can stop waiting for it');
    }

    /*
     * ---------------------------------------------------------------------------
     * The gate the wizard already draws.
     * ---------------------------------------------------------------------------
     */

    public function test_a_bulk_match_is_accepted_by_the_wizards_own_verifier(): void
    {
        /*
         * The whole reason `BulkSourceMatcher` writes a `migration_searches` row.
         *
         * `SeriesMigrator::offeredCandidate()` refuses any destination that was not offered by
         * a search this reader ran for this series. If the bulk flow did not satisfy that, the
         * only ways forward would be to trust the browser or to add a second way into the
         * migrator, and both are worse than writing the row.
         */
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $this->matcher()->run($this->startRun());

        $search = MigrationSearch::query()->where('from_manga_id', 76)->firstOrFail();

        $this->assertSame(MigrationSearch::READY, $search->state);
        $this->assertSame(2033, (int) $search->candidates[0]['manga_id']);
    }

    public function test_the_migrator_still_refuses_a_destination_the_search_never_offered(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $this->matcher()->run($this->startRun());

        $outcome = app(SeriesMigrator::class)->migrate($this->user, 76, 999999);

        $this->assertFalse($outcome['ok'], 'a destination the search did not offer is still refused');
    }

    /*
     * ---------------------------------------------------------------------------
     * Approval.
     * ---------------------------------------------------------------------------
     */

    public function test_an_exact_match_on_every_signal_is_already_ticked(): void
    {
        $item = $this->offerable(chaptersHere: 41, chaptersThere: 41, similarity: 1.0, overlap: 1.0);

        $this->page()->instance()->isTicked($item);
        $this->assertTrue($item->isConfident());
    }

    public function test_a_chapter_count_that_disagrees_is_not_already_ticked(): void
    {
        /*
         * The chapter count is the load bearing signal, not the title.
         *
         * Measured, five of six real candidates for one series tied at a title similarity of
         * 1.000 while their chapter counts ran from 21 to 333. A title alone cannot separate
         * them, so a row that matches on title and disagrees on chapters is not the same
         * series and must not be ticked without a reader saying so.
         */
        $item = $this->offerable(chaptersHere: 41, chaptersThere: 333, similarity: 1.0, overlap: 1.0);

        $this->assertFalse($item->isConfident());
    }

    public function test_a_different_author_is_not_already_ticked(): void
    {
        $this->assertFalse($this->offerable(chaptersHere: 41, chaptersThere: 41, similarity: 1.0, overlap: 0.0)->isConfident());
    }

    public function test_an_author_nobody_credits_is_not_treated_as_a_disagreement(): void
    {
        /*
         * `author_overlap` is null whenever either side credits nobody, and a self hosted
         * install has plenty of sources that credit nobody. Treating that as a mismatch would
         * leave almost nothing pre-ticked on exactly the installs least able to check.
         */
        $this->assertTrue($this->offerable(chaptersHere: 41, chaptersThere: 41, similarity: 1.0, overlap: null)->isConfident());
    }

    public function test_a_series_with_no_chapter_count_is_not_already_ticked(): void
    {
        $item = $this->offerable(chaptersHere: null, chaptersThere: 41, similarity: 1.0, overlap: 1.0);

        $this->assertFalse($item->isConfident());
    }

    public function test_a_weak_title_is_not_already_ticked(): void
    {
        $this->assertFalse($this->offerable(chaptersHere: 41, chaptersThere: 41, similarity: 0.8, overlap: 1.0)->isConfident());
    }

    public function test_a_row_the_reader_has_not_touched_uses_the_measured_default(): void
    {
        $item = $this->offerable(chaptersHere: 41, chaptersThere: 41, similarity: 1.0, overlap: 1.0);
        $component = $this->page();

        $this->assertTrue($component->instance()->isTicked($item));

        $component->set('approved', [$item->from_manga_id => false]);

        $this->assertFalse(
            $component->instance()->isTicked($item->fresh()),
            'a deliberate untick is different from a row nobody touched',
        );
    }

    public function test_an_unticked_default_is_not_in_the_approvals(): void
    {
        $item = $this->offerable(chaptersHere: 41, chaptersThere: 333, similarity: 1.0, overlap: 1.0);
        $run = $this->startRun();
        $item->update(['bulk_migration_id' => $run->id]);
        $run->update(['state' => BulkMigration::READY]);

        $component = $this->page();

        $this->assertTrue(
            $component->instance()->effectiveApprovals()->isEmpty(),
            'a row the reader has to tick is not queued for moving',
        );
    }

    public function test_ticking_nothing_moves_nothing(): void
    {
        $run = $this->readyRunWithNoOffers();

        $tally = app(BulkMover::class)->move($run, collect());

        $this->assertSame(0, $tally['moved']);
        $this->assertSame(0, $tally['refused']);
    }

    /*
     * ---------------------------------------------------------------------------
     * Moving.
     * ---------------------------------------------------------------------------
     */

    public function test_an_approved_series_moves(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $run = $this->startRun();
        $this->matcher()->run($run);

        $tally = app(BulkMover::class)->move($run, collect([76 => 2033]));

        $this->assertSame(1, $tally['moved']);
        $this->assertSame(0, $tally['refused']);
        $this->assertSame(1, SeriesMigration::query()->count());
        $this->assertSame(2033, (int) SeriesMigration::query()->value('to_manga_id'));

        $this->assertSame(
            BulkMigrationItem::MOVED,
            BulkMigrationItem::query()->value('status'),
            'and the item says what happened',
        );
    }

    public function test_a_pair_that_was_not_offered_is_not_moved(): void
    {
        $run = $this->readyRunWithNoOffers();

        $item = BulkMigrationItem::createFor($this->user->id, [
            'bulk_migration_id' => $run->id,
            'from_manga_id' => 76,
            'to_manga_id' => 2033,
            'title' => 'Something',
            'status' => BulkMigrationItem::PENDING,
        ]);

        $tally = app(BulkMover::class)->move($run, collect([$item->from_manga_id => 2033]));

        $this->assertSame(0, $tally['moved'], 'the migrator refused it because no search offered it');
        $this->assertSame(BulkMigrationItem::REFUSED, $item->fresh()->status);
    }

    public function test_an_item_from_another_readers_run_is_not_moved(): void
    {
        $run = $this->readyRunWithNoOffers();

        $other = User::factory()->create();

        BulkMigrationItem::createFor($other->id, [
            'bulk_migration_id' => $run->id,
            'from_manga_id' => 76,
            'to_manga_id' => 2033,
            'title' => 'Not yours',
            'status' => BulkMigrationItem::PENDING,
        ]);

        $tally = app(BulkMover::class)->move($run, collect([76 => 2033]));

        $this->assertSame(0, $tally['moved']);
    }

    public function test_a_series_that_already_moved_is_not_moved_twice(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $run = $this->startRun();
        $this->matcher()->run($run);

        app(BulkMover::class)->move($run, collect([76 => 2033]));
        $second = app(BulkMover::class)->move($run, collect([76 => 2033]));

        $this->assertSame(0, $second['moved'], 'a second press of the same button does nothing');
        $this->assertSame(1, SeriesMigration::query()->count());
    }

    public function test_one_refusal_does_not_undo_the_moves_that_succeeded(): void
    {
        /*
         * One transaction per series, deliberately.
         *
         * A run of eighty moves is not one atomic operation and should not pretend to be. A
         * reader whose read state moved for some of their series and not the rest has no way
         * to tell, and cannot undo the ones that did move.
         */
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $run = $this->startRun();
        $this->matcher()->run($run);

        $tally = app(BulkMover::class)->move($run, collect([76 => 2033, 999 => 1234]));

        $this->assertSame(1, $tally['moved']);
        $this->assertSame(1, $tally['refused']);
        $this->assertSame(1, SeriesMigration::query()->count(), 'the good move stands');
    }

    /*
     * ---------------------------------------------------------------------------
     * What the page shows.
     * ---------------------------------------------------------------------------
     */

    public function test_the_page_lists_the_sources_holding_the_readers_series(): void
    {
        $this->seriesOnFrom(76, 'One', 10);
        $this->seriesOnFrom(77, 'Two', 10);
        $this->seriesElsewhere(3000, 'Three', 10, '7777777777777777');

        $this->page()
            ->assertSee('Asura Scans')
            /*
             * The mirror has no row for this id, but the problem is not nameless: the lens
             * carries the name the live rows gave it, so the reader sees "Somewhere Else"
             * rather than a number that means nothing. Measured against the stills' two
             * numeric rows, Papyrus prints a numeral only when neither the mirror nor the
             * server had a name, which the page then says plainly.
             */
            ->assertSee('Somewhere Else');

        /*
         * A source with nothing of the reader's on it is not a place to leave: a run out of
         * it can only produce "nothing in your library is on that source". It remains a fine
         * destination (the reader picks the source their library is about to live on), so
         * the refusal is scoped to the leaving menu rather than to the whole page.
         */
        $this->page()->assertDontSee('$set(\'from\', \''.self::TO_SOURCE.'\')');
    }

    public function test_the_holdings_tally_counts_per_source_and_joins_the_mirror(): void
    {
        $this->seriesOnFrom(76, 'One', 10);
        $this->seriesOnFrom(77, 'Two', 10);
        $this->seriesElsewhere(3000, 'Three', 10, self::TO_SOURCE);

        $rows = app(Library::class)->holdingsBySource($this->user->id)->values();

        $this->assertCount(2, $rows);

        $this->assertSame(self::FROM_SOURCE, $rows[0]['source_id']);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertSame('Asura Scans', $rows[0]['display_name']);
        $this->assertSame('en', $rows[0]['lang']);
        $this->assertTrue($rows[0]['installed']);

        $this->assertSame(1, $rows[1]['count']);
        $this->assertTrue($rows[1]['installed']);
    }

    public function test_a_source_without_a_mirror_row_takes_its_name_from_the_live_rows(): void
    {
        /*
         * Measured shape from IMG_1179 on 2026-10-17: one of its two numeral rows belongs to
         * a mirror row Papyrus synced (there rendered by name), the other has no mirror row
         * at all. The reader's own series do not lose their place because the mirror missed
         * a row, and the lens carries the name the server gave it, so that is where the name
         * comes from rather than a numeral.
         */
        $this->seriesElsewhere(4000, 'The Series Itself', 30, '9876543210987654');

        $row = app(Library::class)->holdingsBySource($this->user->id)
            ->firstWhere('source_id', '9876543210987654');

        $this->assertIsArray($row);
        $this->assertSame('Somewhere Else', $row['display_name']);
        $this->assertTrue($row['named']);
        $this->assertFalse($row['installed']);
        $this->assertSame(1, $row['count']);
    }

    public function test_the_holdings_tally_orders_by_count_descending(): void
    {
        $this->seriesOnFrom(76, 'One', 10);
        $this->seriesElsewhere(3000, 'Three', 10, self::TO_SOURCE);
        $this->seriesOnFrom(77, 'Two', 10);

        $rows = app(Library::class)->holdingsBySource($this->user->id)->values();

        $this->assertSame(self::FROM_SOURCE, $rows[0]['source_id'], '2 holdings outrank 1, whichever the lens ordered them in first.');
        $this->assertSame(self::TO_SOURCE, $rows[1]['source_id']);
    }

    public function test_the_page_says_so_when_nothing_in_the_library_has_a_source(): void
    {
        $this->page()->assertSee(__('Nothing in your library is on a source this server knows about'));
    }

    public function test_the_page_offers_the_moves_and_counts_them(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $run = $this->startRun();
        $this->matcher()->run($run);

        $this->page()
            ->assertSee('Hiding a Logistics Center')
            ->assertSee('Same as here: 41 chapters.')
            ->assertSee('Same author')
            ->assertSee(__('Move the ticked series'))

            // Both ends pictured, the way the wizard's rows answer the identity question.
            ->assertSee((string) ImageUrl::thumbnail(76, ImageUrl::defaultFormat()))
            ->assertSee((string) ImageUrl::thumbnail(2033, ImageUrl::defaultFormat()));
    }

    public function test_the_series_with_nowhere_to_go_are_cards_with_the_two_exits(): void
    {
        $this->seriesOnFrom(76, 'Something Obscure', 12);
        $this->searchHits[self::TO_SOURCE] = [];

        $this->matcher()->run($this->startRun());

        $this->page()
            ->assertSee('Something Obscure')
            ->assertSee(__('No alternatives found'))
            ->assertSee(__('Search manually'))
            ->assertSee(__("Don't migrate"))
            ->assertSee('One series with nowhere to go.');
    }

    public function test_a_set_aside_row_hides_from_the_cards_and_the_run_still_counts_it(): void
    {
        $this->seriesOnFrom(76, 'Something Obscure', 12);
        $this->searchHits[self::TO_SOURCE] = [];

        $this->matcher()->run($this->startRun());

        $this->page()
            ->assertSee('Something Obscure')
            ->call('setAsideItem', 76)
            ->assertDontSee('Something Obscure')
            ->assertSee('You set one aside. It is still on the old source.')

            // The run counted it; the cards are the thing that hid, not the total.
            ->assertSee('One series with nowhere to go.');
    }

    /**
     * A multi destination run names the destination that produced each row.
     *
     * A row matched on one of several destinations and told "on the new source" answers
     * no reader's question about which one. The row's own stamp carries the name, and
     * nothing else on the card emits it, so the name in the render is the assertion. The
     * second destination staying absent is the per row half: a name borrowed from the
     * run would stamp both rows.
     */
    public function test_a_multi_target_card_names_the_destination_that_produced_each_row(): void
    {
        $this->source('MangaTaro', 700, self::SECOND_TARGET);
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);
        $this->destination(2033, 'Hiding a Logistics Center', 41);
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];
        $this->searchHits[self::SECOND_TARGET] = [];

        $this->matcher()->run($this->startTargetedRun(self::TO_SOURCE, self::SECOND_TARGET));

        $this->page()
            ->assertSee('on MangaFire')
            ->assertDontSee('on MangaTaro');
    }

    /**
     * A row from before destinations carried names borrows the run's own.
     *
     * Batch one stamped the destination id on the item and never a name, so for the runs
     * before the compose screen the run's own columns were the only name anywhere; a
     * row that ignored them would render "on" with nothing behind it.
     */
    public function test_a_row_without_a_name_of_its_own_borrows_the_run_s_single_destination(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);
        $this->destination(2033, 'Hiding a Logistics Center', 41);
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $run = $this->startRun();
        $this->matcher()->run($run);

        // What the runs before the compose screen were stamped with: the destination
        // named once on the run, the item carrying only the id.
        $run->items()->update(['to_source_name' => null]);
        $run->update(['to_source_name' => 'MangaFire']);

        $this->page()
            ->assertSee('on MangaFire');
    }

    /**
     * The earlier comparisons list speaks for runs on both shapes.
     *
     * A list run leaves its single destination columns empty, and an entry that read
     * them rendered "MangaFire (JA) to , 0 matched" in the browser: the from side named,
     * the destinations blank, the count beside them. The list reads the same naming seam
     * the card reads, so the two cannot drift apart.
     */
    public function test_the_earlier_comparisons_list_names_the_destinations_of_a_multi_target_run(): void
    {
        $this->source('MangaTaro', 700, self::SECOND_TARGET);
        $runA = $this->startTargetedRun(self::TO_SOURCE, self::SECOND_TARGET);
        $runA->update(['from_source_name' => 'Asura Scans']);

        $this->startRun();

        $this->page()
            ->assertSee('MangaFire, MangaTaro')
            ->assertSee('Asura Scans')
            ->assertSee('0 matched');
    }

    /**
     * Naming a run's destinations does not depend on a reader being logged in.
     *
     * The scope on the target rows fails closed with no authenticated user, which is
     * how the matcher compared against zero destinations in the queue worker; the run
     * itself pins the rows, so the naming read drops the scope the same deliberate way.
     */
    public function test_a_run_names_its_destinations_without_a_logged_in_reader(): void
    {
        $this->source('MangaTaro', 700, self::SECOND_TARGET);

        $run = $this->startTargetedRun(self::TO_SOURCE, self::SECOND_TARGET);

        Auth::logout();

        $this->assertSame(['MangaFire', 'MangaTaro'], $run->destinationNames());
    }

    /**
     * The cover hairline is the run's in flight mark: a row whose compare has landed
     * carries the cover atom's progress treatment at the bottom edge of its own cover,
     * and the strip goes the moment the run settles, because progress that outlives
     * its run is noise the reader stops trusting. The placement is the owner's call of
     * 2026-10-18; the stills resolve their rows mid run and pin no edge themselves.
     */
    public function test_a_comparing_run_marks_each_landed_row_at_the_cover_s_edge(): void
    {
        $run = $this->startRun();

        BulkMigrationItem::createFor($this->user->id, [
            'bulk_migration_id' => $run->getKey(),
            'from_manga_id' => 76,
            'to_manga_id' => 2033,
            'title' => 'Hiding a Logistics Center',
            'to_title' => 'Hiding a Logistics Center',
            'chapters_here' => 41,
            'chapters_there' => 41,
            'title_similarity' => 1.0,
            'chapter_delta' => 0,
            'author_overlap' => 1.0,
            'status' => BulkMigrationItem::PENDING,
        ]);

        $this->page()
            ->assertSee('cover-progress')
            ->assertDontSee('approved.76');

        $run->update(['state' => BulkMigration::READY, 'finished_at' => now()]);

        $this->page()
            ->assertSee('approved.76')
            ->assertDontSee('cover-progress');
    }

    /**
     * The flight is a readout, not a decision. Rows appear as their compares land so
     * the mark that says "this one is accounted for" has somewhere to live, and
     * everything that acts waits for the run to settle: the tick rides nothing mid
     * run, the offer bar stays folded, and the dead end cards keep the decisions
     * company, because a dead end is a question the run must be over to answer.
     *
     * The mark rides the cover atom's own progress treatment (the seam the library
     * grid already pays for) rather than a second copy at the row.
     */
    public function test_the_flight_renders_landed_rows_but_not_acts(): void
    {
        $run = $this->startRun();

        BulkMigrationItem::createFor($this->user->id, [
            'bulk_migration_id' => $run->getKey(),
            'from_manga_id' => 76,
            'to_manga_id' => 2033,
            'title' => 'Hiding a Logistics Center',
            'to_title' => 'Hiding a Logistics Center',
            'chapters_here' => 41,
            'chapters_there' => 41,
            'title_similarity' => 1.0,
            'chapter_delta' => 0,
            'author_overlap' => 1.0,
            'status' => BulkMigrationItem::PENDING,
        ]);

        BulkMigrationItem::createFor($this->user->id, [
            'bulk_migration_id' => $run->getKey(),
            'from_manga_id' => 84,
            'to_manga_id' => null,
            'title' => 'Amagami-san Chi no Enai Musume',
            'to_title' => null,
            'chapters_here' => 12,
            'chapters_there' => null,
            'title_similarity' => 0.2,
            'chapter_delta' => null,
            'author_overlap' => null,
            'status' => BulkMigrationItem::SKIPPED,
            'reason' => 'Not found on any of the destinations this run compared.',
        ]);

        $this->page()
            ->assertSee('Hiding a Logistics Center')
            ->assertSee('cover-progress')
            ->assertDontSee('approved.76')
            ->assertDontSee('Move the ticked series')
            ->assertDontSee('No alternatives found');

        $run->update(['state' => BulkMigration::READY, 'finished_at' => now()]);

        $this->page()
            ->assertSee('Hiding a Logistics Center')
            ->assertSee('approved.76')
            ->assertSee('Move the ticked series')
            ->assertSee('No alternatives found');
    }

    /**
     * The poll rides the comparing card, and settling is the stop.
     *
     * The page declared its poll through a `with()` array, the same route the wizard page
     * was caught on: nothing in Livewire or volt ever calls that method, so the directive
     * never reached the rendered page and the page never polled at all. Measured live
     * twice on 2026-10-17: run 4 went ready at 19:32:38 and the card still read
     * "Looking up your series" at 19:33:10, and run 5, ready 19:39:40, was the same
     * seconds later, until a reload. The idiom is the wizard's: the directive sits on the
     * block that shows progress, and the block unmounting stops it.
     */
    public function test_the_comparing_card_polls_and_a_ready_run_rides_nothing(): void
    {
        $run = $this->startRun();

        $running = $this->page()->html();
        $this->assertTrue(
            str_contains($running, 'wire:poll'),
            'With a comparison in flight the page must actually poll, which the old with() route never arranged.',
        );

        $run->update(['state' => BulkMigration::READY, 'finished_at' => now()]);

        $settled = $this->page()->html();
        $this->assertFalse(
            str_contains($settled, 'wire:poll'),
            'A reader looking at a finished comparison should not pay for a poll.',
        );
    }

    /**
     * A run whose series all moved keeps its rows after the page is reloaded.
     *
     * The offer table used to key on pending rows alone, so once the move emptied it the
     * card answered with the one sentence it knows for an empty table, that nothing on
     * the source was on the new one, over a run whose rows all say moved. Measured live
     * on 2026-10-17 against the rebuilt scratch library: the run had just moved six
     * series and the reloaded page denied it.
     */
    public function test_a_run_that_moved_every_series_keeps_its_rows_after_a_reload(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $run = $this->startRun();
        $this->matcher()->run($run);

        // What a successful move does to the rows, which its own tests cover directly.
        BulkMigrationItem::query()
            ->forUser($this->user->id)
            ->where('bulk_migration_id', $run->id)
            ->update(['status' => BulkMigrationItem::MOVED, 'reason' => null]);

        $this->page()
            ->assertSee('Hiding a Logistics Center')
            ->assertSee('moved', escape: false)
            ->assertDontSee(__('Nothing on that source is on the new one'))
            ->assertDontSee('Tick all');
    }

    /**
     * A row refused at move time keeps its row and its reason too.
     *
     * Same family as the moved rows above: the table vanishing when nothing is pending
     * also swallowed the refusals, the only copy of a reason the reader was given. The
     * refusal's cause and rhyme live in the mover's tests; this pin is that the row the
     * reader must still be able to read stays on the page those words came out through.
     */
    public function test_a_refused_series_keeps_its_row_and_its_reason(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $run = $this->startRun();
        $this->matcher()->run($run);

        // What a refused move does to the row, which the mover's tests cover directly.
        BulkMigrationItem::query()
            ->forUser($this->user->id)
            ->where('bulk_migration_id', $run->id)
            ->update([
                'status' => BulkMigrationItem::REFUSED,
                'reason' => 'The source this series is leaving lists no chapters, so your read progress there could not be carried over.',
            ]);

        $this->page()
            ->assertSee('Hiding a Logistics Center')
            ->assertSee('refused', escape: false)
            ->assertSee('The source this series is leaving lists no chapters')
            ->assertSee(__('Look for another'))
            ->assertDontSee(__('Nothing on that source is on the new one'));
    }

    public function test_the_run_names_the_series_it_is_about_to_compare(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);
        $this->seriesOnFrom(77, 'The Berserkers Second Playthrough', 12);

        $run = $this->startRun();
        $this->matcher()->run($run);

        /*
         * One of the two, not an exact one: the name left standing is the one the run was
         * last in front of, and which of the two that is belongs to the library batch's
         * order, which this fixture does not own. What the test owns is that the field
         * names a series the run compared rather than nobody.
         */
        $this->assertContains(
            $run->fresh()->current_title,
            ['Hiding a Logistics Center', 'The Berserkers Second Playthrough'],
        );
    }

    public function test_a_stop_between_two_series_keeps_the_compared_and_leaves_the_rest(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);
        $this->seriesOnFrom(77, 'The Berserkers Second Playthrough', 12);
        $this->seriesOnFrom(78, 'Never Reached', 3);
        $this->searchHits[self::TO_SOURCE] = [];

        $matcher = new class(app(CandidateFinder::class), app(SeriesGateway::class), app(Library::class)) extends BulkSourceMatcher
        {
            public int $looked = 0;

            /*
             * A stop that lands after two compares, without a timing race. The prod path is
             * the same act the page's cancel press performs: the row's state stops reading
             * RUNNING, and the loop trusts exactly that.
             */
            protected function hasBeenStopped(BulkMigration $run): bool
            {
                $run->refresh();

                if (++$this->looked >= 3) {
                    $run->update(['state' => BulkMigration::CANCELLED, 'finished_at' => now()]);

                    return true;
                }

                return false;
            }
        };

        $run = $this->startRun();
        $tally = $matcher->run($run);

        $this->assertSame(BulkMigration::CANCELLED, $run->fresh()->state);
        $this->assertSame(2, $tally['checked'], 'the series after the stop was not asked about');

        /*
         * The row says the same thing as the tally. This is the assert the first version
         * of this test failed, and rightly: the stop return before it wrote no counts, so
         * a reader who stopped a run inside the progress-throttle window saw a card that
         * said zero above rows the run had made.
         */
        $this->assertSame(2, $run->fresh()->series_checked);
        $this->assertSame(2, BulkMigrationItem::query()->count());
        $this->assertNotNull($run->fresh()->finished_at);

        /*
         * Exactly one of the three has no row, and which one belongs to the library
         * batch's order, which this fixture does not own. What the test owns is that the
         * stop left exactly one out, and left it out with no row behind at all.
         */
        $uncompared = collect([76, 77, 78])
            ->reject(fn (int $id): bool => BulkMigrationItem::query()->where('from_manga_id', $id)->exists())
            ->values();

        $this->assertCount(1, $uncompared, 'one series was never asked about');
    }

    public function test_a_death_that_lands_after_a_stop_does_not_rename_the_stop(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);

        $matcher = new class(app(CandidateFinder::class), app(SeriesGateway::class), app(Library::class)) extends BulkSourceMatcher
        {
            public function run(BulkMigration $run, int $limit = 0): array
            {
                // The stop lands inside the same window in which the run then dies.
                $run->update(['state' => BulkMigration::CANCELLED, 'finished_at' => now()]);

                throw new \RuntimeException('the library could not be read');
            }
        };

        $run = $this->startRun();
        $job = new BuildBulkMigration($run->id, (int) $this->user->id);

        try {
            $job->handle($matcher);
            $this->fail('the job rethrows what the matcher could not survive');
        } catch (\RuntimeException) {
            // The rethrow is the prod behavior this test keeps.
        }

        $this->assertSame(BulkMigration::CANCELLED, $run->fresh()->state, 'the reader named the outcome, not the death');
        $this->assertNull($run->fresh()->error);
    }

    public function test_the_reader_can_stop_a_run_that_is_still_going(): void
    {
        $run = $this->startRun();

        $this->page()
            ->assertSee(__('Stop comparing'))
            ->call('cancel')
            ->assertSee(__('The comparison stopped'));

        $this->assertSame(BulkMigration::CANCELLED, $run->fresh()->state);
        $this->assertNotNull($run->fresh()->finished_at);

        // A second press has nothing to stop, and changes nothing.
        $this->page()->call('cancel');

        $this->assertSame(BulkMigration::CANCELLED, $run->fresh()->state);
    }

    public function test_a_run_stopped_by_the_reader_shows_what_it_compared_and_what_it_never_reached(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->seriesOnFrom(77, 'The Berserkers Second Playthrough', 12);
        $this->seriesOnFrom(78, 'Never Reached', 3);
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $matcher = new class(app(CandidateFinder::class), app(SeriesGateway::class), app(Library::class)) extends BulkSourceMatcher
        {
            public int $looked = 0;

            /*
             * A stop after two compares, the same seam the engine-side stop test uses. The
             * run below is then a real partial: two named rows, and one series with no row
             * at all. Hand-picking the counters would fabricate a run whose numbers and
             * rows disagree, which is the state the page is for preventing.
             */
            protected function hasBeenStopped(BulkMigration $run): bool
            {
                $run->refresh();

                if (++$this->looked >= 3) {
                    $run->update(['state' => BulkMigration::CANCELLED, 'finished_at' => now()]);

                    return true;
                }

                return false;
            }
        };

        $run = $this->startRun();
        $matcher->run($run);

        /*
         * Which series the stop left out belongs to the library batch's order, which this
         * fixture does not own. What the test owns is the count: two names named in the
         * run's rows, one absent entirely, and the page showing both numbers honestly.
         */
        $titles = [
            76 => 'Hiding a Logistics Center',
            77 => 'The Berserkers Second Playthrough',
            78 => 'Never Reached',
        ];

        $comparedIds = BulkMigrationItem::query()
            ->where('bulk_migration_id', $run->id)
            ->pluck('from_manga_id')
            ->all();

        $awaiting = collect($titles)
            ->reject(fn (string $title, int $id): bool => in_array($id, $comparedIds, true))
            ->values();

        $this->assertCount(1, $awaiting, 'the stop left one series without a row');
        $this->assertSame(2, $run->fresh()->series_checked);

        $this->page()
            ->assertSee(__('The comparison stopped'))
            ->assertSee('You stopped it, so 1 series was never looked at.')
            ->assertSee($awaiting[0]);
    }

    /*
     * ---------------------------------------------------------------------------
     * Fixtures.
     * ---------------------------------------------------------------------------
     */

    private function matcher(): BulkSourceMatcher
    {
        return app(BulkSourceMatcher::class);
    }

    private function startRun(): BulkMigration
    {
        return BulkMigration::createFor($this->user->id, [
            'from_source_id' => self::FROM_SOURCE,
            'to_source_id' => self::TO_SOURCE,
            'state' => BulkMigration::RUNNING,
            'started_at' => now(),
        ]);
    }

    /**
     * A run the newer page writes: the destinations the reader picked as a list, the
     * single destination columns left alone. Each target is stamped with what the mirror
     * knew at creation time, exactly as the page does.
     */
    private function startTargetedRun(string ...$targets): BulkMigration
    {
        $run = BulkMigration::createFor($this->user->id, [
            'from_source_id' => self::FROM_SOURCE,
            'state' => BulkMigration::RUNNING,
            'started_at' => now(),
        ]);

        foreach (array_values($targets) as $seq => $sourceId) {
            BulkMigrationTarget::createFor($this->user->id, [
                'bulk_migration_id' => $run->getKey(),
                'source_id' => $sourceId,
                'source_name' => Source::query()
                    ->where('suwayomi_source_id', $sourceId)
                    ->value('display_name'),
                'lang' => 'en',
                'seq' => $seq,
            ]);
        }

        return $run;
    }

    private function readyRunWithNoOffers(): BulkMigration
    {
        return BulkMigration::createFor($this->user->id, [
            'from_source_id' => self::FROM_SOURCE,
            'to_source_id' => self::TO_SOURCE,
            'state' => BulkMigration::READY,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    /**
     * An item that could be offered, with the comparison the confidence test reads.
     */
    private function offerable(
        ?int $chaptersHere,
        ?int $chaptersThere,
        float $similarity,
        ?float $overlap,
    ): BulkMigrationItem {
        $run = $this->readyRunWithNoOffers();

        return BulkMigrationItem::createFor($this->user->id, [
            'bulk_migration_id' => $run->id,
            'from_manga_id' => 76,
            'to_manga_id' => 2033,
            'title' => 'Hiding a Logistics Center',
            'to_title' => 'Hiding a Logistics Center',
            'chapters_here' => $chaptersHere,
            'chapters_there' => $chaptersThere,
            'title_similarity' => $similarity,
            'chapter_delta' => $chaptersHere !== null && $chaptersThere !== null ? $chaptersThere - $chaptersHere : null,
            'author_overlap' => $overlap,
            'status' => BulkMigrationItem::PENDING,
        ]);
    }

    private function seriesOnFrom(int $mangaId, string $title, int $chapters, ?string $author = null): void
    {
        $this->meta[$mangaId] = [
            'title' => $title,
            'author' => $author,
            'source_id' => self::FROM_SOURCE,
            'source_name' => 'Asura Scans',
        ];

        $this->chapterCounts[$mangaId] = $chapters;

        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => $mangaId,
            'in_library' => true,
            'title' => $title,
        ]);
    }

    private function seriesElsewhere(int $mangaId, string $title, int $chapters, string $sourceId): void
    {
        $this->meta[$mangaId] = [
            'title' => $title,
            'author' => null,
            'source_id' => $sourceId,
            'source_name' => 'Somewhere Else',
        ];

        $this->chapterCounts[$mangaId] = $chapters;

        SeriesState::createFor($this->user->id, [
            'suwayomi_manga_id' => $mangaId,
            'in_library' => true,
            'title' => $title,
        ]);
    }

    /**
     * The matched count, which is the number the page puts next to "Looked at".
     *
     * Survived the mutation check because nothing asserted it at all. Every row below was
     * PENDING and the run said zero matched, which is a page that contradicts itself.
     */
    public function test_the_matched_count_is_reported(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->seriesOnFrom(77, 'Nothing Here', 10);
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $run = $this->startRun();
        $result = $this->matcher()->run($run);

        $this->assertSame(1, $result['matched'], 'one destination listed it, the other did not');
        $this->assertSame(1, $run->fresh()->series_matched);
        $this->assertSame(2, $run->fresh()->series_checked);
    }

    /**
     * The origin source is gone from the catalogue between picking it and running.
     *
     * Without the guard this is a read of a property on null, so the run dies with a type
     * error and the page watches a run that never finishes. The guard is the difference
     * between a sentence and a stack trace.
     */
    public function test_an_origin_source_that_vanished_says_so(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);

        Source::query()->where('suwayomi_source_id', self::FROM_SOURCE)->delete();

        $run = $this->startRun();
        $this->matcher()->run($run);
        $run->refresh();

        $this->assertSame(BulkMigration::FAILED, $run->state);
        $this->assertStringContainsString('moving away from', (string) $run->error);
    }

    /**
     * A destination that could not be read is not offered as a move.
     *
     * The candidate exists, because a search returned it, but refreshing it 500s, so the
     * finder marks it `unavailable`. Offering it would be offering a move that fails on
     * press, and the whole point of the status is to keep that off the list.
     */
    public function test_a_destination_that_cannot_be_read_is_not_offered(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        // The search found it; reading it does not work.
        $this->unknown[] = 2033;

        $run = $this->startRun();
        $this->matcher()->run($run);

        $item = BulkMigrationItem::query()->where('from_manga_id', 76)->firstOrFail();

        $this->assertSame(
            BulkMigrationItem::SKIPPED,
            $item->status,
            'a destination the reader cannot read is a row that fails when they press it',
        );
        $this->assertSame(0, $run->fresh()->series_matched);

        /*
         * And on the page it sits with the nowhere to go cards, carrying the reason it
         * carries, rather than being an untickable row among the tickable ones: the move
         * is not offered and the row is not hidden either.
         */
        $this->page()
            ->assertSee('One series with nowhere to go.')
            ->assertSee('Hiding a Logistics Center')
            ->assertDontSee(__('Move the ticked series'));
    }

    /**
     * One source that throws does not cost the reader the rest of the shelf.
     *
     * A pass over eighty series is a slice of something bigger. If the destination is
     * unreachable every series fails the same way, so the run has to finish and say so
     * rather than dying on the first one and leaving the page watching forever.
     */
    public function test_an_unreachable_destination_finishes_the_run_and_reports_every_series(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41);
        $this->seriesOnFrom(77, 'Another One', 12);
        $this->destination(2033, 'Hiding a Logistics Center', 41);

        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];
        $this->unreachable[] = self::TO_SOURCE;

        $run = $this->startRun();
        $result = $this->matcher()->run($run);
        $run->refresh();

        $this->assertSame(BulkMigration::READY, $run->state, 'the run finished rather than dying');
        $this->assertSame(2, $result['checked'], 'both series were compared');
        $this->assertNotNull($run->finished_at);

        /*
         * SKIPPED, not FAILED, and the harness is why that is worth pinning.
         *
         * My first version of this asserted FAILED and got none, correctly: `sweepAndRank`
         * treats a source it cannot search as a source with no candidates, so `findOn()`
         * returns an empty list and the series reads as not listed there. Which is the right
         * answer, and was the wrong thing for me to assume without checking.
         */
        $this->assertSame(
            2,
            BulkMigrationItem::query()->where('status', BulkMigrationItem::SKIPPED)->count(),
            'every series is accounted for, and none of them is offered a move that cannot happen',
        );

        $this->assertSame(0, BulkMigrationItem::query()->where('status', BulkMigrationItem::PENDING)->count());
    }

    /**
     * Another reader's row must be left exactly as it was.
     *
     * The existing `test_an_item_from_another_readers_run_is_not_moved` asserts only that
     * nothing moved, and it survives dropping `forUser()` outright, because the migrator
     * refuses the pair for want of an offer either way. What it does not notice is that the
     * mover then writes REFUSED onto **their** row. The row is the observable that
     * separates the two checks.
     */
    public function test_another_readers_row_is_left_untouched(): void
    {
        $run = $this->readyRunWithNoOffers();

        $other = User::factory()->create();

        $theirs = BulkMigrationItem::createFor($other->id, [
            'bulk_migration_id' => $run->id,
            'from_manga_id' => 76,
            'to_manga_id' => 2033,
            'title' => 'Not yours',
            'status' => BulkMigrationItem::PENDING,
        ]);

        app(BulkMover::class)->move($run, collect([76 => 2033]));

        $this->assertSame(
            BulkMigrationItem::PENDING,
            $theirs->fresh()->status,
            'this reader pressing a button must not write to another reader\'s migration state',
        );
    }

    /**
     * An item belonging to a different run, with a destination that really was offered.
     *
     * The offer is real here, which is the point. Without it the migrator refuses and the
     * test proves nothing; with it, dropping the run filter moves a series through a
     * comparison the reader never saw and never ticked.
     */
    public function test_an_item_from_a_different_run_is_not_moved_even_with_a_real_offer(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        // Run A compares and offers. Run B is a separate, unrelated comparison.
        $runA = $this->startRun();
        $this->matcher()->run($runA);

        $runB = $this->readyRunWithNoOffers();

        $item = BulkMigrationItem::query()
            ->where('bulk_migration_id', $runA->id)
            ->where('from_manga_id', 76)
            ->firstOrFail();

        // The reader approves it through run B, which never compared this series.
        $tally = app(BulkMover::class)->move($runB, collect([76 => 2033]));

        $this->assertSame(0, $tally['moved'], 'run B never showed this series');
        $this->assertSame(0, SeriesMigration::query()->count());
        $this->assertSame(BulkMigrationItem::PENDING, $item->fresh()->status);
    }

    /**
     * A destination the request names that the row does not.
     *
     * Both 2033 and 9999 were genuinely offered for this series, so the migrator's own gate
     * passes either one. Only the mover's equality check knows which one the reader actually
     * ticked, and a request that names the other one is a tampered request.
     */
    public function test_a_destination_the_row_does_not_name_is_refused_even_when_it_was_offered(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(9999, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [
            2033 => 'Hiding a Logistics Center',
            9999 => 'Hiding a Logistics Center',
        ];

        $run = $this->startRun();
        $this->matcher()->run($run);

        $item = BulkMigrationItem::query()
            ->where('from_manga_id', 76)
            ->where('to_manga_id', 2033)
            ->firstOrFail();

        // The request names 9999. The row says 2033.
        $tally = app(BulkMover::class)->move($run, collect([76 => 9999]));

        $this->assertSame(0, $tally['moved'], 'the reader ticked 2033, not 9999');
        $this->assertSame(BulkMigrationItem::PENDING, $item->fresh()->status);

        $migration = SeriesMigration::query()->first();

        $this->assertNull($migration, 'and nothing moved anywhere');
    }

    /**
     * Read progress is carried, and the page says how much.
     *
     * The tally feeds the completion sentence, so a hardcoded zero reports a move that
     * carried forty chapters of read state as having carried nothing.
     */
    public function test_chapters_carried_is_the_migrations_own_count(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->destination(2033, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::TO_SOURCE] = [2033 => 'Hiding a Logistics Center'];

        $run = $this->startRun();
        $this->matcher()->run($run);

        $tally = app(BulkMover::class)->move($run, collect([76 => 2033]));

        $migration = SeriesMigration::query()->firstOrFail();

        $this->assertSame(1, $tally['moved']);
        $this->assertSame(
            (int) $migration->chapters_carried,
            $tally['chapters_carried'],
            'the number on the page is the migration\'s own, not a constant',
        );
    }

    /**
     * A title exactly on the bar is ticked; one hair under is not.
     *
     * The comparison is `<` and the boundary matters, because the threshold is configurable
     * and a reader who measures it will set it to the value they saw.
     */
    public function test_the_title_threshold_is_exclusive_at_the_boundary(): void
    {
        $this->assertTrue(
            $this->offerable(41, 41, 0.95, null)->isConfident(0.95),
            'exactly on the bar counts as agreeing',
        );

        $this->assertFalse(
            $this->offerable(41, 41, 0.9499, null)->isConfident(0.95),
            'one ten-thousandth under it does not',
        );
    }

    /**
     * A row that already moved is not offered again.
     *
     * `isConfident()` has its own offerability check rather than relying on the page, so a
     * second press of the same button cannot re-tick what the first press moved.
     */
    public function test_an_already_moved_row_is_not_offered_again(): void
    {
        $item = $this->offerable(41, 41, 1.0, null);
        $item->update(['status' => BulkMigrationItem::MOVED]);

        $this->assertFalse(
            $item->fresh()->isConfident(0.95),
            'everything else about it still agrees, so only offerability can be refusing it',
        );
    }

    /**
     * The same source twice offers nothing, and asks the server nothing.
     *
     * Moving a source onto itself is the one pair the page refuses to create, and the
     * refusal here is the second half of that: a run row can be written by hand or by an
     * older client, so the matcher says why rather than handing back the leaving side's
     * own catalogue as a destination.
     */
    public function test_a_source_moved_onto_itself_offers_nothing(): void
    {
        $this->seriesOnFrom(76, 'Hiding a Logistics Center', 41, 'Life Ruined');
        $this->searchHits[self::FROM_SOURCE] = [76 => 'Hiding a Logistics Center'];

        $run = $this->startTargetedRun(self::FROM_SOURCE);
        $this->matcher()->run($run);

        $fresh = $run->fresh();

        $this->assertSame(BulkMigration::FAILED, $fresh->state);
        $this->assertSame([], $this->sourcesSearched(), 'and it did not go looking');
        $this->assertNull(BulkMigrationItem::query()->where('bulk_migration_id', $run->id)->first());
    }

    private function destination(int $mangaId, string $title, int $chapters, ?string $author = null): void
    {
        $this->meta[$mangaId] = [
            'title' => $title,
            'author' => $author,
            'source_id' => self::TO_SOURCE,
            'source_name' => 'MangaFire',
        ];

        $this->chapterCounts[$mangaId] = $chapters;
    }

    private function source(string $name, int $offset, string $sourceId): Source
    {
        return Source::create([
            'suwayomi_source_id' => $sourceId,
            'name' => $name,
            'display_name' => $name,
            'extension_pkg_name' => 'eu.kanade.tachiyomi.extension.en.'.strtolower(str_replace(' ', '', $name)).$offset,
            'lang' => 'en',
            'home_url' => "https://{$name}.test",
            'content_warning' => Source::SAFE,
            'is_installed' => true,
            'supports_latest' => true,
        ]);
    }

    /**
     * Which sources a search was actually sent to.
     *
     * @return array<int, string>
     */
    private function sourcesSearched(): array
    {
        return $this->searchedSources;
    }

    /** @var array<int, string> */
    private array $searchedSources = [];

    /**
     * @return array<int, array<string, mixed>>
     */
    private function chapterNodes(int $mangaId): array
    {
        $count = $this->chapterCounts[$mangaId] ?? 0;
        $nodes = [];

        for ($n = 1; $n <= $count; $n++) {
            $nodes[] = [
                'id' => ($mangaId * 1000) + $n,
                'name' => "Chapter {$n}",
                'chapterNumber' => $n,
                'scanlator' => null,
                'uploadDate' => 0,
                'pageCount' => 20,
                'isRead' => false,
                'isDownloaded' => false,
                'lastPageRead' => 0,
                'sourceOrder' => $n,
            ];
        }

        return $nodes;
    }

    /**
     * @return array<string, mixed>
     */
    private function mangaNode(int $mangaId): array
    {
        $meta = $this->meta[$mangaId] ?? [];

        return [
            'id' => (string) $mangaId,
            'title' => (string) ($meta['title'] ?? "Series {$mangaId}"),
            'url' => '/series/'.$mangaId,
            'realUrl' => 'https://source.test/'.$mangaId,
            'thumbnailUrl' => '/api/v1/manga/'.$mangaId.'/thumbnail',
            'status' => 'ONGOING',
            'author' => $meta['author'] ?? null,
            'artist' => null,
            'genre' => [],
            'description' => null,
            'initialized' => isset($this->chapterCounts[$mangaId]),
            'unreadCount' => 0,
            'updateStrategy' => 'ALWAYS_UPDATE',
            'chaptersLastFetchedAt' => 1790776498,
            'source' => [
                'id' => (string) ($meta['source_id'] ?? self::FROM_SOURCE),
                'name' => (string) ($meta['source_name'] ?? 'Asura Scans'),
                'lang' => 'en',
                'contentWarning' => 'SAFE',
            ],
            'chapters' => [
                'totalCount' => $this->chapterCounts[$mangaId] ?? 0,
                'nodes' => $this->chapterNodes($mangaId),
            ],
        ];
    }

    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');
            $variables = (array) ($request->data()['variables'] ?? []);

            $this->sent[] = $query;

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            /*
             * The batched library call, which is how the page learns which source a series is
             * on. `SeriesGateway::fetchMany()` sends `query Many` and reads `mangas.nodes`.
             */
            if (str_contains($query, 'query Many')) {
                $ids = (array) ($variables['ids'] ?? []);
                $nodes = [];

                foreach ($ids as $id) {
                    if (in_array((int) $id, $this->unknown, true)) {
                        continue;
                    }

                    $nodes[] = [
                        'id' => (string) (int) $id,
                        'title' => $this->meta[(int) $id]['title'] ?? "Series {$id}",
                        'thumbnailUrl' => null,
                        'status' => 'ONGOING',
                        'initialized' => isset($this->chapterCounts[(int) $id]),
                        'source' => [
                            'id' => (string) ($this->meta[(int) $id]['source_id'] ?? self::FROM_SOURCE),
                            'name' => (string) ($this->meta[(int) $id]['source_name'] ?? 'Asura Scans'),
                            'lang' => 'en',
                        ],
                        'chapters' => [
                            'totalCount' => $this->chapterCounts[(int) $id] ?? 0,
                            'nodes' => array_map(
                                static fn (array $chapter): array => [
                                    'id' => $chapter['id'],
                                    'isDownloaded' => false,
                                    'name' => $chapter['name'],
                                ],
                                $this->chapterNodes((int) $id),
                            ),
                        ],
                    ];
                }

                return Http::response(['data' => ['mangas' => ['nodes' => $nodes]]]);
            }

            if (str_contains($query, 'mutation Search')) {
                $sourceId = (string) ($variables['source'] ?? '');
                $this->searchedSources[] = $sourceId;
                $hits = $this->searchHits[$sourceId] ?? [];

                if (in_array($sourceId, $this->unreachable, true)) {
                    return Http::response('down', 500);
                }

                return Http::response(['data' => ['fetchSourceManga' => [
                    'hasNextPage' => false,
                    'mangas' => array_map(
                        static fn (int $id, string $title): array => [
                            'id' => (string) $id,
                            'title' => $title,
                            'url' => '/series/'.$id,
                            'realUrl' => 'https://source.test/'.$id,
                            'author' => null,
                            'artist' => null,
                            'description' => null,
                            'status' => 'ONGOING',
                            'initialized' => false,
                        ],
                        array_keys($hits),
                        array_values($hits),
                    ),
                ]]]);
            }

            if (str_contains($query, 'mutation Refresh')) {
                $mangaId = (int) ($variables['id'] ?? 0);

                if (in_array($mangaId, $this->unknown, true)) {
                    return Http::response('gone', 500);
                }

                return Http::response(['data' => ['fetchMangaAndChapters' => [
                    'manga' => $this->mangaNode($mangaId),
                    'chapters' => $this->chapterNodes($mangaId),
                ]]]);
            }

            if (str_contains($query, 'manga(id:')) {
                $mangaId = (int) ($variables['id'] ?? 0);

                if (in_array($mangaId, $this->failFind, true)) {
                    return Http::response('gone', 500);
                }

                if (in_array($mangaId, $this->unknown, true)) {
                    return Http::response(['data' => ['manga' => null]]);
                }

                return Http::response(['data' => ['manga' => $this->mangaNode($mangaId)]]);
            }

            return Http::response(['data' => []]);
        });
    }
}
