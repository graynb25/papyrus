<?php

namespace Tests\Unit\Support;

use App\Models\SeriesState;
use App\Models\User;
use App\Support\UserKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * One conversion, one answer.
 *
 * The tree had three spellings of this conversion and they disagreed about a
 * numeric string: the cast normalised it, the bare form left it a string, and
 * `->id` read a different property. The cases below hold the normalised answer,
 * the two ways a caller can hand over no usable id, and the scope the string
 * path actually reaches.
 */
class UserKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_model_resolves_to_its_key(): void
    {
        $user = User::factory()->create();

        $this->assertSame($user->id, UserKey::of($user));
    }

    public function test_an_integer_resolves_to_itself(): void
    {
        $this->assertSame(41, UserKey::of(41));
    }

    public function test_a_numeric_string_resolves_to_an_integer(): void
    {
        $this->assertSame(41, UserKey::of('41'));
    }

    /**
     * The plan's string-id claim, exercised through the scope that consumes the
     * helper rather than asserted only on the helper's return value.
     */
    public function test_a_numeric_string_resolves_the_same_rows_as_the_integer(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $series = SeriesState::factory()->create(['user_id' => $alice->id]);
        SeriesState::factory()->create(['user_id' => $bob->id]);

        $byInteger = SeriesState::forUser($alice->id)->pluck('id')->all();
        $byString = SeriesState::forUser(UserKey::of((string) $alice->id))->pluck('id')->all();

        $this->assertSame([$series->id], $byInteger);
        $this->assertSame($byInteger, $byString);
    }

    public function test_a_non_numeric_string_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A user key must be an integer or a numeric string.');

        UserKey::of('not-a-key');
    }

    public function test_an_empty_string_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A user key must be an integer or a numeric string.');

        UserKey::of('');
    }

    public function test_zero_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A user key must be positive.');

        UserKey::of(0);
    }

    public function test_a_negative_id_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A user key must be positive.');

        UserKey::of(-7);
    }

    public function test_an_unsaved_user_is_refused_by_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An unsaved user has no key to resolve.');

        UserKey::of(new User);
    }
}
