<?php

namespace App\Backup;

use Carbon\CarbonImmutable;
use Papyrus\Launcher\BackupPair;
use Throwable;

/**
 * One backup, as far as a page can tell from the folder.
 *
 * ## Why this wraps `BackupPair` rather than reading the manifest again
 *
 * Because `BackupPair` is the one implementation of what a manifest means, and it is **already
 * autoloadable from the application**: `composer.json` maps `Papyrus\Launcher\` to
 * `packaging/launcher/src/`.
 *
 * That is worth writing down because I got it wrong first. The first version of this class parsed the
 * manifest itself, on the reasoning that the launcher "has no autoloader and depends on nothing but
 * PHP" so the application could not load its classes. The no-autoloader part is true of `papyrus.php`,
 * which requires its thirty files by hand, and completely irrelevant to the application, which
 * reaches the same namespace through Composer. So the first version put a second reader of one format
 * into the codebase, which is how the health command and the supervisor came to disagree about which
 * processes were this install's, for the life of a feature, with nothing failing.
 *
 * So the manifest is read once, by `BackupPair`, and this class is only the tolerant wrapper a page
 * needs around it.
 *
 * ## Why tolerant
 *
 * Because `BackupPair::read()` **throws**, and that is right for its caller: a restore must not
 * proceed against a manifest it does not understand. A page listing backups has to survive a truncated
 * one and show the reader that the thing is broken, rather than render an error page and show nothing.
 *
 * ## Why the digest check is here at all
 *
 * Because a backup folder can be edited, copied half of, or filled by a disk that failed silently.
 * Measured on the reader's own install the two files a manifest describes are 4.9MB and 52MB, and a
 * digest over each is fast enough to do on a page load. Without it, a page listing backups would list
 * a truncated manga database beside the others with nothing to say about it.
 *
 * This is a weaker check than a restore, and it is honest about being one. A digest that matches says
 * the file is the one the manifest describes. It does not say the pair is restorable, because only
 * attempting that says so.
 */
final class BackupSummary
{
    /**
     * @param  list<string>  $missing  The files this backup names that are not on disk.
     */
    private function __construct(
        public readonly string $token,
        public readonly string $path,
        public readonly string $takenAt,
        public readonly int $seriesStates,
        public readonly int $chapterStates,
        public readonly ?string $serverVersion,
        public readonly int $bytes,
        public readonly bool $complete,
        public readonly bool $intact,
        public readonly array $missing,
        public readonly ?string $problem,
    ) {}

    /**
     * Read a manifest, and never throw.
     *
     * @param  string  $manifest  The manifest's path.
     * @param  string  $directory  Where it sits, and therefore where both halves must sit too.
     */
    public static function fromManifest(string $manifest, string $directory): self
    {
        try {
            $pair = BackupPair::read($manifest, $directory);
        } catch (Throwable $e) {
            /*
             * Its message, not ours.
             *
             * `BackupPair` explains what is wrong in the words of somebody who owns the format, and
             * a second explanation here would be a second answer to the same question. Only the
             * shape is added: that this is a listing, so the reader knows the thing exists and is
             * unusable rather than wondering whether they ever took one.
             */
            return new self(
                token: self::tokenFrom(basename($manifest)),
                path: basename($manifest),
                takenAt: '',
                seriesStates: 0,
                chapterStates: 0,
                serverVersion: null,
                bytes: 0,
                complete: false,
                intact: false,
                missing: [],
                problem: $e->getMessage(),
            );
        }

        $missing = [];
        $checked = 0;
        $matched = 0;

        foreach (['papyrus', 'suwayomi'] as $half) {
            $file = $pair->pathOf($half);

            if (! is_file($file)) {
                $missing[] = basename($file);

                continue;
            }

            $checked++;

            $expected = $half === 'papyrus' ? $pair->papyrusSha256 : $pair->suwayomiSha256;

            if (hash_file('sha256', $file) === $expected) {
                $matched++;
            }
        }

        return new self(
            token: $pair->token,
            path: basename($manifest),
            takenAt: $pair->takenAt,
            seriesStates: $pair->seriesStates,
            chapterStates: $pair->chapterStates,
            serverVersion: $pair->suwayomiVersion,
            bytes: $pair->papyrusBytes + $pair->suwayomiBytes,
            complete: $missing === [] && $checked === 2,
            intact: $checked === 2 && $matched === 2,
            missing: $missing,
            problem: self::problem($missing, $checked, $matched),
        );
    }

    /**
     * When this was taken, in the reader's own words.
     *
     * Relative for anything recent, because "3 hours ago" is what a reader acts on and
     * `2026-10-14T20:38:57+00:00` is what a log is for.
     */
    public function ageForHumans(): string
    {
        if ($this->takenAt === '') {
            return 'at an unknown time';
        }

        try {
            return CarbonImmutable::parse($this->takenAt)->diffForHumans();
        } catch (Throwable) {
            return 'at an unknown time';
        }
    }

    /**
     * A one-line description of what it holds, in the words the manifest used.
     */
    public function describe(): string
    {
        if (! $this->complete) {
            return 'Incomplete';
        }

        return sprintf(
            '%s series and %s chapter states',
            number_format($this->seriesStates),
            number_format($this->chapterStates),
        );
    }

    /**
     * A size worth putting on a page.
     */
    public function sizeForHumans(): string
    {
        if ($this->bytes < 1024) {
            return $this->bytes.' bytes';
        }

        return round($this->bytes / 1048576, 1).' MB';
    }

    /**
     * @param  list<string>  $missing
     */
    private static function problem(array $missing, int $checked, int $matched): ?string
    {
        if ($missing !== []) {
            return sprintf(
                'This backup is missing %s, so it cannot be restored. A backup needs both halves, '
                .'because your library points into the manga database.',
                implode(' and ', $missing),
            );
        }

        if ($checked === 2 && $matched < 2) {
            return 'A file here no longer matches the manifest, so this is not the backup it claims to be.';
        }

        return null;
    }

    private static function tokenFrom(string $name): string
    {
        return str_ends_with($name, '.json')
            ? substr($name, strlen('backup-'), -strlen('.json'))
            : $name;
    }
}
