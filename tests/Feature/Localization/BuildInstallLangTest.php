<?php

namespace Tests\Feature\Localization;

use Tests\TestCase;

class BuildInstallLangTest extends TestCase
{
    public function test_the_build_copies_lang_and_checks_it_before_booting(): void
    {
        $source = (string) file_get_contents(base_path('packaging/runtime/build-install.php'));

        $this->assertMatchesRegularExpression('/const APP_ENTRIES = \[(.*?)\];/s', $source);
        preg_match('/const APP_ENTRIES = \[(.*?)\];/s', $source, $entries);
        $this->assertStringContainsString("'lang'", $entries[1], 'lang must be copied with the application entries');

        $this->assertMatchesRegularExpression('/const LARVEL_BOOT_PATHS = \[(.*?)\];/s', $source);
        preg_match('/const LARVEL_BOOT_PATHS = \[(.*?)\];/s', $source, $bootPaths);
        $this->assertStringContainsString("'lang'", $bootPaths[1], 'lang must be checked as a framework boot path');

        $this->assertMatchesRegularExpression('/[$]ours = \[(.*?)\];/s', $source);
        preg_match('/[$]ours = \[(.*?)\];/s', $source, $ours);
        $this->assertStringContainsString("'lang'", $ours[1], 'lang must be classified as project-authored');

        $this->assertStringContainsString('foreach (APP_ENTRIES as $entry)', $source);
        $this->assertStringContainsString('copyTree($source, $into.DIRECTORY_SEPARATOR.$entry', $source);
        $this->assertStringContainsString('foreach (LARVEL_BOOT_PATHS as $relative)', $source);
        $this->assertStringContainsString('Add them to APP_ENTRIES', $source);

        $this->assertStringNotContainsString('absent from this repository', $source);
        $this->assertStringNotContainsString('`lang/` is the expected case', $source);
    }
}
