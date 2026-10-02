<?php

namespace Tests\Unit\Support;

use App\Support\LanguageName;
use PHPUnit\Framework\TestCase;

class LanguageNameTest extends TestCase
{
    public function test_region_qualified_codes_get_the_region_name(): void
    {
        $this->assertSame('Portuguese (Brazil)', LanguageName::name('pt-BR'));
        $this->assertSame('Spanish (Latin America)', LanguageName::name('es-419'));
        $this->assertSame('Chinese (Traditional)', LanguageName::name('zh-Hant'));
    }

    public function test_the_common_codes_get_common_names(): void
    {
        $this->assertSame('English', LanguageName::name('en'));
        $this->assertSame('Japanese', LanguageName::name('ja'));
    }

    public function test_the_local_source_s_pseudo_language_is_named(): void
    {
        // Measured: Suwayomi's local source reports lang 'localsourcelang',
        // which is not a BCP 47 tag and is never going to be in intl.
        $this->assertSame('Local files', LanguageName::name('localsourcelang'));
    }

    public function test_an_unknown_code_falls_back_to_itself(): void
    {
        $this->assertSame('qq-ZZ', LanguageName::name('qq-ZZ'));
    }

    public function test_an_empty_code_names_nothing(): void
    {
        $this->assertSame('', LanguageName::name(''));
        $this->assertSame('', LanguageName::name('  '));
    }
}
