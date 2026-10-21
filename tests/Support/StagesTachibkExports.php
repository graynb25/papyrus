<?php

namespace Tests\Support;

use App\Export\TachibkExport;
use App\Models\User;
use App\Suwayomi\AuthManager;
use App\Suwayomi\BackupGateway;
use App\Suwayomi\FailureKind;
use App\Suwayomi\Gateway;
use App\Suwayomi\SuwayomiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Feature\Suwayomi\SeriesGatewayTest;

/**
 * The exporter's own test rig, shared by every test that runs {@see TachibkExport} for a
 * real user: the fresh server export staged by a bound gateway fake, the ledger's four
 * real tables, and the two queries a plan is built from answered by the one HTTP fake.
 *
 * Extracted from {@see TachibkExportTest} when the export's job needed the same
 * arrangement, rather than a second copy that could drift from the first one the receipt's
 * numbers were proven against. The fake answers login through {@see
 * \Tests\Support\MintsTokens}, so a using class carries that trait beside this one.
 */
trait StagesTachibkExports
{
    /**
     * Same trap as {@see SeriesGatewayTest}: one fake for the
     * whole class, overrides keyed by the needle in the document they answer.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $answers = [];

    /**
     * Bind the fresh export as a file this runner's fake gateway already holds.
     */
    private function stageFreshExportFrom(string $fixture): void
    {
        $this->app->instance(
            BackupGateway::class,
            new class(app(Gateway::class), app(AuthManager::class), $fixture) extends BackupGateway
            {
                public function __construct(Gateway $gateway, AuthManager $auth, private readonly string $fixture)
                {
                    parent::__construct($gateway, $auth);
                }

                public function createBackup(array $flags = []): string
                {
                    return 'backups/staged-fresh.tachibk';
                }

                public function download(string $path, string $sink, ?int $timeout = null): void
                {
                    if (! copy($this->fixture, $sink)) {
                        throw new RuntimeException('the fake download could not stage its fixture');
                    }
                }
            },
        );
    }

    /**
     * Bind a fresh export the runner's fake gateway holds, whose download refuses.
     *
     * The refusal is the shape a real 503 takes inside the gateway: a wrapped
     * {@see SuwayomiException}, the run's own refusal family.
     */
    private function stageFreshExportFromRefusing(string $sentence): void
    {
        $this->app->instance(
            BackupGateway::class,
            new class(app(Gateway::class), app(AuthManager::class), $sentence) extends BackupGateway
            {
                public function __construct(Gateway $gateway, AuthManager $auth, private readonly string $sentence)
                {
                    parent::__construct($gateway, $auth);
                }

                public function createBackup(array $flags = []): string
                {
                    return 'backups/staged-fresh.tachibk';
                }

                public function download(string $path, string $sink, ?int $timeout = null): void
                {
                    throw new SuwayomiException($this->sentence, FailureKind::Unavailable);
                }
            },
        );
    }

    /**
     * Redirect the staged download to a plain RuntimeException, the one exception kind
     * {@see TachibkExport} does not translate into a refusal: the shape an unexpected
     * error takes reaching the job, whatever actually raised it.
     */
    private function stageFreshExportThatBreaksPlainly(): void
    {
        $this->app->instance(
            BackupGateway::class,
            new class(app(Gateway::class), app(AuthManager::class)) extends BackupGateway
            {
                public function createBackup(array $flags = []): string
                {
                    return 'backups/staged-fresh.tachibk';
                }

                public function download(string $path, string $sink, ?int $timeout = null): void
                {
                    throw new RuntimeException('the transfer gave up on its own terms');
                }
            },
        );
    }

    /**
     * The exporter main test's arrangement, carried here so the suites that need it share
     * one copy instead of three: Naruto with two shelves, and the two chapters the server
     * never learned about marked read in the ledger. The job test's receipt numbers and
     * the download seam's product both assert against what this arrangement produced,
     * which is why neither of them resets its one knob differently.
     */
    private function arrangeTheRoundTrip(User $user): void
    {
        $seriesState = $this->ownSeries($user, 76);
        $default = $this->ownShelf($user, 'Default');
        $readingSoon = $this->ownShelf($user, 'Reading soon');
        $this->fileUnder($user, $default, $seriesState);
        $this->fileUnder($user, $readingSoon, $seriesState);
        $this->markRead($user, 76, 904);
        $this->markRead($user, 76, 905);

        $this->withCategories = true;

        $this->stageFreshExportFrom($this->buildTachibkBackup());
        $this->answerIdentities([76 => ['source' => '2327480808438768017', 'url' => 'rSmmf']]);
        $this->answerSeries(76, [
            ['id' => 901, 'sourceOrder' => 1, 'chapterNumber' => 700.5],
            ['id' => 902, 'sourceOrder' => 2, 'chapterNumber' => 700],
            ['id' => 903, 'sourceOrder' => 3, 'chapterNumber' => 699],
            ['id' => 904, 'sourceOrder' => 4, 'chapterNumber' => 698],
            ['id' => 905, 'sourceOrder' => 5, 'chapterNumber' => 686.5],
        ]);
    }

    /**
     * The whole Identities answer, shaped like the server's: nodes that carry the pair.
     *
     * @param  array<int, array{source: string, url: string}>  $identities
     */
    private function answerIdentities(array $identities): void
    {
        $nodes = [];

        foreach ($identities as $mangaId => $identity) {
            $nodes[] = ['id' => $mangaId, 'url' => $identity['url'], 'source' => ['id' => $identity['source']]];
        }

        $this->answers['query Identities'] = ['data' => ['mangas' => ['nodes' => $nodes]]];
    }

    /**
     * The `find()` answer for one series: initialised, and carrying the two keys the
     * importer pairs by.
     *
     * @param  list<array{id: int, sourceOrder: int, chapterNumber: float|int}>  $nodes
     */
    private function answerSeries(int $mangaId, array $nodes): void
    {
        $this->answers['query Series'] = ['data' => ['manga' => [
            'id' => $mangaId,
            'title' => 'anything',
            'url' => 'their-source-url',
            'initialized' => true,
            'source' => ['id' => '1'],
            'chapters' => ['totalCount' => count($nodes), 'nodes' => $nodes],
        ]]];
    }

    private function ownSeries(User $user, int $mangaId): int
    {
        $id = (int) DB::table('series_states')->insertGetId([
            'user_id' => $user->getKey(),
            'suwayomi_manga_id' => $mangaId,
            'in_library' => true,
        ]);

        if ($id === 0) {
            $this->fail('the series state row did not land');
        }

        return $id;
    }

    private function ownShelf(User $user, string $name): int
    {
        $id = (int) DB::table('categories')->insertGetId([
            'user_id' => $user->getKey(),
            'name' => $name,
            'order_column' => 0,
        ]);

        if ($id === 0) {
            $this->fail('the shelf row did not land');
        }

        return $id;
    }

    private function fileUnder(User $user, int $categoryId, int $seriesStateId): void
    {
        DB::table('category_series')->insert([
            'user_id' => $user->getKey(),
            'category_id' => $categoryId,
            'series_state_id' => $seriesStateId,
        ]);
    }

    private function markRead(User $user, int $mangaId, int $chapterId): void
    {
        DB::table('chapter_states')->insert([
            'user_id' => $user->getKey(),
            'suwayomi_chapter_id' => $chapterId,
            'suwayomi_manga_id' => $mangaId,
            'is_read' => true,
        ]);
    }

    /**
     * The one HTTP fake the rig answers through: login mints tokens, then the answers
     * array, then an empty document, exactly TachibkExportTest's own closure.
     */
    private function fakeTheExportLedgerQueries(): void
    {
        Http::fake(function (Request $request) {
            $body = $request->data();
            $query = is_array($body) ? ($body['query'] ?? '') : '';

            if (is_string($query) && str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => ['accessToken' => $this->accessToken(), 'refreshToken' => $this->refreshToken()]],
                ]);
            }

            foreach ($this->answers as $needle => $response) {
                if (is_string($query) && str_contains($query, $needle)) {
                    return Http::response($response);
                }
            }

            return Http::response(['data' => []]);
        });
    }
}
