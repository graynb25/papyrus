<?php

namespace Tests\Feature;

use App\Models\Download;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The downloads page says what a download is, and its row is the way back to it.
 *
 * ## What was wrong
 *
 * The row read `Chapter {suwayomi_chapter_id}`, which is a database id, so a reader looking at a
 * finished download saw "Chapter 14430" and had nothing to recognise it by. The row already
 * carried `suwayomi_manga_id`, so the series was known all along and simply was not being read.
 *
 * The row already linked to the series, but only through the words of the title, so a click
 * anywhere else on it did nothing. A row that looks like a link and is not over four fifths of
 * itself is worse than no link at all, so the anchor is now stretched over the row and the
 * buttons above it.
 *
 * ## Why the title costs one call and not one per row
 *
 * The page holds up to 200 rows and a title is one `manga(id:)` each, measured at about 15ms
 * against the live server, which is three seconds of serial waiting to render a list.
 * `SeriesGateway::fetchMany()` is the batched call the library page already uses for this exact
 * problem and its payload **already** carries `chapters { nodes { id name } }`, so the series
 * titles and the chapter names come out of a request the shape was making anyway.
 * `test_the_label_is_one_batched_call_however_many_rows_there_are` pins that, because a loop is
 * the obvious way to write this and it is the reason the page was slow before.
 */
class DownloadRowLabelTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    /**
     * Series the server holds, keyed by manga id.
     *
     * @var array<int, array{title: string, chapters: array<int, string>}> manga id => title and
     *                                                                     chapter id => name
     */
    private array $catalogue = [];

    /**
     * @var array<int, string> every GraphQL document sent
     */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        /*
         * One fake for the whole file. `Http::fake()` called twice does not replace the first
         * registration, so a second call in a test is silently ignored. Trap 116.
         */
        Http::fake(function (Request $request) {
            $query = (string) data_get($request->data(), 'query', '');
            $this->sent[] = $query;

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($query, 'mangas(')) {
                return Http::response(['data' => ['mangas' => ['nodes' => $this->nodesFor($query)]]]);
            }

            return Http::response(['data' => []]);
        });
    }

    /**
     * The batched payload, answering only the ids that were asked for.
     *
     * @return array<int, array<string, mixed>>
     */
    private function nodesFor(string $query): array
    {
        $wanted = [];

        if (preg_match('/in:\s*\[([^\]]*)\]/', $query, $m) === 1) {
            $wanted = array_values(array_filter(array_map(
                static fn (string $id): int => (int) trim($id),
                explode(',', $m[1]),
            )));
        }

        $nodes = [];

        foreach ($this->catalogue as $mangaId => $series) {
            if ($wanted !== [] && ! in_array((int) $mangaId, $wanted, true)) {
                continue;
            }

            $nodes[] = [
                'id' => (string) $mangaId,
                'title' => $series['title'],
                'thumbnailUrl' => null,
                'status' => 'ONGOING',
                'initialized' => true,
                'source' => ['id' => '6247824327199706550', 'name' => 'Asura', 'lang' => 'en'],
                'chapters' => [
                    'totalCount' => count($series['chapters']),
                    // Keyed by chapter id rather than by position. Deriving ids from the array
                    // index gives two series the same chapter 14430, so the second row's lookup
                    // finds nothing and the label silently falls back to the raw id.
                    'nodes' => array_map(static fn (int $id, string $name): array => [
                        'id' => (string) $id,
                        'name' => $name,
                        'isDownloaded' => true,
                    ], array_keys($series['chapters']), array_values($series['chapters'])),
                ],
            ];
        }

        return $nodes;
    }

    /**
     * @param  array<int, array{title: string, chapters: array<int, string>}>  $catalogue
     * @param  array<int, array{0: int, 1: int}>  $rows  [manga id, chapter id]
     */
    private function givenDownloads(array $catalogue, array $rows): void
    {
        $this->catalogue = $catalogue;
        $this->sent = [];

        foreach ($rows as [$mangaId, $chapterId]) {
            Download::createFor($this->user->id, [
                'suwayomi_chapter_id' => $chapterId,
                'suwayomi_manga_id' => $mangaId,
                'state' => Download::FINISHED,
                'page_count' => 16,
                'finished_at' => now(),
            ]);
        }
    }

    public function test_a_download_row_names_its_series(): void
    {
        $this->givenDownloads(
            [4987 => ['title' => 'Only I Have an EX-Grade Summon', 'chapters' => [14430 => 'Chapter 1', 14431 => 'Chapter 2']]],
            [[4987, 14431]],
        );

        Livewire::test('pages::downloads')
            ->assertOk()
            ->assertSee('Only I Have an EX-Grade Summon');
    }

    public function test_a_download_row_does_not_lead_with_a_database_id(): void
    {
        $this->givenDownloads(
            [4987 => ['title' => 'Only I Have an EX-Grade Summon', 'chapters' => [14430 => 'Chapter 1', 14431 => 'Chapter 2']]],
            [[4987, 14431]],
        );

        Livewire::test('pages::downloads')
            ->assertDontSee('Chapter 14431');
    }

    public function test_a_download_row_names_the_chapter_rather_than_its_id(): void
    {
        $this->givenDownloads(
            [4987 => ['title' => 'Only I Have an EX-Grade Summon', 'chapters' => [14430 => 'Chapter 1', 14431 => 'Chapter 2']]],
            [[4987, 14431]],
        );

        // Chapter 2, so the label has to come from the server's name rather than from the id,
        // which would read 14431.
        Livewire::test('pages::downloads')
            ->assertSee('Chapter 2');
    }

    public function test_two_downloads_of_different_series_each_name_their_own(): void
    {
        $this->givenDownloads(
            [
                4987 => ['title' => 'Only I Have an EX-Grade Summon', 'chapters' => [14430 => 'Chapter 1']],
                76 => ['title' => 'The Berserker Second Playthrough', 'chapters' => [14431 => 'Ch. 1']],
            ],
            [[4987, 14430], [76, 14431]],
        );

        Livewire::test('pages::downloads')
            ->assertSee('Only I Have an EX-Grade Summon')
            ->assertSee('The Berserker Second Playthrough')
            ->assertSee('Ch. 1');
    }

    public function test_a_chapter_the_server_leaves_unnamed_falls_back_to_its_number(): void
    {
        $this->givenDownloads(
            [4987 => ['title' => 'Only I Have an EX-Grade Summon', 'chapters' => [14430 => 'Chapter 1', 14431 => '']]],
            [[4987, 14431]],
        );

        Livewire::test('pages::downloads')
            ->assertOk()
            ->assertSee('Chapter 14431');
    }

    public function test_a_download_row_links_to_its_series(): void
    {
        $this->givenDownloads(
            [4987 => ['title' => 'Only I Have an EX-Grade Summon', 'chapters' => [14430 => 'Chapter 1']]],
            [[4987, 14430]],
        );

        Livewire::test('pages::downloads')
            ->assertSee(route('series', ['manga' => 4987]), false);
    }

    public function test_the_whole_row_is_the_link_not_only_its_words(): void
    {
        $this->givenDownloads(
            [4987 => ['title' => 'Only I Have an EX-Grade Summon', 'chapters' => [14430 => 'Chapter 1']]],
            [[4987, 14430]],
        );

        $html = Livewire::test('pages::downloads')->html();

        /*
         * A stretched link, so the anchor's own box covers the row and a click on the blank
         * right-hand side of it navigates. Without this the reader has to hit the words.
         */
        $doc = $this->dom($html);

        $link = $doc->getElementById('download-series-link');

        $this->assertNotNull($link, 'the row carries a link marked for the check');
        $this->assertTrue(
            $this->hasClass($link, 'after:absolute') && $this->hasClass($link, 'after:inset-0'),
            'The link must be stretched over the row, or four fifths of it is not clickable.'
        );

        /*
         * The stretched link is positioned against the nearest positioned ancestor, which is
         * what the browser does with a positioned `::after`. So the assertion walks up from the
         * anchor and asks whether it reaches one before it reaches something that is not
         * positioned, which is precisely the condition under which the stretch is measured
         * against the right box. Counting levels up instead would pin the template's nesting,
         * and the anchor happens to sit three deep through the heading wrapper today.
         */
        $row = null;

        for ($node = $link->parentNode; $node !== null; $node = $node->parentNode) {
            if (! $node instanceof \DOMElement) {
                break;
            }

            if ($this->hasClass($node, 'relative')) {
                $row = $node;
                break;
            }

            if ($node->tagName === 'body') {
                break;
            }
        }

        $this->assertNotNull(
            $row,
            'The stretched link has no positioned ancestor, so it would measure itself against something further up and the clickable area would be wrong in a way that looks fine until it is clicked.'
        );

        $this->assertTrue(
            $this->hasClass($row, 'flex'),
            'And the box it is measuring itself against should be the row.'
        );
    }

    public function test_the_row_buttons_sit_above_the_stretched_link(): void
    {
        $this->givenDownloads(
            [4987 => ['title' => 'Only I Have an EX-Grade Summon', 'chapters' => [14430 => 'Chapter 1']]],
            [[4987, 14430]],
        );

        $html = Livewire::test('pages::downloads')->html();

        /*
         * Load-bearing and invisible otherwise. The stretched link covers the whole row, so a
         * delete button underneath it navigates to the series instead of deleting, and the
         * reader loses nothing visibly.
         */
        $doc = $this->dom($html);

        // Flux compiles a <flux:button> down to a plain <button>, so the rendered document has
        // no flux:button left in it. Asking for the compiled name is the whole question here.
        $buttons = $doc->getElementsByTagName('button');
        $found = null;

        foreach ($buttons as $button) {
            if (str_starts_with((string) $button->getAttribute('wire:click'), 'forget')) {
                $found = $button;
                break;
            }
        }

        $this->assertNotNull($found, 'the delete control is on a finished row');
        $this->assertTrue(
            $this->hasClass($found, 'z-10'),
            'The delete control has to sit above the row-wide link or clicking it navigates to the series.'
        );
    }

    public function test_the_label_is_one_batched_call_however_many_rows_there_are(): void
    {
        $catalogue = [];
        $rows = [];

        foreach ([4987, 4988, 4989, 4990] as $i => $mangaId) {
            $catalogue[$mangaId] = ['title' => 'Series '.$mangaId, 'chapters' => [14430 + $i => 'Chapter 1']];
            $rows[] = [$mangaId, 14430 + $i];
        }

        $this->givenDownloads($catalogue, $rows);

        Livewire::test('pages::downloads')->assertOk();

        $batched = array_values(array_filter($this->sent, static fn (string $q): bool => str_contains($q, 'mangas(')));

        $this->assertCount(
            1,
            $batched,
            'Four rows must not become four `manga(id:)` calls. The bulk call is measured at 246ms for 300 ids against 15ms each, so a loop here is three seconds of waiting.'
        );
    }

    public function test_a_reader_with_no_downloads_sees_no_calls(): void
    {
        Livewire::test('pages::downloads')->assertOk();

        $batched = array_values(array_filter($this->sent, static fn (string $q): bool => str_contains($q, 'mangas(')));

        $this->assertSame([], $batched, 'Nothing to label means nothing to fetch.');
    }

    public function test_one_readers_downloads_are_not_shown_to_another(): void
    {
        $this->givenDownloads(
            [4987 => ['title' => 'Only I Have an EX-Grade Summon', 'chapters' => [14430 => 'Chapter 1']]],
            [[4987, 14430]],
        );

        $other = User::factory()->create();

        Livewire::actingAs($other)
            ->test('pages::downloads')
            ->assertOk()
            ->assertDontSee('Only I Have an EX-Grade Summon');
    }

    /**
     * The rendered markup, parsed.
     *
     * Reading a class off parsed markup instead of off the string is the whole point of these
     * assertions: Flux merges its own classes into ours and emits a run of data-flux
     * attributes before them, so a string search has to guess how much context to look at, and
     * it guessed wrong three times. Attribute order and formatting are not observable here.
     */
    private function dom(string $html): \DOMDocument
    {
        $doc = new \DOMDocument;
        $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>'.$html.'</body>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );

        return $doc;
    }

    /**
     * Whether an element carries a class, whatever order they sit in.
     */
    private function hasClass(\DOMElement $element, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [], true);
    }
}
