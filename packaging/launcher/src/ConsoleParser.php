<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

/**
 * Laravel's console output: what `migrate.log`, `configure.log`, `queue.log` and `scheduler.log`
 * contain.
 *
 * ## What it looks like
 *
 * ```
 *  INFO Preparing database.
 *  Creating migration table .. 23.34ms DONE
 *  INFO Created .env from .env.example.
 * ```
 *
 * A leading space, an optional level word, then the message. The progress lines carry no level at
 * all, which is Laravel reporting that the thing went normally.
 *
 * ## Why there is no timestamp
 *
 * Laravel does not write one, and inventing a time for a line is worse than admitting it has
 * none. The collector supplies the log file's modification time instead and the doc says so. That
 * is exact for the last line and an upper bound for the rest, which is the honest description of
 * it.
 *
 * These files are the ones that matter most when an install will not start: the migration result
 * and the `Created .env` line are usually the whole answer, and both live here.
 *
 * ## Why the message is trimmed
 *
 * Laravel indents everything by one space so its own output reads as a block. That space is
 * layout, not content, and it is stripped so a collected message can be compared against a grep
 * or pasted into a search. The parser is otherwise forbidden from touching the text.
 */
final class ConsoleParser implements LineParser
{
    /**
     * The level word, then optionally a colon, then the message.
     *
     * Laravel writes both shapes and the colon is optional here because the real `migrate.log`
     * does not have one:
     *
     * ```
     *  INFO Preparing database.
     *  DONE  23.34ms  Creating migration table
     * ```
     *
     * With the colon required, every one of those lines failed to match, fell through to the
     * untagged path, and ended up with the level word still in the message. So the collected file
     * said `INFO Preparing database.` in its message column, which is a line that is wrong about
     * itself.
     */
    private const WITH_LEVEL = '/^\s*(TRACE|DEBUG|INFO|NOTICE|WARNING|WARN|ERROR|CRITICAL|FAILURE|SUCCESS|DONE)\s*:?\s+(.*)$/s';

    public function parse(string $line): ParsedLine
    {
        if (preg_match(self::WITH_LEVEL, $line, $matches) === 1) {
            $level = $matches[1];
            $message = $matches[2];

            return ParsedLine::record(
                null,
                $this->level($level),
                'artisan',
                rtrim($message),
            );
        }

        return ParsedLine::record(null, 'INFO', 'artisan', trim($line));
    }

    /**
     * Laravel's success markers are not severities.
     *
     * `SUCCESS` and `DONE` are how a migration reports that it worked. Mapping them onto a level
     * above INFO would be inventing a problem that did not happen.
     */
    private function level(string $level): string
    {
        return match ($level) {
            'NOTICE', 'WARNING' => 'WARN',
            'CRITICAL', 'FAILURE' => 'ERROR',
            'SUCCESS', 'DONE' => 'INFO',
            default => $level,
        };
    }
}
