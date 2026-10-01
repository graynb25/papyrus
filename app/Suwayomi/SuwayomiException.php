<?php

namespace App\Suwayomi;

use RuntimeException;
use Throwable;

/**
 * A failure talking to Suwayomi.
 *
 * GraphQL error messages are untrusted: the server appends full Kotlin stack
 * traces to the `message` field, which can leak internals into logs or UI. The
 * raw payload is kept in $errors for diagnostics; the display message is
 * truncated and sanitised.
 */
class SuwayomiException extends RuntimeException
{
    /** Messages longer than this are truncated before they reach a log or view. */
    public const MAX_MESSAGE_LENGTH = 400;

    /**
     * @param  array<int, array{message?: string, path?: mixed, extensions?: mixed}>|null  $errors
     */
    public function __construct(
        string $message,
        public readonly FailureKind $kind = FailureKind::Unknown,
        public readonly ?array $errors = null,
        public readonly ?float $elapsedMs = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(self::sanitise($message), previous: $previous);
    }

    public static function timeout(float $elapsedMs, ?Throwable $previous = null): self
    {
        return new self(
            "Suwayomi did not respond within the allotted time ({$elapsedMs}ms).",
            FailureKind::Timeout,
            elapsedMs: $elapsedMs,
            previous: $previous,
        );
    }

    public static function unauthenticated(?Throwable $previous = null): self
    {
        return new self(
            'Suwayomi rejected our credentials. Check SUWAYOMI_USERNAME / SUWAYOMI_PASSWORD and that AuthMode is UI_LOGIN.',
            FailureKind::Unauthenticated,
            previous: $previous,
        );
    }

    public static function unavailable(string $reason, ?Throwable $previous = null): self
    {
        return new self("Could not reach Suwayomi: {$reason}", FailureKind::Unavailable, previous: $previous);
    }

    /**
     * @param  array<int, array{message?: string, path?: mixed, extensions?: mixed}>  $errors
     */
    public static function query(array $errors, float $elapsedMs, ?Throwable $previous = null): self
    {
        $first = $errors[0]['message'] ?? 'Unknown GraphQL error.';

        return new self(
            $first,
            FailureKind::Query,
            errors: $errors,
            elapsedMs: $elapsedMs,
            previous: $previous,
        );
    }

    public function isTimeout(): bool
    {
        return $this->kind === FailureKind::Timeout;
    }

    public function isUnauthenticated(): bool
    {
        return $this->kind === FailureKind::Unauthenticated;
    }

    /**
     * Whether retrying the same call could plausibly succeed. A query error
     * from the server is usually deterministic; the rest is worth one more go.
     */
    public function isTransient(): bool
    {
        return $this->kind !== FailureKind::Query;
    }

    private static function sanitise(string $message): string
    {
        // Collapse the server's newlines so a stack trace stays one log line.
        $message = preg_replace('/\s+/', ' ', $message) ?? $message;

        return mb_strlen($message) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($message, 0, self::MAX_MESSAGE_LENGTH - 1).'…'
            : $message;
    }
}
