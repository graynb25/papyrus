<?php

namespace Tests\Support;

/**
 * A script's text with its full-line comments removed, which is the only thing worth
 * asserting on.
 *
 * Both assertions in the sandbox runner's own test file that read a raw file were wrong
 * the first time, and for the same reason: the runner explains at length why it does
 * *not* use the gate's ports, so `8402` and `4571` appear in the prose on purpose and a
 * substring check over the whole file reported the explanation as the defect.
 *
 * The same shape returned on 2026-10-16, caught by the first full suite run after the
 * gate learned to start the shortcut: the gate's comments quote `--open` while saying
 * the flag belongs to `Papyrus.cmd`, and the browser test that read the raw file went
 * red with the gate working exactly as designed.
 *
 * That is the "a grep that cannot match what is there is not evidence it is absent"
 * family pointed the other way: a grep that matches too much is not evidence of
 * anything either. A check that reads the comments is reporting on the author's
 * explanation rather than on the program.
 */
trait StripsScriptComments
{
    /**
     * The file's text with every full-line comment removed.
     *
     * A line whose first non-space character is `#` is a PowerShell line comment and is
     * dropped. A trailing comment on a line of code survives: that line is code, and
     * this does not parse the language.
     */
    protected function scriptCode(string $path): string
    {
        $lines = explode("\n", (string) file_get_contents($path));

        $kept = [];

        foreach ($lines as $line) {
            if (! str_starts_with(ltrim($line), '#')) {
                $kept[] = $line;
            }
        }

        return implode("\n", $kept);
    }
}
