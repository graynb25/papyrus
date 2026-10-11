<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use DateTimeImmutable;

/**
 * PHP's own error log, which carries a real timestamp and a severity inside the message.
 *
 * ## What it looks like
 *
 * ```
 * [11-Oct-2026 01:14:51 UTC] PHP Deprecated:  Function curl_close() is deprecated since 8.5, as ...
 * ```
 *
 * ## Why this one is worth a parser at all
 *
 * Because it is the only place a PHP deprecation is visible, and a deprecation is not noise. It
 * means a call that PHP 8.5 will eventually refuse. The clean-room run logged one on every start
 * and nothing else in the install mentioned it, so without this parser a reader who wanted to know
 * would have had to open the raw file and read it by hand.
 *
 * The timezone is in the timestamp, unlike every other format in this layer, so it is used as
 * written rather than reinterpreted as local time.
 */
final class PhpErrorParser implements LineParser
{
    private const TIMESTAMPED = '/^\[([^\]]+)\]\s?(.*)$/s';

    /** The severity words PHP writes, longest first so FATAL matches before ERROR. */
    private const SEVERITIES = [
        'FATAL ERROR' => 'ERROR',
        'PARSE ERROR' => 'ERROR',
        'E_ERROR' => 'ERROR',
        'E_WARNING' => 'WARN',
        'E_DEPRECATED' => 'WARN',
        'E_NOTICE' => 'INFO',
        'PHP Deprecated' => 'WARN',
        'PHP Warning' => 'WARN',
        'PHP Notice' => 'INFO',
        'PHP Fatal error' => 'ERROR',
        'PHP Parse error' => 'ERROR',
    ];

    public function parse(string $line): ParsedLine
    {
        if (preg_match(self::TIMESTAMPED, $line, $matches) !== 1) {
            return ParsedLine::record(null, 'INFO', 'php', $line);
        }

        $message = $matches[2];

        return ParsedLine::record(
            $this->timestamp($matches[1]),
            $this->level($message),
            'php',
            $message,
        );
    }

    /**
     * The level lives in the message, so it is found and left in place.
     *
     * It is not stripped. `PHP Deprecated:` is the first thing anyone looks for in this file, and
     * a normalised line that had quietly removed the word PHP would read as if it were about
     * something else.
     */
    private function level(string $message): string
    {
        foreach (self::SEVERITIES as $marker => $level) {
            if (str_contains($message, $marker)) {
                return $level;
            }
        }

        return 'INFO';
    }

    private function timestamp(string $value): ?string
    {
        $date = DateTimeImmutable::createFromFormat('d-M-Y H:i:s T', trim($value));

        if ($date === false) {
            return null;
        }

        return $date->format('Y-m-d\TH:i:s.vP');
    }
}
