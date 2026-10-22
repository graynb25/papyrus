<?php

namespace Tests\Unit\Support;

use App\Models\User;
use App\Support\AdultContent;
use App\Support\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The reader half's own preferences, and the gate that rides on them.
 *
 * Two things are being held here. The first is the seam itself: the table has
 * been in the schema since the second migration and this is the second
 * preference to use it, so the reading and the writing are written once and
 * every reader of the table goes through the same door. The second is the
 * gate, whose whole claim is that a link is not a confirmation and that one
 * reader's choice is not another reader's.
 */
class UserPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private UserPreference $preferences;

    private AdultContent $adult;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->preferences = new UserPreference;
        $this->adult = new AdultContent($this->preferences);
        $this->user = User::factory()->create();
    }

    public function test_an_unset_preference_is_absent_rather_than_false(): void
    {
        $this->assertNull($this->preferences->read($this->user, 'nothing_here'));
    }

    public function test_a_preference_reads_back_as_it_was_written(): void
    {
        $this->preferences->write($this->user, 'a_switch', true);

        $this->assertTrue($this->preferences->read($this->user, 'a_switch'));

        $this->preferences->write($this->user, 'a_switch', false);

        $this->assertFalse($this->preferences->read($this->user, 'a_switch'));
    }

    public function test_a_preference_is_per_reader(): void
    {
        $other = User::factory()->create();

        $this->preferences->write($this->user, 'a_switch', true);

        $this->assertTrue($this->preferences->read($this->user, 'a_switch'));
        $this->assertNull($this->preferences->read($other, 'a_switch'));
    }

    public function test_two_preferences_do_not_share_a_row(): void
    {
        $this->preferences->write($this->user, 'a_switch', true);
        $this->preferences->write($this->user, 'another_switch', false);

        $this->assertTrue($this->preferences->read($this->user, 'a_switch'));
        $this->assertFalse($this->preferences->read($this->user, 'another_switch'));
    }

    public function test_writing_a_preference_twice_keeps_one_row(): void
    {
        $this->preferences->write($this->user, 'a_switch', true);
        $this->preferences->write($this->user, 'a_switch', false);

        $rows = DB::connection()
            ->table('user_settings')
            ->where('user_id', $this->user->id)
            ->where('key', 'a_switch')
            ->count();

        $this->assertSame(1, $rows);
    }

    public function test_the_gate_is_off_until_a_reader_turns_it_on(): void
    {
        $this->assertFalse($this->adult->enabled($this->user));
    }

    public function test_the_gate_is_per_reader(): void
    {
        $other = User::factory()->create();

        $this->adult->set($this->user, true);

        $this->assertTrue($this->adult->enabled($this->user));
        $this->assertFalse($this->adult->enabled($other), 'One reader opening the gate is not consent for another.');
    }

    public function test_the_gate_can_be_closed_again(): void
    {
        $this->adult->set($this->user, true);
        $this->adult->set($this->user, false);

        $this->assertFalse($this->adult->enabled($this->user));
    }

    public function test_the_statement_carries_the_age_ask(): void
    {
        $statement = $this->adult->statement();

        $this->assertStringContainsString('18 or older', $statement);
    }
}
