<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The installer's own messages, checked against the script rather than a running setup.
 *
 * A compile proves the syntax and the language files are found; it does not prove that a message
 * the [Code] section asks for exists, because Inno happily returns the message name as the text
 * when it is missing. This test is the check that the English defaults and all three translations
 * are present for every message the wizard or the guard can display.
 */
final class InstallerLanguagesTest extends TestCase
{
    private string $script = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/installer/papyrus.iss');
    }

    public function test_english_is_first_and_the_three_translations_use_the_shipped_language_files(): void
    {
        $this->assertMatchesRegularExpression(
            '/^Name: "english"; MessagesFile: "compiler:Default\.isl"$/m',
            $this->script,
        );
        $this->assertMatchesRegularExpression(
            '/^Name: "spanish"; MessagesFile: "compiler:Languages\\\\Spanish\.isl"$/m',
            $this->script,
        );
        $this->assertMatchesRegularExpression(
            '/^Name: "french"; MessagesFile: "compiler:Languages\\\\French\.isl"$/m',
            $this->script,
        );
        $this->assertMatchesRegularExpression(
            '/^Name: "brazilianportuguese"; MessagesFile: "compiler:Languages\\\\BrazilianPortuguese\.isl"$/m',
            $this->script,
        );

        $this->assertLessThan(
            strpos($this->script, 'Name: "spanish"'),
            strpos($this->script, 'Name: "english"'),
            'English must stay first, because it is the default when Windows matches no entry',
        );
    }

    /**
     * The picker is offered on a normal install and suppressed by the silent flags.
     *
     * Inno's `yes` means "show it whenever more than one language exists". Its own silent mode
     * skips wizard pages, and `/LANG` suppresses this one explicitly, so no extra code is needed.
     */
    public function test_the_language_dialog_is_enabled_for_normal_installs(): void
    {
        $this->assertMatchesRegularExpression('/^ShowLanguageDialog=yes$/m', $this->script);
        $this->assertDoesNotMatchRegularExpression('/^ShowLanguageDialog=no$/m', $this->script);
    }

    /**
     * The requested output directory has to reach the compiler, not just be created.
     *
     * The .iss names `build\dist` in `OutputDir`. Without `/O`, `--out` made an empty directory,
     * the compile succeeded into `build\dist`, and the script then failed its own artifact check
     * after overwriting the release directory.
     */
    public function test_the_build_script_passes_the_requested_output_directory_to_the_compiler(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/packaging/installer/build-installer.php');

        $this->assertStringContainsString("'/O'.\$out,", $script);
    }

    /**
     * The wizard's language has to reach the launcher, not stop at its own window.
     */
    public function test_the_installer_writes_the_selected_language_for_the_launcher(): void
    {
        $this->assertStringContainsString("ExpandConstant('{app}\\installer-locale.txt')", $this->script);
        $this->assertStringContainsString("CompareText(ActiveLanguage, 'spanish')", $this->script);
        $this->assertStringContainsString("CompareText(ActiveLanguage, 'french')", $this->script);
        $this->assertStringContainsString("CompareText(ActiveLanguage, 'brazilianportuguese')", $this->script);
        $this->assertStringContainsString("Result := 'es'", $this->script);
        $this->assertStringContainsString("Result := 'fr'", $this->script);
        $this->assertStringContainsString("Result := 'pt_BR'", $this->script);
        $this->assertStringContainsString('CurStepChanged', $this->script);
    }

    public function test_every_message_the_script_asks_for_exists_in_all_four_languages(): void
    {
        $messages = $this->customMessages();
        $used = $this->usedMessages();

        $this->assertNotEmpty($used);

        foreach ($used as $name) {
            $this->assertArrayHasKey($name, $messages, 'the script asks for CustomMessage('.$name.') but the default is missing');

            foreach (['spanish', 'french', 'brazilianportuguese'] as $language) {
                $this->assertArrayHasKey(
                    $language.'.'.$name,
                    $messages,
                    $language.' is missing its '.$name.' message',
                );
            }
        }
    }

    public function test_every_translated_message_is_not_the_english_default(): void
    {
        $messages = $this->customMessages();

        foreach ($messages as $name => $value) {
            if (str_contains($name, '.')) {
                continue;
            }

            foreach (['spanish', 'french', 'brazilianportuguese'] as $language) {
                $this->assertArrayHasKey($language.'.'.$name, $messages);
                $this->assertNotSame(
                    $value,
                    $messages[$language.'.'.$name],
                    $language.'.'.$name.' is still the English text',
                );
            }
        }
    }

    public function test_the_version_placeholder_survives_translation(): void
    {
        $messages = $this->customMessages();

        foreach (['', 'spanish.', 'french.', 'brazilianportuguese.'] as $prefix) {
            $this->assertStringContainsString(
                '%1',
                $messages[$prefix.'DowngradeRefused'],
                'the installed version placeholder must stay in every language',
            );
        }
    }

    public function test_the_code_section_no_longer_hardcodes_reader_messages(): void
    {
        foreach ([
            "'This installer is older",
            "'Papyrus is already installed here",
            "'This older Papyrus",
            "'Papyrus could not be stopped",
            "'Papyrus is still running",
            "'Nothing was changed",
            'Comment: "Start Papyrus"',
        ] as $hardcoded) {
            $this->assertStringNotContainsString(
                $hardcoded,
                $this->script,
                'the installer still hardcodes '.$hardcoded,
            );
        }
    }

    public function test_the_script_has_no_em_or_en_dashes(): void
    {
        $this->assertStringNotContainsString("\u{2014}", $this->script);
        $this->assertStringNotContainsString("\u{2013}", $this->script);
    }

    /**
     * The `[CustomMessages]` section, keyed by the full name including its language prefix.
     *
     * @return array<string, string>
     */
    private function customMessages(): array
    {
        if (preg_match('/^\[CustomMessages\]$(.*?)(?=^\[|\z)/ms', $this->script, $section) !== 1) {
            $this->fail('the installer has no [CustomMessages] section');
        }

        $messages = [];

        foreach (preg_split('/\R/', $section[1]) ?: [] as $line) {
            if (preg_match('/^((?:(?:english|spanish|french|brazilianportuguese)\.)?[A-Za-z][A-Za-z0-9]*)=(.*)$/', trim($line), $matches) === 1) {
                $messages[$matches[1]] = $matches[2];
            }
        }

        return $messages;
    }

    /**
     * Every message name the script actually displays.
     *
     * @return list<string>
     */
    private function usedMessages(): array
    {
        $used = [];

        // Provided by Inno's own .isl files, not by this script's [CustomMessages] block.
        $builtIn = ['CreateDesktopIcon', 'AdditionalIcons', 'LaunchProgram'];

        foreach (["/CustomMessage\\('([A-Za-z][A-Za-z0-9]*)'\\)/", '/\\{cm:([A-Za-z][A-Za-z0-9]*)/'] as $pattern) {
            if (preg_match_all($pattern, $this->script, $matches) > 0) {
                foreach ($matches[1] as $name) {
                    if (! in_array($name, $builtIn, true)) {
                        $used[$name] = true;
                    }
                }
            }
        }

        return array_keys($used);
    }
}
