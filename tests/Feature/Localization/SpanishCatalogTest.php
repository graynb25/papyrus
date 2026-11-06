<?php

namespace Tests\Feature\Localization;

use Tests\TestCase;

class SpanishCatalogTest extends TestCase
{
    /**
     * The Phase 1 and Phase 2 surfaces whose sentence keys must be in lang/es.json.
     *
     * The framework groups in lang/es/ are checked by the application itself,
     * and the mail and Fortify sentences are exercised by their own tests.
     *
     * @return list<string>
     */
    private function files(): array
    {
        return [
            'resources/views/pages/auth/confirm-password.blade.php',
            'resources/views/pages/auth/forgot-password.blade.php',
            'resources/views/pages/auth/login.blade.php',
            'resources/views/pages/auth/register.blade.php',
            'resources/views/pages/auth/reset-password.blade.php',
            'resources/views/pages/auth/two-factor-challenge.blade.php',
            'resources/views/pages/auth/verify-email.blade.php',
            'resources/views/components/auth-footer.blade.php',
            'resources/views/components/auth-header.blade.php',
            'resources/views/components/auth-session-status.blade.php',
            'resources/views/components/passkey-verify.blade.php',
            'resources/views/components/password/margins.blade.php',
            'resources/views/components/confirm.blade.php',
            'resources/views/components/desktop-user-menu.blade.php',
            'resources/views/components/page/header.blade.php',
            'resources/views/components/rating/stars.blade.php',
            'resources/views/components/tags/editor.blade.php',
            'resources/views/flux/toast/index.blade.php',
            'resources/views/layouts/app/sidebar.blade.php',
            'resources/views/layouts/auth/content.blade.php',
            'resources/views/layouts/auth/reading-room.blade.php',
            'resources/views/layouts/auth/simple.blade.php',
            'resources/views/pages/⚡library.blade.php',
            'resources/views/pages/⚡series.blade.php',
            'resources/views/reader/show.blade.php',
            'resources/views/reader/error.blade.php',
            'resources/views/errors/404.blade.php',
            'resources/views/errors/419.blade.php',
            'resources/views/errors/500.blade.php',
            'resources/views/errors/partials/ink.blade.php',
            'resources/views/pages/⚡search.blade.php',
            'resources/views/pages/⚡browse.blade.php',
            'resources/views/pages/⚡downloads.blade.php',
            'resources/views/pages/⚡insights.blade.php',
            'resources/views/pages/⚡sources.blade.php',
            'resources/views/pages/⚡health.blade.php',
            'resources/views/pages/partials/search-skipped.blade.php',
            'resources/views/components/adult-content/gate.blade.php',
            'resources/views/pages/settings/layout.blade.php',
            'resources/views/pages/settings/⚡about.blade.php',
            'resources/views/pages/settings/⚡appearance.blade.php',
            'resources/views/pages/settings/⚡backups.blade.php',
            'resources/views/pages/settings/⚡connections.blade.php',
            'resources/views/pages/settings/⚡delete-user-form.blade.php',
            'resources/views/pages/settings/⚡delete-user-modal.blade.php',
            'resources/views/pages/settings/⚡import.blade.php',
            'resources/views/pages/settings/⚡licences.blade.php',
            'resources/views/pages/settings/⚡profile.blade.php',
            'resources/views/pages/settings/⚡security.blade.php',
            'resources/views/pages/settings/⚡two-factor-setup-modal.blade.php',
            'resources/views/pages/settings/two-factor/⚡recovery-codes.blade.php',
            'resources/views/partials/settings-heading.blade.php',
            'resources/views/components/passkey-registration.blade.php',
            'resources/views/components/stage-list.blade.php',
            'resources/views/livewire/onboarding/first-run-tour.blade.php',
            'resources/views/errors/403.blade.php',
            'resources/views/errors/429.blade.php',
            'resources/views/errors/503.blade.php',
            'app/Backup/BackupSummary.php',
            'app/Backup/RunOutcome.php',
            'app/Live/Backups/BackupFormatting.php',
            'app/Onboarding/TourSteps.php',
            'app/Live/Library/LibraryPageData.php',
            'app/Live/Series/SeriesCoverRules.php',
            'app/Live/Series/SeriesPageData.php',
            'app/Live/Sources/SourcesFormatting.php',
            'app/Live/Sources/SourcesPageData.php',
            'app/Search/HealthDashboard.php',
            'app/Support/Duration.php',
            'app/Support/ReadingState.php',
            'app/Support/ReaderSettings.php',
            'app/Support/Library.php',
            'app/Http/Controllers/ReaderController.php',
        ];
    }

    /**
     * A literal key is not renamed, only translated.
     *
     * Keys that are the same in both languages are legitimate loanwords or
     * technical names that must not be rewritten.
     *
     * @return list<string>
     */
    private function identical(): array
    {
        return [
            'Scanlators',
            'Zoom',
            'NSFW',
            'ComicInfo.xml',
            'protobuf',
            ':count series',
            'Papyrus',
            'Suwayomi',
            'JavaScript',
            'PHP',
            'series',
        ];
    }

    public function test_every_in_scope_sentence_key_has_a_spanish_value(): void
    {
        $catalog = json_decode(
            (string) file_get_contents(base_path('lang/es.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertIsArray($catalog);

        $pattern = "/(?:__|trans_choice)\(\s*'((?:\\\\.|[^'\\\\])*)'/s";

        foreach ($this->files() as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path);

            preg_match_all($pattern, (string) file_get_contents($path), $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[1] as [$raw, $offset]) {
                $key = str_replace(["\\'", '\\\\'], ["'", '\\'], $raw);

                $this->assertArrayHasKey(
                    $key,
                    $catalog,
                    "{$relative}:".$this->lineAt((string) file_get_contents($path), $offset).' has no Spanish value',
                );

                $value = $catalog[$key];
                $this->assertIsString($value);
                $this->assertNotSame('', trim($value));

                $this->assertSame(
                    $this->placeholders($key),
                    $this->placeholders($value),
                    "{$relative}: placeholders differ for: {$key}",
                );

                $this->assertSame(
                    substr_count($key, '|'),
                    substr_count($value, '|'),
                    "{$relative}: plural branches differ for: {$key}",
                );

                if (! in_array($key, $this->identical(), true)) {
                    $this->assertNotSame($key, $value, "{$relative}: still English: {$key}");
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $line): array
    {
        preg_match_all('/:[A-Za-z_][A-Za-z0-9_]*/', $line, $matches);

        $placeholders = $matches[0];
        sort($placeholders);

        return $placeholders;
    }

    private function lineAt(string $text, int $offset): int
    {
        return substr_count(substr($text, 0, $offset), "\n") + 1;
    }
}
