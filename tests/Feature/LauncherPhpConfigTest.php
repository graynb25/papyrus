<?php

namespace Tests\Feature;

use Papyrus\Launcher\Supervisor;
use RuntimeException;
use Tests\TestCase;

/**
 * The php.ini a packaged install actually hands to PHP.
 *
 * ## What was broken
 *
 * `packaging/php/php.ini` ships as a template. It says `extension_dir = "{{RUNTIME}}/ext"` and
 * it means it. For a long time the supervisor resolved the tokens in the Caddyfile and had no
 * equivalent for the ini, so the template reached PHP verbatim.
 *
 * PHP does not reject that. It takes the braces as part of a directory name, fails to find all
 * twelve extensions in it, prints warnings to a stderr nobody reads, and serves an app that
 * cannot open its database. The symptom arrives four layers away as "could not find driver", so
 * nothing anywhere pointed at the ini.
 *
 * ## Why these go through `prepareLayout` and not straight at the substitution
 *
 * Because the wiring is half the claim. `resolvePhpConfig` doing the right thing is worth
 * nothing if `prepareLayout` never calls it, and a test that reaches past it cannot see that.
 * So every test here drives the one method `start()` runs before it spawns anything, which
 * needs no JVM and no port.
 *
 * ## Why the shipped ini is used rather than a fixture everywhere
 *
 * Because the shipped ini is the file that caused the subtlety. It documents the token syntax
 * in a comment that names it, so a whole file scan refuses to start on a configuration that is
 * entirely correct. A hand written fixture cannot reproduce that, and a suite that passes
 * against a fixture while the real file breaks is precisely the failure being guarded here.
 */
class LauncherPhpConfigTest extends TestCase
{
    private string $scratch;

    private string $install;

    private string $data;

    private string $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-ini-'.getmypid().'-'.uniqid();
        $this->install = $this->scratch.DIRECTORY_SEPARATOR.'install';
        $this->data = $this->scratch.DIRECTORY_SEPARATOR.'data';

        // Where papyrus.php puts it, which is inside the data directory rather than beside it.
        // Building the layout any other way would test a shape no reader ever gets.
        $this->logs = $this->data.DIRECTORY_SEPARATOR.'logs';

        @mkdir($this->installIn('runtime', 'php'), 0o777, true);
        @mkdir($this->installIn('runtime', 'frankenphp'), 0o777, true);

        /*
         * The Caddyfile is resolved in the same pass and refuses first if it is missing, so a
         * layout without one cannot reach the ini at all.
         */
        file_put_contents($this->installIn('runtime', 'frankenphp', 'Caddyfile'), implode(PHP_EOL, [
            ':{{APP_PORT}}',
            'root * {{APP_ROOT}}/public',
            'php_server',
            '',
        ]));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->scratch);

        parent::tearDown();
    }

    /**
     * A throwaway install, laid out the way `papyrus.php` lays out a real one.
     */
    private function supervisor(): Supervisor
    {
        return new Supervisor(
            root: $this->install,
            dataDirectory: $this->data,
            logDirectory: $this->logs,
        );
    }

    private function installIn(string ...$parts): string
    {
        return $this->install.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts);
    }

    /**
     * Put an ini where a packaged install ships it, and hand back where that was.
     */
    private function installTemplate(string $contents): string
    {
        $path = $this->installIn('runtime', 'php', 'php.ini');

        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * The real `packaging/php/php.ini`, staged where an install would ship it.
     *
     * Copied rather than referenced in place, because the supervisor writes a resolved copy and
     * a test must never leave a second file behind in the repository it is checking.
     */
    private function installShippedIni(): string
    {
        $shipped = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'packaging'.DIRECTORY_SEPARATOR
            .'php'.DIRECTORY_SEPARATOR.'php.ini';

        $this->assertFileExists($shipped, 'the shipped ini is the file all of this is about');

        return $this->installTemplate((string) file_get_contents($shipped));
    }

    /**
     * Everything `start()` does before it spawns a process.
     *
     * @param  callable(string): void|null  $say
     */
    private function prepareLayout(Supervisor $supervisor, ?callable $say = null): void
    {
        (new \ReflectionMethod($supervisor, 'prepareLayout'))->invoke(
            $supervisor,
            $say ?? static fn (string $line): null => null,
        );
    }

    /**
     * Prepare, then read back the ini that PHP would be handed.
     */
    private function resolveIni(Supervisor $supervisor): string
    {
        $this->prepareLayout($supervisor);

        $this->assertFileExists($this->resolvedPath(), 'nothing wrote the ini PHP is supposed to be given');

        return (string) file_get_contents($this->resolvedPath());
    }

    /**
     * The ini the supervisor is currently handing to its four processes.
     */
    private function iniFor(Supervisor $supervisor): string
    {
        return (string) (new \ReflectionMethod($supervisor, 'ini'))->invoke($supervisor);
    }

    private function resolvedPath(): string
    {
        return $this->data.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.'php.ini';
    }

    /**
     * A path as the ini is expected to spell it.
     */
    private function slashed(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * The lines PHP's ini parser would act on.
     *
     * Blank lines and `;` comments are skipped by the parser, so a token on one of those is
     * text rather than a setting. Everything else is a line a mistake on it reaches the reader.
     *
     * @return list<string>
     */
    private function activeLines(string $ini): array
    {
        $lines = preg_split('#\R#', $ini) ?: [];

        return array_values(array_filter(
            $lines,
            static fn (string $line): bool => trim($line) !== '' && ! str_starts_with(ltrim($line), ';'),
        ));
    }

    /**
     * The resolved copy goes beside the database, and the shipped template survives intact.
     *
     * Two separate reasons, and the second is the one that is easy to miss. Writing into the
     * install fails for an ordinary user under Program Files, so the launcher would work in
     * development and break only on the machines the installer exists for. Overwriting the
     * template is quieter still: the first run bakes this machine's paths into the file that is
     * supposed to stay a reviewable template, and the second run then has no tokens left to
     * replace.
     */
    public function test_the_resolved_ini_is_written_into_the_data_directory_and_the_template_is_left_alone(): void
    {
        $template = $this->installTemplate("extension_dir = \"{{RUNTIME}}/ext\"\n");
        $shipped = (string) file_get_contents($template);

        $this->prepareLayout($this->supervisor());

        $this->assertFileExists(
            $this->resolvedPath(),
            'the ini has to land somewhere PHP can be pointed at',
        );

        $this->assertFileDoesNotExist(
            $this->install.DIRECTORY_SEPARATOR.'php'.DIRECTORY_SEPARATOR.'php.ini',
            'and the data directory is the only one an ordinary user is guaranteed to be able to write',
        );

        $this->assertSame(
            $shipped,
            (string) file_get_contents($template),
            'the template in the install must come out of this untouched, token and all, or the next start has nothing to replace',
        );
    }

    /**
     * All three path tokens become this install's paths, everywhere they appear.
     *
     * `{{RUNTIME}}` is written twice on purpose. A substitution that only handled the first
     * occurrence, or a template edit that moved a token, is exactly the kind of thing that
     * leaves half the file wrong and half the symptoms right.
     */
    public function test_every_path_token_is_replaced_with_this_install_s_paths(): void
    {
        $this->installTemplate(implode(PHP_EOL, [
            'extension_dir = "{{RUNTIME}}/ext"',
            'curl.cainfo = "{{RUNTIME}}/cacert.pem"',
            'error_log = "{{LOGS}}/php-error.log"',
            'sys_temp_dir = "{{TEMP}}"',
            '',
        ]));

        $resolved = $this->resolveIni($this->supervisor());

        $runtime = $this->slashed($this->installIn('runtime'));

        $this->assertStringContainsString('extension_dir = "'.$runtime.'/ext"', $resolved);
        $this->assertStringContainsString('curl.cainfo = "'.$runtime.'/cacert.pem"', $resolved, 'every occurrence, not just the first');
        $this->assertStringContainsString(
            'error_log = "'.$this->slashed($this->logs).'/php-error.log"',
            $resolved,
        );
        $this->assertStringContainsString(
            'sys_temp_dir = "'.$this->slashed($this->data.DIRECTORY_SEPARATOR.'tmp').'"',
            $resolved,
        );

        $this->assertStringNotContainsString(
            '{{',
            $resolved,
            'nothing shaped like a token may survive, since this template carries none in a comment',
        );
    }

    /**
     * The ini the project actually ships resolves with no token left on a line PHP would read.
     *
     * The check is per line rather than a single `str_contains`, because the shipped file
     * legitimately still carries the token syntax inside a comment. Asserting on the whole file
     * would pass today and fail the day anyone edits the documentation.
     */
    public function test_the_shipped_ini_resolves_with_no_token_left_on_a_line_php_would_read(): void
    {
        $this->installShippedIni();

        $resolved = $this->resolveIni($this->supervisor());

        $active = $this->activeLines($resolved);

        $this->assertNotEmpty($active, 'the shipped ini carries real settings, so this cannot pass on an empty file');

        foreach ($active as $line) {
            $this->assertDoesNotMatchRegularExpression(
                '#\{\{[A-Z_]+\}\}#',
                $line,
                sprintf(
                    'PHP would read %s as a literal directory name, load no extensions from it, and '
                    .'leave the reader with a missing database driver four layers away',
                    trim($line),
                ),
            );
        }

        $this->assertStringContainsString(
            'extension=php_pdo_sqlite.dll',
            $resolved,
            'the twelve extensions have to still be listed, or the substitution ate more than the tokens',
        );
    }

    /**
     * The packaged runtime has to accept a real backup, not a fixture-sized one.
     *
     * A measured Tachimanga backup is 41MB and grows with the library it came from. The
     * 64M/68M pair this replaces was close enough that a larger library would hit it.
     * `post_max_size` must stay above `upload_max_filesize`, or the multipart request is
     * truncated before PHP ever compares the file against its own limit.
     */
    public function test_the_shipped_ini_leaves_room_for_a_real_library_backup(): void
    {
        $this->installShippedIni();

        $settings = [];
        foreach ($this->activeLines($this->resolveIni($this->supervisor())) as $line) {
            if (preg_match('/^\s*(memory_limit|upload_max_filesize|post_max_size)\s*=\s*([0-9]+[KMG]?)\s*$/i', $line, $match)) {
                $settings[strtolower($match[1])] = strtoupper($match[2]);
            }
        }

        $this->assertSame('512M', $settings['memory_limit'] ?? null);
        $this->assertSame('256M', $settings['upload_max_filesize'] ?? null);
        $this->assertSame('260M', $settings['post_max_size'] ?? null);

        $bytes = static function (string $value): int {
            $unit = strtoupper(substr($value, -1));

            return (int) $value * match ($unit) {
                'G' => 1024 ** 3,
                'M' => 1024 ** 2,
                'K' => 1024,
                default => 1,
            };
        };

        $this->assertGreaterThan(
            $bytes($settings['upload_max_filesize']),
            $bytes($settings['post_max_size']),
            'post_max_size has to carry the file plus multipart framing, or the upload is silently truncated',
        );
        $this->assertGreaterThan(
            41_272_047,
            $bytes($settings['upload_max_filesize']),
            'the measured real backup is 41MB and has to fit with room for a larger library',
        );
    }

    /**
     * The shipped ini turns off the two settings that turn a file read into code execution.
     *
     * This is defence in depth rather than a live hole, and that is exactly why it has to be
     * asserted: the next person who edits this file has no symptom to notice. PHP's default for
     * `allow_url_fopen` is on, and a future include or template defect would then be able to fetch
     * and run something remote.
     */
    public function test_the_shipped_ini_turns_off_url_fopen_and_url_include(): void
    {
        $this->installShippedIni();

        $settings = $this->settingsFrom($this->resolveIni($this->supervisor()));

        $this->assertSame(
            'off',
            strtolower($settings['allow_url_fopen'] ?? ''),
            'allow_url_fopen must be Off, or a future file read can become a remote fetch',
        );

        $this->assertSame(
            'off',
            strtolower($settings['allow_url_include'] ?? ''),
            'allow_url_include must be Off, or a future include can execute a remote stream',
        );
    }

    /**
     * `open_basedir` names every root the application legitimately uses, and nothing wider.
     *
     * The four are the app root, the runtime, the data directory and the temp directory the rest
     * of the file already points at. Windows separates them with `;`. A path outside these roots is
     * a widened blast radius, and a missing root is an install that boots and then fails on its
     * first write, so both directions are asserted.
     */
    public function test_open_basedir_lists_the_app_runtime_data_and_temp_roots(): void
    {
        $this->installShippedIni();

        $resolved = $this->resolveIni($this->supervisor());
        $settings = $this->settingsFrom($resolved);

        $roots = explode(';', $settings['open_basedir'] ?? '');

        $this->assertStringNotContainsString(
            '{{',
            $settings['open_basedir'] ?? '',
            'an unresolved token in open_basedir is a directory name PHP will never find anything in',
        );

        foreach ([
            'the install root' => $this->slashed($this->install),
            'the runtime' => $this->slashed($this->installIn('runtime')),
            'the data directory' => $this->slashed($this->data),
            'the temp directory' => $this->slashed($this->data.DIRECTORY_SEPARATOR.'tmp'),
        ] as $what => $expected) {
            $this->assertContains(
                $expected,
                $roots,
                $what.' must be an allowed root, or the process that needs it fails on its first read '
                    .'or write',
            );
        }

        $this->assertNotContains(
            $this->slashed($this->install.DIRECTORY_SEPARATOR.'..'),
            $roots,
            'the parent of the install is not an allowed root',
        );
    }

    /**
     * The active settings, keyed by name, with surrounding quotes removed.
     *
     * Read from the same active-line list the other assertions use, so a setting that is commented
     * out cannot be mistaken for one PHP applies.
     *
     * @return array<string, string>
     */
    private function settingsFrom(string $ini): array
    {
        $settings = [];

        foreach ($this->activeLines($ini) as $line) {
            if (preg_match('/^\s*([A-Za-z0-9_.]+)\s*=\s*(.*?)\s*$/', $line, $match) !== 1) {
                continue;
            }

            $value = trim($match[2]);

            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }

            $settings[strtolower($match[1])] = $value;
        }

        return $settings;
    }

    /**
     * A comment naming the token syntax is documentation, not a fault.
     *
     * The shipped ini explains its own templating in a comment that names `{{TOKEN}}` outright.
     * A whole file scan therefore throws on a configuration that is completely correct, and a
     * working install refuses to start because its own documentation is present. This is the
     * specific over-correction the comment skip exists to avoid.
     */
    public function test_an_ini_whose_only_token_is_in_a_comment_resolves(): void
    {
        $this->installTemplate(implode(PHP_EOL, [
            '; tokens look like {{TOKEN}} and are filled in when the install is configured',
            '',
            'memory_limit = 512M',
            '',
        ]));

        $resolved = $this->resolveIni($this->supervisor());

        $this->assertStringContainsString(
            'memory_limit = 512M',
            $resolved,
            'the real setting beside the comment must still be there',
        );

        $this->assertStringContainsString(
            '{{TOKEN}}',
            $resolved,
            'the comment must be carried through rather than deleted, or this passes because the text was removed instead of skipped',
        );
    }

    /**
     * The same rule, against the real file rather than a purpose built one.
     *
     * Paired with the test above because the two fail differently. A fixture is what proves the
     * branch exists; the shipped ini is what proves the branch is pointed at the file that
     * needed it.
     */
    public function test_the_shipped_ini_starts_despite_documenting_the_token_syntax_by_name(): void
    {
        $this->installShippedIni();

        $resolved = $this->resolveIni($this->supervisor());

        $this->assertStringContainsString(
            '{{TOKEN}}',
            $resolved,
            'the shipped ini explains the templating in a comment, and that comment must survive into the resolved copy',
        );
    }

    /**
     * A token on a line PHP reads refuses to start, and says which line and where.
     *
     * `{{TMP}}` rather than something invented, because a mistyped token is the realistic way to
     * get here: the shipped ini grows a setting, someone reaches for the neighbouring name, and
     * it looks right. PHP does not object. It takes `{{TMP}}` for a directory, finds nothing,
     * and the next thing to shell out complains about a temporary file that is not there. The
     * person holding that message has a log, not a debugger, so it has to carry both the line
     * and the path to open.
     */
    public function test_a_token_left_on_an_active_line_refuses_to_start(): void
    {
        $this->installTemplate(implode(PHP_EOL, [
            'extension_dir = "{{RUNTIME}}/ext"',
            'sys_temp_dir = "{{TMP}}"',
            '',
        ]));

        try {
            $this->prepareLayout($this->supervisor());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                $this->resolvedPath(),
                $e->getMessage(),
                'the message must name the file that was written, because that is the path to open',
            );

            $this->assertStringContainsString(
                'sys_temp_dir = "{{TMP}}"',
                $e->getMessage(),
                'and it must quote the offending line, since nobody can diff an ini against a sentence',
            );

            return;
        }

        $this->fail('an ini still carrying a template token on a line PHP reads must refuse to start');
    }

    /**
     * Every substituted path is spelled with forward slashes, like the Caddyfile beside it.
     *
     * An ini path is read by the C runtime rather than by PHP's own path handling, so a Windows
     * path arriving with backslashes produces a mixed path that PHP accepts at startup and then
     * cannot find anything under. `resolveWebConfig` has the same rule for the same reason, and
     * a change to one and not the other is a change nobody notices until an install is made on
     * Windows.
     */
    public function test_the_paths_written_into_the_ini_use_forward_slashes(): void
    {
        $this->installTemplate(implode(PHP_EOL, [
            'extension_dir = "{{RUNTIME}}/ext"',
            'error_log = "{{LOGS}}/php-error.log"',
            'sys_temp_dir = "{{TEMP}}"',
            '',
        ]));

        $resolved = $this->resolveIni($this->supervisor());

        // Without this the assertion below compares two strings that were never going to
        // differ, and reports the normalisation as working on a platform that never needed it.
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertStringContainsString(
                '\\',
                $this->data,
                'a data directory with no backslashes means the check below cannot fail here',
            );
        }

        $this->assertStringNotContainsString(
            '\\',
            $resolved,
            'a backslash in an ini path is a path PHP starts with and then cannot load anything from',
        );

        $this->assertStringContainsString(
            'extension_dir = "'.$this->slashed($this->installIn('runtime')).'/ext"',
            $resolved,
            'and the value must still be this install, not a path that merely happens to have no backslashes',
        );

        $this->assertStringNotContainsString(
            '\\',
            (string) file_get_contents(
                $this->data.DIRECTORY_SEPARATOR.'frankenphp'.DIRECTORY_SEPARATOR.'Caddyfile',
            ),
            'the ini and the Caddyfile are resolved in one pass and follow the same separator rule',
        );
    }

    /**
     * `{{RUNTIME}}` stays in the install. `{{LOGS}}` and `{{TEMP}}` go to the data directory.
     *
     * This reads like an inconsistency and is the opposite of one. The extensions under the
     * runtime are only ever read, so a read only install directory is exactly right for them,
     * and copying 130MB of DLLs into the data directory on every start to satisfy a path that
     * is never written to would be the worse trade. Everything the ini does write belongs in
     * the one place an ordinary user can write.
     *
     * Pinned deliberately. The natural tidy up here is to make all three look alike, and doing
     * so trades 130MB a start for a file that reads better.
     */
    public function test_the_runtime_token_stays_in_the_install_while_the_writable_tokens_go_to_the_data_directory(): void
    {
        $this->installShippedIni();

        $resolved = $this->resolveIni($this->supervisor());

        $this->assertStringContainsString(
            'extension_dir = "'.$this->slashed($this->installIn('runtime')).'/ext"',
            $resolved,
            'the extensions are read from where they were shipped',
        );

        $this->assertStringNotContainsString(
            'extension_dir = "'.$this->slashed($this->data),
            $resolved,
            'and must not be redirected into the data directory just to look consistent with the other two',
        );

        $this->assertStringContainsString(
            'error_log = "'.$this->slashed($this->logs).'/php-error.log"',
            $resolved,
            'the error log is written on every request that fails, so it belongs in the data directory',
        );

        $this->assertStringContainsString(
            'sys_temp_dir = "'.$this->slashed($this->data.DIRECTORY_SEPARATOR.'tmp').'"',
            $resolved,
            'and so does the temp directory, which is the one the shipped ini warns about at length',
        );
    }

    /**
     * Which file PHP is pointed at changes once, and at the right moment.
     *
     * Before the layout is prepared there is no resolved copy to point at, so the template is
     * what is named, and the existence check throws rather than handing PHP a path that is not
     * there. After preparation the resolved copy is what all four processes get, which is the
     * whole point: the ini is the only thing that loads the extensions.
     */
    public function test_php_is_handed_the_template_before_preparation_and_the_resolved_copy_after(): void
    {
        $template = $this->installShippedIni();
        $supervisor = $this->supervisor();

        $this->assertSame(
            $template,
            $this->iniFor($supervisor),
            'with nothing resolved yet the shipped template is the only ini there is',
        );

        $this->prepareLayout($supervisor);

        $this->assertSame(
            $this->resolvedPath(),
            $this->iniFor($supervisor),
            'after preparation every process gets the resolved copy, or no extension loads at all',
        );
    }

    /**
     * A missing shipped ini is named, rather than found later as a missing database driver.
     *
     * PHP with no ini does not fail. It starts, reports a version, loads none of the twelve
     * extensions, and Laravel then says "could not find driver". Every step is correct
     * behaviour for what it was given, which is the whole reason the launcher has to check.
     */
    public function test_a_missing_shipped_ini_is_named_rather_than_left_to_fail_later(): void
    {
        $supervisor = $this->supervisor();

        try {
            $this->iniFor($supervisor);
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(
                'PHP configuration file',
                $e->getMessage(),
            );

            $this->assertStringContainsString(
                $this->installIn('runtime', 'php', 'php.ini'),
                $e->getMessage(),
                'and where it was looked for, which is the path to fix',
            );

            $this->assertStringContainsString(
                'database',
                $e->getMessage(),
                'and what it costs, because the real symptom is four layers away and says nothing about the ini',
            );

            return;
        }

        $this->fail('a launcher with no php.ini must say so rather than start PHP with no extensions');
    }

    /**
     * A resolved copy left behind by an earlier run is rewritten, not trusted.
     *
     * The same rule the Caddyfile follows, and for the same reason. A stale ini is an ini
     * naming the paths of an install that has since moved, and an app whose extensions do not
     * load gives no clue why. Rewriting costs one file write and removes the possibility.
     */
    public function test_a_resolved_ini_left_by_an_earlier_run_is_rewritten(): void
    {
        $this->installTemplate("extension_dir = \"{{RUNTIME}}/ext\"\n");

        $supervisor = $this->supervisor();

        $this->prepareLayout($supervisor);

        file_put_contents(
            $this->resolvedPath(),
            "extension_dir = \"C:/an/install/that/moved/last/week\"\n",
        );

        $resolved = $this->resolveIni($supervisor);

        $this->assertStringContainsString(
            $this->slashed($this->installIn('runtime')),
            $resolved,
            'a path left behind by an earlier run must not survive the next start',
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

    /**
     * A process that will spawn children must say where its ini is, not only point at it itself.
     *
     * ## What was broken, and it took the whole install down
     *
     * The queue worker and the scheduler were both started with `-c <ini>`, and both worked. But
     * `schedule:work` does not run the schedule: it shells out to `artisan schedule:run`, building
     * that command from `PHP_BINARY` with no `-c` of its own, because Laravel assumes it inherited
     * a configured interpreter.
     *
     * It had not. This launcher's own PHP loads no ini at all, so the grandchild found none, loaded
     * no extensions, and died on its first query:
     *
     *     PDOException: could not find driver
     *     sqlite:...\AppData\Local\Papyrus\database.sqlite
     *
     * Which is the exact symptom this file's own docblock describes arriving "four layers away",
     * through a door nobody was watching. And it was invisible in three places at once: the
     * supervisor reported all four processes healthy because the child dies inside a live parent,
     * the child wrote to a temp file under `tmp/` rather than to `scheduler.log`, and it recurred
     * every ten seconds because `schedule:run` is what dispatches the work.
     *
     * FrankenPHP had `PHPRC` all along, for exactly this reason. The two workers had only the flag.
     *
     * ## Why this reads the source
     *
     * Because `startWorkers()` starts real processes, and a test that spawns a real scheduler to
     * assert an environment variable is a test that needs luck and leaves one behind when it
     * fails. The property is a statement about what is written down, so it is asserted against
     * what is written down.
     */
    public function test_every_process_that_spawns_children_is_told_where_its_ini_is(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/packaging/launcher/src/Supervisor.php'
        );

        // Comments stripped, for the reason the rest of this suite reads code rather than prose:
        // this bug is one where the code looked right and the comment explained it away. A test
        // that also matched the prose could be satisfied by a paragraph.
        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

        preg_match_all('/new ManagedProcess\((.*?)\n\s*\);/s', $code, $blocks);

        $this->assertNotEmpty(
            $blocks[1],
            'no ManagedProcess was found, so this test would pass for the wrong reason',
        );

        // `schedule:work` is the one that shells out. `queue:work` is held to the same rule
        // because a job that dispatches another job is the same problem one level down, and
        // because two processes written differently is how this started.
        $spawns = ['queue:work', 'schedule:work'];

        $checked = 0;

        foreach ($blocks[1] as $block) {
            if (! str_contains($block, $spawns[0]) && ! str_contains($block, $spawns[1])) {
                continue;
            }

            $checked++;

            $this->assertStringContainsString(
                'PHPRC',
                $this->environmentGivenTo($block, $code),
                'this process runs an artisan command that spawns children, and PHPRC is the only '
                    .'thing its children inherit. Without it a grandchild finds no ini, loads no '
                    .'extensions, and dies on its first query with "could not find driver".',
            );
        }

        $this->assertSame(
            count($spawns),
            $checked,
            'one of the workers that spawn children was not found at all, so the loop above '
                .'checked nothing about it',
        );
    }

    /**
     * The environment a `ManagedProcess` was actually given, following one local variable.
     *
     * Both workers deliberately share one array rather than repeating it, which is the right
     * choice and means a naive "does this block contain PHPRC" reading sees neither. Resolving the
     * hop keeps the sharing and keeps the assertion honest, and it still fails if a block is handed
     * something that turns out to be neither the array nor a variable.
     */
    private function environmentGivenTo(string $block, string $code): string
    {
        // Written inline: the array is right there in the block.
        if (preg_match('/environment:\s*(\[.*?\])\s*,/s', $block, $inline)) {
            return $inline[1];
        }

        // A local variable, resolved from where it is assigned.
        if (preg_match('/environment:\s*(\$[A-Za-z_]\w*)/', $block, $reference)) {
            $name = preg_quote($reference[1], '/');

            if (preg_match('/'.$name.'\s*=\s*(\[.*?\])\s*(?:\+|;)/s', $code, $assignment)) {
                return $assignment[1];
            }
        }

        return '';
    }

    /**
     * And the ini it points at is the resolved one, not the template.
     *
     * Belongs with the above because `PHPRC` pointing at the template is worse than no `PHPRC`:
     * PHP starts, finds `extension_dir = "{{RUNTIME}}/ext"`, fails every extension, and answers
     * exactly as it does with no ini at all. That is the version of this bug the other half of
     * this file exists to prevent, arriving by a different route.
     */
    public function test_the_ini_the_workers_are_pointed_at_is_resolved_rather_than_the_template(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2).'/packaging/launcher/src/Supervisor.php'
        );

        $code = (string) preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);

        preg_match('/\$environment = (\[[^;]*?\][^;]*?);/s', $code, $assignment);

        $this->assertNotEmpty(
            $assignment,
            'the workers no longer share one environment, so this test is checking something that '
                .'has moved',
        );

        $this->assertStringContainsString(
            "'PHPRC' => \$this->ini()",
            $assignment[1],
            'PHPRC must name the resolved ini. $this->ini() falls back to the unresolved template, '
                .'and a grandchild pointed at the template loads no extensions either.',
        );
    }
}
