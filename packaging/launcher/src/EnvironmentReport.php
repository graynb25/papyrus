<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

use DateTimeImmutable;

/**
 * The `environment.txt` in a diagnostics bundle: what this machine is, and nothing else.
 *
 * ## Why every probe is named rather than discovered
 *
 * Because a bundle gets attached to a public issue. Anything that dumps by default has to be
 * filtered afterwards, and a filter is an argument about what a secret *looks like*: `*_KEY`,
 * `*_TOKEN`, `*_SECRET`. That list is never finished, and a value called `PAPYRUS_AUTH` or one
 * sitting in a URL sails straight through it.
 *
 * So nothing here enumerates. Every line below is a value someone decided was worth knowing,
 * produced by a function that returns a scalar the author chose to call. There is no path through
 * this class that can emit a variable, a header, a query string or a file's contents, because
 * none of them is ever read.
 *
 * That is the same discipline the rest of this project arrived at the hard way: a condition
 * asserted rather than established is how six separate checks this session produced clean,
 * specific and entirely fictional claims. A blocklist would have been exactly that shape.
 *
 * ## What is deliberately absent
 *
 * Environment variables, credentials, library tokens, the reader's library contents, and the
 * reader's database. Not "filtered out of" that list. Never read.
 *
 * Paths *are* included, and a Windows path contains the reader's username. That is a real
 * trade: the data directory being on a different drive from the install is a common cause, and
 * a redacted path cannot answer it. The bundle's README says so plainly, so the decision to
 * attach it stays with the reader.
 */
final class EnvironmentReport
{
    public function __construct(
        private readonly Supervisor $supervisor,
        private readonly Prober $prober,
    ) {}

    public function render(): string
    {
        $sections = [];

        foreach ([
            'When' => $this->when(),
            'What is installed' => $this->installed(),
            'PHP' => $this->php(),
            'Operating system' => $this->operatingSystem(),
            'Paths' => $this->paths(),
            'Ports' => $this->ports(),
            'Disk' => $this->disk(),
            'PHP extensions' => $this->extensions(),
        ] as $heading => $rows) {
            $sections[] = $this->section($heading, $rows);
        }

        $sections[] = $this->note(
            'No environment variables, credentials, library contents or reader data are collected here. '
            .'Every line above is a value this program asked the operating system for by name.',
        );

        return implode(PHP_EOL, $sections).PHP_EOL;
    }

    /** @return array<string, string> */
    private function when(): array
    {
        $zone = date_default_timezone_get();

        return [
            'collected at' => (new DateTimeImmutable)->format('Y-m-d H:i:s.vP'),
            // Not "UTC (UTC)", which is what a naive offset suffix prints when the zone already
            // is UTC and reads like a mistake.
            'timezone' => $zone === 'UTC' ? 'UTC' : $zone.', '.gmdate('P').' from UTC',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function installed(): array
    {
        return [
            'papyrus version' => $this->papyrusVersion(),
            'suwayomi version' => $this->suwayomiVersion(),
        ];
    }

    /**
     * What `version.txt` says, which the build writes from the git commit it was built from.
     *
     * Read as text and reported as written, including the "unknown, built outside a git checkout"
     * the build falls back to. An install genuinely built from source with no version recorded is
     * worth knowing about, and the build writing that sentence is why it reads as a fact rather
     * than as a gap in this report.
     */
    private function papyrusVersion(): string
    {
        $path = $this->supervisor->environmentPath().DIRECTORY_SEPARATOR.'version.txt';

        if (! is_file($path)) {
            return 'not recorded, this install was not produced by the build script';
        }

        $lines = array_values(array_filter(array_map(
            'trim',
            file($path, FILE_IGNORE_NEW_LINES) ?: [],
        )));

        if ($lines === []) {
            return 'version.txt is present but empty';
        }

        return implode(', ', array_map(
            static fn (string $line): string => str_replace(
                ['commit ', 'built '],
                ['', 'built '],
                $line,
            ),
            $lines,
        ));
    }

    private function suwayomiVersion(): string
    {
        $version = $this->prober->suwayomiVersion($this->supervisor->suwayomiPort());

        return $version === '' ? 'unknown, Suwayomi is not answering' : $version;
    }

    /**
     * @return array<string, string>
     */
    private function php(): array
    {
        return [
            'version' => PHP_VERSION,
            // phpversion('build_date') rather than a constant. `PHP_BUILD_DATE` does not exist,
            // and writing it anyway would have been a line that only ever runs in an install
            // nobody has, which is the worst place for a fatal error to hide.
            'build date' => $this->buildDate(),
            'thread safety' => PHP_ZTS ? 'thread safe' : 'non thread safe',
            'interface' => PHP_SAPI,
            'loaded configuration' => php_ini_loaded_file() ?: 'none, which is normal in a packaged install',
            'memory limit' => (string) ini_get('memory_limit'),
            'maximum execution time' => (string) ini_get('max_execution_time').'s',
            'upload maximum' => (string) ini_get('upload_max_filesize'),
        ];
    }

    /**
     * `phpversion('build_date')` is empty on some builds, including this one.
     *
     * An empty value beside a label reads as a broken report rather than as "this PHP does not
     * say", so the absence is stated instead of printed as a blank.
     */
    private function buildDate(): string
    {
        $date = (string) phpversion('build_date');

        if (trim($date) === '') {
            return PHP_OS.' '.php_uname('r').', this PHP build does not report one';
        }

        return PHP_OS.' '.php_uname('r').', built '.$date;
    }

    /**
     * @return array<string, string>
     */
    private function operatingSystem(): array
    {
        return [
            'name' => php_uname('s'),
            'release' => php_uname('r'),
            'version' => php_uname('v'),
            'architecture' => php_uname('m'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function paths(): array
    {
        $database = $this->supervisor->databaseFile();

        return [
            'install' => $this->supervisor->environmentPath(),
            'data' => $this->supervisor->dataDirectory(),
            'logs' => $this->supervisor->logDirectory(),
            'database' => $database.($this->describeFile($database) ?? ', does not exist yet'),
        ];
    }

    private function describeFile(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        return sprintf(', %d bytes', (int) filesize($path));
    }

    /**
     * @return array<string, string>
     */
    private function ports(): array
    {
        $app = $this->supervisor->appPort();
        $suwayomi = $this->supervisor->suwayomiPort();

        return [
            'application' => $app.($this->prober->isPortTaken($app) ? ', something is listening' : ', nothing is listening'),
            'suwayomi' => $suwayomi.($this->prober->isPortTaken($suwayomi) ? ', something is listening' : ', nothing is listening'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function disk(): array
    {
        $volume = $this->volumeOf($this->supervisor->dataDirectory());

        $free = @disk_free_space($volume);

        return [
            'data volume' => $volume,
            'free space' => $free === false
                ? 'could not be read'
                : sprintf('%.1f GB of %.1f GB', $free / 1_073_741_824, @disk_total_space($volume) / 1_073_741_824),
        ];
    }

    private function volumeOf(string $path): string
    {
        return strlen($path) > 1 && $path[1] === ':' ? substr($path, 0, 2) : $path;
    }

    /**
     * Extension names only, no configuration values.
     *
     * The names say whether something needed is missing, which is the question, and a value could
     * carry a path or a credential depending on the extension and the host.
     *
     * @return array<string, string>
     */
    private function extensions(): array
    {
        $names = get_loaded_extensions();

        sort($names);

        return [
            'count' => (string) count($names),
            'loaded' => implode(', ', $names),
        ];
    }

    /**
     * @param  array<string, string>  $rows
     */
    private function section(string $heading, array $rows): string
    {
        $width = 0;

        foreach (array_keys($rows) as $label) {
            $width = max($width, strlen($label));
        }

        $lines = [$heading];

        foreach ($rows as $label => $value) {
            $lines[] = '  '.str_pad($label, $width).'  '.$value;
        }

        return implode(PHP_EOL, $lines);
    }

    private function note(string $text): string
    {
        return PHP_EOL.'Note'.PHP_EOL.'  '.wordwrap($text, 76, PHP_EOL.'  ', false);
    }
}
