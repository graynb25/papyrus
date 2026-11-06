<?php

namespace Tests\Unit\Support;

use App\Support\LanguageName;
use Tests\TestCase;

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

    public function test_spanish_names_come_from_intl(): void
    {
        app()->setLocale('es');

        $this->assertSame('portugués (Brasil)', LanguageName::name('pt-BR'));
        $this->assertSame('español (Latinoamérica)', LanguageName::name('es-419'));
        $this->assertSame('chino (tradicional)', LanguageName::name('zh-Hant'));
        $this->assertSame('inglés', LanguageName::name('en'));
        $this->assertSame('cantonés', LanguageName::name('yue'));
    }

    public function test_spanish_pseudo_languages_are_translated(): void
    {
        app()->setLocale('es');

        $this->assertSame('Archivos locales', LanguageName::name('localsourcelang'));
        $this->assertSame('Todos los idiomas', LanguageName::name('all'));
        $this->assertSame('Otro', LanguageName::name('other'));
    }

    public function test_an_unknown_code_falls_back_to_itself_in_spanish(): void
    {
        app()->setLocale('es');

        $this->assertSame('qq-ZZ', LanguageName::name('qq-ZZ'));
    }

    public function test_french_and_portuguese_names_come_from_intl(): void
    {
        app()->setLocale('fr');

        $this->assertSame('portugais (Brésil)', LanguageName::name('pt-BR'));
        $this->assertSame('espagnol (Amérique latine)', LanguageName::name('es-419'));
        $this->assertSame('chinois (traditionnel)', LanguageName::name('zh-Hant'));
        $this->assertSame('anglais', LanguageName::name('en'));
        $this->assertSame('qq-ZZ', LanguageName::name('qq-ZZ'));

        app()->setLocale('pt_BR');

        $this->assertSame('português (Brasil)', LanguageName::name('pt-BR'));
        $this->assertSame('espanhol (América Latina)', LanguageName::name('es-419'));
        $this->assertSame('chinês (tradicional)', LanguageName::name('zh-Hant'));
        $this->assertSame('inglês', LanguageName::name('en'));
        $this->assertSame('qq-ZZ', LanguageName::name('qq-ZZ'));
    }

    public function test_french_and_portuguese_pseudo_languages_are_translated(): void
    {
        app()->setLocale('fr');

        $this->assertSame('Fichiers locaux', LanguageName::name('localsourcelang'));
        $this->assertSame('Toutes les langues', LanguageName::name('all'));
        $this->assertSame('Autre', LanguageName::name('other'));

        app()->setLocale('pt_BR');

        $this->assertSame('Arquivos locais', LanguageName::name('localsourcelang'));
        $this->assertSame('Todos os idiomas', LanguageName::name('all'));
        $this->assertSame('Outro', LanguageName::name('other'));
    }

    public function test_the_locale_can_be_given_explicitly(): void
    {
        $this->assertSame('portugués (Brasil)', LanguageName::name('pt-BR', 'es'));
        $this->assertSame('Portuguese (Brazil)', LanguageName::name('pt-BR', 'en'));
        $this->assertSame('portugais (Brésil)', LanguageName::name('pt-BR', 'fr'));
        $this->assertSame('português (Brasil)', LanguageName::name('pt-BR', 'pt_BR'));
        $this->assertSame('Local files', LanguageName::name('localsourcelang', 'en'));
    }
}
