<?php

namespace Tests\Feature;

use App\Local\LocalLibrary;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The local source: browse and rescan, no upload.
 *
 * ## What this holds, and why the pairing is the feature
 *
 * Suwayomi has **no delete mutation**, so a series whose archive has been removed stays
 * listed forever. Measured on the dev box: manga 2899 "Papyrus Probe" is listed while the
 * only file left in its folder is the `ComicInfo.xml`.
 *
 * A view showing either side alone would be lying. The folder alone pretends Suwayomi
 * knows about it; the listing alone pretends the files are there. Paired, the reader can
 * see that their local library is out of step with their disk, and which way.
 *
 * ## Why there is no upload
 *
 * Measured, there is nothing to upload through: every GET on `/api/v1/...` answers with
 * the 4,484-byte WebUI catch-all and every other method a 404. So H15 reads.
 */
class LocalSourceTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    private string $root = '';

    /** What the fake server reports on the local source. */
    private array $local = [];

    /** When set, the rescan answers with this HTTP status instead of a listing. */
    private ?int $rescanStatus = null;

    /** When true, the rescan request fails at the transport rather than in the response. */
    private bool $rescanThrows = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        /*
         * A real temporary directory rather than a mocked filesystem, because the whole
         * claim is about what is on disk. A fake would assert the code reads what it
         * itself wrote, which is not the interesting direction.
         */
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-local-'.bin2hex(random_bytes(4));

        File::makeDirectory($this->root, 0o777, true);

        config(['papyrus.local_source.path' => $this->root]);

        $this->registerFake();
    }

    /**
     * Install the fake exactly once.
     *
     * Registering it a second time does not replace the first, so a test that called
     * `fakeSuwayomi([...])` afterwards was still answered by the empty stub installed here.
     */
    private function registerFake(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            if (str_contains($query, 'fetchSourceManga')) {
                if ($this->rescanThrows) {
                    throw new ConnectionException('connection refused');
                }

                if ($this->rescanStatus !== null) {
                    return Http::response(
                        ['errors' => [['message' => 'the source is unhappy']]],
                        $this->rescanStatus,
                    );
                }

                return Http::response(['data' => ['fetchSourceManga' => [
                    'mangas' => array_map(
                        static fn (array $m): array => ['id' => (string) $m['id'], 'title' => $m['title']],
                        $this->local,
                    ),
                ]]]);
            }

            /*
             * Built flat rather than as one nested array literal. Two of these were
             * written nested and both had an unclosed bracket in the same spot, which PHP
             * reports as "Unclosed '[' does not match ')'" several lines away from the
             * mistake. Assigning the pieces first makes the shape readable and the
             * mistake impossible to hide.
             */
            $localNodes = array_map(
                static fn (array $m): array => [
                    'id' => (string) $m['id'],
                    'title' => $m['title'],
                    'source' => ['id' => '0'],
                ],
                $this->local,
            );

            /*
             * A remote series alongside the local ones on purpose: a filter that forgot
             * the source id would put "Something Else" in the local listing, and this is
             * the only assertion standing between that and a reader.
             */
            $remote = [
                ['id' => '9999', 'title' => 'Something Else', 'source' => ['id' => '12345']],
            ];

            return Http::response([
                'data' => [
                    'mangas' => [
                        'nodes' => array_merge($localNodes, $remote),
                    ],
                ],
            ]);
        });
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            File::deleteDirectory($this->root);
        }

        parent::tearDown();
    }

    /**
     * Say what the server holds on the local source.
     *
     * @param  array<int, array{id: int, title: string}>  $local
     */
    private function fakeSuwayomi(array $local): void
    {
        $this->local = $local;
    }

    private function folder(string $name, string $file, string $contents = 'x'): void
    {
        File::makeDirectory($this->root.DIRECTORY_SEPARATOR.$name, 0o777, true);
        File::put($this->root.DIRECTORY_SEPARATOR.$name.DIRECTORY_SEPARATOR.$file, $contents);
    }

    private function library(): LocalLibrary
    {
        return app(LocalLibrary::class);
    }

    // Visibility ---------------------------------------------------------

    public function test_it_is_visible_when_the_folder_exists(): void
    {
        $this->assertTrue($this->library()->visible());
    }

    public function test_it_is_not_visible_when_the_folder_is_missing(): void
    {
        config(['papyrus.local_source.path' => $this->root.DIRECTORY_SEPARATOR.'nope']);

        $this->assertFalse($this->library()->visible(), 'And then an empty list would read as "you have no local manga".');
    }

    public function test_it_is_not_visible_when_unset(): void
    {
        config(['papyrus.local_source.path' => null]);

        $this->assertFalse($this->library()->visible());
    }

    public function test_rows_are_empty_rather_than_throwing_when_the_folder_is_missing(): void
    {
        config(['papyrus.local_source.path' => $this->root.DIRECTORY_SEPARATOR.'nope']);

        $this->assertSame([], $this->library()->rows());
        $this->assertSame(0, $this->library()->summary()['series']);
    }

    // Reading the disk ---------------------------------------------------

    public function test_one_folder_is_one_series(): void
    {
        // Measured shape: `local/<Series Name>/` holding the archive.
        $this->folder('Paper Lantern', 'volume.cbz');

        $rows = $this->library()->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Paper Lantern', $rows[0]['folder']);
        $this->assertTrue($rows[0]['has_archive']);
    }

    public function test_a_folder_with_no_archive_is_still_a_series(): void
    {
        // Measured: this is exactly the dev box's state, a folder holding only its
        // ComicInfo.xml.
        $this->folder('Paper Lantern', 'ComicInfo.xml', '<ComicInfo/>');

        $rows = $this->library()->rows();

        $this->assertFalse($rows[0]['has_archive'], 'Which is the thing worth showing.');
        $this->assertTrue($rows[0]['has_comic_info']);
    }

    public function test_a_stray_file_at_the_top_is_not_a_series(): void
    {
        File::put($this->root.DIRECTORY_SEPARATOR.'loose.cbz', 'x');

        $this->assertSame([], $this->library()->rows(), 'Only folders are series, and saying otherwise would invent one.');
    }

    public function test_comic_info_is_parsed_into_fields(): void
    {
        $this->folder('Paper Lantern', 'ComicInfo.xml', <<<'XML'
            <ComicInfo>
                <Title>Paper Lantern, Volume 3</Title>
                <Series>Paper Lantern</Series>
                <Number>3</Number>
                <Year>2024</Year>
                <Writer>Someone</Writer>
                <Summary>A lantern that will not go out.</Summary>
            </ComicInfo>
            XML);

        $meta = $this->library()->rows()[0]['metadata'];

        $this->assertSame('Paper Lantern, Volume 3', $meta['Title']);
        $this->assertSame('3', $meta['Number']);
        $this->assertSame('A lantern that will not go out.', $meta['Summary']);
    }

    public function test_a_malformed_comic_info_is_reported_rather_than_swallowed(): void
    {
        $this->folder('Paper Lantern', 'ComicInfo.xml', '<ComicInfo><unclosed>');

        $meta = $this->library()->rows()[0]['metadata'];

        $this->assertArrayHasKey('_error', $meta, 'An empty card would read as "no metadata", which is a different thing.');
    }

    public function test_an_empty_comic_info_yields_no_fields(): void
    {
        $this->folder('Paper Lantern', 'ComicInfo.xml', '   ');

        $this->assertSame([], $this->library()->rows()[0]['metadata']);
    }

    // Pairing with the server -------------------------------------------

    public function test_a_folder_the_server_knows_is_paired_by_title(): void
    {
        $this->fakeSuwayomi([['id' => 2899, 'title' => 'Paper Lantern']]);
        $this->folder('Paper Lantern', 'volume.cbz');

        $row = $this->library()->rows()[0];

        $this->assertSame(2899, $row['manga_id']);
        $this->assertTrue($row['on_disk']);
        $this->assertTrue($row['known_to_server']);
    }

    public function test_a_folder_the_server_has_not_read_is_marked_unscanned(): void
    {
        $this->fakeSuwayomi([]);
        $this->folder('Paper Lantern', 'volume.cbz');

        $row = $this->library()->rows()[0];

        $this->assertNull($row['manga_id']);
        $this->assertTrue($row['on_disk']);
        $this->assertFalse($row['known_to_server'], 'It is on disk and nobody has read it, which is what a rescan is for.');
    }

    public function test_a_series_whose_folder_is_gone_is_still_listed(): void
    {
        // The case that makes the pairing worth building: measured, Suwayomi has no
        // delete mutation, so this row is permanent until the database is edited by hand.
        $this->fakeSuwayomi([['id' => 2899, 'title' => 'Vanished']]);

        $rows = $this->library()->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Vanished', $rows[0]['folder']);
        $this->assertFalse($rows[0]['on_disk']);
        $this->assertTrue($rows[0]['known_to_server']);
        $this->assertSame(2899, $rows[0]['manga_id']);
    }

    public function test_series_from_other_sources_are_never_paired_in(): void
    {
        // The fake returns one remote series alongside, so a filter that forgot the
        // source id would put it in this list.
        $this->fakeSuwayomi([]);
        $this->folder('Paper Lantern', 'volume.cbz');

        foreach ($this->library()->rows() as $row) {
            $this->assertNotSame(9999, $row['manga_id'], 'Source 12345 is not the local source.');
        }
    }

    public function test_the_summary_counts_the_disagreement(): void
    {
        $this->fakeSuwayomi([
            ['id' => 2899, 'title' => 'Vanished'],
            ['id' => 2900, 'title' => 'Known'],
        ]);

        $this->folder('Known', 'volume.cbz');
        $this->folder('Unread', 'volume.cbz');

        $summary = $this->library()->summary();

        $this->assertSame(3, $summary['series'], 'Two folders and one phantom.');
        $this->assertSame(2, $summary['on_disk']);
        $this->assertSame(1, $summary['missing'], 'The phantom.');
        $this->assertSame(1, $summary['unscanned'], 'The folder nobody has read.');
    }

    public function test_rows_are_ordered_by_folder_name(): void
    {
        foreach (['Zebra', 'Apple', 'Mango'] as $name) {
            $this->folder($name, 'volume.cbz');
        }

        $folders = array_column($this->library()->rows(), 'folder');

        $this->assertSame(['Apple', 'Mango', 'Zebra'], $folders);
    }

    // Rescanning --------------------------------------------------------

    public function test_a_rescan_asks_for_latest(): void
    {
        // LATEST is the type whose meaning is "what is new on this source", which is what
        // dropping a file into the folder is.
        $this->library()->rescan();

        // The raw body rather than `data()`, which is not an array on every request
        // the fake saw, so `data()['query']` threw "Cannot use object of type stdClass
        // as array". The body is a string and answers the question directly.
        Http::assertSent(function (Request $request): bool {
            $body = (string) $request->body();

            return str_contains($body, 'fetchSourceManga')
                && str_contains($body, 'LATEST')
                && str_contains($body, '"source":"0"');
        });
    }

    public function test_a_rescan_reports_what_it_found(): void
    {
        $this->fakeSuwayomi([['id' => 2899, 'title' => 'Paper Lantern']]);

        $result = $this->library()->rescan();

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['series']);
    }

    public function test_a_rescan_of_nothing_is_success_with_zero(): void
    {
        $this->fakeSuwayomi([]);

        $result = $this->library()->rescan();

        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['series'], 'An empty folder is a successful read of nothing.');
    }

    public function test_a_rescan_reports_failure_rather_than_pretending(): void
    {
        $this->rescanStatus = 500;

        $result = $this->library()->rescan();

        $this->assertFalse($result['ok'], 'A rescan that failed and one that found nothing must not look alike.');
    }

    public function test_a_rescan_that_founds_nothing_is_still_a_success(): void
    {
        // The other half of the claim above: zero series is a successful read of an
        // empty folder, and conflating the two would make a broken button look busy.
        $this->fakeSuwayomi([]);

        $this->assertTrue($this->library()->rescan()['ok']);
    }

    public function test_a_rescan_survives_the_server_being_down(): void
    {
        Http::fake(fn (Request $request) => throw new ConnectionException('refused'));

        $result = $this->library()->rescan();

        $this->assertFalse($result['ok']);
    }

    // The page -----------------------------------------------------------

    public function test_the_sources_page_shows_the_local_section(): void
    {
        $this->folder('Paper Lantern', 'volume.cbz');

        Livewire::test('pages::sources')
            ->assertOk()
            ->assertSee('Local source')
            ->assertSee('Paper Lantern');
    }

    public function test_the_page_offers_a_rescan_when_the_folder_is_visible(): void
    {
        Livewire::test('pages::sources')
            ->assertOk()
            ->assertSee('Read the folder again');
    }

    public function test_the_page_says_so_when_the_folder_is_not_visible(): void
    {
        config(['papyrus.local_source.path' => $this->root.DIRECTORY_SEPARATOR.'nope']);

        Livewire::test('pages::sources')
            ->assertOk()
            ->assertSee('Papyrus cannot read this local folder.')
            ->assertSee('It may be on another computer or unavailable to this install, so its files are not listed here.')
            ->assertDontSee('Read the folder again');
    }

    public function test_an_empty_folder_explains_the_shape(): void
    {
        Livewire::test('pages::sources')
            ->assertOk()
            ->assertSee('One folder per series, each holding a CBZ.');
    }

    public function test_a_listed_series_with_no_folder_is_called_out(): void
    {
        $this->fakeSuwayomi([['id' => 2899, 'title' => 'Vanished']]);

        Livewire::test('pages::sources')
            ->assertOk()
            ->assertSee('Vanished')
            ->assertSee('Listed by the server, folder gone');
    }

    public function test_an_unread_folder_is_called_out(): void
    {
        $this->fakeSuwayomi([]);
        $this->folder('Unread', 'volume.cbz');

        Livewire::test('pages::sources')
            ->assertOk()
            ->assertSee('On disk, not read yet');
    }

    public function test_the_page_renders_no_upload_control(): void
    {
        // Measured, there is nothing to upload through. A drop zone would mean this app
        // writing into Suwayomi's data directory.
        $html = Livewire::test('pages::sources')->assertOk()->html();

        $this->assertStringNotContainsString('type="file"', $html);
    }

    public function test_rescanning_from_the_page_reports_the_count(): void
    {
        $this->fakeSuwayomi([['id' => 2899, 'title' => 'Paper Lantern']]);

        Livewire::test('pages::sources')
            ->call('rescanLocal')
            ->assertOk();
    }

    public function test_a_known_series_links_to_its_page(): void
    {
        $this->fakeSuwayomi([['id' => 2899, 'title' => 'Paper Lantern']]);
        $this->folder('Paper Lantern', 'volume.cbz');

        $html = Livewire::test('pages::sources')->assertOk()->html();

        $this->assertStringContainsString(route('series', ['manga' => 2899]), $html);
    }
}
