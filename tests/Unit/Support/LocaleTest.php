<?php

namespace Tests\Unit\Support;

use App\Support\Locale;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    public function test_it_normalizes_the_supported_tags(): void
    {
        $this->assertSame('pt_BR', Locale::normalize('pt-BR'));
        $this->assertSame('pt_BR', Locale::normalize('pt_BR'));
        $this->assertSame('pt_BR', Locale::normalize('PT-br'));
        $this->assertSame('pt_BR', Locale::normalize('pt'));
        $this->assertSame('es', Locale::normalize('es-419'));
        $this->assertSame('es', Locale::normalize('es_ES'));
        $this->assertSame('fr', Locale::normalize('fr-CA'));
        $this->assertSame('en', Locale::normalize('en-GB'));
    }

    public function test_it_refuses_a_language_it_does_not_support(): void
    {
        $this->assertNull(Locale::normalize('de'));
        $this->assertNull(Locale::normalize('pt-PT'));
        $this->assertNull(Locale::normalize(''));
        $this->assertNull(Locale::normalize(null));
    }

    public function test_the_supported_list_keeps_the_target_codes(): void
    {
        $this->assertSame(['en', 'es', 'fr', 'pt_BR'], Locale::supported());
        $this->assertSame(['en', 'es', 'fr', 'pt_BR'], Locale::catalogued());
        $this->assertSame('en', Locale::fallback());
    }

    public function test_the_html_language_tag_uses_a_hyphen(): void
    {
        $this->assertSame('pt-BR', Locale::htmlLang('pt_BR'));
        $this->assertSame('es', Locale::htmlLang('es'));
    }

    public function test_a_catalogued_fallback_is_honoured_and_an_uncatalogued_one_becomes_english(): void
    {
        config(['papyrus.locales.fallback' => 'es']);
        $this->assertSame('es', Locale::fallback());

        config(['papyrus.locales.fallback' => 'fr']);
        $this->assertSame('fr', Locale::fallback());

        config(['papyrus.locales.fallback' => 'pt_BR']);
        $this->assertSame('pt_BR', Locale::fallback());

        config(['papyrus.locales.fallback' => 'it']);
        $this->assertSame('en', Locale::fallback());
    }

    public function test_it_has_native_and_display_names(): void
    {
        $this->assertStringContainsString('Español', Locale::nativeName('es'));
        $this->assertStringContainsString('Français', Locale::nativeName('fr'));
        $this->assertStringContainsString('Português', Locale::nativeName('pt_BR'));
        $this->assertStringContainsString('Spanish', Locale::displayName('es', 'en'));
        $this->assertStringContainsString('Français', Locale::displayName('fr', 'fr'));
        $this->assertStringContainsString('Português', Locale::displayName('pt_BR', 'pt_BR'));
    }

    public function test_the_spanish_catalog_is_valid_and_carries_the_foundation_keys(): void
    {
        $path = base_path('lang/es.json');
        $this->assertFileExists($path);

        $catalog = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($catalog);
        $this->assertSame('Idioma', $catalog['Language'] ?? null);
        $this->assertSame('Cambiar idioma', $catalog['Change language'] ?? null);
        $this->assertArrayHasKey('Page expired', $catalog);
    }
}
