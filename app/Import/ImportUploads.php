<?php

namespace App\Import;

use App\Models\ImportRun;
use Illuminate\Support\Facades\Storage;

/**
 * The bytes of a backup this app stored itself.
 *
 * ## Why containment is a class and not a private method
 *
 * The rule "only a file inside `imports` may be read by a queued job, and only such a file
 * may be deleted" is one rule with three callers: the job's path guard, the page's confirm
 * and discard actions, and the expiry sweep. It was a private method on the job, which made
 * the second copy inevitable. A prefix is not containment (`imports-evil` starts with
 * `imports`), so the check canonicalises both sides and requires the separator.
 *
 * A leaf that no longer exists is resolved through its parent. That is the missing-upload
 * case, and it lets a reader be told "missing" rather than "outside" for their own file.
 */
final class ImportUploads
{
    /**
     * Whether the path names a file this app put under its own `imports` directory.
     */
    public function owns(string $path): bool
    {
        $root = realpath(Storage::disk('local')->path('imports'));

        if ($root === false) {
            return false;
        }

        $candidate = realpath($path);

        if ($candidate === false) {
            $parent = realpath(dirname($path));

            if ($parent === false) {
                return false;
            }

            $candidate = $parent.DIRECTORY_SEPARATOR.basename($path);
        }

        $root = rtrim($root, '/\\');
        $candidate = rtrim($candidate, '/\\');

        if (PHP_OS_FAMILY === 'Windows') {
            $root = strtolower($root);
            $candidate = strtolower($candidate);
        }

        return $candidate !== $root && str_starts_with($candidate, $root.DIRECTORY_SEPARATOR);
    }

    /**
     * Delete a stored upload, and nothing else.
     *
     * A server path the owner typed is not ours to remove, and `owns()` is what makes the
     * difference. A missing file is already the desired state.
     */
    public function forget(?string $path): void
    {
        if ($path === null || $path === '' || ! $this->owns($path) || ! is_file($path)) {
            return;
        }

        @unlink($path);
    }

    /**
     * Remove uploads that no live run references and that are past the retention period.
     *
     * The run-based expiry covers an abandoned review, but not an upload whose job never
     * started: there is no run row to expire. This sweep is the second half of the same
     * lifecycle, bounded by age so it cannot touch a file a request has just stored.
     *
     * @param  \DateTimeInterface  $cutoff  Files modified before this are candidates.
     * @return int How many files were removed.
     */
    public function forgetUnreferenced(\DateTimeInterface $cutoff): int
    {
        $root = realpath(Storage::disk('local')->path('imports'));

        if ($root === false || ! is_dir($root)) {
            return 0;
        }

        $referenced = ImportRun::query()
            ->forAllUsers()
            ->whereIn('state', [ImportRun::AWAITING_CONFIRMATION, ImportRun::RUNNING])
            ->whereNotNull('file_path')
            ->pluck('file_path')
            ->filter(fn (mixed $path): bool => is_string($path) && $path !== '')
            ->map(fn (string $path): string => $this->normalise($path))
            ->all();

        $referenced = array_flip($referenced);
        $removed = 0;

        foreach (glob($root.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $modified = filemtime($file);

            if ($modified === false || $modified >= $cutoff->getTimestamp()) {
                continue;
            }

            if (isset($referenced[$this->normalise($file)])) {
                continue;
            }

            @unlink($file);
            $removed++;
        }

        return $removed;
    }

    /**
     * The same spelling on both sides of a comparison, including Windows case and separators.
     */
    private function normalise(string $path): string
    {
        $real = realpath($path);

        if ($real !== false) {
            $path = $real;
        }

        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
