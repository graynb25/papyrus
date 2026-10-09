<?php

namespace App\Console\Commands;

use App\Import\BackupReader;
use App\Import\ImportRefused;
use App\Import\Tachimanga\TachimangaReader;
use App\Support\Suwayomi\Timestamps;
use Illuminate\Console\Command;

/**
 * Read another reader app's backup and print what is in it, changing nothing.
 *
 * ## Why a command rather than a page
 *
 * Two reasons, and the second is the real one.
 *
 * First, the file does not fit through this server. `upload_max_filesize` is 2M and
 * `post_max_size` is 8M against a measured backup of 39.36MB, and `post_max_size` binds
 * first. `upload_max_filesize` is `PHP_INI_PERDIR`, so it cannot be raised at runtime. So
 * this command is not a convenience, it is the only way a large backup reaches the reader
 * at all today.
 *
 * Second, this is phase 1 of an import and phase 1 writes nothing. Making the only way to
 * reach the reader a dry run means the first thing anybody can do with a backup is look at
 * it safely, before a route and a Livewire component exist.
 *
 * ## What the output is for
 *
 * The counts are the deliverable, not a progress bar. A backup holding 132,185 manga of
 * which 1,277 are the library has to show both, because "found 1,277" alone does not tell
 * a reader that 131,908 rows were browse cache rather than their shelf. Categories are
 * printed as the free-text labels they are, with member counts, and are not matched to
 * shelves: nothing in the file distinguishes a label a reader typed from a system's.
 */
class InspectBackup extends Command
{
    protected $signature = 'import:inspect
                            {path : Path to the backup file the other app exported}';

    protected $description = 'Read another reader app\'s library backup and report what it holds, without importing anything';

    public function handle(Timestamps $timestamps): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->components->error("There is no file at {$path}.");

            return self::FAILURE;
        }

        // Each registered reader is asked in turn. One today, and the loop is what makes
        // adding a second a registration rather than a change here.
        $reader = $this->readerFor($path);

        if ($reader === null) {
            $this->components->error('No reader recognises that file.');
            // No markup here. This is a terminal, and a <code> tag renders as itself.
            $this->line('  A Tachimanga backup is the .tmb that Tachimanga writes from its own backup screen.');

            return self::FAILURE;
        }

        try {
            $backup = $reader->read($path);
        } catch (ImportRefused $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Read a %s backup, format version %d.',
            $backup->format,
            $backup->schemaVersion,
        ));

        // The keys are the point of this table, so they are shown. `except` drops the two
        // that the line above already said, and an earlier version passed an empty first
        // column, which printed eight bare numbers with nothing to identify them.
        $this->table(
            ['Found', 'Count'],
            collect($backup->summary())
                ->except('format', 'schema_version')
                ->map(fn (int|string $count, string $what): array => [
                    str_replace('_', ' ', $what),
                    number_format((int) $count),
                ])
                ->values()
                ->all(),
        );

        if ($backup->categories->isNotEmpty()) {
            $this->components->info('Categories in that file, as the labels they are:');
            $this->table(
                ['Label', 'Series'],
                $backup->categories->map(fn (int $count, string $label): array => [$label, number_format($count)])
                    ->values()
                    ->all(),
            );
            $this->components->warn(
                'These are names a reader typed, so nothing here is matched to a shelf. '
                .'You will be asked which ones to map.'
            );
        }

        $this->newLine();
        $this->line(sprintf(
            '  %d of %d series in the file are on the shelf. The rest is what the app '
            .'cached while browsing, and importing it is not something this command does.',
            $backup->series->count(),
            $backup->totalInFile,
        ));

        $downloaded = $backup->downloadedChapterCount();

        if ($downloaded > 0) {
            $this->line(sprintf(
                '  %s chapters were downloaded on the other device. Downloaded files are not '
                .'in a backup, so those would come across as read state with no local copy.',
                number_format($downloaded),
            ));
        }

        $this->newLine();
        $this->components->info('Nothing was imported. No library was touched.');

        return self::SUCCESS;
    }

    /**
     * The first registered reader that claims the file, or null.
     *
     * Tries every reader rather than switching on the extension, because the extension is
     * chosen by another app: Tachimanga names its own files by timestamp and a reader who
     * renamed one in a file manager is not trying to deceive anyone.
     *
     * @param  list<BackupReader>  $readers
     */
    private function readerFor(string $path, array $readers = []): ?BackupReader
    {
        $readers = $readers ?: [new TachimangaReader(new Timestamps)];

        foreach ($readers as $reader) {
            if ($reader->recognises($path)) {
                return $reader;
            }
        }

        return null;
    }
}
