<?php

namespace App\Backup;

use Carbon\CarbonImmutable;
use Papyrus\Launcher\RunRecord;
use Throwable;

/**
 * What one `papyrus snapshot` or `papyrus restore` actually did.
 *
 * ## Why this is a wrapper rather than a second reader of the record
 *
 * Because `RunRecord` is already autoloadable from the application: `composer.json` maps
 * `Papyrus\Launcher\` to `packaging/launcher/src/`. My first version parsed the run record itself, on
 * the reasoning that the launcher has no autoloader and so the application could not reach it. The
 * no-autoloader part is true of `papyrus.php`, which requires its thirty files by hand, and irrelevant
 * to the application, which reaches the same namespace through Composer.
 *
 * That first version was a second implementation of one question, free to disagree with the first,
 * and a test asserted they agreed rather than preventing the possibility. There is no second reader
 * here. `RunRecord::read()` returns null for anything it cannot understand and `view()` produces the
 * whole shape a screen draws, so this class adds the two things a page needs and the record does not
 * have: a sentence saying how it went, and the launcher's machine names in words.
 *
 * ## Why the operation stops the application, and what that means for progress
 *
 * Because the manga half has to be copied with nothing writing it, and proving that means Suwayomi
 * has to be stopped. `wire:poll` and `EventSource` are both served by the app process, so whatever
 * carried the progress would die at the exact moment the reader most needs it, and a bar that was
 * climbing and then stopped is the precise shape of "I thought it worked".
 *
 * So the launcher writes each stage down as it changes, and the application reads the result when it
 * comes back. Three honest windows rather than one stream:
 *
 *     before    what is about to happen, and that Papyrus is going to stop
 *     during    nothing, because nothing is serving. The launcher prints this to the terminal.
 *     after     this class: every stage, every retry, and any failure
 *
 * The "during" window has no progress bar and that is not an omission. There is no server to draw it.
 */
final class RunOutcome
{
    /**
     * @param  array<string, mixed>  $view  `RunRecord::view()`, kept whole so nothing is dropped.
     */
    private function __construct(
        public readonly string $token,
        public readonly string $path,
        public readonly array $view,
    ) {}

    /**
     * Read a run record, or null when there is none or it cannot be understood.
     *
     * Null rather than an exception, because every caller is a page deciding what to draw and a
     * malformed file must render as "nothing to show" rather than as an error page.
     */
    public static function fromRecord(string $record, ?int $now = null): ?self
    {
        try {
            $run = RunRecord::read($record);
        } catch (Throwable) {
            return null;
        }

        if ($run === null) {
            return null;
        }

        return new self(
            token: (string) $run->view($now)['token'],
            path: $record,
            view: $run->view($now),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function stages(): array
    {
        /** @var list<array<string, mixed>> $stages */
        $stages = $this->view['stages'] ?? [];

        return $stages;
    }

    /**
     * The stage that failed, or null. Named rather than a flag, so a page can quote the reason.
     *
     * @return array<string, mixed>|null
     */
    public function failure(): ?array
    {
        foreach ($this->stages() as $stage) {
            if (($stage['state'] ?? null) === 'failed') {
                return $stage;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function retries(): array
    {
        /** @var list<string> $retries */
        $retries = $this->view['retries'] ?? [];

        return $retries;
    }

    public function finished(): bool
    {
        return (bool) ($this->view['finished'] ?? false);
    }

    public function failed(): bool
    {
        return (bool) ($this->view['failed'] ?? false);
    }

    /**
     * Whether a launcher was killed part way through, rather than merely being slow.
     *
     * The launcher's rule, passed through unchanged: a run whose last word was "running" and which
     * has not moved for two minutes is not going to move again. Measured earlier in this project, the
     * launcher reported processes stopped that were still running, so "the tool says it is working" is
     * not a thing to take at face value here either.
     */
    public function stalled(): bool
    {
        return (bool) ($this->view['stalled'] ?? false);
    }

    /**
     * One line saying how this went, in the words a reader would use.
     */
    public function verdict(): string
    {
        if ($this->stalled()) {
            return __('This did not finish. Papyrus was stopped part way through and nothing restarted it.');
        }

        $what = $this->operation() === 'restore' ? __('This restore') : __('This backup');

        if ($this->failed()) {
            $stage = $this->failure();

            return __(':what stopped while :stage. :message', [
                'what' => $what,
                'stage' => $this->readable((string) ($stage['stage'] ?? '')),
                'message' => $this->oneline(is_string($stage['message'] ?? null) ? $stage['message'] : null),
            ]);
        }

        if (! $this->finished()) {
            return __(':what is still running.', ['what' => $what]);
        }

        return __(':what finished.', ['what' => $what]);
    }

    public function operation(): string
    {
        return (string) ($this->view['operation'] ?? '');
    }

    public function startedAt(): string
    {
        return (string) ($this->view['started_at'] ?? '');
    }

    public function ageForHumans(): string
    {
        $at = $this->startedAt();

        if ($at === '') {
            return __('at an unknown time');
        }

        return $this->readableTime($at);
    }

    /**
     * `copying_manga_half` as `copying manga half`, which is what a page should say.
     *
     * The launcher's names are for a machine, with underscores so something can rely on them. A reader
     * is not a script and reads sentences, so the underscores come out and the wording is not
     * otherwise changed, because inventing a second set of names would be a translation to keep in step.
     */
    public function readable(string $stage): string
    {
        return match ($stage) {
            'checking_target' => __('checking the server'),
            'stopping' => __('stopping Papyrus'),
            'copying_manga_half' => __('saving your manga data'),
            'copying_reader_half' => __('saving your library'),
            'writing_manifest' => __('checking the backup details'),
            'restarting' => __('starting Papyrus again'),
            default => str_replace('_', ' ', $stage),
        };
    }

    private function readableTime(string $at): string
    {
        try {
            return CarbonImmutable::parse($at)->diffForHumans();
        } catch (Throwable) {
            return __('at an unknown time');
        }
    }

    private function oneline(?string $message): string
    {
        if ($message === null || trim($message) === '') {
            return __('Nothing further was said.');
        }

        foreach (preg_split('#\R#', trim($message)) ?: [] as $line) {
            if (trim($line) !== '') {
                return trim($line);
            }
        }

        return __('Nothing further was said.');
    }
}
