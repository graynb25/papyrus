<?php

namespace Papyrus\Launcher;

/**
 * Two files that belong to the same moment, and the manifest that says so.
 *
 * ## What a pair is for
 *
 * The install holds two databases with different facts in them:
 *
 * | | Held by | What it holds |
 * | --- | --- | --- |
 * | `database.sqlite` | this project | read state per series and per chapter, shelves, settings, the AniList link, the import ledger |
 * | `database.mv.db` | Suwayomi, as H2 | series, chapters, covers, sources |
 *
 * And they reference each other: `series_states.suwayomi_manga_id` points at a row in the H2 file.
 *
 * That reference is why a pair exists. Restoring one half on its own leaves every one of those
 * pointers aimed at a row that means something else, and the symptom is not an error. It is a library
 * that reads as empty, with no message anywhere.
 *
 * ## Why a manifest rather than two filenames
 *
 * Because "these two files" is not something that can be checked. Two files in a directory could
 * have been taken a week apart, could be from two different installs, or could be a copy of one and
 * the original of the other. A manifest binds them to one token and records what each held at that
 * moment, which is the only thing that makes "this is a pair" a statement rather than a hope.
 *
 * ## Why a manifest naming one half is refused
 *
 * Because restoring it would replace one database and leave the other alone, which is the exact
 * outcome this whole arrangement exists to prevent, reached by a different road. Someone hand-editing
 * a manifest, and a snapshot written by a version that only knew about one database, both produce it.
 */
final class BackupPair
{
    /** @param string $directory Where the manifest sat, and therefore where both halves must sit too. */
    private function __construct(
        private readonly string $directory,
        public readonly string $token,
        public readonly string $takenAt,
        public readonly string $papyrusFile,
        public readonly string $papyrusSha256,
        public readonly int $papyrusBytes,
        public readonly int $seriesStates,
        public readonly int $chapterStates,
        public readonly string $suwayomiFile,
        public readonly string $suwayomiSha256,
        public readonly int $suwayomiBytes,
        public readonly string $suwayomiVersion,
    ) {}

    /**
     * Read and validate a manifest.
     *
     * Validation is of the *shape*, not of the files. Whether the files are present and intact is
     * checked by {@see Restorer} immediately before it touches anything, and keeping the two apart
     * means this can be exercised without a filesystem and the refusal can say which half is wrong.
     *
     * @throws RestoreRefused
     */
    public static function read(string $manifestPath, string $directory): self
    {
        if (! is_file($manifestPath)) {
            throw (new RestoreRefused(sprintf('There is no backup manifest at %s.', $manifestPath)))
                ->attempted($manifestPath);
        }

        $raw = @file_get_contents($manifestPath);

        if (! is_string($raw) || trim($raw) === '') {
            throw (new RestoreRefused(sprintf('The manifest at %s is empty.', $manifestPath)))
                ->attempted($manifestPath);
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw (new RestoreRefused(sprintf(
                'The manifest at %s is not readable JSON, so which two files belong together is '
                .'unknown. It said: %s',
                $manifestPath,
                json_last_error_msg(),
            )))->attempted($manifestPath);
        }

        return self::fromArray($decoded, $directory);
    }

    /**
     * Build from a decoded manifest.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RestoreRefused
     */
    public static function fromArray(array $data, string $directory): self
    {
        $token = self::text($data, 'token', 'the manifest');

        // Both halves or neither. See the class docstring for why a lopsided one is refused.
        foreach (['papyrus', 'suwayomi'] as $half) {
            if (! isset($data[$half]) || ! is_array($data[$half])) {
                /*
                 * The name of the missing half is in the message, not just in the code.
                 *
                 * The first version of this passed the half to `sprintf` with no placeholder for it,
                 * so a reader who handed over a lopsided manifest was told it was not a pair and not
                 * which half was absent. PHPStan caught it. That is the failure AGENTS.md names: a
                 * message that reports a verdict without the one fact needed to act on it.
                 */
                throw (new RestoreRefused(sprintf(
                    'The manifest has no "%s" section, so it is not a pair. Restoring it would '
                    .'replace one database and leave the other alone, and the symptom of that is a '
                    .'library that reads as empty with nothing saying why.',
                    $half,
                )))->attempted($half);
            }
        }

        $papyrus = $data['papyrus'];
        $suwayomi = $data['suwayomi'];

        return new self(
            directory: rtrim($directory, '/\\'),
            token: $token,
            takenAt: self::text($data, 'taken_at', 'the manifest'),
            papyrusFile: self::fileName($papyrus, 'papyrus'),
            papyrusSha256: self::digest($papyrus, 'papyrus'),
            papyrusBytes: self::number($papyrus, 'bytes', 'papyrus'),
            seriesStates: self::number($papyrus, 'series_states', 'papyrus'),
            chapterStates: self::number($papyrus, 'chapter_states', 'papyrus'),
            suwayomiFile: self::fileName($suwayomi, 'suwayomi'),
            suwayomiSha256: self::digest($suwayomi, 'suwayomi'),
            suwayomiBytes: self::number($suwayomi, 'bytes', 'suwayomi'),
            suwayomiVersion: self::text($suwayomi, 'server_version', 'the manga half'),
        );
    }

    /**
     * The absolute path of one half, refusing a manifest that names something outside its directory.
     *
     * A manifest is a file a reader can edit, so a `file` of `../../something` is not a hypothetical.
     * Refusing it here means a restore can only ever read files that sit beside the manifest, which
     * is the only place a backup of this install has any business being.
     *
     * @param  string  $half  Either `papyrus` or `suwayomi`.
     */
    public function pathOf(string $half): string
    {
        $name = $half === 'suwayomi' ? $this->suwayomiFile : $this->papyrusFile;

        return $this->directory.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * A one-line description for a reader, naming both halves and what they held.
     */
    public function describe(?Translator $translator = null): string
    {
        if ($translator !== null) {
            return $translator->get('restore.description', [
                'token' => $this->token,
                'taken' => $this->takenAt,
                'series' => $this->seriesStates,
                'chapters' => $this->chapterStates,
                'version' => $this->suwayomiVersion,
            ]);
        }

        return sprintf(
            '%s, taken %s: %d series and %d chapter states, against Suwayomi %s',
            $this->token,
            $this->takenAt,
            $this->seriesStates,
            $this->chapterStates,
            $this->suwayomiVersion,
        );
    }

    public function __toString(): string
    {
        return $this->token;
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $key, string $where): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw (new RestoreRefused(sprintf('The manifest has no "%s" in %s.', $key, $where)))
                ->attempted($key);
        }

        return trim($value);
    }

    /** @param array<string, mixed> $data */
    private static function number(array $data, string $key, string $where): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw (new RestoreRefused(sprintf('The manifest has no "%s" in %s.', $key, $where)))
                ->attempted($key);
        }

        return (int) $value;
    }

    /**
     * A digest, lower case and exactly 64 hex characters.
     *
     * @param  array<string, mixed>  $data
     */
    private static function digest(array $data, string $half): string
    {
        $value = $data['sha256'] ?? null;

        if (! is_string($value) || preg_match('/^[0-9a-f]{64}$/i', trim($value)) !== 1) {
            throw (new RestoreRefused(sprintf(
                'The manifest has no usable SHA-256 for the %s half, so there is no way to tell '
                .'whether the file it names is the one it describes.',
                $half,
            )))->attempted('sha256');
        }

        return strtolower(trim($value));
    }

    /** @param array<string, mixed> $data */
    private static function fileName(array $data, string $half): string
    {
        $value = self::text($data, 'file', sprintf('the %s half', $half));

        /*
         * A bare name, refused rather than sanitised.
         *
         * Refused because silently stripping `../` would mean the manifest says one thing and the
         * restore reads another, and the reader has no way to see which. A manifest naming a path is
         * either wrong or not from this project.
         */
        if ($value !== basename($value) || $value === '.' || $value === '..') {
            throw (new RestoreRefused(sprintf(
                'The manifest names the %s half as "%s". A backup has to be a file sitting beside '
                .'the manifest, so a path is refused rather than tidied up.',
                $half,
                $value,
            )))->attempted('file');
        }

        return $value;
    }
}
