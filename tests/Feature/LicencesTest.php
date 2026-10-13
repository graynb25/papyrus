<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Licences;
use App\Support\LicensedPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
