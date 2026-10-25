<?php

namespace Tests\Unit;

use App\Support\UploadLimit;
use Tests\TestCase;

/**
 * The one number that decides whether an upload reaches the app.
 *
 * The measured defect this replaces: the packaged ini allowed 64M while Livewire refused
 * anything over 12MB, so a real 41MB backup was stopped by a limit nobody had written on
 * the page. The page and the endpoint now ask this class, so the parsing has to be right
 * before either can be.
 */
class UploadLimitTest extends TestCase
{
    public function test_the_smaller_of_the_two_ini_values_wins(): void
    {
        $this->assertSame(2 * 1024 ** 2, UploadLimit::fromIni('8M', '2M'));
        $this->assertSame(2 * 1024 ** 2, UploadLimit::fromIni('2M', '8M'));
    }

    public function test_the_shipped_sizes_leave_room_for_the_measured_backup(): void
    {
        $this->assertSame(256 * 1024 ** 2, UploadLimit::fromIni('260M', '256M'));
    }

    public function test_a_zero_means_no_limit_and_drops_out_of_the_comparison(): void
    {
        $this->assertSame(2 * 1024 ** 2, UploadLimit::fromIni('0', '2M'));
        $this->assertSame(2 * 1024 ** 2, UploadLimit::fromIni('2M', '0'));
        $this->assertSame(0, UploadLimit::fromIni('0', '0'));
    }

    public function test_an_empty_or_unreadable_value_means_no_limit(): void
    {
        $this->assertSame(0, UploadLimit::fromIni('', ''));
        $this->assertSame(8 * 1024 ** 2, UploadLimit::fromIni('nonsense', '8M'));
    }

    public function test_every_suffix_php_accepts_is_read(): void
    {
        $this->assertSame(512 * 1024, UploadLimit::fromIni('512K', '1M'));
        $this->assertSame(1024 ** 3, UploadLimit::fromIni('1G', '2G'));
        $this->assertSame(1536, UploadLimit::fromIni('1536', '2M'), 'a bare byte count is legal in ini');
    }

    public function test_the_suffix_is_case_insensitive(): void
    {
        $this->assertSame(2 * 1024 ** 2, UploadLimit::fromIni('8m', '2m'));
    }
}
