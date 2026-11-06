<?php

namespace Papyrus\Launcher;

/**
 * Where a packaged install keeps its data when nobody has said.
 *
 * ## Why this needs a decision at all
 *
 * Because the two ways this project is installed want different answers, and the launcher is the
 * only thing that knows which one it is looking at.
 *
 * A **portable install** is a folder a reader puts anywhere: `D:\Apps\Papyrus`. Its data belongs
 * beside it, so the whole thing stays on whatever the folder is on, including a USB stick. Moving
 * it to `%LOCALAPPDATA%` would silently break that, and the reader would find out by losing the
 * library.
 *
 * An **installed copy** lives at `C:\Program Files\Papyrus`. Its data cannot go beside it, because
 * that directory is read only for a normal user. `InstallLayout::assertWritable()` refuses to
 * start in that case, which is the correct outcome and a poor first impression: the reader is told
 * a path is unwritable and given no indication that a perfectly good one exists.
 *
 * So: beside the install when that is possible, `%LOCALAPPDATA%` when it is not, and always say
 * which and why.
 *
 * ## Why the test for "is it possible" creates nothing
 *
 * Because choosing a location should not have the side effect of making it. A probe that creates
 * the directory in order to test it leaves an empty directory behind when the answer turns out to be
 * "no", which is exactly the kind of residue this project has been bitten by elsewhere.
 *
 * So the check walks up to the nearest ancestor that exists and asks whether that one is writable.
 * If it is, the directory could be created there. If it could not be created there, it certainly
 * could not be created one level deeper, which is why stopping at the first existing ancestor is
 * sufficient rather than approximate.
 *
 * ## And if the check is wrong
 *
 * `assertWritable()` still probes with a real write before anything starts, so a wrong answer here
 * costs a refusal at startup with a clear message rather than a half working install. This class
 * exists to get the common case right; the guard is not replaced by it.
 */
final class DataLocation
{
    /**
     * @param  (callable(string): bool)|null  $writable  Overrides the filesystem check, for tests.
     */
    public function __construct(private $writable = null) {}

    /**
     * The data directory for this install, and the sentence explaining it.
     *
     * `reason` stays the English sentence for callers that print it directly. `reason_key` is the
     * stable name the launcher translates into the reader's language, so the sentence and the key
     * can never be changed independently.
     *
     * @return array{path: string, reason: string, reason_key: string}
     */
    public function resolve(string $root): array
    {
        $local = $this->localRoot();

        if ($local !== null && $this->inside($root, $local)) {
            /*
             * An install the installer put here.
             *
             * `%LOCALAPPDATA%\Programs\Papyrus` is where the installer writes, so an install found
             * under `%LOCALAPPDATA%` was put there deliberately and the reader expects their data
             * in one predictable place. That is `%LOCALAPPDATA%\Papyrus`, not a `papyrus-data`
             * folder beside the program: a reader looking for their library looks in their own
             * AppData, and a folder beside the program is not there.
             *
             * This is checked before writability, because a per-user install is writable and would
             * otherwise always take the portable branch.
             */
            return [
                'path' => $local.DIRECTORY_SEPARATOR.'Papyrus',
                'reason' => 'this install is under your AppData, so this is where Windows keeps '
                    .'your own data, rather than beside the program',
                'reason_key' => 'installed',
            ];
        }

        $portable = dirname($root).DIRECTORY_SEPARATOR.'papyrus-data';

        if ($this->canWrite($portable)) {
            return [
                'path' => $portable,
                'reason' => 'beside the install, so the whole thing can be moved or carried together',
                'reason_key' => 'portable',
            ];
        }

        if ($local !== null) {
            return [
                'path' => $local.DIRECTORY_SEPARATOR.'Papyrus',
                'reason' => 'the install is somewhere that cannot be written to, so this is under '
                    .'%LOCALAPPDATA% where Windows keeps a reader\'s own data',
                'reason_key' => 'unwritable',
            ];
        }

        /*
         * Neither. The portable path is returned rather than a third guess, so the refusal that
         * follows comes from assertWritable() naming a real path and saying what is wrong with it.
         * Inventing a location here would only move the failure somewhere less clear.
         */
        return [
            'path' => $portable,
            'reason' => 'no writable location was found, so Papyrus will say where it tried',
            'reason_key' => 'none',
        ];
    }

    /**
     * `%LOCALAPPDATA%` for this user, or null when the machine does not have one.
     *
     * Read from the environment rather than derived, because Windows owns this location and a
     * profile can be moved. A hard-coded path would put a reader's data somewhere their profile is
     * not, which is the exact failure this project spent a phase fixing.
     */
    private function localRoot(): ?string
    {
        $local = getenv('LOCALAPPDATA');

        if (! is_string($local) || trim($local) === '') {
            return null;
        }

        return rtrim($local, '\\/');
    }

    /**
     * Whether a path is inside another, comparing case-insensitively as Windows does.
     *
     * The separator on the end of the parent matters, or `C:\Users\<you>\AppDataLocal` counts
     * as being inside `C:\Users\<you>\AppData`, and that reader's data goes to the wrong place.
     */
    public function inside(string $path, string $parent): bool
    {
        $normalised = str_replace('/', '\\', $path);
        $boundary = rtrim(str_replace('/', '\\', $parent), '\\').'\\';

        return stripos($normalised, $boundary) === 0;
    }

    /**
     * Whether a directory could be created and written at this path.
     *
     * Not `is_writable($dir)`, because the directory usually does not exist yet and asking about
     * it would say no for the wrong reason.
     */
    private function canWrite(string $directory): bool
    {
        if ($this->writable !== null) {
            return ($this->writable)($directory);
        }

        $existing = $directory;

        while (! is_dir($existing)) {
            $parent = dirname($existing);

            // dirname() of a root is itself, so this terminates rather than looping.
            if ($parent === $existing) {
                return false;
            }

            $existing = $parent;
        }

        return is_writable($existing);
    }
}
