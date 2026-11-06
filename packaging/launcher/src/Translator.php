<?php

declare(strict_types=1);

namespace Papyrus\Launcher;

/**
 * The launcher's own message catalogue, with no framework behind it.
 *
 * ## Why this is not Laravel's translator
 *
 * The whole point of the launcher is that it keeps working when the application does not. A
 * translation layer that needs the framework would be unavailable in exactly the situation a reader
 * needs `stop`, `health` or `rollback`, so the catalogues are plain PHP arrays beside this class.
 *
 * ## Why the locale resolution order is fixed
 *
 * A reader may set `PAPYRUS_LOCALE`, Windows has a UI language, and English is the source. Those are
 * three different claims, and an explicit choice has to win. A regional tag is normalised to the
 * catalogue that can actually render it: `es-ES` and `es-419` are Spanish, `fr-FR` is French, and
 * `pt` and `pt-BR` are Brazilian Portuguese. `pt-PT` is deliberately not folded into `pt_BR`,
 * because European Portuguese is a different variant and labelling Brazilian copy as European
 * Portuguese would misrepresent it. It falls through to the next candidate, and then to English.
 *
 * ## Why English is the fallback rather than a failure
 *
 * A launcher that refuses to start because a locale is unsupported is worse than one that speaks
 * English. Every catalogue is complete today, and a missing key still falls back to the English
 * value so a half finished translation cannot make a command unreadable.
 */
final class Translator
{
    /** The source language, and the one every other catalogue falls back to. */
    public const FALLBACK = 'en';

    /**
     * Every catalogue this launcher can render.
     *
     * @var list<string>
     */
    public const LOCALES = ['en', 'es', 'fr', 'pt_BR'];

    /** @var array<string, array<string, string>> */
    private static array $catalogues = [];

    /** @var array<string, string> */
    private array $messages;

    public function __construct(private readonly string $locale = self::FALLBACK)
    {
        $this->messages = self::catalogue($locale);
    }

    /**
     * The translator for the environment this process was started in.
     *
     * `PAPYRUS_LOCALE` first, then the Windows UI language, then English. The Windows query is made
     * when no explicit locale is set, or when the explicit one names no catalogue, because it starts
     * `reg.exe` and most packaged starts do not need it.
     */
    public static function fromEnvironment(): self
    {
        $explicit = getenv('PAPYRUS_LOCALE');
        $explicit = is_string($explicit) ? $explicit : null;

        /*
         * A supported explicit tag is answered without starting `reg.exe`. An unsupported one is
         * not a refusal: it falls through to the Windows UI language and then to English, which is
         * the same order `resolve()` applies.
         */
        if (self::normalize($explicit) !== null) {
            return new self(self::resolve($explicit));
        }

        return new self(self::resolve($explicit, self::windowsLocale()));
    }

    /**
     * Resolve a locale from an explicit choice and a system language, in that order.
     *
     * Each candidate is normalised and unsupported tags are skipped rather than treated as a
     * refusal. That is what makes `PAPYRUS_LOCALE=de-DE` on a French Windows fall back to French,
     * and an unsupported pair fall back to English.
     */
    public static function resolve(?string $explicit, ?string $system = null): string
    {
        foreach ([$explicit, $system] as $candidate) {
            $normalized = self::normalize($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return self::FALLBACK;
    }

    /**
     * Turn a Windows or BCP 47 tag into one of this launcher's catalogue codes.
     *
     * `pt-PT` is intentionally absent: see the class docblock. Returning null rather than guessing
     * is what lets the caller try the next candidate honestly.
     */
    public static function normalize(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $tag = strtolower(trim(str_replace('-', '_', $locale)));

        if ($tag === '') {
            return null;
        }

        if ($tag === 'en' || str_starts_with($tag, 'en_')) {
            return 'en';
        }

        if ($tag === 'es' || str_starts_with($tag, 'es_')) {
            return 'es';
        }

        if ($tag === 'fr' || str_starts_with($tag, 'fr_')) {
            return 'fr';
        }

        if ($tag === 'pt' || $tag === 'pt_br') {
            return 'pt_BR';
        }

        return null;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * One message, with `:name` placeholders replaced.
     *
     * The English catalogue is consulted when a translated catalogue is missing a key, so an
     * incomplete translation degrades to a readable sentence rather than to a raw key name.
     *
     * @param  array<string, string|int>  $replace
     */
    public function get(string $key, array $replace = []): string
    {
        $line = $this->messages[$key] ?? self::catalogue(self::FALLBACK)[$key] ?? $key;

        foreach ($replace as $name => $value) {
            $line = str_replace(':'.$name, (string) $value, $line);
        }

        return $line;
    }

    public function has(string $key): bool
    {
        return isset($this->messages[$key]);
    }

    /**
     * Whether a typed answer means yes in this catalogue.
     *
     * The prompt is localised, so the accepted word has to be too. An empty answer, which is what
     * a closed pipe or a redirected stdin gives, counts as no.
     */
    public function isYes(string $answer): bool
    {
        $words = [
            'en' => ['y', 'yes'],
            'es' => ['s', 'sí', 'si'],
            'fr' => ['o', 'oui'],
            'pt_BR' => ['s', 'sim'],
        ][$this->locale] ?? ['y', 'yes'];

        return in_array(strtolower(trim($answer)), $words, true);
    }

    /**
     * Every message key in a catalogue, sorted. Used by the catalog test.
     *
     * @return list<string>
     */
    public static function keys(string $locale): array
    {
        $keys = array_keys(self::catalogue($locale));
        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    private static function catalogue(string $locale): array
    {
        if (isset(self::$catalogues[$locale])) {
            return self::$catalogues[$locale];
        }

        $path = dirname(__DIR__).DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.$locale.'.php';

        if (! in_array($locale, self::LOCALES, true) || ! is_file($path)) {
            return self::$catalogues[$locale] = [];
        }

        /** @var mixed $messages */
        $messages = require $path;

        if (! is_array($messages)) {
            return self::$catalogues[$locale] = [];
        }

        $clean = [];

        foreach ($messages as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $clean[$key] = $value;
            }
        }

        return self::$catalogues[$locale] = $clean;
    }

    /**
     * The Windows UI language, or null when this is not Windows or the registry cannot be read.
     *
     * `Languages` is the user's ordered UI language list and matches what Windows itself would use.
     * `LocaleName` is the fallback for older profiles that do not carry the list. Both are read
     * through the absolute `reg.exe` path for the same reason every other tool is: a bare name can
     * resolve to a file beside a per-user install.
     */
    private static function windowsLocale(): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }

        $reg = self::regExecutable();

        if ($reg === null) {
            return null;
        }

        $queries = [
            ['HKCU\Control Panel\International\User Profile', 'Languages', true],
            ['HKCU\Control Panel\International', 'LocaleName', false],
        ];

        foreach ($queries as [$key, $value, $multi]) {
            $output = @shell_exec(sprintf(
                '%s query %s /v %s 2>NUL',
                escapeshellarg($reg),
                escapeshellarg($key),
                escapeshellarg($value),
            ));

            if (! is_string($output)) {
                continue;
            }

            /*
             * `Languages` is a REG_MULTI_SZ whose members `reg.exe` prints separated by a literal
             * `\0`. The first member is the preferred UI language, which is the one to use. The
             * regex stops at whitespace, so a tag cannot swallow the rest of the line.
             */
            $pattern = $multi
                ? '/'.$value.'\s+REG_MULTI_SZ\s+(\S+)/i'
                : '/'.$value.'\s+REG_SZ\s+(\S+)/i';

            if (preg_match($pattern, $output, $matches) !== 1) {
                continue;
            }

            $first = str_replace('\\0', "\n", $matches[1]);
            $first = explode("\n", $first)[0];
            $first = trim($first);

            if ($first !== '') {
                return $first;
            }
        }

        return null;
    }

    private static function regExecutable(): ?string
    {
        $path = SystemTools::systemRoot().'\\System32\\reg.exe';

        return is_file($path) ? $path : null;
    }
}
