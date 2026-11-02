<?php

namespace Tests\Support;

/**
 * A real translation for sentence keys, so a missing __() cannot hide.
 *
 * English copy is also a key, and no translation catalog ships with the app, so a
 * string wrapped in __() and one that is not both render the same English
 * sentence. The fixture writes a JSON catalog into the test's scratch directory
 * and points the loader at it, which is the shape a localised install uses.
 */
trait UsesTranslations
{
    /**
     * @param  array<string, string>  $lines
     */
    protected function useTranslations(array $lines, string $locale = 'xx'): void
    {
        $path = $this->scratchPath('translations');
        $file = $path.DIRECTORY_SEPARATOR.$locale.'.json';

        file_put_contents($file, json_encode($lines, JSON_THROW_ON_ERROR));

        app('translation.loader')->addJsonPath($path);
        app()->setLocale($locale);
    }
}
