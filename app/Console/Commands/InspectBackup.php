<?php

namespace App\Console\Commands;

use App\Import\BackupReaders;
use App\Import\ImportRefused;
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

    public function handle(BackupReaders $readers): int
    {
        $path = (string) $this->argument('path');

        if (! is_file($path)) {
            $this->components->error("There is no file at {$path}.");

            return self::FAILURE;
        }

        $reader = $readers->tryFor($path);

        if ($reader === null) {
            $this->components->error('No reader recognises that file.');
            // No markup here. This is a terminal, and a <code> tag renders as itself.
            $this->line('  This server reads backups from: '.$readers->supported().'.');
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
            'Read a %s backup%s.',
            $backup->format,
            /*
             * `.tachibk` states no version anywhere, so there is nothing to print and a plain
             * %d would print a 0 the file never wrote. A reader would take that for a version
             * this server recognised, which is the opposite of what happened.
             */
            $backup->schemaVersion === null
                ? ', which states no format version'
                : ', format version '.$backup->schemaVersion,
        ));

        // The keys are the point of this table, so they are shown. `except` drops the two
        // that the line above already said, and an earlier version passed an empty first
        // column, which printed eight bare numbers with nothing to identify them.
        $this->table(
            ['Found', 'Count'],
            collect($backup->summary())
                ->except('format', 'schema_version')
                ->map(fn (int|string|null $count, string $what): array => [
                    str_replace('_', ' ', $what),
                    /*
                 * A null count prints as "none" rather than as 0. Casting it would turn a
                 * value this server could not determine into a confident zero, which on a
                 * report about somebody's library is the one number nobody should be given
                 * without knowing it is missing.
                 */
                    $count === null ? 'none' : number_format((int) $count),
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

        /*
         * Only claim a browse cache where there is one. Tachimanga carries 132,185 series of
         * which 1,277 are the library, and that difference matters enough to say out loud. A
         * `.tachibk` only ever holds what the reader chose to keep, so there the two counts are
         * equal, and telling someone that 4 of 4 were on the shelf and "the rest is what the
         * app cached" describes a thing that does not exist in their file.
         */
        $this->line($backup->series->count() === $backup->totalInFile
            ? sprintf('  All %s series in the file are on the shelf.', number_format($backup->series->count()))
            : sprintf(
                '  %s of %s series in the file are on the shelf. The rest is what the app '
                .'cached while browsing, and importing it is not something this command does.',
                number_format($backup->series->count()),
                number_format($backup->totalInFile),
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
}
