<?php

namespace Tests\Feature;

use App\Jobs\RunLiveBackup;
use App\Jobs\RunLiveRestore;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * The queue contracts the live pair relies on.
 *
 * ## Why the job property is the number that counts
 *
 * The launcher starts `queue:work --tries=3`, so a job that declares no `$tries` is
 * attempted three times whatever its docblock says. `RunLiveBackup` promised two
 * attempts and set only its backoff, so the worker supplied three, while its sibling
 * `RunLiveRestore` declared two. Both properties are held here together so they cannot
 * drift apart again.
 *
 * ## Why the wait is measured against the jobs
 *
 * The database queue treats a job held longer than `retry_after` as lost and hands it to
 * another worker. The backup, restore, export, bulk migration and import jobs all declare
 * a 3600 second ceiling, so a 90 second default redelivers a running job long before it
 * can finish. The test reads every job that declares a timeout so a future longer one
 * fails here instead of being silently redelivered.
 */
class QueueContractTest extends TestCase
{
    /**
     * The two attempts each sibling promises, read from the property the queue reads.
     */
    public function test_the_live_pair_jobs_each_declare_two_attempts(): void
    {
        $this->assertSame(
            2,
            $this->declaredTries(RunLiveBackup::class),
            'The backup docblock promises two attempts, and without the property the launcher\'s --tries=3 supplies a third.',
        );

        $this->assertSame(
            2,
            $this->declaredTries(RunLiveRestore::class),
            'The restore sibling declares two, so a change in one of them must not leave the pair disagreeing.',
        );
    }

    /**
     * The effective database wait, against the longest timeout a job declares.
     */
    public function test_the_database_queue_waits_longer_than_the_longest_job_timeout(): void
    {
        $longest = 0;
        $longestJob = '';

        foreach (File::files(app_path('Jobs')) as $file) {
            $job = 'App\\Jobs\\'.pathinfo($file->getFilename(), PATHINFO_FILENAME);

            if (! class_exists($job)) {
                continue;
            }

            $defaults = (new ReflectionClass($job))->getDefaultProperties();

            /*
             * A job whose timeout is decided by its constructor carries no default, so it
             * cannot be read here. The ones that do declare a default are the long running
             * family this contract is about, and the assertion below names the ceiling so
             * a silently different one cannot make this test vacuous.
             */
            $timeout = $defaults['timeout'] ?? null;

            if (! is_int($timeout) || $timeout <= $longest) {
                continue;
            }

            $longest = $timeout;
            $longestJob = $job;
        }

        $retryAfter = (int) config('queue.connections.database.retry_after');

        $this->assertSame(3600, $longest, 'The measured longest declared job ceiling, so the comparison below is real.');
        $this->assertGreaterThan(
            $longest,
            $retryAfter,
            "The database queue's effective retry_after of {$retryAfter} seconds must sit past {$longestJob}'s {$longest} second timeout, or a killed worker redelivers the job while it is still meant to be running.",
        );
    }

    /**
     * The `$tries` default a queued job declares, or null when it declares none.
     *
     * @param  class-string  $job
     */
    private function declaredTries(string $job): mixed
    {
        $defaults = (new ReflectionClass($job))->getDefaultProperties();

        return $defaults['tries'] ?? null;
    }
}
