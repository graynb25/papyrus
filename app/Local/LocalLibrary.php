<?php

namespace App\Local;

use App\Suwayomi\Gateway;
use App\Suwayomi\SuwayomiException;

/**
 * What Suwayomi's local source is holding, and what is actually on disk.
 *
 * ## Why this reads two things instead of one
 *
 * They can disagree, and when they do the disagreement is the useful information.
 * Measured: the local source lists manga 2899 "Papyrus Probe", and the only file left on
 * disk is that folder's `ComicInfo.xml` with the archive gone. Suwayomi has **no delete
 * mutation**, so a series whose file has been removed stays listed forever.
 *
 * A view that showed either one alone would be lying. Showing the source's listing
 * pretends the files are there; showing the folder pretends Suwayomi knows about it.
 * Paired, the reader can see that their local library is out of step with their disk and
 * which way.
 *
 * ## Why there is no upload
 *
 * Suwayomi has no upload API at all: measured, every GET on `/api/v1/...` returns the
 * 4,484-byte WebUI catch-all and every other method a 404. A drop zone would mean
 * Laravel writing into Suwayomi's data directory, which is a boundary this project has
 * not crossed, and the folder is shared by every reader because the source cannot scope
 * it. So this reads, and offers a rescan.
 *
 * ## The folder is only visible on the same machine
 *
 * The path is Suwayomi's data root, which is a filesystem path on whatever machine runs
 * the server. This deployment is a native Windows JAR beside Laravel, so it is visible.
 * When it is not, `visible()` is false and the caller says so rather than showing an
 * empty library that looks like "you have no local manga".
 */
class LocalLibrary
{
    /** The local source's id, which is not a manga id and not 1. */
    public const SOURCE_ID = '0';

    public function __construct(
        private readonly Gateway $gateway,
    ) {}

    /**
     * Whether the folder can be read from here at all.
     */
    public function visible(): bool
    {
        $path = $this->path();

        return $path !== null && is_dir($path) && is_readable($path);
    }

    /**
     * The configured folder, or null when it is not set.
     */
    public function path(): ?string
    {
        $path = config('papyrus.local_source.path');

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * One row per series folder on disk, paired with what Suwayomi thinks of it.
     *
     * @return array<int, array{
     *     folder: string,
     *     title: string|null,
     *     manga_id: int|null,
     *     files: array<int, array{name: string, bytes: int, kind: string}>,
     *     has_archive: bool,
     *     has_comic_info: bool,
     *     metadata: array<string, string>,
     *     on_disk: bool,
     *     known_to_server: bool
     * }>
     */
    public function rows(): array
    {
        $folders = $this->foldersOnDisk();
        $known = $this->mangaOnServer();

        $rows = [];

        foreach ($folders as $folder => $files) {
            $hasArchive = false;
            $hasInfo = false;

            foreach ($files as $file) {
                if ($file['kind'] === 'archive') {
                    $hasArchive = true;
                }

                if ($file['kind'] === 'comicinfo') {
                    $hasInfo = true;
                }
            }

            $mangaId = $known[$folder]['id'] ?? null;

            $rows[] = [
                'folder' => $folder,
                'title' => $known[$folder]['title'] ?? null,
                'manga_id' => $mangaId,
                'files' => $files,
                'has_archive' => $hasArchive,
                'has_comic_info' => $hasInfo,
                'metadata' => $hasInfo ? $this->comicInfo($folder) : [],
                'on_disk' => true,
                'known_to_server' => $mangaId !== null,
            ];
        }

        /*
         * And a row for every series Suwayomi holds whose folder has gone, because those
         * are the rows a reader needs to see and there is no delete mutation to clear
         * them. Marked so the page can say what happened rather than list a phantom.
         */
        foreach ($known as $folder => $series) {
            if (array_key_exists($folder, $folders)) {
                continue;
            }

            $rows[] = [
                'folder' => $folder,
                'title' => $series['title'],
                'manga_id' => $series['id'],
                'files' => [],
                'has_archive' => false,
                'has_comic_info' => false,
                'metadata' => [],
                'on_disk' => false,
                'known_to_server' => true,
            ];
        }

        usort(
            $rows,
            static fn (array $a, array $b): int => strnatcasecmp($a['folder'], $b['folder']),
        );

        return $rows;
    }

    /**
     * Counts worth stating on the page.
     *
     * @return array{series: int, on_disk: int, missing: int, unscanned: int, folders: int}
     */
    public function summary(): array
    {
        $rows = $this->rows();

        $summary = ['series' => 0, 'on_disk' => 0, 'missing' => 0, 'unscanned' => 0, 'folders' => 0];

        foreach ($rows as $row) {
            $summary['series']++;

            if ($row['on_disk']) {
                $summary['on_disk']++;
                $summary['folders']++;
            } else {
                $summary['missing']++;
            }

            if ($row['on_disk'] && ! $row['known_to_server']) {
                $summary['unscanned']++;
            }
        }

        return $summary;
    }

    /**
     * Ask Suwayomi to read the folder again.
     *
     * LATEST rather than SEARCH: measured, all three of `SEARCH`, `POPULAR` and `LATEST`
     * answer on source 0 and all three return the same listing, but LATEST is the one
     * whose meaning is "what is new on this source", which is what dropping a file into
     * the folder is. Measured at 15ms, so the button is cheap enough to be honest about
     * having run.
     *
     * @return array{ok: bool, series: int, message: string}
     */
    public function rescan(): array
    {
        try {
            $data = $this->gateway->execute(<<<'GQL'
                mutation Rescan($source: LongString!) {
                    fetchSourceManga(input: {source: $source, type: LATEST, page: 1}) {
                        mangas { id title }
                    }
                }
                GQL, ['source' => self::SOURCE_ID]);
        } catch (SuwayomiException $e) {
            return [
                'ok' => false,
                'series' => 0,
                'message' => $e->isTimeout()
                    ? __('The server did not answer in time. Nothing was changed.')
                    : $e->getMessage(),
            ];
        }

        $mangas = $data['fetchSourceManga']['mangas'] ?? [];

        return [
            'ok' => true,
            'series' => is_array($mangas) ? count($mangas) : 0,
            'message' => __('The folder was read again.'),
        ];
    }

    /**
     * What Suwayomi's local source is holding, keyed by folder name.
     *
     * Keyed by the title, because that is the only handle both sides share: Suwayomi
     * derives the series name from the folder and exposes no field for the folder path.
     * Measured, the local source's language is `localsourcelang` and its id is 0, and
     * there is no `isLocal` field on `SourceType` to lean on.
     *
     * @return array<string, array{id: int, title: string}>
     */
    private function mangaOnServer(): array
    {
        try {
            $data = $this->gateway->execute(<<<'GQL'
                query LocalManga {
                    mangas {
                        nodes { id title source { id } }
                    }
                }
                GQL);
        } catch (SuwayomiException) {
            return [];
        }

        $known = [];

        foreach ($data['mangas']['nodes'] ?? [] as $node) {
            if ((string) ($node['source']['id'] ?? '') !== self::SOURCE_ID) {
                continue;
            }

            $title = (string) ($node['title'] ?? '');

            if ($title === '') {
                continue;
            }

            $known[$title] = ['id' => (int) ($node['id'] ?? 0), 'title' => $title];
        }

        return $known;
    }

    /**
     * One folder per series, each with its files.
     *
     * Measured, Suwayomi's local source expects `local/<Series Name>/` containing the
     * archive and optionally a `ComicInfo.xml`. That is what makes a folder a series and
     * not a directory to descend into, so the tree is one level deep by design.
     *
     * @return array<string, array<int, array{name: string, bytes: int, kind: string}>>
     */
    private function foldersOnDisk(): array
    {
        $root = $this->path();

        if ($root === null || ! is_dir($root) || ! is_readable($root)) {
            return [];
        }

        $folders = [];

        $entries = @scandir($root);

        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $root.DIRECTORY_SEPARATOR.$entry;

            if (! is_dir($path)) {
                continue;
            }

            $files = [];

            $children = @scandir($path);

            if ($children === false) {
                continue;
            }

            foreach ($children as $child) {
                if ($child === '.' || $child === '..') {
                    continue;
                }

                $childPath = $path.DIRECTORY_SEPARATOR.$child;

                if (! is_file($childPath)) {
                    continue;
                }

                $files[] = [
                    'name' => $child,
                    'bytes' => (int) @filesize($childPath),
                    'kind' => $this->kind($child),
                ];
            }

            usort($files, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));

            $folders[$entry] = $files;
        }

        return $folders;
    }

    /**
     * What kind of file this is, as far as the local source cares.
     *
     * `cbz` is Suwayomi's archive type. Everything else is listed as other, because the
     * point of the view is to show a reader what is on disk, and claiming a `.zip` is
     * readable when nothing has tried to read it would be a guess.
     */
    private function kind(string $name): string
    {
        $lower = strtolower($name);

        return match (true) {
            $lower === 'comicinfo.xml' => 'comicinfo',
            str_ends_with($lower, '.cbz') => 'archive',
            default => 'other',
        };
    }

    /**
     * The fields worth reading out of a ComicInfo.xml.
     *
     * Parsed here rather than left to Suwayomi because Suwayomi's own metadata is not
     * exposed per field on the local source in a shape this can rely on, and a reader
     * looking at the folder wants to see what the file says.
     *
     * @return array<string, string>
     */
    private function comicInfo(string $folder): array
    {
        $root = $this->path();

        if ($root === null) {
            return [];
        }

        $file = $root.DIRECTORY_SEPARATOR.$folder.DIRECTORY_SEPARATOR.'ComicInfo.xml';

        if (! is_file($file)) {
            return [];
        }

        $raw = @file_get_contents($file);

        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $previous = libxml_use_internal_errors(true);

        $xml = simplexml_load_string($raw);

        libxml_use_internal_errors($previous);

        if ($xml === false) {
            // A malformed file is a real state and the page should say so rather than
            // show an empty card.
            return ['_error' => __('This ComicInfo.xml could not be parsed.')];
        }

        $wanted = [
            'Title', 'Series', 'Number', 'Volume', 'Year', 'Publisher',
            'Writer', 'Penciller', 'Inker', 'Summary', 'Genre',
        ];

        $out = [];

        foreach ($wanted as $field) {
            $value = trim((string) ($xml->{$field} ?? ''));

            if ($value !== '') {
                $out[$field] = $value;
            }
        }

        return $out;
    }
}
