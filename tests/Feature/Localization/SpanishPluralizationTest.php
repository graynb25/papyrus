<?php

namespace Tests\Feature\Localization;

use Tests\TestCase;

class SpanishPluralizationTest extends TestCase
{
    public function test_every_spanish_plural_key_renders_at_zero_one_and_two(): void
    {
        $catalog = json_decode(
            (string) file_get_contents(base_path('lang/es.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($catalog);

        app()->setLocale('es');

        try {
            foreach ($catalog as $key => $value) {
                if (! is_string($key) || ! str_contains($key, '|')) {
                    continue;
                }

                $this->assertIsString($value);

                foreach ([0, 1, 2] as $count) {
                    $line = trans_choice($key, $count, $this->replacements($key, $count));

                    $this->assertNotSame('', trim($line), "empty plural line for: {$key}");
                    $this->assertStringNotContainsString('{', $line, "literal brace for: {$key}");
                    $this->assertStringNotContainsString('[', $line, "literal bracket for: {$key}");
                    $this->assertStringNotContainsString('|', $line, "literal pipe for: {$key}");
                }
            }
        } finally {
            app()->setLocale('en');
        }
    }

    /**
     * @return array<string, string>
     */
    private function replacements(string $key, int $count): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $key, $matches);

        $replacements = [];

        foreach (array_unique($matches[1]) as $name) {
            $replacements[$name] = $name === 'count' ? (string) $count : 'value';
        }

        return $replacements;
    }
}
