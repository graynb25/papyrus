<?php

namespace Tests\Support;

use App\Suwayomi\Gateway;
use App\Suwayomi\SuwayomiException;

/**
 * A Gateway that records what the health probe asked, without a server.
 *
 * The page tests fake HTTP end to end, which proves the probe travels the
 * real Transport and AuthManager. This double exists for the one fact HTTP
 * faking cannot show: the per-call budget. Laravel's fake receives the PSR
 * request, where a client timeout is not visible, so the seam is recorded
 * here instead.
 */
final class RecordingHealthGateway extends Gateway
{
    /** @var list<string> */
    public array $documents = [];

    /** @var list<int|null> */
    public array $timeouts = [];

    public bool $unreachable = false;

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function execute(string $document, array $variables = [], ?int $timeout = null): array
    {
        $this->documents[] = $document;
        $this->timeouts[] = $timeout;

        if ($this->unreachable) {
            throw SuwayomiException::unavailable('Suwayomi is unreachable.');
        }

        return ['sources' => ['totalCount' => 108]];
    }
}
