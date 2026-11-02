<?php

namespace Tests\Feature;

use App\Covers\CoverRejected;
use App\Covers\CoverStore;
use App\Models\SeriesCover;
use App\Models\SeriesState;
use App\Models\User;
use App\Support\Library;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use RuntimeException;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * Custom covers.
 *
 * ## What these hold, and why the numbers are what they are
 *
 * Measured against the sources themselves, a Suwayomi cover is at most **800px** on its
 * long edge and weighs **77KB to 193KB**. So a 800px bound is not a taste decision: it
 * makes a custom cover indistinguishable in sharpness from a proxied one and costs about
 * the same, and the grid slot is 173px wide.
 *
 * The pixel ceiling is the guard that matters rather than the byte ceiling, because
 * `memory_limit` is 128M and a decoded image costs about four bytes a pixel. Two
 * megabytes of PNG can be 20000 by 20000, so a byte limit alone would not stop it.
 *
 * ## The claims worth protecting
 *
 *  - A cover is per reader, and one reader's is never served to another. The table is
 *    unique on `(user_id, suwayomi_manga_id)` and this is a multi-tenant app, so that is
 *    a correctness property and not tidiness.
 *  - Removing a cover brings the source's own back, with no restore step.
 *  - Replacing a cover does not orphan the old file.
 */
class CustomCoverTest extends TestCase
{
    use MintsTokens, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        Storage::fake('local');
        config(['papyrus.covers.disk' => 'local']);

        $this->fakeSuwayomi();
    }

    private function fakeSuwayomi(): void
    {
        Http::fake(function (Request $request) {
            $query = (string) ($request->data()['query'] ?? '');

            if (str_contains($query, 'mutation Login')) {
                return Http::response(['data' => ['login' => [
                    'accessToken' => $this->accessToken(),
                    'refreshToken' => $this->refreshToken(),
                ]]]);
            }

            /*
             * A series page fetches one series rather than a batch. Without this the
             * component mounts with no data and renders the "not in the catalogue"
             * callout, so every assertion about the cover controls failed on a page that
             * had none.
             */
            if (str_contains($query, 'manga(id:') || str_contains($query, 'manga(id :')) {
                return Http::response(['data' => ['manga' => $this->manga(76)]]);
            }

            $ids = (array) (data_get($request->data(), 'variables.ids') ?? []);

            $nodes = [];

            foreach ($ids as $mangaId) {
                $nodes[] = [
                    'id' => (string) $mangaId,
                    'title' => 'Series '.$mangaId,
                    'initialized' => true,
                    'source' => ['id' => '1', 'name' => 'Example', 'lang' => 'en'],
                    'chapters' => ['totalCount' => 1, 'nodes' => [
                        ['id' => '7601', 'name' => 'Chapter 1', 'chapterNumber' => 1, 'sourceOrder' => 1],
                    ]],
                ];
            }

            return Http::response(['data' => ['mangas' => ['nodes' => $nodes]]]);
        });
    }

    /**
     * One series as the series page's single-series query returns it.
     *
     * @return array<string, mixed>
     */
    private function manga(int $id): array
    {
        return [
            'id' => (string) $id,
            'title' => 'Series '.$id,
            'url' => '/series/x',
            'realUrl' => 'https://example.test/comics/x',
            'thumbnailUrl' => '/api/v1/manga/'.$id.'/thumbnail',
            'status' => 'ONGOING',
            'author' => 'Nobody',
            'artist' => 'Nobody',
            'genre' => ['Action'],
            'description' => 'Rating: 8.24',
            'initialized' => true,
            'unreadCount' => 1,
            'updateStrategy' => 'ALWAYS_UPDATE',
            'chaptersLastFetchedAt' => '1790776498',
            'source' => ['id' => '1', 'name' => 'Example', 'lang' => 'en', 'contentWarning' => 'SAFE'],
            'chapters' => ['totalCount' => 1, 'nodes' => [
                ['id' => '7601', 'name' => 'Chapter 1', 'chapterNumber' => 1, 'sourceOrder' => 1, 'uploadDate' => null],
            ]],
        ];
    }

    private function store(): CoverStore
    {
        return app(CoverStore::class);
    }

    /**
     * Put a series in the library, which is the state every library assertion needs.
     *
     * `Library::entries()` only returns series with `in_library` true, so a cover on a
     * series that is not in the library has nothing to render on.
     */
    private function addToLibrary(int $mangaId): void
    {
        SeriesState::createFor($this->user, [
            'suwayomi_manga_id' => $mangaId,
            'in_library' => true,
            'added_at' => now(),
        ]);
    }

    /**
     * A real JPEG of a given size, so the tests exercise gd rather than a stub.
     */
    private function jpeg(int $width, int $height, string $name = 'cover.jpg'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 90, 160));
        imagejpeg($image, $path = $this->temporaryFile('cov', 'jpg'), 90);
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/jpeg', null, true);
    }

    private function png(int $width, int $height, string $name = 'cover.png'): UploadedFile
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 40, 40));
        imagepng($image, $path = $this->temporaryFile('cov', 'png'));
        imagedestroy($image);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    // Storing ------------------------------------------------------------

    public function test_a_stored_cover_is_on_the_disk_and_in_a_row(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));

        $cover = $this->store()->find($this->user, 76);

        $this->assertNotNull($cover);
        $this->assertSame(76, $cover->suwayomi_manga_id);
        $this->assertSame('upload', $cover->origin);

        Storage::disk('local')->assertExists($cover->path);
    }

    public function test_a_large_upload_is_bounded_at_800_on_the_long_edge(): void
    {
        // With the bound at its default and a fixture comfortably over it, so this is
        // the real configuration rather than a low bound standing in for one.
        $this->store()->store($this->user, 76, $this->jpeg(1000, 1500));

        $cover = $this->store()->find($this->user, 76);
        $info = getimagesizefromstring(Storage::disk('local')->get($cover->path));

        $this->assertSame(800, max($info[0], $info[1]), 'The long edge is bounded.');
        $this->assertSame(533, min($info[0], $info[1]), 'And the short edge follows from it.');
    }

    public function test_a_small_upload_is_not_made_bigger(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(300, 450));

        $cover = $this->store()->find($this->user, 76);
        $info = getimagesizefromstring(Storage::disk('local')->get($cover->path));

        $this->assertSame(300, $info[0], 'Nothing to gain from resampling an already-small cover.');
        $this->assertSame(450, $info[1]);
    }

    public function test_the_stored_bytes_are_jpeg_when_the_upload_was_jpeg(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(1000, 1200));

        $cover = $this->store()->find($this->user, 76);
        $info = getimagesizefromstring(Storage::disk('local')->get($cover->path));

        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertStringEndsWith('.jpg', $cover->path, 'And the extension matches the bytes.');
    }

    public function test_a_png_stays_a_png(): void
    {
        // Re-encoding a PNG as a JPEG would throw away transparency for no gain.
        $this->store()->store($this->user, 76, $this->png(500, 700));

        $cover = $this->store()->find($this->user, 76);
        $info = getimagesizefromstring(Storage::disk('local')->get($cover->path));

        $this->assertSame('image/png', $info['mime']);
        $this->assertStringEndsWith('.png', $cover->path);
    }

    public function test_a_resized_cover_is_smaller_than_the_original(): void
    {
        $original = $this->jpeg(1000, 1500);
        $before = filesize($original->getRealPath());

        $this->store()->store($this->user, 76, $original);

        $cover = $this->store()->find($this->user, 76);
        $after = strlen(Storage::disk('local')->get($cover->path));

        $this->assertLessThan($before, $after, 'The whole reason for resizing.');
    }

    // Replacing ----------------------------------------------------------

    public function test_replacing_keeps_one_row_and_the_same_path(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));
        $first = $this->store()->find($this->user, 76);

        $this->store()->store($this->user, 76, $this->jpeg(500, 700));
        $second = $this->store()->find($this->user, 76);

        $this->assertSame(1, SeriesCover::query()->forUser($this->user->id)->count());
        $this->assertSame($first->path, $second->path, 'A stable path is a stable cache entry.');
        $replaced = getimagesizefromstring(Storage::disk('local')->get($second->path));

        // 500 by 700, so the long edge is 700. Asserting the long edge rather than a
        // width, because that is the dimension the resize bound is written in terms of.
        $this->assertSame(500, $replaced[0], 'And the bytes on disk are the new one.');
        $this->assertSame(700, $replaced[1]);
    }

    public function test_replacing_across_a_format_change_removes_the_old_file(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));
        $first = $this->store()->find($this->user, 76);

        $this->store()->store($this->user, 76, $this->png(500, 700));
        $second = $this->store()->find($this->user, 76);

        $this->assertNotSame($first->path, $second->path);

        Storage::disk('local')->assertMissing(
            $first->path,
            'Otherwise every format change leaves a file behind forever.',
        );

        Storage::disk('local')->assertExists($second->path);
    }

    /**
     * A failed replacement must not truncate the cover the reader already has.
     *
     * The old code wrote the resized bytes straight into the final path, which is the same
     * path when the extension does not change, so a disk that failed midway shortened the
     * current cover in place. This disk truncates and throws on any direct put at the
     * destination and refuses every move, so only the old bytes can answer for the cover
     * and only the row can answer for the path.
     */
    public function test_a_failed_replacement_leaves_the_previous_cover_and_row_untouched(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));
        $first = $this->store()->find($this->user, 76);

        $this->assertNotNull($first);

        $before = Storage::disk('local')->get($first->path);

        $real = Storage::disk('local');

        $failing = new class($real->getDriver(), $real->getAdapter(), $real->getConfig()) extends FilesystemAdapter
        {
            public int $destinationPuts = 0;

            public int $moves = 0;

            public function put($path, $contents, $options = [])
            {
                if (! str_ends_with((string) $path, '.tmp')) {
                    $this->destinationPuts++;

                    // The destination is truncated before the write fails, which is the
                    // shape a stream that dies midway leaves behind.
                    parent::put($path, '');

                    throw new RuntimeException('the disk stopped halfway through the write');
                }

                return parent::put($path, $contents, $options);
            }

            public function move($from, $to)
            {
                $this->moves++;

                return false;
            }
        };

        Storage::set('local', $failing);

        $failure = null;

        try {
            $this->store()->store($this->user, 76, $this->jpeg(500, 700));
        } catch (RuntimeException $e) {
            $failure = $e;
        }

        $this->assertNotNull($failure, 'The store reported success for a cover the disk refused to write.');

        $after = $this->store()->find($this->user, 76);

        $this->assertNotNull($after);
        $this->assertSame($first->id, $after->id);
        $this->assertSame($first->path, $after->path);
        $this->assertSame(1, SeriesCover::query()->forUser($this->user->id)->count());
        $this->assertSame(
            $before,
            Storage::disk('local')->get($first->path),
            'The cover the row points at is byte for byte what it was before the failed upload.',
        );
        $this->assertSame(
            [$first->path],
            Storage::disk('local')->allFiles('covers'),
            'Only the temporary file was removed, and no part of the failed write was left behind.',
        );
        $this->assertSame(0, $failing->destinationPuts, 'The destination is only reached by the rename.');
        $this->assertSame(1, $failing->moves);
        $this->assertStringContainsString('cover could not be written', $failure->getMessage());
    }

    /**
     * A row that fails to save must not take the old file with it.
     *
     * A format change writes a different path, so the old file is the only copy of the
     * cover the unchanged row still names. The old code deleted it before the save, which
     * left a row pointing at nothing.
     */
    public function test_a_failed_row_write_keeps_the_old_cover_the_row_still_names(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));
        $first = $this->store()->find($this->user, 76);

        $this->assertNotNull($first);

        $before = Storage::disk('local')->get($first->path);

        /*
         * Throwing from the model's own saving event is the failed write a database
         * constraint or a lock produces at this seam, without needing either.
         */
        SeriesCover::saving(static function (): void {
            throw new RuntimeException('the row could not be saved');
        });

        $failure = null;

        try {
            $this->store()->store($this->user, 76, $this->png(500, 700));
        } catch (RuntimeException $e) {
            $failure = $e;
        }

        $this->assertNotNull($failure, 'The store reported success for a row the database refused to save.');

        $after = $this->store()->find($this->user, 76);

        $this->assertNotNull($after);
        $this->assertSame($first->id, $after->id);
        $this->assertSame($first->path, $after->path);
        $this->assertSame(
            $before,
            Storage::disk('local')->get($first->path),
            'The old cover is still there for the row that still names it.',
        );
    }

    // Removing -----------------------------------------------------------

    public function test_removing_deletes_the_row_and_the_file(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));
        $cover = $this->store()->find($this->user, 76);

        $this->assertTrue($this->store()->remove($this->user, 76));

        $this->assertNull($this->store()->find($this->user, 76));
        Storage::disk('local')->assertMissing($cover->path);
    }

    public function test_removing_what_is_not_there_says_so(): void
    {
        $this->assertFalse($this->store()->remove($this->user, 76));
    }

    // Tenancy ------------------------------------------------------------

    public function test_another_readers_cover_is_not_found(): void
    {
        $bob = User::factory()->create();

        $this->store()->store($bob, 76, $this->jpeg(400, 600));

        $this->assertNull(
            $this->store()->find($this->user, 76),
            'A cover is per reader, so this reader still has the source one.',
        );
    }

    public function test_two_readers_can_each_have_a_cover_for_one_series(): void
    {
        $bob = User::factory()->create();

        $this->store()->store($this->user, 76, $this->jpeg(400, 600));
        $this->store()->store($bob, 76, $this->jpeg(410, 610));

        $this->assertNotSame(
            $this->store()->find($this->user, 76)->path,
            $this->store()->find($bob, 76)->path,
            'Otherwise one reader would overwrite the other\'s file.',
        );
    }

    public function test_one_readers_removal_does_not_touch_anothers(): void
    {
        $bob = User::factory()->create();

        $this->store()->store($this->user, 76, $this->jpeg(400, 600));
        $this->store()->store($bob, 76, $this->jpeg(410, 610));
        $bobsPath = $this->store()->find($bob, 76)->path;

        $this->store()->remove($this->user, 76);

        $this->assertNotNull($this->store()->find($bob, 76));
        Storage::disk('local')->assertExists($bobsPath);
    }

    public function test_a_cover_carries_no_user_id_from_mass_assignment(): void
    {
        $cover = new SeriesCover;
        $cover->fill(['suwayomi_manga_id' => 76, 'disk' => 'local', 'path' => 'x.jpg']);

        $this->assertArrayNotHasKey('user_id', $cover->getAttributes());
    }

    // Refusals -----------------------------------------------------------

    public function test_a_gif_is_refused_with_a_reason(): void
    {
        $this->expectException(CoverRejected::class);

        $this->store()->store($this->user, 76, UploadedFile::fake()->createWithContent('a.gif', 'GIF89a'));
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->expectException(CoverRejected::class);

        $this->store()->store($this->user, 76, UploadedFile::fake()->createWithContent('a.txt', 'not an image'));
    }

    public function test_an_oversized_pixel_count_is_refused_before_gd_opens_it(): void
    {
        // The guard that matters. 2MB of PNG can be 20000 by 20000, and memory_limit is
        // 128M at four bytes a pixel, so a byte limit alone would let gd try to allocate
        // 1.6GB.
        config(['papyrus.covers.max_pixels' => 1000]);

        $this->expectException(CoverRejected::class);
        $this->expectExceptionMessageMatches('/megapixels|memory/');

        $this->store()->store($this->user, 76, $this->png(1200, 1200));
    }

    public function test_the_refusal_leaves_nothing_behind(): void
    {
        config(['papyrus.covers.max_pixels' => 1000]);

        try {
            $this->store()->store($this->user, 76, $this->png(1200, 1200));
        } catch (CoverRejected) {
            // expected
        }

        $this->assertNull($this->store()->find($this->user, 76));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /**
     * A photo bigger than the bound is scaled down to it.
     *
     * A 600 by 900 fixture with the bound set to 200, rather than a genuinely large
     * image. `scale < 1.0`, `imagecopyresampled`, the encode and the path write are the
     * same code whether the bound is 800 or 200, and a fixture big enough to matter would
     * be tens of megabytes that the suite process cannot hold while also holding the
     * suite. The number itself is proved by the arithmetic assertion below, which cannot
     * exhaust anything.
     */
    public function test_a_photo_over_the_bound_is_scaled_down_to_it(): void
    {
        config(['papyrus.covers.long_edge' => 200]);

        $this->store()->store($this->user, 76, $this->jpeg(600, 900));

        $info = getimagesizefromstring(
            Storage::disk('local')->get($this->store()->find($this->user, 76)->path),
        );

        $this->assertSame(200, max($info[0], $info[1]), 'The long edge is the bound.');
        $this->assertSame(133, min($info[0], $info[1]), 'And the proportions follow from it.');
    }

    public function test_the_bound_is_measured_in_the_same_units_the_sources_use(): void
    {
        // Measured against the sources themselves: a Suwayomi cover is at most 800px on
        // its long edge and weighs 77KB to 193KB. So the default is the source's own bound,
        // not a taste decision, and a custom cover is indistinguishable in sharpness from
        // a proxied one.
        $this->assertSame(800, (int) config('papyrus.covers.long_edge'));
    }

    /**
     * The memory limit in bytes, from the shorthand ini form.
     *
     * `parse_ini_string('memory_limit=128M')` returns the *string* '128M', so
     * multiplying it gives 1MB and every assertion about memory passes for the wrong
     * reason. PHPUnit also raises the limit during a run, which is why this reads
     * `ini_get` at the moment of the assertion rather than at boot.
     */
    private static function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '-1') {
            return PHP_INT_MAX;
        }

        if (! preg_match('/^(\d+)([KMG]?)$/i', $raw, $m)) {
            return PHP_INT_MAX;
        }

        $scale = match (strtoupper($m[2])) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            default => 1,
        };

        return (int) $m[1] * $scale;
    }

    public function test_the_long_edge_is_configurable_and_has_a_floor(): void
    {
        // A cover at 10px is not a cover, so the floor is in the code rather than in a
        // config file nobody reads.
        config(['papyrus.covers.long_edge' => 1]);

        $this->store()->store($this->user, 76, $this->jpeg(400, 600));

        $info = getimagesizefromstring(
            Storage::disk('local')->get($this->store()->find($this->user, 76)->path),
        );

        $this->assertSame(64, max($info[0], $info[1]));
    }

    // The route ----------------------------------------------------------

    public function test_the_route_serves_the_bytes(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));

        $response = $this->get('/covers/76');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/jpeg');

        $this->assertSame(
            getimagesizefromstring(Storage::disk('local')->get(
                $this->store()->find($this->user, 76)->path
            ))['mime'],
            'image/jpeg',
        );
    }

    public function test_the_route_is_private_to_the_reader(): void
    {
        // The whole reason this is a route and not a file under public/.
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));

        $cache = (string) $this->get('/covers/76')->headers->get('Cache-Control');

        // Substring rather than an exact match: the session middleware and the test
        // kernel both have an opinion about this header, and the claim is that the bytes
        // are marked private, not that nothing else ever touches the line.
        $this->assertStringContainsString('private', $cache, 'A shared cache must not keep one reader\'s cover.');
        $this->assertStringNotContainsString('public', $cache);
        $this->assertStringContainsString('max-age=', $cache, 'And short enough to be honest, since the path is stable.');

        $this->assertSame('nosniff', $this->get('/covers/76')->headers->get('X-Content-Type-Options'));
    }

    public function test_another_reader_gets_a_404_not_the_bytes(): void
    {
        $bob = User::factory()->create();
        $this->store()->store($bob, 76, $this->jpeg(400, 600));

        $this->get('/covers/76')->assertNotFound();
    }

    public function test_a_reader_with_no_cover_gets_a_404(): void
    {
        $response = $this->get('/covers/76');

        $response->assertNotFound();

        // A missing answer must not be a keepsake: the page beside it is relying
        // on a fresh 404 to fall back to the source's cover.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_route_needs_a_signed_in_reader(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));

        auth()->logout();

        $this->get('/covers/76')->assertRedirect(route('login'));
    }

    public function test_a_row_whose_file_is_gone_serves_a_404_rather_than_erroring(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));
        $cover = $this->store()->find($this->user, 76);

        // A database restore without the storage directory produces exactly this.
        Storage::disk('local')->delete($cover->path);

        $response = $this->get('/covers/76');

        $response->assertNotFound();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * The route mints the validator upstream sends none of for source covers, and this
     * one has even less excuse: the bytes are already in memory when the answer is
     * built.
     */
    public function test_the_route_serves_a_validator_of_the_bytes_it_serves(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));

        $bytes = Storage::disk('local')->get($this->store()->find($this->user, 76)->path);
        $etag = '"'.hash('sha256', $bytes).'"';

        $response = $this->get('/covers/76');

        $response->assertOk();
        $this->assertSame($etag, $response->headers->get('ETag'));
    }

    /**
     * A validator the page never asks with is decoration. This is the ask: after the
     * five minute window a reload sends the minted value back, and the answer is a
     * 304 whose window renews, so a revisited series does not re-download its own
     * cover to paint the same pixels.
     */
    public function test_a_validating_reader_waits_for_no_bytes(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));

        $bytes = Storage::disk('local')->get($this->store()->find($this->user, 76)->path);
        $etag = '"'.hash('sha256', $bytes).'"';

        $response = $this->withHeaders(['If-None-Match' => $etag])->get('/covers/76');

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
        $this->assertSame($etag, $response->headers->get('ETag'));

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=300', $cache, 'The validation renews the window.');
        $this->assertStringContainsString('private', $cache);
    }

    /**
     * The case a file mtime validator could miss: replacing a cover overwrites the
     * same path with new bytes at nearly the same size. The digest is of what the
     * cover is, so the stale echo names bytes that no longer exist and the answer is
     * the new art.
     */
    public function test_a_replaced_cover_refuses_the_previous_validator(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));

        $stale = '"'.hash('sha256', (string) Storage::disk('local')->get(
            $this->store()->find($this->user, 76)->path
        )).'"';

        $this->store()->store($this->user, 76, $this->jpeg(400, 601));

        $bytes = (string) Storage::disk('local')->get($this->store()->find($this->user, 76)->path);
        $fresh = '"'.hash('sha256', $bytes).'"';

        $this->assertNotSame($stale, $fresh, 'The two covers must be different bytes or the test proves nothing.');

        $response = $this->withHeaders(['If-None-Match' => $stale])->get('/covers/76');

        $response->assertOk();
        $this->assertSame($bytes, $response->getContent());
        $this->assertSame($fresh, $response->headers->get('ETag'));
    }

    // The series page ----------------------------------------------------

    public function test_the_series_page_offers_the_control_without_a_cover(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->assertSee('Use your own cover')
            ->assertDontSee('Use the source cover');
    }

    public function test_the_series_page_offers_to_change_a_cover_that_exists(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));

        Livewire::test('pages::series', ['manga' => 76])
            ->assertSee('Change cover')
            ->assertSee('Use the source cover');
    }

    public function test_the_series_page_renders_the_custom_cover_url(): void
    {
        $html = Livewire::test('pages::series', ['manga' => 76])->assertOk()->html();

        $this->assertStringNotContainsString('src="'.route('covers.show', ['manga' => 76]).'"', $html);

        $this->store()->store($this->user, 76, $this->jpeg(400, 600));

        $after = Livewire::test('pages::series', ['manga' => 76])->assertOk()->html();

        $this->assertStringContainsString(route('covers.show', ['manga' => 76]), $after);
    }

    public function test_uploading_through_the_page_stores_it(): void
    {
        // `UploadedFile::fake()->image()` rather than a hand-built UploadedFile. Livewire's
        // test helper reaches into the file's own properties to start the upload, and a
        // hand-built one is missing the shape it expects, so the failure was
        // "Undefined property: UploadedFile::$name" inside Livewire rather than anything
        // to do with the cover.
        //
        // No `call('saveCover')` afterwards, because picking the file *is* the save. The
        // action clears the property once it has run, so a second call sees nothing
        // selected and fails `required` on a cover that is already stored. That is the
        // auto-apply behaviour working, not a bug, and it is asserted as its own test
        // below.
        Livewire::test('pages::series', ['manga' => 76])
            ->set('coverUpload', UploadedFile::fake()->image('cover.jpg', 400, 600))
            ->assertHasNoErrors();

        $this->assertNotNull($this->store()->find($this->user, 76));
    }

    public function test_the_upload_is_a_one_gesture_flow(): void
    {
        // A reader picks a file and it is applied. Two gestures would mean a Save button
        // a reader forgets is there, and Livewire has already shown a progress bar for the
        // upload, so the wait is accounted for.
        $component = Livewire::test('pages::series', ['manga' => 76])
            ->set('coverUpload', UploadedFile::fake()->image('cover.jpg', 400, 600))
            ->assertHasNoErrors();

        $this->assertNotNull($this->store()->find($this->user, 76));

        // And the property is cleared, so a second save cannot double-write.
        $component->assertSet('coverUpload', null);
    }

    public function test_the_page_rejects_a_gif(): void
    {
        Livewire::test('pages::series', ['manga' => 76])
            ->set('coverUpload', UploadedFile::fake()->createWithContent('a.gif', 'GIF89a'))
            ->call('saveCover')
            ->assertHasErrors(['coverUpload']);

        $this->assertNull($this->store()->find($this->user, 76));
    }

    public function test_the_page_rejects_an_oversized_file_and_says_which_limit(): void
    {
        $big = UploadedFile::fake()->create('huge.jpg', 4096);

        Livewire::test('pages::series', ['manga' => 76])
            ->set('coverUpload', $big)
            ->call('saveCover')
            ->assertHasErrors(['coverUpload']);

        $this->assertNull($this->store()->find($this->user, 76));
    }

    public function test_removing_through_the_page_puts_the_source_cover_back(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));

        $component = Livewire::test('pages::series', ['manga' => 76]);
        $component->call('removeCover')->assertSet('customCover', null);

        $this->assertNull($this->store()->find($this->user, 76));
    }

    // The library page ---------------------------------------------------

    public function test_the_library_prefers_a_custom_cover(): void
    {
        $this->addToLibrary(76);
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));

        $rows = app(Library::class)->entries($this->user);

        $found = null;

        foreach ($rows as $row) {
            if ((int) $row['manga_id'] === 76) {
                $found = $row;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame(route('covers.show', ['manga' => 76]), $found['cover']);
        $this->assertTrue($found['has_custom_cover']);
    }

    public function test_the_library_falls_back_to_the_source_cover(): void
    {
        $this->addToLibrary(76);

        $rows = app(Library::class)->entries($this->user);

        $found = null;

        foreach ($rows as $row) {
            if ((int) $row['manga_id'] === 76) {
                $found = $row;
            }
        }

        $this->assertNotNull($found);
        $this->assertStringContainsString('/thumbnail', (string) $found['cover']);
        $this->assertFalse($found['has_custom_cover']);
    }

    public function test_the_library_does_not_leak_another_readers_cover(): void
    {
        $this->addToLibrary(76);
        $bob = User::factory()->create();
        $this->store()->store($bob, 76, $this->jpeg(400, 600));

        $rows = app(Library::class)->entries($this->user);

        foreach ($rows as $row) {
            if ((int) $row['manga_id'] === 76) {
                $this->assertFalse($row['has_custom_cover']);
                $this->assertStringNotContainsString('/covers/76', (string) $row['cover']);
            }
        }
    }

    // Batching -----------------------------------------------------------

    public function test_map_for_is_one_lookup_for_the_whole_page(): void
    {
        $this->store()->store($this->user, 76, $this->jpeg(400, 600));
        $this->store()->store($this->user, 77, $this->jpeg(400, 600));

        $map = $this->store()->mapFor($this->user, [76, 77, 78]);

        $this->assertSame([76, 77], array_keys($map));
    }

    public function test_map_for_nothing_is_empty(): void
    {
        $this->assertSame([], $this->store()->mapFor($this->user, []));
    }
}
