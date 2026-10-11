<?php

declare(strict_types=1);

namespace Tests\Feature;

use Papyrus\Launcher\CaddyJsonParser;
use Papyrus\Launcher\Collector;
use Papyrus\Launcher\ConsoleParser;
use Papyrus\Launcher\LauncherParser;
use Papyrus\Launcher\LogbackParser;
use Papyrus\Launcher\PhpErrorParser;
use RuntimeException;
use Tests\TestCase;

/**
 * Layer 2: the normalising collectors.
 *
 * ## Why these run against captured logs rather than samples
 *
 * Because every invented fixture in this project has been wrong about something. The samples
 * below are lines a real Suwayomi, a real Caddy and a real Laravel actually wrote, taken from the
 * clean-room runs, and three of them exist precisely because they surprised the parsers:
 *
 *   - a logback record whose message follows a plain space with no `--` separator;
 *   - a physical line holding both a JVM warning and a logback record;
 *   - a console level word with no colon after it.
 *
 * Inventing a sample would have produced a parser that passed every test and failed on the first
 * real install.
 *
 * ## The property under test throughout
 *
 * **A defect here must never make a line disappear.** The collected files feed a bug report, so a
 * line lost here is evidence destroyed at the moment it is needed, and the bundle still looks
 * complete. The first test asserts that directly, over every fixture, rather than trusting each
 * parser test to imply it.
 */
class CollectorTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-collect-'.getmypid().'-'.uniqid();

        @mkdir($this->scratch, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->scratch);

        parent::tearDown();
    }

    /**
     * Removed recursively.
     *
     * A scratch directory that outlives a failing run is how the next run starts with state it
     * did not create, which is the sort of thing that makes a test pass for the wrong reason.
     */
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

    private function fixtures(): string
    {
        return dirname(__DIR__).DIRECTORY_SEPARATOR.'Fixtures'.DIRECTORY_SEPARATOR.'logs';
    }

    /**
     * A copy of the captured logs, because the collector writes `collected/` beside whatever it is
     * given and the fixtures directory holds only inputs.
     */
    private function logs(): string
    {
        $logs = $this->scratch.DIRECTORY_SEPARATOR.'logs';

        if (! is_dir($logs)) {
            mkdir($logs, 0o777, true);
        }

        foreach (glob($this->fixtures().DIRECTORY_SEPARATOR.'*.log') ?: [] as $log) {
            copy($log, $logs.DIRECTORY_SEPARATOR.basename($log));
        }

        return $logs;
    }

    private function collected(string $logs, string $process): string
    {
        $path = $logs.DIRECTORY_SEPARATOR.'collected'.DIRECTORY_SEPARATOR.$process.'.txt';

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $contents): array
    {
        return $contents === '' ? [] : explode(PHP_EOL, rtrim($contents, PHP_EOL));
    }

    // ---------------------------------------------------------------- the governing property

    /**
     * Every non blank input line is present in the collected file, across every format.
     *
     * A blank line carries nothing, so it is the only thing not carried over. Everything else is
     * either a record line or an indented continuation, and the count has to match exactly: a
     * parser that dropped an unrecognised line would still produce a file that looks complete.
     */
    public function test_every_input_line_survives_into_the_collected_file(): void
    {
        $logs = $this->logs();

        (new Collector($logs))->collect();

        $processes = [
            'suwayomi.log' => 'suwayomi',
            'web.log' => 'web',
            'migrate.log' => 'migrate',
            'configure.log' => 'configure',
            'papyrus.log' => 'papyrus',
            'php-error.log' => 'php',
        ];

        foreach ($processes as $file => $process) {
            $input = 0;

            foreach (file($logs.DIRECTORY_SEPARATOR.$file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (trim($line) !== '') {
                    $input++;
                }
            }

            $output = 0;

            foreach ($this->lines($this->collected($logs, $process)) as $line) {
                if (trim($line) !== '') {
                    $output++;
                }
            }

            $this->assertSame(
                $input,
                $output,
                $file.' lost lines: '.$input.' in, '.$output.' out.',
            );
        }
    }

    /**
     * Every collected line is either a record with all five fields or an indented continuation.
     *
     * There is no third kind, and that is the whole contract. A continuation is a stack frame, or a
     * banner line belonging to the record above it, and the real `suwayomi.log` has five of them
     * because Javalin prints its name in ASCII art. Asserting that every line carries five fields
     * fails on the banner, and the right response to that is to encode what a continuation is
     * rather than to stop producing them.
     */
    public function test_every_collected_line_is_a_record_or_a_marked_continuation(): void
    {
        $logs = $this->logs();

        (new Collector($logs))->collect();

        $records = 0;
        $continuations = 0;

        foreach (glob($logs.DIRECTORY_SEPARATOR.'collected'.DIRECTORY_SEPARATOR.'*.txt') ?: [] as $file) {
            foreach ($this->lines((string) file_get_contents($file)) as $line) {
                if ($line === '') {
                    continue;
                }

                if (str_starts_with($line, '  ')) {
                    $continuations++;

                    continue;
                }

                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}\s{2}\S+\s{1,9}\S+\s{1,24}\S/',
                    $line,
                    basename($file).' produced a line without five fields: '.$line,
                );

                $records++;
            }
        }

        $this->assertGreaterThan(0, $records);
        $this->assertGreaterThan(0, $continuations, 'the Javalin banner should be here as continuations');
    }

    /**
     * The process column is the one the record names, not the one the file is called.
     *
     * `papyrus.log` holds lines recorded against `web` as well as `papyrus`, because health
     * reports on the whole install from one place. Labelling those `papyrus` would send a reader
     * to the launcher when it was the web server that was not answering.
     */
    public function test_the_process_in_the_line_beats_the_name_of_the_file(): void
    {
        $logs = $this->logs();

        (new Collector($logs))->collect();

        $lines = $this->lines($this->collected($logs, 'papyrus'));

        $web = array_values(array_filter($lines, fn ($line) => str_contains($line, 'nothing is listening')));

        $this->assertCount(1, $web);
        $this->assertMatchesRegularExpression('/\sweb\s+PROBLEM\s+launcher\s/', $web[0]);

        $own = array_values(array_filter($lines, fn ($line) => str_contains($line, '4 processes to start')));

        $this->assertCount(1, $own);
        $this->assertMatchesRegularExpression('/\spapyrus\s+INFO\s+launcher\s/', $own[0]);
    }

    // ---------------------------------------------------------------- Suwayomi

    /**
     * The message is kept byte for byte.
     *
     * The layer's whole rule is that it does not rewrite someone else's log, and there is no way
     * to test that except by handing the parser a real line and demanding the exact same text
     * back. A reflow, a trim or a re-indent of the message would all pass a looser assertion.
     */
    public function test_a_suwayomi_message_is_kept_byte_for_byte(): void
    {
        $line = '20:14:37.612 [main] INFO com.zaxxer.hikari.HikariDataSource -- Suwayomi-DB-Pool - Starting...';

        $parsed = (new LogbackParser('2026-10-11'))->parse($line);

        $this->assertSame('Suwayomi-DB-Pool - Starting...', $parsed->message);
        $this->assertSame('INFO', $parsed->level);
        $this->assertSame('com.zaxxer.hikari.HikariDataSource', $parsed->source);
        $this->assertSame('2026-10-11T20:14:37.612+00:00', $parsed->timestamp);
    }

    /**
     * Both separators, because the shipped log uses both in the same file.
     *
     * Requiring `--` rejected three real records and appended them to the record above, which is
     * worse than dropping them: the text survived and was attached to the wrong event.
     */
    public function test_both_logback_separators_are_parsed(): void
    {
        $parser = new LogbackParser('2026-10-11');

        $withSeparator = $parser->parse('20:14:37.612 [main] INFO migration.Something -- with a separator');
        $without = $parser->parse('20:14:39.097 [main] INFO migration.Something setupWebUI(flavor= WebUI)');

        $this->assertSame('with a separator', $withSeparator->message);
        $this->assertSame('setupWebUI(flavor= WebUI)', $without->message);

        foreach ([$withSeparator, $without] as $parsed) {
            $this->assertFalse($parsed->isContinuation, 'a record was mistaken for a continuation');
            $this->assertSame('migration.Something', $parsed->source);
        }
    }

    /**
     * A layout that prints no message still produces a record, not a continuation.
     *
     * `io.javalin.Javalin` appears on its own in the captured log. Treated as a continuation it
     * would collect the banner lines that follow it as if they belonged to the record before.
     */
    public function test_a_record_with_no_message_is_still_a_record(): void
    {
        $parsed = (new LogbackParser('2026-10-11'))->parse('20:14:38.100 [main] INFO io.javalin.Javalin');

        $this->assertFalse($parsed->isContinuation);
        $this->assertSame('', $parsed->message);
        $this->assertSame('io.javalin.Javalin', $parsed->source);
    }

    /**
     * The two JNA lines are labelled NOISE and kept, not removed.
     *
     * Removing them would lose the evidence if a real problem ever followed one. Showing them
     * unlabelled would train a reader to ignore every `WARNING` in the file.
     */
    public function test_the_jna_warnings_are_labelled_rather_than_removed(): void
    {
        $parser = new LogbackParser('2026-10-11');

        $lines = file($this->fixtures().DIRECTORY_SEPARATOR.'suwayomi.log', FILE_IGNORE_NEW_LINES) ?: [];
        $warnings = array_values(array_filter($lines, fn ($line) => str_starts_with($line, 'WARNING: ')));

        $this->assertCount(2, $warnings, 'the fixture should hold both JNA warnings');

        foreach ($warnings as $warning) {
            $parsed = $parser->parse($warning);

            $this->assertFalse($parsed->isContinuation, 'a warning became a continuation');
            $this->assertSame('NOISE', $parsed->level);
            $this->assertSame('jna', $parsed->source);
            $this->assertSame(substr($warning, strlen('WARNING: ')), $parsed->message);
        }

        $this->assertStringContainsString('A restricted method', implode("\n", $warnings));
    }

    /**
     * A line holding two records is reproduced whole, not split.
     *
     * The real `suwayomi.log` has one physical line where a JVM warning and a logback record are
     * glued together, because the JVM writes without a trailing newline. Suwayomi's version, the
     * most useful line in the file, sits at the end of a line labelled NOISE. Splitting it would
     * mean guessing where one record ends inside a line we did not write, which is the rewriting
     * this layer exists to prevent, so the line is kept exactly as it was.
     */
    public function test_a_line_holding_two_records_is_not_split(): void
    {
        $logs = $this->logs();

        (new Collector($logs))->collect();

        $collected = $this->collected($logs, 'suwayomi');

        $this->assertStringContainsString('NOISE', $collected);
        $this->assertStringContainsString(
            'com.sun.jna.Native in an unnamed module (file:/C:/Users/<you>20:14:37.171 [main] INFO '.
            'suwayomi.tachidesk.server.ServerSetup -- Running Suwayomi-Server v2.4.2366',
            $collected,
            'the glued line should be reproduced exactly, both halves and all',
        );
    }

    /**
     * A stack frame stays with the record that owns it.
     *
     * A frame carries no timestamp and no level of its own, so if it were given its own it would
     * either be invented or would claim to be a separate event. It belongs to the line above it.
     */
    public function test_stack_frames_stay_with_the_record_that_owns_them(): void
    {
        $parser = new LogbackParser('2026-10-11');

        $frame = "\tat suwayomi.tachidesk.server.user.UserTypeKt.requireUser(UserType.kt:23)";

        $parsed = $parser->parse($frame);

        $this->assertTrue($parsed->isContinuation);
        $this->assertSame($frame, $parsed->message);
        $this->assertNull($parsed->timestamp, 'a frame must not invent a timestamp');
    }

    /**
     * The rendered file indents a continuation so a reader can see it belongs above.
     *
     * The stored message is untouched; only the rendering indents. That keeps the raw text
     * available for anyone comparing it against the source while still making the record readable.
     */
    public function test_a_continuation_is_indented_under_its_record_when_rendered(): void
    {
        $logs = $this->scratch.DIRECTORY_SEPARATOR.'logs';

        if (! is_dir($logs)) {
            mkdir($logs, 0o777, true);
        }

        file_put_contents(
            $logs.DIRECTORY_SEPARATOR.'suwayomi.log',
            "20:14:44.794 [w] ERROR graphql.Server -- execution failed\n".
            "graphql.ServerException: no\n".
            "\tat graphql.Engine.execute(Engine.java:1)\n",
        );

        (new Collector($logs))->collect();

        $lines = $this->lines($this->collected($logs, 'suwayomi'));

        $this->assertCount(3, $lines);
        $this->assertStringNotContainsString("\n\t", $lines[0], 'the record line holds only its message');
        $this->assertStringStartsWith('  graphql.ServerException', $lines[1]);
        $this->assertStringStartsWith('  	at graphql.Engine.execute', $lines[2]);
    }

    /**
     * Milliseconds are kept.
     *
     * `DateTimeImmutable::setTime` takes microseconds in its fourth argument, not milliseconds,
     * so passing 487 produced `.000` and every sub second distinction in the log was flattened
     * into a lie that looked precise.
     */
    public function test_milliseconds_survive(): void
    {
        $parser = new LogbackParser('2026-10-11');

        $this->assertSame('2026-10-11T20:14:38.025+00:00', $parser->parse('20:14:38.025 [m] INFO a.B -- x')->timestamp);
        $this->assertSame('2026-10-11T20:14:38.001+00:00', $parser->parse('20:14:38.001 [m] INFO a.B -- x')->timestamp);
        $this->assertSame('2026-10-11T20:14:38.999+00:00', $parser->parse('20:14:38.999 [m] INFO a.B -- x')->timestamp);
    }

    /**
     * A log that ran past midnight gets two dates rather than folding back onto its first hour.
     *
     * Logback writes a time with no date. Without this, 23:58 and 00:02 both land on the same
     * day and two events eight hours apart sort as four minutes apart.
     */
    public function test_a_log_that_crossed_midnight_gets_two_dates(): void
    {
        $parser = new LogbackParser('2026-10-11');

        $this->assertStringStartsWith('2026-10-11T23:58', $parser->parse('23:58:00.000 [m] INFO a.B -- late')->timestamp);
        $this->assertStringStartsWith('2026-10-12T00:02', $parser->parse('00:02:00.000 [m] INFO a.B -- early')->timestamp);

        // A clock that wobbles backwards by seconds is not midnight and must not roll the date.
        $parser = new LogbackParser('2026-10-11');
        $parser->parse('10:00:00.000 [m] INFO a.B -- first');
        $this->assertStringStartsWith('2026-10-11T09:59', $parser->parse('09:59:59.000 [m] INFO a.B -- wobble')->timestamp);
    }

    /**
     * The date a time only log gets comes from the file, not from the clock reading the file.
     */
    public function test_the_date_comes_from_the_log_file(): void
    {
        $logs = $this->scratch.DIRECTORY_SEPARATOR.'logs';

        if (! is_dir($logs)) {
            mkdir($logs, 0o777, true);
        }

        $path = $logs.DIRECTORY_SEPARATOR.'suwayomi.log';
        file_put_contents($path, '20:14:38.025 [m] INFO a.B -- x'.PHP_EOL);
        touch($path, (int) mktime(12, 0, 0, 3, 14, 2024));

        (new Collector($logs))->collect();

        $this->assertStringContainsString(
            '2024-03-14T20:14:38.025',
            $this->collected($logs, 'suwayomi'),
        );
    }

    // ---------------------------------------------------------------- Caddy

    /**
     * Caddy's JSON lands in the same five fields as everything else.
     */
    public function test_caddy_json_becomes_the_same_five_fields(): void
    {
        $line = '{"level":"warn","ts":1791681286.5189898,"msg":"Caddyfile input is not formatted"}';

        $parsed = (new CaddyJsonParser)->parse($line);

        $this->assertSame('WARN', $parsed->level);
        $this->assertSame('caddy', $parsed->source);
        $this->assertSame('Caddyfile input is not formatted', $parsed->message);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}[+-]\d{2}:\d{2}$/', $parsed->timestamp);
    }

    /**
     * Keys Caddy attaches beside `msg` are kept rather than dropped.
     *
     * They are the context that makes the line diagnosable. Dropping them silently would be worse
     * than dropping them loudly, because the line still looks complete.
     */
    public function test_caddy_extra_keys_are_kept_after_the_message(): void
    {
        $line = '{"level":"info","ts":1791681286.5189898,"msg":"GOMEMLIMIT is updated",'.
            '"GOMEMLIMIT":15217857331,"previous":9223372036854775807}';

        $message = (new CaddyJsonParser)->parse($line)->message;

        $this->assertStringStartsWith('GOMEMLIMIT is updated', $message);
        $this->assertStringContainsString('GOMEMLIMIT=15217857331', $message);
        $this->assertStringContainsString('previous=9223372036854775807', $message);
    }

    /**
     * A line that is not JSON is kept, because Caddy and a half written line both produce them.
     */
    public function test_a_caddy_line_that_is_not_json_is_kept(): void
    {
        $parsed = (new CaddyJsonParser)->parse('caddy: no such file');

        $this->assertFalse($parsed->isContinuation);
        $this->assertSame('caddy: no such file', $parsed->message);
        $this->assertSame('caddy', $parsed->source);
    }

    // ---------------------------------------------------------------- Laravel

    /**
     * The level word is lifted out of the message, not left in it.
     *
     * The real `migrate.log` writes ` INFO Preparing database.` with no colon. With the colon
     * required, every one of those lines missed the pattern and the collected file said
     * `INFO Preparing database.` in its message column, which is a line that is wrong about
     * itself.
     */
    public function test_a_console_level_word_does_not_end_up_in_the_message(): void
    {
        $parser = new ConsoleParser;

        $withoutColon = $parser->parse(' INFO Preparing database.');
        $withColon = $parser->parse('  ERROR  Something broke');

        $this->assertSame('Preparing database.', $withoutColon->message);
        $this->assertSame('INFO', $withoutColon->level);
        $this->assertSame('Something broke', $withColon->message);
        $this->assertSame('ERROR', $withColon->level);
    }

    /**
     * A progress line with no level at all is kept whole.
     *
     * `Creating migration table .. 23.34ms DONE` is how a migration reports that it worked, so
     * calling it INFO is right and rewording it is not.
     */
    public function test_a_console_progress_line_is_kept_whole(): void
    {
        $parsed = (new ConsoleParser)->parse(' Creating migration table .. 23.34ms DONE');

        $this->assertSame('Creating migration table .. 23.34ms DONE', $parsed->message);
        $this->assertSame('INFO', $parsed->level);
    }

    public function test_a_console_failure_is_not_reported_as_a_success(): void
    {
        $this->assertSame('ERROR', (new ConsoleParser)->parse('  FAILURE  Could not open database')->level);
        $this->assertSame('INFO', (new ConsoleParser)->parse('  DONE  23.34ms')->level);
    }

    // ---------------------------------------------------------------- PHP

    /**
     * A deprecation is the only place a PHP warning is visible, so it is found rather than lost.
     */
    public function test_a_php_deprecation_is_found_and_kept_in_the_message(): void
    {
        $line = '[11-Oct-2026 01:14:51 UTC] PHP Deprecated:  Function curl_close() is deprecated since 8.5';

        $parsed = (new PhpErrorParser)->parse($line);

        $this->assertSame('WARN', $parsed->level);
        $this->assertSame('php', $parsed->source);
        $this->assertStringStartsWith('PHP Deprecated:', $parsed->message);
        $this->assertSame('2026-10-11T01:14:51.000+00:00', $parsed->timestamp);
    }

    public function test_a_php_fatal_error_is_an_error(): void
    {
        $parsed = (new PhpErrorParser)->parse('[11-Oct-2026 01:14:51 UTC] PHP Fatal error:  Uncaught Error');

        $this->assertSame('ERROR', $parsed->level);
    }

    // ---------------------------------------------------------------- the launcher's own log

    /**
     * Layer 1 is read back so the two can be correlated in one file.
     */
    public function test_the_launchers_own_log_joins_the_others(): void
    {
        $logs = $this->logs();

        (new Collector($logs))->collect();

        $collected = $this->collected($logs, 'papyrus');

        $this->assertStringContainsString('INFO', $collected);
        $this->assertStringContainsString('starting, 4 processes to start', $collected);
        $this->assertStringContainsString('could not reach 127.0.0.1:4567 after 60s', $collected);
        $this->assertStringContainsString('launcher', $collected);

        // The line the summary log wrote as `web PROBLEM` keeps web in the process column.
        $web = array_values(array_filter(
            $this->lines($collected),
            fn ($line) => str_contains($line, 'nothing is listening'),
        ));

        $this->assertCount(1, $web);
        $this->assertMatchesRegularExpression('/\sweb\s+PROBLEM\s/', $web[0]);
    }

    public function test_a_launcher_line_keeps_its_marker_level_rather_than_being_reinterpreted(): void
    {
        $parsed = (new LauncherParser)->parse('2026-10-11 16:00:44  papyrus   PROBLEM   could not reach the port');

        $this->assertSame('PROBLEM', $parsed->level);
        $this->assertSame('could not reach the port', $parsed->message);
    }

    // ---------------------------------------------------------------- absence and failure

    /**
     * A log that does not exist still produces an empty collected file.
     *
     * `queue.log` and `scheduler.log` are empty whenever the workers are healthy, which is why
     * health asks the operating system instead of reading them. Writing the empty file keeps
     * "running and silent" and "never started" distinguishable without inferring either from what
     * is missing.
     */
    public function test_a_missing_log_still_produces_an_empty_collected_file(): void
    {
        $logs = $this->scratch.DIRECTORY_SEPARATOR.'logs';

        if (! is_dir($logs)) {
            mkdir($logs, 0o777, true);
        }

        $written = (new Collector($logs))->collect();

        $this->assertArrayHasKey('frankenphp', $written);
        $this->assertSame(0, $written['frankenphp']);
        $this->assertFileExists($logs.DIRECTORY_SEPARATOR.'collected'.DIRECTORY_SEPARATOR.'frankenphp.txt');
        $this->assertSame('', $this->collected($logs, 'frankenphp'));
    }

    /**
     * A collected directory that cannot be written is reported, not swallowed.
     *
     * Unlike the summary log, this throws. A summary line that cannot be written costs one line.
     * A bundle that cannot be assembled at all is the one thing a reader was asked to produce,
     * and returning an empty bundle with no explanation is the failure this layer exists to stop.
     *
     * The blocker is a file, not a missing drive: `C:\no-such-drive-for-tests\...` looks
     * unwritable and is not, because Windows creates the whole chain under `C:\`. That mistake
     * left a test passing without exercising this branch, and a stray directory at a drive root.
     */
    public function test_an_unwritable_collected_directory_is_reported_rather_than_silent(): void
    {
        $blocker = $this->scratch.DIRECTORY_SEPARATOR.'logs-that-is-really-a-file';

        file_put_contents($blocker, 'the path below cannot be created');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot write to/');

        (new Collector($blocker))->collect();
    }

    /**
     * The files method lists what was collected, and nothing else.
     */
    public function test_the_collected_files_are_listed_for_the_bundle(): void
    {
        $collector = new Collector($this->logs());
        $collector->collect();

        $files = $collector->files();

        $this->assertNotEmpty($files);
        $this->assertContainsOnly('string', $files);

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $this->assertStringEndsWith('.txt', $file);
        }

        $this->assertCount(count(glob($this->logs().DIRECTORY_SEPARATOR.'collected'.DIRECTORY_SEPARATOR.'*.txt') ?: []), $files);
    }
}
