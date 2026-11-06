<?php

namespace Tests\Unit\Support;

use App\Models\User;
use App\Support\LocalePreference;
use App\Support\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class LocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private LocalePreference $locales;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locales = new LocalePreference(new UserPreference);
        $this->user = User::factory()->create();
    }

    public function test_an_unset_locale_is_absent(): void
    {
        $this->assertNull($this->locales->read($this->user));
        $this->assertNull($this->user->preferredLocale());
    }

    public function test_a_locale_reads_back_as_it_was_written(): void
    {
        $this->locales->write($this->user, 'es');

        $this->assertSame('es', $this->locales->read($this->user));
        $this->assertSame('es', $this->user->preferredLocale());
    }

    public function test_a_regional_spanish_tag_is_folded_to_the_catalogued_spanish_catalog(): void
    {
        $this->locales->write($this->user, 'es-419');

        $this->assertSame('es', $this->locales->read($this->user));
    }

    public function test_french_and_brazilian_portuguese_are_accepted(): void
    {
        $this->locales->write($this->user, 'fr');
        $this->assertSame('fr', $this->locales->read($this->user));

        $this->locales->write($this->user, 'pt-BR');
        $this->assertSame('pt_BR', $this->locales->read($this->user));
    }

    public function test_an_unsupported_locale_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->locales->write($this->user, 'de');
    }

    public function test_an_uncatalogued_locale_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->locales->write($this->user, 'it');
    }

    public function test_an_uncatalogued_stored_value_reads_as_absent(): void
    {
        app(UserPreference::class)->writeString($this->user, LocalePreference::KEY, 'it');

        $this->assertNull($this->locales->read($this->user));
        $this->assertNull($this->user->preferredLocale());
    }

    public function test_a_locale_is_per_reader(): void
    {
        $other = User::factory()->create();

        $this->locales->write($this->user, 'es');

        $this->assertSame('es', $this->locales->read($this->user));
        $this->assertNull($this->locales->read($other));
    }

    public function test_writing_twice_keeps_one_row(): void
    {
        $this->locales->write($this->user, 'es');
        $this->locales->write($this->user, 'en');

        $this->assertSame('en', $this->locales->read($this->user));
        $this->assertSame(1, DB::table('user_settings')
            ->where('user_id', $this->user->id)
            ->where('key', LocalePreference::KEY)
            ->count());
    }
}
