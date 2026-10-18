<?php

namespace Tests\Feature;

use App\Models\LicenceAcknowledgement;
use App\Models\Scopes\OwnedByUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The acknowledgement ledger behind the licences page.
 *
 * ## Why this is a table and not session state
 *
 * Because what the reader has read survives neither the browser nor the session, and the
 * rearm event it exists to detect is a package version moving across a build. The row is
 * written with the identity that was acknowledged: a reader who read MIT 1.2.3 yesterday is
 * not acknowledged for 1.3.0 today, and the page saying otherwise is the exact lie a licence
 * page must not tell.
 */
class LicenceAcknowledgementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reader_acknowledges_a_package_by_its_current_identity(): void
    {
        $reader = User::factory()->create();

        LicenceAcknowledgement::markFor($reader, 'composer:stub/licence', '1.2.3', 'MIT');

        $this->assertSame(
            'acknowledged',
            LicenceAcknowledgement::stateFor($reader, 'composer:stub/licence', '1.2.3', 'MIT'),
            'the row and the package carry the same identity, so the read covered exactly this',
        );
    }

    /**
     * Two readers are two ledgers. Nothing about one reader's rows is readable by another,
     * which is the same rule every per reader table in this application runs under.
     */
    public function test_acknowledgements_belong_to_one_reader_and_nobody_else(): void
    {
        $who = User::factory()->create();
        $other = User::factory()->create();

        LicenceAcknowledgement::markFor($who, 'composer:stub/licence', '1.2.3', 'MIT');

        $this->assertSame(
            'none',
            LicenceAcknowledgement::stateFor($other, 'composer:stub/licence', '1.2.3', 'MIT'),
        );
    }

    public function test_marking_twice_updates_the_identity_instead_of_piling_rows(): void
    {
        $reader = User::factory()->create();

        LicenceAcknowledgement::markFor($reader, 'composer:stub/licence', '1.0.0', 'MIT');
        LicenceAcknowledgement::markFor($reader, 'composer:stub/licence', '1.1.0', 'MIT');

        $rows = LicenceAcknowledgement::withoutGlobalScope(OwnedByUser::class)
            ->where('user_id', $reader->id)
            ->get();

        $this->assertCount(1, $rows, 're-acknowledging a package has one row, not a history');
        $this->assertSame('1.1.0', $rows->first()->version);
    }

    /**
     * The rearm rule, in both the directions it can fire.
     *
     * A row the reader made with yesterday's version or licence does not count for today's,
     * and the page has to show the reader exactly that rather than an older row's quiet pill
     * standing for a package its row does not describe.
     */
    public function test_a_moved_version_or_licence_rereads_the_acknowledgement(): void
    {
        $reader = User::factory()->create();

        LicenceAcknowledgement::markFor($reader, 'composer:stub/licence', '1.2.3', 'MIT');

        $this->assertSame('acknowledged', LicenceAcknowledgement::stateFor($reader, 'composer:stub/licence', '1.2.3', 'MIT'));
        $this->assertSame(
            'rearmed',
            LicenceAcknowledgement::stateFor($reader, 'composer:stub/licence', '1.3.0', 'MIT'),
            'a version the reader did not read cannot borrow the old pill',
        );
        $this->assertSame(
            'rearmed',
            LicenceAcknowledgement::stateFor($reader, 'composer:stub/licence', '1.2.3', 'Apache-2.0'),
            'neither can a licence the row does not name',
        );
        $this->assertSame(
            'none',
            LicenceAcknowledgement::stateFor($reader, 'npm:node_modules/stub/other', '1.2.3', 'MIT'),
            'and a package the reader never acknowledged has no row to stand on',
        );
    }
}
