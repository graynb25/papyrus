<?php

namespace Tests\Feature;

use Papyrus\Launcher\Translator;
use Tests\TestCase;

/**
 * The launcher's language resolution and its catalogues.
 *
 * The launcher cannot use the framework, so these assertions are against the same class the
 * packaged install runs, loaded through the composer mapping rather than through Laravel.
 */
final class LauncherTranslatorTest extends TestCase
{
    public function test_a_regional_spanish_tag_resolves_to_generic_spanish(): void
    {
        $this->assertSame('es', Translator::resolve('es-ES', 'en-US'));
        $this->assertSame('es', Translator::resolve('es-419', 'en-US'));
        $this->assertSame('es', Translator::resolve('ES_es', 'en-US'));
    }

    public function test_a_regional_french_tag_resolves_to_french(): void
    {
        $this->assertSame('fr', Translator::resolve('fr-FR', 'en-US'));
        $this->assertSame('fr', Translator::resolve('fr_CA', 'en-US'));
    }

    public function test_brazilian_portuguese_accepts_pt_and_pt_br(): void
    {
        $this->assertSame('pt_BR', Translator::resolve('pt-BR', 'en-US'));
        $this->assertSame('pt_BR', Translator::resolve('pt', 'en-US'));
        $this->assertSame('pt_BR', Translator::resolve('pt_BR', 'en-US'));
    }

    /**
     * European Portuguese is not Brazilian Portuguese.
     *
     * Folding `pt-PT` into `pt_BR` would label Brazilian copy as a variant it is not. It falls
     * through the candidate list and ends at English when no later candidate is supported.
     */
    public function test_pt_pt_is_not_folded_into_brazilian_portuguese(): void
    {
        $this->assertNull(Translator::normalize('pt-PT'));
        $this->assertSame('en', Translator::resolve('pt-PT', 'pt-PT'));
    }

    public function test_an_unsupported_explicit_tag_falls_through_to_the_system_language(): void
    {
        $this->assertSame('fr', Translator::resolve('de-DE', 'fr-FR'));
        $this->assertSame('en', Translator::resolve('de-DE', 'de-DE'));
    }

    public function test_the_language_comes_from_papyrus_locale_first(): void
    {
        $previous = getenv('PAPYRUS_LOCALE');

        try {
            putenv('PAPYRUS_LOCALE=es-MX');

            $translator = Translator::fromEnvironment();

            $this->assertSame('es', $translator->locale());
            $this->assertStringContainsString('Ejecuta', $translator->get('health.assert'));
        } finally {
            if (is_string($previous)) {
                putenv('PAPYRUS_LOCALE='.$previous);
            } else {
                putenv('PAPYRUS_LOCALE');
            }
        }
    }

    public function test_the_installer_language_is_used_when_papyrus_locale_is_absent(): void
    {
        $previous = getenv('PAPYRUS_LOCALE');

        try {
            putenv('PAPYRUS_LOCALE');

            $translator = Translator::fromEnvironment('es');

            $this->assertSame('es', $translator->locale());
            $this->assertStringContainsString('Ejecuta', $translator->get('health.assert'));
        } finally {
            if (is_string($previous)) {
                putenv('PAPYRUS_LOCALE='.$previous);
            } else {
                putenv('PAPYRUS_LOCALE');
            }
        }
    }

    public function test_an_explicit_papyrus_locale_beats_the_installer_language(): void
    {
        $previous = getenv('PAPYRUS_LOCALE');

        try {
            putenv('PAPYRUS_LOCALE=fr-FR');

            $this->assertSame('fr', Translator::fromEnvironment('es')->locale());
        } finally {
            if (is_string($previous)) {
                putenv('PAPYRUS_LOCALE='.$previous);
            } else {
                putenv('PAPYRUS_LOCALE');
            }
        }
    }

    public function test_an_unsupported_installer_language_falls_through(): void
    {
        $previous = getenv('PAPYRUS_LOCALE');

        try {
            putenv('PAPYRUS_LOCALE');

            $this->assertSame('en', Translator::fromEnvironment('de-DE')->locale());
        } finally {
            if (is_string($previous)) {
                putenv('PAPYRUS_LOCALE='.$previous);
            } else {
                putenv('PAPYRUS_LOCALE');
            }
        }
    }

    /**
     * A placeholder followed by a literal unit is replaced as a prefix.
     *
     * `health.schedule.dispatching` says `:ages` so the reader gets `3s`, not `3 seconds`, and the
     * replacement key is still `age`. The child queue probe uses the same shape.
     */
    public function test_a_placeholder_followed_by_a_literal_unit_is_substituted(): void
    {
        $this->assertSame(
            'dispatching, last wrote 3s ago',
            (new Translator('en'))->get('health.schedule.dispatching', ['age' => 3]),
        );
        $this->assertStringContainsString(
            '3s',
            (new Translator('es'))->get('health.schedule.dispatching', ['age' => 3]),
        );
    }

    public function test_every_catalogue_has_the_same_non_empty_keys(): void
    {
        $english = Translator::keys('en');

        $this->assertNotEmpty($english);

        foreach (['es', 'fr', 'pt_BR'] as $locale) {
            $this->assertSame(
                $english,
                Translator::keys($locale),
                $locale.' must define every key the source catalogue defines',
            );

            foreach ($english as $key) {
                $this->assertNotSame(
                    '',
                    trim((new Translator($locale))->get($key)),
                    $locale.' has an empty value for '.$key,
                );
            }
        }
    }

    /**
     * The installer scans the stop output for this phrase to tell a failed stop from a failed run.
     *
     * A translated stop line that dropped it would make the installer believe every process had
     * closed, and it would replace files underneath a live JVM. The phrase stays in every language
     * and the explanation after it is translated.
     */
    public function test_every_stop_failure_line_keeps_the_installer_marker(): void
    {
        foreach (Translator::LOCALES as $locale) {
            $this->assertStringContainsString(
                'did not stop',
                (new Translator($locale))->get('launcher.stop.failed'),
                $locale.' must keep the phrase the installer scans for',
            );
        }
    }

    public function test_yes_words_follow_the_catalogue(): void
    {
        $this->assertTrue((new Translator('en'))->isYes('yes'));
        $this->assertTrue((new Translator('es'))->isYes('sí'));
        $this->assertTrue((new Translator('es'))->isYes('s'));
        $this->assertTrue((new Translator('fr'))->isYes('oui'));
        $this->assertTrue((new Translator('pt_BR'))->isYes('sim'));

        $this->assertFalse((new Translator('es'))->isYes('yes'));
        $this->assertFalse((new Translator('fr'))->isYes('no'));
        $this->assertFalse((new Translator('en'))->isYes(''));
    }

    /**
     * The batch guards are fragments because cmd.exe cannot read accented UTF-8 reliably.
     *
     * Every language must carry every guard. The fallback to English exists for a damaged install,
     * not as a way to ship three incomplete translations without noticing.
     */
    public function test_the_batch_guards_have_a_fragment_in_every_language(): void
    {
        $root = dirname(__DIR__, 2).'/packaging/launcher/lang/cmd';
        $blocks = ['missing-php', 'missing-launcher', 'refused-runtime', 'start-failed'];

        foreach (Translator::LOCALES as $locale) {
            foreach ($blocks as $block) {
                $path = $root.'/'.$locale.'/'.$block.'.txt';

                $this->assertFileExists($path, $locale.' is missing the '.$block.' guard');
                $this->assertNotSame('', trim((string) file_get_contents($path)), $path.' is empty');
            }
        }
    }

    public function test_the_batch_script_stays_ascii_and_reads_the_fragments(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/launcher/Papyrus.cmd');

        $this->assertSame(
            0,
            preg_match('/[^\x00-\x7F]/', $script),
            'Papyrus.cmd must stay ASCII; cmd.exe misreads accented UTF-8 in a batch file',
        );
        $this->assertStringContainsString('chcp 65001', $script, 'the fragments need the UTF-8 console page');
        $this->assertStringContainsString('call :message missing-php', $script);
        $this->assertStringContainsString('call :message missing-launcher', $script);
        $this->assertStringContainsString('call :message refused-runtime', $script);
        $this->assertStringContainsString('call :message start-failed', $script);
        $this->assertStringContainsString('%SystemRoot%\System32\reg.exe', $script);
        $this->assertStringContainsString('%SystemRoot%\System32\findstr.exe', $script);
        $this->assertStringNotContainsString("('reg query", $script);
        $this->assertStringNotContainsString('^| findstr', $script);
    }
}
