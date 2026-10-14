<?php

namespace Papyrus\Launcher;

use RuntimeException;

/**
 * A restore, or a snapshot, was refused. Nothing was changed.
 *
 * A distinct type from a generic exception because the caller has to tell two situations apart and
 * handle them differently: a refusal means the install is untouched and the reader can try something
 * else, and any other failure means something is now in an unknown state and the reader has to be
 * told that rather than allowed to open Papyrus and believe what they see.
 *
 * That distinction is the whole reason this class exists rather than returning a status string, and
 * a status string would be lost the first time somebody wrapped the call.
 */
class RestoreRefused extends RuntimeException
{
    /** @var list<string> What was tried, in the order it was tried. */
    private array $attempts = [];

    public function attempted(string $what): static
    {
        $this->attempts[] = $what;

        return $this;
    }

    /** @return list<string> */
    public function attempts(): array
    {
        return $this->attempts;
    }
}
