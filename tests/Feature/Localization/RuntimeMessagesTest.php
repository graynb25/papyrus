<?php

namespace Tests\Feature\Localization;

use App\AniList\Preference;
use App\Live\RunPresenter;
use App\Search\FilterVocabulary;
use Tests\TestCase;

class RuntimeMessagesTest extends TestCase
{
    public function test_the_anilist_explanation_is_translated(): void
    {
        $preference = new Preference;

        app()->setLocale('es');
        $this->assertStringStartsWith('AniList es una base de datos pública', $preference->explanation());
        $this->assertStringContainsString('Activarlo envía a AniList los títulos', $preference->explanation());

        app()->setLocale('fr');
        $this->assertStringStartsWith('AniList est une base de données publique', $preference->explanation());
        $this->assertStringContainsString("L'activer envoie à AniList les titres", $preference->explanation());

        app()->setLocale('pt_BR');
        $this->assertStringStartsWith('O AniList é um banco de dados público', $preference->explanation());
        $this->assertStringContainsString('Ativar isto envia ao AniList os títulos', $preference->explanation());
    }

    public function test_the_search_filter_hint_is_translated(): void
    {
        $vocabulary = app(FilterVocabulary::class);

        app()->setLocale('es');
        $this->assertStringContainsString('Las fuentes que no admiten', $vocabulary->termsHint());
        $this->assertStringContainsString('Género, Estado, Orden', $vocabulary->termsHint());

        app()->setLocale('fr');
        $this->assertStringContainsString('Les sources qui ne peuvent pas honorer', $vocabulary->termsHint());
        $this->assertStringContainsString('Genre, Statut, Tri', $vocabulary->termsHint());

        app()->setLocale('pt_BR');
        $this->assertStringContainsString('As fontes que não conseguem aplicar', $vocabulary->termsHint());
        $this->assertStringContainsString('Gênero, Status, Ordenação', $vocabulary->termsHint());
    }

    public function test_comic_info_field_labels_are_catalogued(): void
    {
        $labels = [
            'Series', 'Number', 'Volume', 'Year', 'Publisher',
            'Writer', 'Penciller', 'Inker', 'Summary', 'Title', 'Genre',
        ];

        foreach (['es', 'fr', 'pt_BR'] as $locale) {
            $catalog = json_decode(
                (string) file_get_contents(base_path("lang/{$locale}.json")),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            foreach ($labels as $label) {
                $this->assertArrayHasKey($label, $catalog, "{$locale} is missing the {$label} label");
            }
        }
    }

    public function test_the_restore_confirmation_names_the_visible_cancel_button(): void
    {
        $key = 'Nothing changes until you confirm. Choose Never mind to leave your library as it is.';

        foreach (['es', 'fr', 'pt_BR'] as $locale) {
            $this->assertStringContainsString(
                trans('Never mind', [], $locale),
                trans($key, [], $locale),
                "{$locale} names a cancel button that is not on screen",
            );
        }
    }

    public function test_legacy_backup_summaries_are_translated(): void
    {
        $presenter = new RunPresenter;
        $summary = '12 series states and 34 chapter states against Suwayomi v1.2.3, taken without stopping anything';

        app()->setLocale('es');
        $this->assertSame(
            'Copia completa: 12 series y 34 capítulos guardados mientras Papyrus seguía abierto. Servidor de manga v1.2.3.',
            $presenter->humanizeBackupSummary($summary),
        );

        app()->setLocale('fr');
        $this->assertSame(
            'Sauvegarde terminée : 12 séries et 34 chapitres enregistrés pendant que Papyrus restait ouvert. Serveur de manga v1.2.3.',
            $presenter->humanizeBackupSummary($summary),
        );

        app()->setLocale('pt_BR');
        $this->assertSame(
            'Backup concluído: 12 séries e 34 capítulos salvos enquanto o Papyrus continuava aberto. Servidor de mangá v1.2.3.',
            $presenter->humanizeBackupSummary($summary),
        );
    }
}
