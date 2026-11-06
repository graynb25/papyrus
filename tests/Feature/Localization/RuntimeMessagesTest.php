<?php

namespace Tests\Feature\Localization;

use App\AniList\Preference;
use App\Live\RunPresenter;
use Tests\TestCase;

class RuntimeMessagesTest extends TestCase
{
    public function test_the_anilist_explanation_is_translated(): void
    {
        $preference = new Preference;

        app()->setLocale('es');
        $this->assertStringStartsWith('AniList es una base de datos pública', $preference->explanation());
        $this->assertStringContainsString('Activarlo envía los títulos', $preference->explanation());

        app()->setLocale('fr');
        $this->assertStringStartsWith('AniList est une base de données publique', $preference->explanation());
        $this->assertStringContainsString("L'activer leur envoie les titres", $preference->explanation());

        app()->setLocale('pt_BR');
        $this->assertStringStartsWith('O AniList é um banco de dados público', $preference->explanation());
        $this->assertStringContainsString('Ativar isto envia a eles os títulos', $preference->explanation());
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
