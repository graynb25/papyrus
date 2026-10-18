<?php

namespace Tests\Feature;

use App\Models\LicenceAcknowledgement;
use App\Models\User;
use App\Support\Licences;
use App\Support\LicensedPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The licences page, and whether it can be trusted.
 *
 * ## Why a generated page needs tests at all
 *
 * Because the failure this guards against is not a wrong number, it is a *missing* one. A licence page
 * that quietly omits a package is worse than no licence page, because it is evidence of compliance
 * and is not evidence of anything. So the tests here are mostly about coverage rather than about
 * correctness of any single row.
 *
 * ## Why the two large packaged pieces are asserted by name
 *
 * Because they are 57% of the install and neither lockfile mentions them. A generator that read only
 * `composer.lock` would produce a perfectly accurate page that omitted the single largest piece of
 * third party code in the product, and nothing about the output would look wrong.
 */
class LicencesTest extends TestCase
{
    use RefreshDatabase;

    private function licences(): Licences
    {
        return app(Licences::class);
    }

    public function test_it_accounts_for_everything_the_install_is_made_of(): void
    {
        $all = $this->licences()->all();

        $this->assertGreaterThan(100, count($all), 'a PHP application with this many dependencies should list more than a hundred');
        $this->assertSame(count($all), $this->licences()->totalCount());
    }

    /**
     * The two big pieces are named explicitly, because nothing else would notice them going missing.
     */
    public function test_it_names_the_bundled_pieces_no_lockfile_knows_about(): void
    {
        $names = array_map(static fn ($p): string => $p->name, $this->licences()->bundled());

        $this->assertContains('Suwayomi Server', $names, 'the manga server is most of the install and is in no lockfile');
        $this->assertContains('Eclipse Temurin JRE', $names, 'the Java runtime is most of the install and is in no lockfile');
    }

    public function test_the_bundled_suwayomi_version_comes_from_the_file_the_build_verifies(): void
    {
        $suwayomi = $this->licences()->bundled()[0];

        $expected = json_decode(
            (string) file_get_contents(base_path('packaging/suwayomi.json')),
            true,
        )['tested'];

        $this->assertSame(
            $expected,
            $suwayomi->version,
            'the licence page must name the same release the build asserts a digest against, or it is '
                .'describing a different jar than the one that ships',
        );
    }

    /**
     * The one that matters most, and the reason this page exists in the form it does.
     *
     * `livewire/flux` reports `proprietary`, and it is the component library every page in the
     * application is built from. A reader is entitled to know that before deciding to keep using it.
     */
    public function test_a_proprietary_dependency_is_flagged_rather_than_buried(): void
    {
        $this->licences();

        $this->assertGreaterThanOrEqual(
            1,
            $this->licences()->proprietaryCount(),
            'the component library this app is built from reports a proprietary licence and that has '
                .'to be counted and shown, not left inside a table of a hundred and forty rows',
        );

        $flagged = array_values(array_filter(
            $this->licences()->all(),
            static fn ($p): bool => $p->isProprietary,
        ));

        $this->assertContains(
            'livewire/flux',
            array_map(static fn ($p): string => $p->name, $flagged),
            'the one dependency whose licence is not open source should be findable by name',
        );
    }

    /**
     * Everything else is open, and a regression here would mean the page started calling ordinary
     * MIT code proprietary, which would be its own kind of misleading.
     */
    public function test_the_permissive_packages_are_not_flagged(): void
    {
        $wrongly = [];

        foreach ($this->licences()->all() as $package) {
            $licence = strtolower($package->licence);

            if (! $package->isProprietary && ! str_contains($licence, 'proprietary')) {
                continue;
            }

            if ($package->isProprietary && ! str_contains($licence, 'proprietary') && ! str_contains($licence, 'not recorded')) {
                $wrongly[] = $package->name.' ('.$package->licence.')';
            }
        }

        $this->assertSame([], $wrongly, 'these are open licences being reported as closed');
    }

    /**
     * An absent licence is a gap in the record, not a statement about the package.
     *
     * The first version of this folded "not recorded" into "not open source", on the reasoning that
     * silence is not permission. It reported twenty three packages as closed when exactly one is: the
     * other twenty two are platform specific prebuilt binaries for darwin, freebsd and arm that ship
     * nothing on a Windows install. Calling those closed is a false accusation, and a page that
     * overstates its own restrictions is not evidence of compliance either.
     *
     * So there are three states, and the two counts are kept apart.
     */
    public function test_an_unrecorded_licence_is_not_accused_of_being_closed(): void
    {
        $open = LicensedPackage::make('a', '1', 'MIT', 'test');
        $closed = LicensedPackage::make('b', '1', 'Proprietary', 'test');
        $unknown = LicensedPackage::make('c', '1', 'not recorded in the lock file', 'test');

        $this->assertFalse($open->isProprietary);
        $this->assertFalse($open->isUnrecorded());

        $this->assertTrue($closed->isProprietary);
        $this->assertFalse($closed->isUnrecorded());

        $this->assertFalse($unknown->isProprietary, 'an absent licence is not an accusation');
        $this->assertTrue($unknown->isUnrecorded());
    }

    /**
     * And the two counts are reported apart, so a change to one cannot quietly inflate the other.
     */
    public function test_the_two_counts_are_reported_apart(): void
    {
        $l = $this->licences();

        $this->assertSame(1, $l->proprietaryCount(), 'exactly one package names itself proprietary');
        $this->assertGreaterThan(0, $l->unrecordedCount(), 'some npm entries record no licence');

        $both = array_filter(
            $l->all(),
            static fn ($p): bool => $p->isProprietary && $p->isUnrecorded(),
        );

        $this->assertSame([], $both, 'a package cannot be both closed and unrecorded');
    }

    public function test_the_summary_adds_up_to_the_total(): void
    {
        $sum = array_sum($this->licences()->summary());

        $this->assertSame(
            $this->licences()->totalCount(),
            $sum,
            'the summary is what a reader reads first, so it has to account for exactly what is listed',
        );
    }

    public function test_papyrus_states_its_own_licence_from_the_file(): void
    {
        $this->assertNotSame('not stated', $this->licences()->papyrus());
        $this->assertFileExists(base_path('LICENSE'));
    }

    // The page itself ----------------------------------------------------

    public function test_the_page_renders_and_names_the_licences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('licences.edit'))
            ->assertOk()
            ->assertSee('Suwayomi Server')
            ->assertSee('livewire/flux');
    }

    public function test_the_page_names_how_many_packages_are_not_open_source(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('licences.edit'))
            ->assertOk()
            ->assertSee((string) $this->licences()->proprietaryCount());
    }

    public function test_the_page_is_behind_auth(): void
    {
        $this->get(route('licences.edit'))->assertRedirect(route('login'));
    }

    // Phase 5: what an install without lock files reads, the text behind each row, and the ledger.

    /**
     * The shipped tree carries `vendor/composer/installed.json` and no composer.lock, so the
     * first version of this page answered empty there: two bundled rows and nothing else,
     * while the disk beside it held the whole record. The root is a seam, so the same page
     * reads what the install actually has.
     */
    public function test_an_install_without_lock_files_is_read_from_what_the_install_carries(): void
    {
        $root = $this->scratchPath('licences-install');
        mkdir($root.'/vendor/composer', 0777, true);

        file_put_contents(
            $root.'/vendor/composer/installed.json',
            (string) json_encode([
                'dev' => true,
                'packages' => [
                    ['name' => 'stub/php-two', 'version' => '2.0.0', 'license' => 'Proprietary'],
                    ['name' => 'stub/php-one', 'version' => '1.2.3', 'license' => ['MIT', 'LGPL-2.1']],
                ],
            ]),
        );

        $licences = new Licences($root);
        $rows = $licences->composerPackages();

        $this->assertSame(
            [
                ['stub/php-one', '1.2.3', 'MIT or LGPL-2.1'],
                ['stub/php-two', '2.0.0', 'Proprietary'],
            ],
            array_map(static fn (LicensedPackage $p): array => [$p->name, $p->version, $p->licence], $rows),
            'an install has no composer.lock, so its list comes from the file composer wrote beside the vendor tree',
        );

        foreach ($rows as $row) {
            $this->assertSame('PHP, shipped in this install', $row->source, 'the label names the file the list was read from');
        }

        $this->assertSame(1, $licences->proprietaryCount());
    }

    public function test_a_composer_row_reveals_its_text_byte_read_from_the_file_beside_it(): void
    {
        $row = array_values(array_filter(
            $this->licences()->composerPackages(),
            static fn (LicensedPackage $p): bool => $p->name === 'symfony/yaml',
        ))[0] ?? null;

        $this->assertNotNull($row, 'symfony/yaml is in the lock the checkout reads');

        $this->assertSame(
            (string) file_get_contents(base_path('vendor/symfony/yaml/LICENSE')),
            $this->licences()->textFor($row),
            'the text is the file itself, byte for byte, or the page is a paraphrase',
        );
    }

    public function test_flux_says_nothing_about_text_because_it_ships_no_licence_file(): void
    {
        $row = array_values(array_filter(
            $this->licences()->all(),
            static fn (LicensedPackage $p): bool => $p->isProprietary,
        ))[0] ?? null;

        $this->assertNotNull($row);
        $this->assertSame('livewire/flux', $row->name);
        $this->assertNull($this->licences()->textFor($row), 'no file beside the package means no text, not an invented one');
    }

    public function test_the_bundled_texts_are_read_from_the_files_this_repository_tracks_for_exactly_this(): void
    {
        [$suwayomi, $jre] = $this->licences()->bundled();

        $this->assertSame(
            (string) file_get_contents(base_path('packaging/licences/suwayomi-server.txt')),
            $this->licences()->textFor($suwayomi),
        );

        $this->assertSame(
            (string) file_get_contents(base_path('packaging/licences/java-runtime.txt')),
            $this->licences()->textFor($jre),
        );
    }

    /**
     * On an install the npm rows come from what the build staged, not from a lockfile that
     * does not travel. A row with a staged file reveals its text; a row the publishers
     * declared but the registry shipped without a file says that much and no more.
     */
    public function test_on_an_install_the_npm_rows_are_read_from_the_staged_manifest(): void
    {
        $root = $this->scratchPath('licences-manifest');
        mkdir($root.'/licences/node/node_modules/stub/one', 0777, true);

        file_put_contents($root.'/licences/node/node_modules/stub/one/LICENSE', "STUB TEXT\n");
        file_put_contents($root.'/licences/node/manifest.json', (string) json_encode([
            'packages' => [
                [
                    'name' => 'stub/one',
                    'path' => 'node_modules/stub/one',
                    'version' => '1.0.0',
                    'licence' => 'MIT',
                    'upstream' => 'https://www.example.com/stub/one',
                    'file' => 'node/node_modules/stub/one/LICENSE',
                ],
                [
                    'name' => 'stub/closed',
                    'path' => 'node_modules/stub/closed',
                    'version' => '1.1.0',
                    'licence' => 'not recorded in the lock file',
                    'upstream' => 'https://www.npmjs.com/package/stub/closed',
                    'file' => null,
                ],
            ],
        ]));

        $licences = new Licences($root);
        $rows = $licences->npmPackages();

        $this->assertCount(2, $rows);

        [$gap, $text] = $rows;

        $this->assertSame(['stub/one', '1.0.0', 'MIT'], [$text->name, $text->version, $text->licence]);
        $this->assertSame('JavaScript, shipped in this install', $text->source);
        $this->assertSame("STUB TEXT\n", $licences->textFor($text));
        $this->assertSame('https://www.example.com/stub/one', $text->upstream);

        $this->assertSame(['stub/closed', '1.1.0', 'not recorded in the lock file'], [$gap->name, $gap->version, $gap->licence]);
        $this->assertNull($licences->textFor($gap), 'no staged file means no text, whatever the row names');
    }

    // The page wiring: a reveal that reads the shipped file, and an acknowledgement the reader marks.

    public function test_a_row_reveals_the_text_it_promised(): void
    {
        $this->actingAs(User::factory()->create());

        $page = Livewire::test('pages::settings.licences')
            ->assertSee('Read the licence');

        $page->call('reveal', 'composer:symfony/yaml');

        $this->assertStringContainsString(
            'Copyright (c) 2004-present Fabien Potencier',
            $page->html(),
            'the reveal shows the first line of the file the install ships',
        );
    }

    public function test_a_row_with_no_text_behind_it_carries_no_reveal(): void
    {
        $this->actingAs(User::factory()->create());

        $html = Livewire::test('pages::settings.licences')->html();

        $fluxAt = strpos($html, 'livewire/flux');
        $rowEnd = $fluxAt === false ? false : strpos($html, '</li>', $fluxAt);
        $fluxRow = $fluxAt !== false && $rowEnd !== false ? substr($html, $fluxAt, $rowEnd - $fluxAt) : '';

        $this->assertNotSame('', $fluxRow, 'flux had a row on the page to begin with');

        $this->assertStringContainsString('not open source', $fluxRow);
        $this->assertStringNotContainsString('Read the licence', $fluxRow, 'flux ships no licence file so the row offers no text');
    }

    public function test_a_reader_can_acknowledge_a_package_and_the_page_says_so(): void
    {
        $who = User::factory()->create();

        $row = array_values(array_filter(
            $this->licences()->composerPackages(),
            static fn (LicensedPackage $p): bool => $p->name === 'symfony/yaml',
        ))[0] ?? null;

        $this->assertNotNull($row);

        $this->actingAs($who);

        $page = Livewire::test('pages::settings.licences')->call('acknowledge', 'composer:symfony/yaml');

        $this->assertStringContainsString('Acknowledged', $page->html());

        $this->assertDatabaseHas('licence_acknowledgements', [
            'user_id' => $who->id,
            'package' => 'composer:symfony/yaml',
            'version' => $row->version,
            'licence' => $row->licence,
        ]);
    }

    public function test_an_acknowledgement_whose_package_has_moved_is_rearmed_again(): void
    {
        $who = User::factory()->create();

        LicenceAcknowledgement::markFor($who, 'composer:symfony/yaml', '0.0.0', 'MIT');

        $this->actingAs($who);

        $page = Livewire::test('pages::settings.licences');

        $this->assertStringContainsString('changed since', $page->html(), 'the row the reader marked does not stand for the package it describes');

        $page->call('acknowledge', 'composer:symfony/yaml');

        $current = array_values(array_filter(
            $this->licences()->composerPackages(),
            static fn (LicensedPackage $p): bool => $p->name === 'symfony/yaml',
        ))[0];

        $this->assertDatabaseHas('licence_acknowledgements', [
            'user_id' => $who->id,
            'package' => 'composer:symfony/yaml',
            'version' => $current->version,
            'licence' => $current->licence,
        ]);
        $this->assertStringContainsString('Acknowledged', $page->html());
    }

    public function test_a_ledger_is_nobody_elses_rows(): void
    {
        $who = User::factory()->create();
        $other = User::factory()->create();

        LicenceAcknowledgement::markFor($who, 'composer:symfony/yaml', '99.99.99', 'MIT');

        $html = $this->actingAs($other)->get(route('licences.edit'))->getContent();

        // Both pills are refused, deliberately: the stale row belongs to the reader who made
        // it, so it cannot even report itself as moved on someone else's screen.
        $this->assertStringNotContainsString('Acknowledged', (string) $html, 'one reader mark is not readable by another');
        $this->assertStringNotContainsString('changed since', (string) $html, 'neither is a row that only reports its own staleness');
    }
}
