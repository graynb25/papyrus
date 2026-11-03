<?php

namespace Tests\Unit\Sources;

use App\Live\Sources\SourcesFormatting;
use App\Live\Sources\SourcesRules;
use App\Models\ExtensionStore;
use App\Suwayomi\ExtensionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The sources page's per-row and selection rules, exercised without mounting.
 *
 * Trust, install and update each re-read the row and the reader's trusted
 * stores, because the rendered list is not evidence. The selection rules take
 * the pressed names so their order and their exclusions are direct to test.
 */
class SourcesRulesTest extends TestCase
{
    use RefreshDatabase;

    private SourcesRules $rules;

    private ExtensionStore $trusted;

    private ExtensionStore $untrusted;

    private array $trustedIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = new SourcesRules(app(ExtensionManager::class), new SourcesFormatting);

        $this->trusted = ExtensionStore::create([
            'index_url' => 'https://example.test/index.pb',
            'name' => 'Example Store',
            'signing_key' => 'abc123',
        ]);

        $this->untrusted = ExtensionStore::create([
            'index_url' => 'https://untrusted.test/index.pb',
            'name' => 'Untrusted Store',
            'signing_key' => 'def456',
        ]);

        $this->trustedIds = [$this->trusted->id];
    }

    public function test_trust_follows_the_store_the_extension_came_from(): void
    {
        $this->assertTrue($this->rules->isTrusted($this->extension('pkg.trusted'), $this->trustedIds));
        $this->assertFalse($this->rules->isTrusted($this->extension('pkg.untrusted', storeUrl: 'https://untrusted.test/index.pb'), $this->trustedIds));
    }

    public function test_an_unknown_or_missing_store_is_untrusted(): void
    {
        $this->assertFalse($this->rules->isTrusted($this->extension('pkg.other', storeUrl: 'https://other.test/index.pb'), $this->trustedIds));
        $this->assertFalse($this->rules->isTrusted(['pkgName' => 'pkg.none'], $this->trustedIds));
        $this->assertFalse($this->rules->isTrusted($this->extension('pkg.trusted'), []));
    }

    public function test_install_is_allowed_only_for_an_uninstalled_trusted_extension(): void
    {
        $extensions = $this->extensions();

        $this->assertTrue($this->rules->canInstallName('pkg.available', $extensions, $this->trustedIds));
        $this->assertFalse($this->rules->canInstallName('pkg.installed', $extensions, $this->trustedIds));
        $this->assertFalse($this->rules->canInstallName('pkg.untrusted', $extensions, $this->trustedIds));
        $this->assertFalse($this->rules->canInstallName('pkg.missing', $extensions, $this->trustedIds));
    }

    public function test_update_is_allowed_only_for_an_installed_update_from_a_trusted_store(): void
    {
        $extensions = $this->extensions();

        $this->assertTrue($this->rules->canUpdateName('pkg.updatable', $extensions, $this->trustedIds));
        $this->assertFalse($this->rules->canUpdateName('pkg.installed', $extensions, $this->trustedIds));
        $this->assertFalse($this->rules->canUpdateName('pkg.available', $extensions, $this->trustedIds));
        $this->assertFalse($this->rules->canUpdateName('pkg.untrusted-update', $extensions, $this->trustedIds));
        $this->assertFalse($this->rules->canUpdateName('pkg.missing', $extensions, $this->trustedIds));
    }

    public function test_the_row_read_returns_the_row_or_null(): void
    {
        $extensions = $this->extensions();

        $this->assertSame('Available', $this->rules->rowFor('pkg.available', $extensions)['name'] ?? null);
        $this->assertNull($this->rules->rowFor('pkg.missing', $extensions));
    }

    public function test_the_selection_keeps_the_pressed_order_and_drops_untrusted_or_installed_names(): void
    {
        $extensions = $this->extensions();

        $this->assertSame(
            ['pkg.available', 'pkg.other-available'],
            $this->rules->installableFromSelection(
                ['pkg.untrusted', 'pkg.available', 'pkg.installed', 'pkg.other-available', 'pkg.missing'],
                $extensions,
                $this->trustedIds,
            ),
        );

        $this->assertSame(
            ['pkg.other-available', 'pkg.available'],
            $this->rules->installableFromSelection(
                ['pkg.other-available', 'pkg.available'],
                $extensions,
                $this->trustedIds,
            ),
            'the reader pressed in this order, so the install follows it',
        );

        $this->assertSame([], $this->rules->installableFromSelection(['pkg.installed'], $extensions, $this->trustedIds));
    }

    public function test_flagged_among_keeps_only_the_names_the_notice_list_covers(): void
    {
        $extensions = $this->extensions();

        $this->assertSame(
            ['pkg.kayn', 'pkg.comix'],
            $this->rules->flaggedAmong(
                ['pkg.clean', 'pkg.kayn', 'pkg.missing', 'pkg.comix'],
                $extensions,
            ),
        );
    }

    public function test_warning_rows_leave_out_a_pending_name_whose_notice_has_gone(): void
    {
        $extensions = $this->extensions();

        $rows = $this->rules->warningRows(['pkg.kayn', 'pkg.missing', 'pkg.clean'], $extensions);

        $this->assertSame(
            [[
                'name' => 'Kayn Scans',
                'icon' => 'exclamation-triangle',
                'text' => 'This site is known to block readers that search aggressively. Space searches out or pin it; expect long bans, not errors you can retry away.',
            ]],
            $rows,
        );

        $clean = new Collection([$this->extension('pkg.kayn', name: 'Kayn Scans', cleanName: true)]);

        $this->assertSame([], $this->rules->warningRows(['pkg.kayn'], $clean));
        $this->assertSame([], $this->rules->flaggedAmong(['pkg.kayn'], $clean));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function extensions(): Collection
    {
        return new Collection([
            $this->extension('pkg.available', 'Available', storeUrl: 'https://example.test/index.pb'),
            $this->extension('pkg.installed', 'Installed', installed: true, storeUrl: 'https://example.test/index.pb'),
            $this->extension('pkg.updatable', 'Updatable', installed: true, hasUpdate: true, storeUrl: 'https://example.test/index.pb'),
            $this->extension('pkg.untrusted', 'Untrusted', storeUrl: 'https://untrusted.test/index.pb'),
            $this->extension('pkg.untrusted-update', 'Untrusted Update', installed: true, hasUpdate: true, storeUrl: 'https://untrusted.test/index.pb'),
            $this->extension('pkg.other-available', 'Other Available', storeUrl: 'https://example.test/index.pb'),
            $this->extension('pkg.kayn', 'Kayn Scans', storeUrl: 'https://example.test/index.pb'),
            $this->extension('pkg.comix', 'Comix (all)', storeUrl: 'https://example.test/index.pb'),
            $this->extension('pkg.clean', 'Clean Scan', storeUrl: 'https://example.test/index.pb'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extension(
        string $pkgName,
        string $name = 'Example',
        bool $installed = false,
        bool $hasUpdate = false,
        string $storeUrl = 'https://example.test/index.pb',
        bool $cleanName = false,
    ): array {
        return [
            'pkgName' => $pkgName,
            'name' => $cleanName ? 'Clean Scan' : $name,
            'isInstalled' => $installed,
            'hasUpdate' => $hasUpdate,
            'storeIndexUrl' => $storeUrl,
        ];
    }
}
