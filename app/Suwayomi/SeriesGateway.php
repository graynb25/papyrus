<?php

namespace App\Suwayomi;

use App\Support\Suwayomi\Timestamps;

/**
 * Reads one series and its chapters.
 *
 * ## Manga ids are global
 *
 * Measured against v2.3.2243: `manga(id:)` resolves with **no source named**, and
 * the ids are distinct across sources. Manga 174 is One Piece on MangaDex while
 * manga 76 is a Berserker title on Asura Scans, and both resolve from the bare id.
 * An earlier research note claimed the same series on two sources gives two rows
 * with the same id; that is wrong, and it means the series route needs no source
 * segment.
 *
 * ## The chapter list takes no arguments
 *
 * `manga(id:) { chapters { nodes } }` accepts no `first`, no `offset`, no `order`.
 * A 41 chapter series comes back whole, and so would a 1000 chapter one. The list
 * is therefore paginated for the browser and not for the server, which means the
 * initial page render pulls every chapter. That is accepted deliberately: the
 * alternatives are many round trips, or a server-side cache we would then have to
 * keep fresh.
 *
 * ## pageCount is -1 until the pages are fetched
 *
 * Every chapter on a freshly fetched series reports `pageCount: -1`. Only
 * `fetchChapterPages` produces a real count, and that call downloads the images,
 * which took 2.0s for a 22 page chapter. So the chapter list cannot show progress
 * or page counts on first view, and must not pretend otherwise. Progress is
 * recorded from the reader, which is the only place it can come from.
 *
 * ## Timestamps arrive in mixed units
 *
 * `uploadDate` is milliseconds and `fetchedAt` is seconds, in the same payload.
 * {@see Timestamps::fromEpochAuto()} reads either.
 */
class SeriesGateway
{
    public function __construct(
        private readonly Gateway $gateway,
        private readonly Timestamps $timestamps,
    ) {}

    /**
     * The series and its whole chapter list, without mutating anything.
     *
     * This is the cheap path: a plain query that reads what the server already
     * holds. Use it to render a series page the reader has visited before.
     *
     * @return array<string, mixed>|null null when the server does not know this manga
     *
     * @throws SuwayomiException
     */
    public function find(int $mangaId): ?array
    {
        $data = $this->gateway->execute(<<<'GQL'
            query Series($id: Int!) {
                manga(id: $id) {
                    id
                    title
                    url
                    realUrl
                    thumbnailUrl
                    status
                    author
                    artist
                    genre
                    description
                    initialized
                    unreadCount
                    updateStrategy
                    chaptersLastFetchedAt
                    source { id name lang contentWarning }
                    chapters {
                        totalCount
                        nodes {
                            id
                            name
                            chapterNumber
                            scanlator
                            uploadDate
                            pageCount
                            isRead
                            isDownloaded
                            lastPageRead
                            sourceOrder
                        }
                    }
                }
            }
            GQL, ['id' => $mangaId]);

        $manga = $data['manga'] ?? null;

        return is_array($manga) ? $this->hydrate($manga) : null;
    }

    /**
     * Ask the server to refresh the series and its chapter list from the source.
     *
     * Expensive and mutating, so the UI gates it behind an explicit click. A
     * series the reader has never opened has `initialized: false` or a
     * `chaptersLastFetchedAt` of 0, and this is what fills that in.
     *
     * `fetchChapters` and `fetchMangaAndChapters` both throw a bare
     * `java.lang.Exception: No chapters found` when the source's chapter listing
     * yields nothing, which is a real upstream condition rather than a fault of
     * ours. It arrives as a GraphQL error with HTTP 200, so it is caught and
     * reported as "no chapters" instead of surfacing as a failed page.
     *
     * @return array{series: array<string, mixed>|null, error: string|null}
     *
     * @throws SuwayomiException
     */
    public function refresh(int $mangaId): array
    {
        try {
            $data = $this->gateway->execute(<<<'GQL'
                mutation Refresh($id: Int!) {
                    fetchMangaAndChapters(input: { id: $id, fetchManga: true, fetchChapters: true }) {
                        manga {
                            id title url realUrl thumbnailUrl status author artist
                            genre description initialized unreadCount updateStrategy
                            chaptersLastFetchedAt
                            source { id name lang contentWarning }
                        }
                        chapters {
                            id name chapterNumber scanlator uploadDate
                            pageCount isRead isDownloaded lastPageRead sourceOrder
                        }
                    }
                }
                GQL, ['id' => $mangaId], (int) config('papyrus.search.per_source_timeout', 20) + 20);
        } catch (SuwayomiException $e) {
            if (str_contains(strtolower($e->getMessage()), 'no chapters')) {
                return ['series' => $this->find($mangaId), 'error' => $e->getMessage()];
            }

            throw $e;
        }

        $manga = $data['fetchMangaAndChapters']['manga'] ?? null;
        $chapters = $data['fetchMangaAndChapters']['chapters'] ?? [];

        // The mutation returns chapters as a bare list, so it has to be wrapped to
        // match the shape the query returns. A shape mismatch here would silently
        // read as "this series has no chapters".
        if (is_array($manga)) {
            $manga['chapters'] = ['totalCount' => count($chapters), 'nodes' => $chapters];
        }

        return [
            'series' => is_array($manga) ? $this->hydrate($manga) : null,
            'error' => null,
        ];
    }

    /**
     * Many series at once, keyed by manga id.
     *
     * This exists because the library needs it and nothing else does. A library
     * page has to render a title and a cover per entry, and the naive
     * implementation is one `manga(id:)` per entry: measured at about 15ms each
     * against the live server, so 4.5 seconds of serial waiting for a 300 series
     * library.
     *
     * `mangas(filter: {id: {in: [...]}})` is the batch form. Measured with 300
     * ids against v2.3.2243: 246ms, 63KB, one request. The cost tracks the response
     * rather than the number of round trips, so a library page is flat in library
     * size and there is no local metadata cache to keep fresh.
     *
     * Three constraints in this schema, all measured:
     *
     * - The filter is an `IntFilterInput` while `MangaType.id` returns a
     *   `LongString`. Declare the variable as `[Int!]!`, not `[LongString!]!`,
     *   which the server rejects with a type mismatch, and not `[Long!]!`, which
     *   it rejects as an unknown type.
     * - An id the server does not know is dropped silently. Seven ids in, six
     *   rows out, no error. Callers must treat a short result as normal.
     * - `chapters` here takes no arguments either, exactly as on `manga(id:)`.
     *
     * @param  array<int, int>  $mangaIds
     * @return array<int, array<string, mixed>> keyed by manga id, ids cast to int
     *
     * @throws SuwayomiException
     */
    public function fetchMany(array $mangaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $mangaIds)));

        if ($ids === []) {
            return [];
        }

        /*
         * `first` has to exceed the number of ids asked for, because it pages the
         * result set and a library page renders every entry it was asked about.
         * The +10 is so adding one series does not silently truncate a full page.
         *
         * No comments inside the document below. GraphQL permits them and the server's
         * ANTLR parser does not: a `/* ... *\/` block inside a selection set comes back
         * as "Invalid syntax with ANTLR error 'token recognition error at: /'". The
         * explanation belongs here, in PHP, where it costs nothing.
         *
         * `name` on the chapter nodes is the one field added for the continue-reading
         * feed. Free, because the call is made either way to render the cards, and
         * worth having because the label is not derivable from the number: measured,
         * two sources label one chapter `Chapter 1` and `Ch. 1`.
         */
        $data = $this->gateway->execute(<<<'GQL'
            query Many($ids: [Int!]!, $first: Int!) {
                mangas(filter: { id: { in: $ids } }, first: $first) {
                    nodes {
                        id
                        title
                        thumbnailUrl
                        status
                        initialized
                        source { id name lang }
                        chapters {
                            totalCount
                            nodes { id isDownloaded name }
                        }
                    }
                }
            }
            GQL, ['ids' => $ids, 'first' => count($ids) + 10]);

        $rows = [];

        foreach ($data['mangas']['nodes'] ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            $rows[(int) ($node['id'] ?? 0)] = $node;
        }

        return $rows;
    }

    /**
     * The same batch, plus the two chapter fields a navigator needs.
     *
     * `pageCount` and `lastPageRead`, and nothing else is different. A separate method
     * rather than extra fields on `fetchMany()` because the library page has no use
     * for them and `pageCount` is the field that drags a response up: it is absent or
     * -1 for every chapter nobody has downloaded, and a reader with 300 series would
     * carry the difference on every library render to get nothing.
     *
     * Free where it matters, though. The call is one round trip either way and the
     * cost tracks the response rather than the number of ids, measured at 246ms for
     * 300 ids.
     *
     * The reason it is asked for at all: a read-page heat strip needs a fraction per
     * chapter, and a fraction needs both a numerator and a denominator. Ours is
     * `last_page_read`; the server's is `pageCount`, and without it a chapter that is
     * half read is indistinguishable from one that is untouched.
     *
     * @param  array<int, int>  $mangaIds
     * @return array<int, array<string, mixed>>
     *
     * @throws SuwayomiException
     */
    public function fetchManyWithPages(array $mangaIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $mangaIds)));

        if ($ids === []) {
            return [];
        }

        $data = $this->gateway->execute(<<<'GQL'
            query ManyWithPages($ids: [Int!]!, $first: Int!) {
                mangas(filter: { id: { in: $ids } }, first: $first) {
                    nodes {
                        id
                        title
                        chapters {
                            totalCount
                            nodes { id name pageCount lastPageRead isDownloaded }
                        }
                    }
                }
            }
            GQL, ['ids' => $ids, 'first' => count($ids) + 10]);

        $rows = [];

        foreach ($data['mangas']['nodes'] ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            $rows[(int) ($node['id'] ?? 0)] = $node;
        }

        return $rows;
    }

    /**
     * Fetch a chapter's page images.
     *
     * The only call that produces a real `pageCount`, and the only one that
     * downloads the images. It is behind the reader opening a chapter, never
     * behind listing them.
     *
     * @return array{page_count: int, pages: array<int, string>}
     *
     * @throws SuwayomiException
     */
    public function pages(int $chapterId): array
    {
        $data = $this->gateway->execute(
            'mutation Pages($id: Int!) { fetchChapterPages(input: { chapterId: $id }) { chapter { id name pageCount } pages } }',
            ['id' => $chapterId],
            (int) config('papyrus.reader.page_fetch_timeout', 90),
        );

        $payload = $data['fetchChapterPages'] ?? [];
        $pages = $payload['pages'] ?? [];

        return [
            'page_count' => (int) ($payload['chapter']['pageCount'] ?? count($pages)),
            'pages' => array_values(array_map(static fn (mixed $url): string => (string) $url, $pages)),
        ];
    }

    /**
     * The title and the chapter numbers, and nothing else.
     *
     * The tracker's only server need: what a series is called, to resolve its
     * AniList id, and which chapter id is which number, to turn read flags into
     * a progress value. {@see find()} answers both and drags descriptions,
     * genres and every chapter's metadata along with it, and a job that runs
     * once per finished chapter has no use for any of that.
     *
     * @return array{title: string, chapters: array<int, float|null>} chapter id to number
     *
     * @throws SuwayomiException
     */
    public function chapterMap(int $mangaId): ?array
    {
        $data = $this->gateway->execute(<<<'GQL'
            query ChapterMap($id: Int!) {
                manga(id: $id) {
                    title
                    chapters {
                        nodes { id chapterNumber }
                    }
                }
            }
            GQL, ['id' => $mangaId]);

        $manga = $data['manga'] ?? null;

        if (! is_array($manga)) {
            return null;
        }

        $chapters = [];

        foreach (($manga['chapters']['nodes'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }

            $chapters[(int) ($node['id'] ?? 0)] = is_numeric($node['chapterNumber'] ?? null)
                ? (float) $node['chapterNumber']
                : null;
        }

        return [
            'title' => (string) ($manga['title'] ?? ''),
            'chapters' => $chapters,
        ];
    }

    /**
     * Cast the payload's string ids and mixed-unit timestamps once, so no caller
     * has to remember which fields need it.
     *
     * @param  array<string, mixed>  $manga
     * @return array<string, mixed>
     */
    private function hydrate(array $manga): array
    {
        $manga['id'] = $this->timestamps->toInt($manga['id'] ?? null);
        $manga['chaptersLastFetchedAt'] = $this->timestamps->fromEpochAuto($manga['chaptersLastFetchedAt'] ?? null);
        $manga['genre'] = array_values($manga['genre'] ?? []);

        // Source ids exceed 2^53 and must stay strings all the way out, since they
        // key our `sources` table and would be corrupted by any JSON number.
        $manga['source']['id'] = isset($manga['source']['id'])
            ? (string) $manga['source']['id']
            : null;

        $nodes = $manga['chapters']['nodes'] ?? [];

        $manga['chapters']['nodes'] = array_map(function (array $chapter): array {
            $chapter['id'] = $this->timestamps->toInt($chapter['id'] ?? null);
            $chapter['chapterNumber'] = is_numeric($chapter['chapterNumber'] ?? null)
                ? (float) $chapter['chapterNumber']
                : null;
            $chapter['pageCount'] = (int) ($chapter['pageCount'] ?? -1);
            $chapter['isDownloaded'] = (bool) ($chapter['isDownloaded'] ?? false);
            $chapter['uploadDate'] = $this->timestamps->fromEpochAuto($chapter['uploadDate'] ?? null);

            return $chapter;
        }, $nodes);

        return $manga;
    }
}
