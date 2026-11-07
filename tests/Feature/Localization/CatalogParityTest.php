<?php

namespace Tests\Feature\Localization;

use Tests\TestCase;

class CatalogParityTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const LOCALES = ['es', 'fr', 'pt_BR'];

    /**
     * @var list<string>
     */
    private const GROUP_FILES = ['auth', 'pagination', 'passwords', 'validation'];

    /**
     * Values that are genuinely identical to their English source key.
     *
     * @return list<string>
     */
    private function identical(string $locale): array
    {
        $common = [
            'Papyrus',
            'Suwayomi',
            'PHP',
            'JavaScript',
            'NSFW',
            'ComicInfo.xml',
            'protobuf',
            'Zoom',
        ];

        $specific = match ($locale) {
            'fr' => [
                'Description',
                'Genre',
                'Extensions',
                'Scanlators',
                'source|sources',
                ':count page|:count pages',
                '{1} :count extension|[2,*] :count extensions',
                'FAQ',
                'Page :number',
                'Pages',
                'Sources',
                'Sites',
                'Source :id',
                'format',
                'ok',
                'version',
                'Licences',
                ':language, :count source|:language, :count sources',
                '{1} :count minute|[2,*] :count minutes',
                '{1} :count page|[2,*] :count pages',
                '{1} :count site|[2,*] :count sites',
                '{1} :count source|[2,*] :count sources',
            ],
            'pt_BR' => [
                'Backup',
                'Backups',
                'Status',
                'Volume',
                'Downloads',
                'Sites',
                'Scanlators',
                '{1} :count site|[2,*] :count sites',
            ],
            default => [],
        };

        return array_merge($common, $specific);
    }

    public function test_the_translated_catalogs_have_the_same_keys_in_the_same_order(): void
    {
        $spanish = $this->catalog('es');

        $this->assertCount(1368, $spanish);

        foreach (['fr', 'pt_BR'] as $locale) {
            $this->assertSame(
                array_keys($spanish),
                array_keys($this->catalog($locale)),
                "{$locale}.json must mirror the Spanish key set and order",
            );
        }
    }

    public function test_every_sentence_value_is_a_non_empty_string_with_matching_placeholders_and_branches(): void
    {
        foreach (self::LOCALES as $locale) {
            foreach ($this->catalog($locale) as $key => $value) {
                $this->assertIsString($key);
                $this->assertIsString($value, "{$locale}: {$key}");
                $this->assertNotSame('', trim($value), "{$locale}: empty value for {$key}");

                $this->assertSame(
                    $this->placeholders($key),
                    $this->placeholders($value),
                    "{$locale}: placeholders differ for {$key}",
                );

                $this->assertSame(
                    substr_count($key, '|'),
                    substr_count($value, '|'),
                    "{$locale}: plural branches differ for {$key}",
                );

                $this->assertSame(
                    $this->selectors($key),
                    $this->selectors($value),
                    "{$locale}: plural selectors differ for {$key}",
                );

                $this->assertSame(
                    $this->markup($key),
                    $this->markup($value),
                    "{$locale}: HTML fragments differ for {$key}",
                );

                $this->assertSame(
                    $this->edgeSpaces($key),
                    $this->edgeSpaces($value),
                    "{$locale}: edge spaces differ for {$key}",
                );
            }
        }
    }

    public function test_no_english_value_survives_outside_the_allowlist(): void
    {
        foreach (['fr', 'pt_BR'] as $locale) {
            $actual = [];

            foreach ($this->catalog($locale) as $key => $value) {
                if ($key === $value) {
                    $actual[] = $key;
                }
            }

            $allowed = $this->identical($locale);

            sort($actual);
            sort($allowed);

            $this->assertSame(
                $allowed,
                $actual,
                "{$locale}: the identical-value allowlist is stale or incomplete",
            );
        }
    }

    public function test_the_catalogs_are_utf8_without_bom_or_crlf(): void
    {
        foreach (self::LOCALES as $locale) {
            $bytes = (string) file_get_contents(base_path("lang/{$locale}.json"));

            $this->assertFalse(str_starts_with($bytes, "\xEF\xBB\xBF"), "{$locale}.json has a BOM");
            $this->assertStringNotContainsString("\r\n", $bytes, "{$locale}.json uses CRLF");
        }
    }

    public function test_framework_groups_mirror_the_spanish_key_shape(): void
    {
        foreach (self::GROUP_FILES as $group) {
            $spanish = $this->group('es', $group);

            foreach (['fr', 'pt_BR'] as $locale) {
                $translated = $this->group($locale, $group);

                $this->assertSame(
                    $this->shape($spanish),
                    $this->shape($translated),
                    "lang/{$locale}/{$group}.php does not mirror the Spanish shape",
                );

                $spanishLines = $this->flatten($spanish);
                $translatedLines = $this->flatten($translated);

                $this->assertSame(array_keys($spanishLines), array_keys($translatedLines));

                foreach ($spanishLines as $path => $line) {
                    $this->assertIsString($line);
                    $this->assertIsString($translatedLines[$path]);
                    $this->assertNotSame('', trim($translatedLines[$path]), "lang/{$locale}/{$group}.php: {$path} is empty");
                    $this->assertSame(
                        $this->placeholders($line),
                        $this->placeholders($translatedLines[$path]),
                        "lang/{$locale}/{$group}.php: placeholders differ at {$path}",
                    );

                    $this->assertSame(
                        $this->markup($line),
                        $this->markup($translatedLines[$path]),
                        "lang/{$locale}/{$group}.php: HTML fragments differ at {$path}",
                    );
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        $catalog = json_decode(
            (string) file_get_contents(base_path("lang/{$locale}.json")),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($catalog);

        /** @var array<string, string> $catalog */
        return $catalog;
    }

    /**
     * @return array<string, mixed>
     */
    private function group(string $locale, string $group): array
    {
        /** @var array<string, mixed> $lines */
        $lines = require base_path("lang/{$locale}/{$group}.php");

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, mixed>
     */
    private function shape(array $array): array
    {
        $shape = [];

        foreach ($array as $key => $value) {
            $shape[$key] = is_array($value) ? $this->shape($value) : get_debug_type($value);
        }

        return $shape;
    }

    /**
     * @param  array<string, mixed>  $array
     * @return array<string, string>
     */
    private function flatten(array $array, string $prefix = ''): array
    {
        $flat = [];

        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $path);

                continue;
            }

            $flat[$path] = (string) $value;
        }

        return $flat;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $line): array
    {
        preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $line, $matches);

        $placeholders = $matches[0];
        sort($placeholders);

        return $placeholders;
    }

    /**
     * @return list<string>
     */
    private function selectors(string $line): array
    {
        preg_match_all('/\{\d+(?:,\d*)?\}|\[\d+,\*\]/', $line, $matches);

        $selectors = $matches[0];
        sort($selectors);

        return $selectors;
    }

    /**
     * @return list<string>
     */
    private function markup(string $line): array
    {
        preg_match_all('/<[^>]+>|&[A-Za-z][A-Za-z0-9]*;|&#\d+;/', $line, $matches);

        $markup = $matches[0];
        sort($markup);

        return $markup;
    }

    /**
     * @return array{int, int}
     */
    private function edgeSpaces(string $line): array
    {
        preg_match('/^ */', $line, $leading);
        preg_match('/ *$/', $line, $trailing);

        return [strlen($leading[0]), strlen($trailing[0])];
    }
}
