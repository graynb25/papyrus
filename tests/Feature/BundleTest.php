<?php

declare(strict_types=1);

namespace Tests\Feature;

use Papyrus\Launcher\Bundle;
use Papyrus\Launcher\EnvironmentReport;
use Papyrus\Launcher\Health;
use Papyrus\Launcher\Prober;
use Papyrus\Launcher\Supervisor;
use Papyrus\Launcher\ZipWriter;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Step 5: the diagnostics bundle.
 *
 * ## Why a bundle is worth this much care
 *
 * Because it is the only artefact a reader is asked to send, and it is the only one they will
 * never read themselves. Every other log exists to be looked at by whoever is fixing the problem.
 * The bundle exists to be looked at by someone who does not know the install, and the difference
 * between a bundle that helps and one that wastes an evening is almost always one file that is
 * quietly missing.
 *
 * ## Why these tests are built the way they are
 *
 * The bundle is assembled against real files rather than mocks wherever a real file will do,
 * because the failure this guards against is a file that should have been there and was not. A
 * mocked log directory always produces a log, which is exactly the situation that hides the bug.
 *
 * The zip is verified by opening it with `ZipArchive`, so "this writes a valid archive" is a
 * checked claim rather than a belief about packed integers.
 */
class BundleTest extends TestCase
{
    private string $scratch;

    private string $data;

    private string $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-bundle-'.getmypid().'-'.uniqid();
        $this->data = $this->scratch.DIRECTORY_SEPARATOR.'data';
        $this->logs = $this->data.DIRECTORY_SEPARATOR.'logs';

        mkdir($this->logs, 0o777, true);

        $this->seedWithRealLogs();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->scratch);

        parent::tearDown();
    }

    /**
     * The captured logs from the clean room runs, so the bundle is assembled over real content.
     */
    private function seedWithRealLogs(): void
    {
        $fixtures = dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'logs';

        foreach (['suwayomi.log', 'web.log', 'migrate.log', 'papyrus.log'] as $file) {
            copy($fixtures.DIRECTORY_SEPARATOR.$file, $this->logs.DIRECTORY_SEPARATOR.$file);
        }
    }

    private function supervisor(): Supervisor
    {
        return new Supervisor($this->scratch, $this->data, $this->logs);
    }

    /**
     * A supervisor whose workers are all reported running, so `processes.txt` can be read without
     * asking this machine what it happens to be running.
     */
    private function supervisorWithWorkers(): Supervisor
    {
        $supervisor = $this->supervisor();
        $supervisor->useLivenessProbe(fn (): bool => true);

        return $supervisor;
    }

    /**
     * A prober that answers instantly, for the tests that are not about health's content.
     *
     * The real `Prober` makes live HTTP requests with a three second timeout each. Sixteen bundle
     * tests sharing one took three minutes of the suite, nearly all of it waiting on ports that were
     * never going to answer. Health's own reporting is covered by `HealthTest` and by the freshness
     * test below, which passes a prober that says exactly what it should.
     */
    private function fastProber(): Prober
    {
        return new class extends Prober
        {
            public function suwayomiAnswers(int $port, float $timeoutSeconds = 3.0): bool
            {
                return false;
            }

            public function suwayomiVersion(int $port, float $timeoutSeconds = 3.0): string
            {
                return '';
            }

            public function isPortTaken(int $port): bool
            {
                return false;
            }

            public function readiness(int $port): ?array
            {
                return null;
            }
        };
    }

    private function bundle(): Bundle
    {
        return new Bundle($this->supervisor(), $this->fastProber());
    }

    private function read(string $path): string
    {
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    // ---------------------------------------------------------------- the shape of the bundle

    public function test_the_bundle_contains_every_file_it_promises(): void
    {
        $directory = $this->bundle()->write();

        foreach (['README.txt', 'summary.txt', 'health.txt', 'processes.txt', 'environment.txt'] as $file) {
            $this->assertFileExists($directory.DIRECTORY_SEPARATOR.$file, $file.' is missing from the bundle');
        }

        foreach (['suwayomi.txt', 'web.txt', 'migrate.txt', 'papyrus.txt'] as $file) {
            $this->assertFileExists(
                $directory.DIRECTORY_SEPARATOR.'collected'.DIRECTORY_SEPARATOR.$file,
                $file.' is missing from the bundle',
            );
        }
    }

    public function test_the_readme_says_what_is_not_in_the_bundle(): void
    {
        $readme = $this->read($this->bundle()->write().DIRECTORY_SEPARATOR.'README.txt');

        $this->assertStringContainsString('Your library', $readme);
        $this->assertStringContainsString('Your database', $readme);
        $this->assertStringContainsString('Any credential', $readme);
        $this->assertStringContainsString('never reads the environment', $readme);

        // The username is genuinely in the paths, so the reader has to be told before attaching.
        $this->assertStringContainsString('account name', $readme);
    }

    // ---------------------------------------------------------------- the never omit rule

    /**
     * A file that cannot be produced is written saying so, rather than left out.
     *
     * An absent `health.txt` is indistinguishable from a healthy install, which is the exact
     * reading the bundle exists to prevent. So a failed step produces a file explaining itself.
     *
     * Built against a log directory holding nothing, because a directory that happens to contain
     * a summary log would let this test pass without exercising the path at all. That has happened
     * three times in this project.
     */
    public function test_a_file_that_cannot_be_produced_says_so_rather_than_vanishing(): void
    {
        $bare = $this->scratch.DIRECTORY_SEPARATOR.'bare';

        mkdir($bare.DIRECTORY_SEPARATOR.'logs', 0o777, true);

        $summary = (new Bundle(
            new Supervisor($this->scratch, $bare, $bare.DIRECTORY_SEPARATOR.'logs'),
            new Prober,
        ))->write().DIRECTORY_SEPARATOR.'summary.txt';

        $contents = $this->read($summary);

        $this->assertStringContainsString('could not be produced', $contents);
        $this->assertStringContainsString('has not written one yet', $contents);
        $this->assertNotSame('', trim($contents), 'the file exists but says nothing');
    }

    public function test_the_bundle_is_written_even_when_no_logs_exist_at_all(): void
    {
        $empty = $this->scratch.DIRECTORY_SEPARATOR.'empty';
        mkdir($empty.DIRECTORY_SEPARATOR.'logs', 0o777, true);

        $directory = (new Bundle(
            new Supervisor($this->scratch, $empty, $empty.DIRECTORY_SEPARATOR.'logs'),
            new Prober,
        ))->write();

        foreach (['README.txt', 'summary.txt', 'health.txt', 'processes.txt', 'environment.txt'] as $file) {
            $this->assertFileExists($directory.DIRECTORY_SEPARATOR.$file, $file.' is missing');
        }
    }

    // ---------------------------------------------------------------- the empty log trap

    /**
     * Two empty worker logs are the healthy case, and the bundle has to say so.
     *
     * This is the reason `processes.txt` exists. `queue.log` and `scheduler.log` are empty whenever
     * the workers are fine, because there is nothing to say when nothing is wrong. An empty file
     * and a process that never started look identical, and a reader who concludes from a file
     * size that two workers are dead files a bug report about a bug that does not exist.
     */
    public function test_an_empty_worker_log_is_reported_as_a_healthy_silent_worker(): void
    {
        // An empty file that genuinely exists, which is the healthy silent case.
        file_put_contents($this->logs.DIRECTORY_SEPARATOR.'queue.log', '');

        $directory = (new Bundle($this->supervisorWithWorkers(), $this->fastProber()))->write();

        $processes = $this->read($directory.DIRECTORY_SEPARATOR.'processes.txt');

        $this->assertMatchesRegularExpression('/queue\s+running/', $processes);
        $this->assertStringContainsString('running and silent', $processes);
        $this->assertStringContainsString('queue.log, 0 non blank line(s)', $processes);
    }

    /**
     * A log that does not exist is not a quiet log.
     *
     * Found by reading the output, not by a failing test. The first version printed "running and
     * silent, which is what a healthy worker looks like" beside a log file that did not exist,
     * which is a false reassurance written by the one file whose whole job is to stop false
     * reassurance. Silence is evidence. Absence is not.
     */
    public function test_a_missing_log_is_not_reported_as_a_silent_one(): void
    {
        $directory = (new Bundle($this->supervisorWithWorkers(), $this->fastProber()))->write();

        $processes = $this->read($directory.DIRECTORY_SEPARATOR.'processes.txt');

        $this->assertStringContainsString('which does not exist', $processes);
        $this->assertStringContainsString(
            'not the same as a quiet one',
            $processes,
            'a missing log must not be described as silence',
        );
    }

    /**
     * A stopped process with a log gets the other sentence.
     *
     * Without it a reader sees an old failure and concludes the process is still failing, which is
     * the opposite of what the evidence says.
     */
    public function test_a_stopped_process_with_a_log_is_said_to_hold_earlier_output(): void
    {
        file_put_contents($this->logs.DIRECTORY_SEPARATOR.'suwayomi.log', 'old output'.PHP_EOL);

        $supervisor = $this->supervisor();
        $supervisor->useLivenessProbe(fn (): bool => false);

        $processes = $this->read(
            (new Bundle($supervisor, $this->fastProber()))->write().DIRECTORY_SEPARATOR.'processes.txt',
        );

        $this->assertStringContainsString('anything in its log is from an earlier run', $processes);
        $this->assertStringNotContainsString('running and silent', $processes);
    }

    public function test_a_worker_that_never_started_is_reported_as_not_running(): void
    {
        $supervisor = $this->supervisor();
        $supervisor->useLivenessProbe(fn (): bool => false);

        $processes = $this->read((new Bundle($supervisor, new Prober))->write().DIRECTORY_SEPARATOR.'processes.txt');

        $this->assertMatchesRegularExpression('/queue\s+not running/', $processes);
        $this->assertStringNotContainsString('running and silent', $processes);
    }

    public function test_each_process_reports_the_size_of_its_own_log(): void
    {
        $processes = $this->read($this->bundle()->write().DIRECTORY_SEPARATOR.'processes.txt');

        $this->assertStringContainsString('suwayomi.log, 86 non blank line(s)', $processes);
        $this->assertStringContainsString('web.log, 17 non blank line(s)', $processes);
    }

    // ---------------------------------------------------------------- environment

    /**
     * The environment report is a fixed list of probes, never a dump.
     *
     * Asserted negatively as well as positively: the thing that must never appear is a value
     * nobody chose to name, and the only way to catch that is to put secrets in the environment
     * and require they do not come out.
     */
    public function test_the_environment_report_never_emits_an_environment_variable(): void
    {
        putenv('PAPYRUS_TEST_SECRET_TOKEN=do-not-leak-me');
        putenv('MY_API_KEY=also-secret');

        /*
         * A PATH segment of our own, rather than one that happens to be on this machine.
         *
         * The version of this that walked the real PATH failed on a GitHub Windows runner, and the
         * runner was right and the test was wrong. setup-php installs PHP at `C:\tools\php`, which
         * is on PATH, and the report legitimately says:
         *
         *     loaded configuration    C:\tools\php\php.ini
         *
         * So a real PATH segment appeared in a report that is not dumping the environment, and the
         * assertion "no PATH segment may ever appear" was too strong. The report names the paths it
         * deliberately asked the operating system for: the install root, the data directory, PHP's
         * own ini. Any of those can sit inside a PATH entry on some machine.
         *
         * A segment that exists nowhere removes the collision and strengthens the test. It is a
         * harmless name, so it covers the case the loop was written for, and if anything ever reads
         * `getenv('PATH')` this exact string comes out on every machine, rather than only on one
         * whose PATH does not collide with its own PHP.
         */
        $sentinel = 'Z:\\papyrus-test-only-path-segment';
        $original = (string) getenv('PATH');

        /*
         * Set before the render, and this ordering is the whole test.
         *
         * Written the other way round first, with the sentinel put in place after the report had
         * already been produced, the assertion was true by construction and a `getenv('PATH')`
         * mutation sailed through it: the report could not have contained a segment that was not in
         * the environment when it was written. It passed against the exact leak it was written to
         * catch, which is the failure mode this project keeps paying for.
         */
        putenv('PATH='.$sentinel.';'.$original);

        try {
            $report = (new EnvironmentReport($this->supervisor(), $this->fastProber()))->render();

            $this->assertStringNotContainsString('do-not-leak-me', $report);
            $this->assertStringNotContainsString('also-secret', $report);
            $this->assertStringContainsString('No environment variables', $report);

            $this->assertStringNotContainsString(
                $sentinel,
                $report,
                'a PATH segment reached the report, so an environment variable is being read',
            );
        } finally {
            putenv('PATH='.$original);
            putenv('PAPYRUS_TEST_SECRET_TOKEN');
            putenv('MY_API_KEY');
        }
    }

    public function test_the_environment_report_names_what_it_cannot_know(): void
    {
        $report = (new EnvironmentReport($this->supervisor(), new Prober))->render();

        // No version.txt means this install was not produced by the build script, and saying so
        // beats printing a constant that would drift from whatever actually shipped.
        $this->assertStringContainsString('not produced by the build script', $report);
        $this->assertStringContainsString('No environment variables', $report);
    }

    /**
     * The commit the build recorded is reported, rather than a number anyone has to remember to
     * bump and get wrong.
     */
    public function test_the_environment_report_reports_the_commit_the_build_recorded(): void
    {
        file_put_contents(
            $this->scratch.DIRECTORY_SEPARATOR.'version.txt',
            'commit 1af4461'.PHP_EOL.'built 2026-10-11 09:00:00+00:00'.PHP_EOL,
        );

        $report = (new EnvironmentReport($this->supervisor(), $this->fastProber()))->render();

        $this->assertStringContainsString('1af4461', $report);
        $this->assertStringContainsString('built 2026-10-11 09:00:00+00:00', $report);
    }

    /**
     * A version file that exists but is empty is a different fact from one that is missing.
     */
    public function test_an_empty_version_file_says_so(): void
    {
        file_put_contents($this->scratch.DIRECTORY_SEPARATOR.'version.txt', '');

        $report = (new EnvironmentReport($this->supervisor(), $this->fastProber()))->render();

        $this->assertStringContainsString('present but empty', $report);
    }

    public function test_the_environment_report_reports_the_data_directory_free_space(): void
    {
        $report = (new EnvironmentReport($this->supervisor(), $this->fastProber()))->render();

        $this->assertMatchesRegularExpression('/free space\s+[\d.]+ GB/', $report);
    }

    // ---------------------------------------------------------------- health is asked again

    /**
     * Health is rendered at collection time, not copied from an earlier run.
     *
     * A report describing the wrong moment is worse than no report: a reader who restarted a
     * worker and then collected would otherwise send the state from before the restart.
     */
    public function test_health_in_the_bundle_is_fresh_rather_than_copied(): void
    {
        // Health checks the database file on disk as well as asking the app, so a real file has to
        // exist for the all green answer. Stubbing readiness alone would have produced a FAIL here
        // and taught nothing about freshness.
        file_put_contents($this->data.DIRECTORY_SEPARATOR.'database.sqlite', str_repeat('x', 2048));

        $supervisor = $this->supervisor();
        $supervisor->useLivenessProbe(fn (): bool => true);

        $prober = new class extends Prober
        {
            public function suwayomiAnswers(int $port, float $timeoutSeconds = 3.0): bool
            {
                return true;
            }

            public function suwayomiVersion(int $port, float $timeoutSeconds = 3.0): string
            {
                return 'v2.4.2366';
            }

            public function isPortTaken(int $port): bool
            {
                return true;
            }

            public function readiness(int $port): ?array
            {
                return ['status' => 200, 'checks' => ['key' => true, 'database' => true, 'pending_migrations' => 0]];
            }
        };

        $health = $this->read((new Bundle($supervisor, $prober))->write().DIRECTORY_SEPARATOR.'health.txt');

        $this->assertStringContainsString('overall', $health);
        $this->assertMatchesRegularExpression('/overall\s+ok\b/', $health);
    }

    // ---------------------------------------------------------------- the zip

    /**
     * A step that throws produces a file explaining itself, not an empty one.
     *
     * The guarantee is "a failure is written down", and until this existed nothing forced a step
     * to fail. Every other test took the happy path, so a catch block that returned an empty string
     * instead of the explanation passed all of them.
     */
    public function test_a_step_that_throws_writes_an_explanation_into_its_file(): void
    {
        $exploding = new class extends Prober
        {
            public function suwayomiVersion(int $port, float $timeoutSeconds = 3.0): string
            {
                throw new RuntimeException('the machine refused to answer');
            }

            public function isPortTaken(int $port): bool
            {
                return false;
            }

            public function suwayomiAnswers(int $port, float $timeoutSeconds = 3.0): bool
            {
                return false;
            }

            public function readiness(int $port): ?array
            {
                return null;
            }
        };

        $environment = $this->read(
            (new Bundle($this->supervisor(), $exploding))->write().DIRECTORY_SEPARATOR.'environment.txt',
        );

        $this->assertStringContainsString('could not be produced', $environment);
        $this->assertStringContainsString('the machine refused to answer', $environment);
        $this->assertStringContainsString('the environment report', $environment);
    }

    /**
     * When collection itself fails, the record counts say so rather than claiming zero.
     *
     * Zero is a claim. It says the log was read and had nothing in it, which is exactly what a
     * reader takes "running and silent" to mean, so a failure must never be allowed to print it.
     *
     * The blocker is a file where `logs/collected/` has to go: the one way established to make a
     * directory genuinely unwritable on this platform, unlike a missing drive letter.
     */
    public function test_a_failed_collection_says_unknown_rather_than_zero_records(): void
    {
        file_put_contents($this->logs.DIRECTORY_SEPARATOR.'collected', 'a file, not a directory');

        $processes = $this->read($this->bundle()->write().DIRECTORY_SEPARATOR.'processes.txt');

        $this->assertStringContainsString('unknown', $processes);
        $this->assertStringNotContainsString('0 record(s)', $processes);
    }

    /**
     * The archive declares what it does, and the CRC it records is the CRC of the bytes.
     *
     * Asserted against the raw bytes rather than by reading the archive back, because reading it
     * back proved nothing: libzip hands over the contents whether the recorded CRC is wrong or the
     * method claims deflate over stored data, so both of those mutations survived a test that
     * looked like it verified the archive. The fields are checked where they are written.
     */
    public function test_the_archive_declares_stored_compression_and_a_correct_crc(): void
    {
        $writer = new ZipWriter;
        $writer->add('note.txt', 'the quick brown fox');

        $path = $this->scratch.DIRECTORY_SEPARATOR.'fields.zip';
        file_put_contents($path, $writer->finish());

        $bytes = (string) file_get_contents($path);

        $this->assertSame("\x50\x4b\x03\x04", substr($bytes, 0, 4), 'local file header signature');

        // Bytes 8 and 9 are the compression method, which must be 0 because that is what it does.
        $this->assertSame(0, unpack('v', substr($bytes, 8, 2))[1], 'the archive must declare method 0, stored');

        // Bytes 14 to 17 are the CRC of the contents.
        $this->assertSame(
            crc32('the quick brown fox'),
            unpack('V', substr($bytes, 14, 4))[1],
            'the recorded CRC must be the CRC of the bytes',
        );

        // And the end of central directory record, which is what makes it an archive at all.
        $this->assertSame("\x50\x4b\x05\x06", substr($bytes, -22, 4));
        $this->assertSame(1, unpack('v', substr($bytes, -12, 2))[1], 'one entry on this disk');
    }

    /**
     * The zip opens in a real zip reader and holds what the directory held.
     *
     * The bundle cannot use `ZipArchive`, because the packaged runtime probably has no `ext-zip`.
     * That is exactly why it is worth checking this project's own writer against `ZipArchive`
     * here, where the extension is known to exist: a hand written archive is either valid or it is
     * a file a reader cannot open, and only a real reader can tell us which.
     */
    public function test_the_zip_opens_in_a_real_zip_reader_and_holds_everything(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is needed to verify the archive this project wrote');
        }

        $archivePath = $this->bundle()->zip($this->bundle()->write());

        $this->assertFileExists($archivePath);

        $zip = new ZipArchive;
        $opened = $zip->open($archivePath);

        $this->assertTrue($opened === true, 'the archive this project wrote did not open: error '.$opened);

        $names = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }

        $this->assertContains('papyrus-diagnostics/README.txt', $names);
        $this->assertContains('papyrus-diagnostics/health.txt', $names);
        $this->assertContains('papyrus-diagnostics/processes.txt', $names);
        $this->assertContains('papyrus-diagnostics/collected/suwayomi.txt', $names);

        // And the contents survive the round trip, which is the part packing bugs hide in.
        $readme = (string) $zip->getFromName('papyrus-diagnostics/README.txt');

        $this->assertStringContainsString('Papyrus diagnostics', $readme);

        $suwayomi = (string) $zip->getFromName('papyrus-diagnostics/collected/suwayomi.txt');

        $this->assertStringContainsString('Mutex status is clear', $suwayomi);
        $this->assertStringContainsString('NOISE', $suwayomi);

        $zip->close();
    }

    /**
     * An archive with no files in it is still a valid archive.
     *
     * The empty case is where the end-of-central-directory record is most easily wrong, and it is
     * the case a reader hits when an install is broken badly enough that nothing was logged.
     */
    public function test_an_empty_archive_is_still_valid(): void
    {
        $writer = new ZipWriter;

        $path = $this->scratch.DIRECTORY_SEPARATOR.'empty.zip';
        file_put_contents($path, $writer->finish());

        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is needed to verify the archive this project wrote');
        }

        $zip = new ZipArchive;

        $this->assertTrue($zip->open($path) === true);
        $this->assertSame(0, $zip->numFiles);

        $zip->close();
    }

    public function test_the_zip_refuses_a_file_it_cannot_read_rather_than_omitting_it(): void
    {
        $writer = new ZipWriter;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot read/');

        $writer->addFile('missing.txt', $this->scratch.DIRECTORY_SEPARATOR.'does-not-exist.txt');
    }

    public function test_binary_content_survives_the_round_trip_byte_for_byte(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is needed to verify the archive this project wrote');
        }

        // A null byte and a high byte, because a writer that treats its input as text gets one of
        // these wrong and only a binary payload shows it.
        $bytes = "head\x00\xFF\xC3\x28tail";

        $path = $this->scratch.DIRECTORY_SEPARATOR.'binary.zip';

        $writer = new ZipWriter;
        $writer->add('bytes.bin', $bytes);
        file_put_contents($path, $writer->finish());

        $zip = new ZipArchive;
        $zip->open($path);

        $this->assertSame($bytes, (string) $zip->getFromName('bytes.bin'));

        $zip->close();
    }

    // ---------------------------------------------------------------- collected content

    public function test_the_bundle_carries_the_collected_logs_in_the_five_field_shape(): void
    {
        $directory = $this->bundle()->write();

        $suwayomi = $this->read($directory.DIRECTORY_SEPARATOR.'collected'.DIRECTORY_SEPARATOR.'suwayomi.txt');

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}\s{2}suwayomi\s+NOISE\s+jna\s+/m',
            $suwayomi,
        );
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.DIRECTORY_SEPARATOR.$entry;

            is_dir($child) ? $this->removeTree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
