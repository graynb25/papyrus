<?php

namespace Papyrus\Launcher;

use JsonException;

/**
 * What a restore or a snapshot is doing, written down as it happens.
 *
 * ## Why this is a file and not a stream
 *
 * Because the process that would carry a stream is the one being stopped. `wire:poll` and
 * `EventSource` are both served by the application, and a restore stops the application. Whatever
 * mechanism carried the progress would die at the exact moment the reader most needs it, and a
 * stream that was showing progress and then stopped is the precise shape of "I thought it worked".
 *
 * So the launcher, which is still running, writes each stage to disk as it completes, and the
 * application reads the result back when it returns. The same shape as `SummaryLog`, which the
 * launcher already writes and the app already reads.
 *
 * ## What "what a user does not know, they will think worked" means here
 *
 * It is a property of this file, not of the screen that draws it. Four things follow, and each is a
 * mistake I have made somewhere in this project:
 *
 * 1. **A stage is written when it changes, not at the end.** A run that dies half way leaves the
 *    stages it finished marked finished and the one it was on marked running. Written only on
 *    completion, a run that died would leave nothing at all and read as "nothing happened".
 * 2. **A run that died reads as failed, not as running for ever.** The reader is told
 *    `stopped_without_finishing` rather than shown a bar that climbs to nothing, because a run that
 *    was killed mid-flight and one that is merely slow look identical otherwise.
 * 3. **Retries are recorded.** "Tried twice and worked" is a different thing from "worked", and a
 *    reader who sees the first attempt fail is entitled to know that something did.
 * 4. **A stale run does not read as current.** Every run carries a token and a started-at time, and
 *    the reader is told how long ago it was, because a run from last week rendered as "restoring"
 *    is worse than showing nothing.
 *
 * ## Why every write is atomic
 *
 * Because a half written JSON file is unreadable, and unreadable would be rendered as "no run",
 * which is the exact failure this exists to prevent. Written to a sibling and renamed, which is
 * atomic on the same filesystem, so a reader either sees the previous record or the new one and
 * never a torn one.
 */
final class RunRecord
{
    /**
     * The stages a restore moves through, in the order it moves through them.
     *
     * ## Why each operation has its own list rather than one shared list
     *
     * Because the first version declared one constant tuned for a restore, and a snapshot then
     * inherited two stages that do not apply to it: it has nothing to read a backup from and nothing
     * to check a pair against. They would have sat at `pending` for ever, which is the small lie told
     * by leaving something out, and the reason `skip()` exists at all.
     *
     * The caller passes its own list instead, so the launcher owns the vocabulary rather than this
     * class guessing at it. The application renders whatever it finds, so it needs no list of its own
     * and cannot fall out of step with either command.
     *
     * `stopping` and `restarting` are spelled the same in both on purpose. A reader who has watched
     * one operation stop the install has watched the other do it too.
     */
    public const RESTORE_STAGES = [
        'reading_backup',
        'checking_pair',
        'stopping',
        'copying_reader_half',
        'copying_manga_half',
        'verifying',
        'restarting',
    ];

    /**
     * The stages a snapshot moves through, in the order it moves through them.
     *
     * `checking_target` is first because refusing to overwrite an existing backup has to happen
     * before anything is stopped. Every stage after that one costs the reader their install being
     * down, and a refusal that arrives once it is down is a refusal they paid for.
     */
    public const SNAPSHOT_STAGES = [
        'checking_target',
        'stopping',
        'copying_manga_half',
        'copying_reader_half',
        'writing_manifest',
        'restarting',
    ];

    /** A stage that was entered and did not finish, and never will, because the launcher is gone. */
    public const STOPPED = 'stopped_without_finishing';

    /**
     * @param  array<string, array{state: string, attempts: int, message: string|null, started_at: string|null, finished_at: string|null}>  $stages
     */
    private function __construct(
        private readonly string $path,
        private readonly string $operation,
        private readonly string $token,
        private readonly string $startedAt,
        private array $stages,
    ) {}

    /**
     * Start a record, refusing to overwrite one that is already there.
     *
     * Refusing rather than replacing is the same rule the snapshot command follows for a target file:
     * a same-second second run must not quietly replace a good record with a worse one. A caller that
     * genuinely wants a new run removes the old one first.
     *
     * @param  array<array-key, mixed>  $stages  This operation's own stages: {@see RESTORE_STAGES}
     *                                           for a restore, {@see SNAPSHOT_STAGES} for a snapshot, never
     *                                           a union. Typed loosely on purpose: PHP does not enforce the
     *                                           type of an array element, so the check below is the only thing
     *                                           standing between a caller and a record no screen can draw.
     */
    public static function begin(string $path, string $operation, array $stages, string $token, string $startedAt): self
    {
        if ($stages === []) {
            throw new RestoreRefused(sprintf(
                'A %s was started with no stages to report, so nothing it did could ever be shown to '
                .'a reader. That is refused rather than written.',
                $operation,
            ));
        }

        foreach ($stages as $stage) {
            if (! is_string($stage) || trim($stage) === '') {
                throw new RestoreRefused(sprintf(
                    'The stage list given to a %s holds something that is not a stage name, so the '
                    .'record would name stages no screen can draw.',
                    $operation,
                ));
            }
        }

        if (is_file($path)) {
            throw new RestoreRefused(sprintf(
                'There is already a run recorded at %s. Look at it before starting another one, '
                .'because a %s that is still going looks exactly like one that has finished.',
                $path,
                $operation,
            ));
        }

        $blank = [];

        foreach ($stages as $stage) {
            $blank[$stage] = self::blankStage();
        }

        $record = new self($path, $operation, $token, $startedAt, $blank);
        $record->write();

        return $record;
    }

    /**
     * A stage nobody has touched yet.
     *
     * @return array{state: string, attempts: int, message: string|null, started_at: string|null, finished_at: string|null}
     */
    private static function blankStage(): array
    {
        return [
            'state' => 'pending',
            'attempts' => 0,
            'message' => null,
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * Read a record, or null when there is none or it cannot be understood.
     *
     * Null rather than an exception, because every caller is a screen deciding what to draw and a
     * malformed file must render as "nothing to show" rather than as an error page. A record that
     * cannot be read is a bug worth logging, not a reader's problem.
     */
    public static function read(string $path): ?self
    {
        if (! is_file($path)) {
            return null;
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! isset($decoded['operation'], $decoded['token'], $decoded['stages'])) {
            return null;
        }

        /*
         * This operation's own stage list, not a list chosen here.
         *
         * It is stored separately from the stages themselves so that a stage which was entered and
         * then never written, by a launcher killed at exactly that moment, still reads back as a
         * stage. A restore and a snapshot have different stages and the first version had one list
         * tuned for a restore, so anything that guessed here would invent stages that never ran.
         *
         * A record written before this was added has no `stage_order`, so the keys it does have are
         * the list. That is the best available answer and it is never empty for a record that was
         * written at all, because a record with no stages was refused before it was written.
         */
        $order = [];

        foreach (is_array($decoded['stage_order'] ?? null) ? $decoded['stage_order'] : [] as $name) {
            if (is_string($name) && $name !== '') {
                $order[] = $name;
            }
        }

        if ($order === []) {
            foreach (array_keys(is_array($decoded['stages']) ? $decoded['stages'] : []) as $name) {
                if (is_string($name) && $name !== '') {
                    $order[] = $name;
                }
            }
        }

        if ($order === []) {
            return null;
        }

        $stages = [];

        foreach ($order as $stage) {
            $row = is_array($decoded['stages'][$stage] ?? null) ? $decoded['stages'][$stage] : [];

            /*
             * Normalised field by field rather than merged with `+`.
             *
             * This is a file that came off disk, written by a previous version of this project or by a
             * launcher that died halfway. Taking its shape on trust means a record written by a build
             * that knew fewer stages reaches the screen as whatever it happened to contain. Every
             * field is checked, and a missing one becomes the honest value, which for a stage nobody
             * recorded is `pending`.
             *
             * PHPStan required this too, and it is the better answer either way: the declared shape is
             * now something the code guarantees rather than something it hopes for.
             */
            $stages[$stage] = [
                'state' => is_string($row['state'] ?? null) ? $row['state'] : 'pending',
                'attempts' => is_int($row['attempts'] ?? null) ? $row['attempts'] : 0,
                'message' => is_string($row['message'] ?? null) ? $row['message'] : null,
                'started_at' => is_string($row['started_at'] ?? null) ? $row['started_at'] : null,
                'finished_at' => is_string($row['finished_at'] ?? null) ? $row['finished_at'] : null,
            ];
        }

        return new self(
            $path,
            (string) $decoded['operation'],
            (string) $decoded['token'],
            (string) ($decoded['started_at'] ?? ''),
            $stages,
        );
    }

    /**
     * Enter a stage. Called the moment the work starts, not when it finishes.
     *
     * Re-entering increments the attempt count rather than resetting it, because the second try is
     * information: a stage that needed a retry is a stage worth looking at afterwards.
     */
    public function enter(string $stage, ?string $message = null): self
    {
        if (! array_key_exists($stage, $this->stages)) {
            throw new RestoreRefused(sprintf('"%s" is not a stage of a %s.', $stage, $this->operation));
        }

        $this->stages[$stage]['state'] = 'running';
        $this->stages[$stage]['attempts']++;
        $this->stages[$stage]['message'] = $message;
        $this->stages[$stage]['started_at'] = $this->stages[$stage]['started_at'] ?? gmdate('c');
        $this->stages[$stage]['finished_at'] = null;

        $this->write();

        return $this;
    }

    public function finish(string $stage, ?string $message = null): self
    {
        if (! array_key_exists($stage, $this->stages)) {
            throw new RestoreRefused(sprintf('"%s" is not a stage of a %s.', $stage, $this->operation));
        }

        $this->stages[$stage]['state'] = 'done';
        $this->stages[$stage]['message'] = $message;
        $this->stages[$stage]['finished_at'] = gmdate('c');

        $this->write();

        return $this;
    }

    public function fail(string $stage, string $message): self
    {
        if (! array_key_exists($stage, $this->stages)) {
            throw new RestoreRefused(sprintf('"%s" is not a stage of a %s.', $stage, $this->operation));
        }

        $this->stages[$stage]['state'] = 'failed';
        $this->stages[$stage]['message'] = $message;
        $this->stages[$stage]['finished_at'] = gmdate('c');

        $this->write();

        return $this;
    }

    /**
     * Mark a stage as deliberately not done, so it does not read as still pending for ever.
     *
     * For a stage this particular run had no reason to reach. Without it a snapshot that never copies
     * the manga half would show a row that has been "pending" since the run began, which is a small
     * lie told by omission.
     */
    public function skip(string $stage, ?string $message = null): self
    {
        if (! array_key_exists($stage, $this->stages)) {
            throw new RestoreRefused(sprintf('"%s" is not a stage of a %s.', $stage, $this->operation));
        }

        $this->stages[$stage]['state'] = 'skipped';
        $this->stages[$stage]['message'] = $message;
        $this->stages[$stage]['finished_at'] = gmdate('c');

        $this->write();

        return $this;
    }

    /**
     * The stages as a screen should draw them, in order, with the whole story.
     *
     * @return array{operation: string, token: string, started_at: string, age_seconds: int, finished: bool, failed: bool, stalled: bool, stages: list<array<string, mixed>>, retries: list<string>}
     */
    public function view(?int $now = null, int $stalledAfter = 120): array
    {
        $now ??= time();
        $started = strtotime($this->startedAt) ?: $now;

        $rows = [];
        $failed = false;
        $busy = false;
        $retries = [];

        foreach ($this->stages as $name => $stage) {
            $attempts = $stage['attempts'];

            if ($attempts > 1) {
                $retries[] = $name;
            }

            if ($stage['state'] === 'failed') {
                $failed = true;
            }

            if ($stage['state'] === 'running') {
                $busy = true;
            }

            $rows[] = [
                'stage' => $name,
                'state' => $stage['state'],
                'attempts' => $attempts,
                'message' => $stage['message'],
                'retried' => $attempts > 1,
            ];
        }

        /*
         * A run whose last word was "running" and which has not moved for two minutes is not slow.
         *
         * It is a launcher that was killed, and the alternative is a bar that climbs to nothing and
         * never stops, which is the exact failure this whole record exists to prevent. Measured earlier
         * this session: the launcher reported processes stopped that were still running, so "the tool
         * says it is working" is not a thing to take at face value in this project either.
         */
        $stalled = $busy && ($now - $started) > $stalledAfter;

        return [
            'operation' => $this->operation,
            'token' => $this->token,
            'started_at' => $this->startedAt,
            'age_seconds' => max(0, $now - $started),
            'finished' => ! $busy,
            'failed' => $failed,
            'stalled' => $stalled,
            'stages' => $rows,
            'retries' => $retries,
        ];
    }

    /**
     * The record, as written.
     *
     * @return array{version: int, operation: string, token: string, started_at: string, stage_order: list<string>, stages: array<string, array{state: string, attempts: int, message: string|null, started_at: string|null, finished_at: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'operation' => $this->operation,
            'token' => $this->token,
            'started_at' => $this->startedAt,
            'stage_order' => array_keys($this->stages),
            'stages' => $this->stages,
        ];
    }

    /**
     * Write it atomically.
     *
     * A reader either sees the previous record or this one and never a torn one, which matters
     * because "torn" would render as "no run" and "no run" is the failure this is here to stop.
     */
    private function write(): void
    {
        $encoded = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new RestoreRefused('The run record could not be encoded, so nothing was written.');
        }

        $directory = dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o777, true) && ! is_dir($directory)) {
            throw new RestoreRefused(sprintf('Could not create %s to write the run record.', $directory));
        }

        $temporary = $this->path.'.'.getmypid().'.tmp';

        if (@file_put_contents($temporary, $encoded) === false) {
            throw new RestoreRefused(sprintf('Could not write the run record to %s.', $temporary));
        }

        // rename() is atomic within a filesystem, which is all this needs.
        if (! @rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new RestoreRefused(sprintf('Could not put the run record in place at %s.', $this->path));
        }
    }
}
