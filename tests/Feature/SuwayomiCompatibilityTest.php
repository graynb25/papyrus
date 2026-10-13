<?php

namespace Tests\Feature;

use Papyrus\Launcher\SuwayomiCompatibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The version comparison, on its own.
 *
 * ## Why this is a separate file from HealthTest
 *
 * Because the interesting failures here are not about how a sentence is worded, they are about
 * whether `version_compare` is being handed something it can order. And the single most dangerous
 * property of that function, on this input, is that it returns a confident wrong answer.
 *
 * ## The trap this file exists for
 *
 * Suwayomi reports `v2.4.2366`. `version_compare('v2.4.2366', '2.4.0', '<')` is **true**: a leading
 * `v` makes the first component non-numeric, so PHP compares the strings rather than the numbers
 * and finds `v2...` less than `2...`. So a comparison written the obvious way declares the exact
 * server Papyrus ships to be too old, every single time, on every install.
 *
 * The obvious way is not hypothetical here. The same trap is already documented in
 * `App\Console\Commands\SuwayomiCheck`, where it was noticed and handled there. Two places fixing
 * the same trap is one place too many, which is the reason the rule now lives in this class and the
 * command will read it from here.
 */
class SuwayomiCompatibilityTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir().DIRECTORY_SEPARATOR.'papyrus-compat-'.getmypid().'-'.uniqid();

        mkdir($this->scratch, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->scratch.DIRECTORY_SEPARATOR.'*') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->scratch);
    }

    private function write(array $contents): string
    {
        $path = $this->scratch.DIRECTORY_SEPARATOR.'suwayomi.json';

        file_put_contents($path, json_encode($contents));

        return $path;
    }

    /**
     * The trap, demonstrated rather than described.
     *
     * If PHP ever changed this, `normalise()` would become unnecessary rather than wrong, and this
     * test would say so. Until then it is the reason the method exists.
     */
    public function test_the_leading_v_sorts_below_every_number_without_being_stripped(): void
    {
        $this->assertTrue(
            version_compare('v2.4.2366', '2.4.0', '<'),
            'if this ever becomes false, the note in SuwayomiCompatibility is stale and the stripping '
                .'is no longer load bearing, though it would still be correct to keep',
        );

        $this->assertFalse(
            version_compare(SuwayomiCompatibility::normalise('v2.4.2366'), '2.4.0', '<'),
            'the shipped version must not compare as older than its own floor',
        );
    }

    public function test_it_strips_a_leading_v_in_either_case(): void
    {
        $this->assertSame('2.4.2366', SuwayomiCompatibility::normalise('v2.4.2366'));
        $this->assertSame('2.4.2366', SuwayomiCompatibility::normalise('V2.4.2366'));
        $this->assertSame('2.4.2366', SuwayomiCompatibility::normalise('  v2.4.2366  '));
        $this->assertSame('2.4.2366', SuwayomiCompatibility::normalise('2.4.2366'));
    }

    /**
     * Anything unorderable is null, and null is not "old".
     *
     * The probe returns an empty string when Suwayomi answers but omits the field. Treating that as
     * an old version would fail a working server over a missing sentence.
     */
    public function test_something_it_cannot_order_is_null_rather_than_a_version(): void
    {
        foreach (['', '   ', 'unknown', '2.4.x', 'nightly', 'v', '2..4', null] as $reported) {
            $this->assertNull(
                SuwayomiCompatibility::normalise((string) $reported),
                'nothing should be invented from '.var_export($reported, true),
            );
        }
    }

    public function test_it_reads_both_numbers_and_reports_them_the_way_suwayomi_writes_them(): void
    {
        $compatibility = SuwayomiCompatibility::read($this->write([
            'tested' => '2.4.2366',
            'minimum' => '2.4.0',
        ]));

        $this->assertTrue($compatibility->isKnown());
        $this->assertSame('v2.4.2366', $compatibility->tested());
        $this->assertSame('v2.4.0', $compatibility->minimum());
    }

    /**
     * A file that cannot be understood produces no opinion.
     *
     * Not a default, and not a guess. The caller reports "could not check", which is the only honest
     * thing to say about a file that is absent, malformed, or missing half its answer.
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function unusableFiles(): array
    {
        return [
            'no minimum' => [['tested' => '2.4.2366'], 'minimum'],
            'no tested' => [['minimum' => '2.4.0'], 'tested'],
            'both empty' => [['tested' => '  ', 'minimum' => '  '], 'whitespace'],
            'a word rather than a version' => [['tested' => 'latest', 'minimum' => '2.4.0'], 'words'],
            'a leading v, which the build rejects' => [['tested' => 'v2.4.2366', 'minimum' => '2.4.0'], 'v'],
        ];
    }

    /**
     * @param  array<string, mixed>  $contents
     */
    #[DataProvider('unusableFiles')]
    public function test_a_file_it_cannot_use_is_reported_as_unknown(array $contents, string $why): void
    {
        $compatibility = SuwayomiCompatibility::read($this->write($contents));

        $this->assertFalse($compatibility->isKnown(), 'this build must claim no opinion, because of '.$why);

        // And it must not answer anything either, rather than answering from a default.
        $this->assertNull($compatibility->isTooOld('v2.3.2243'));
        $this->assertNull($compatibility->isTested('v2.4.2366'));
    }

    public function test_a_missing_file_is_reported_as_unknown(): void
    {
        $compatibility = SuwayomiCompatibility::read($this->scratch.DIRECTORY_SEPARATOR.'not-there.json');

        $this->assertFalse($compatibility->isKnown());
        $this->assertSame('', $compatibility->tested());
        $this->assertSame('', $compatibility->minimum());
    }

    public function test_a_file_that_is_not_json_is_reported_as_unknown(): void
    {
        $path = $this->scratch.DIRECTORY_SEPARATOR.'suwayomi.json';

        file_put_contents($path, 'tested: 2.4.2366');

        $this->assertFalse(SuwayomiCompatibility::read($path)->isKnown());
    }

    /**
     * The two verdicts, and the boundary between them.
     */
    public function test_it_separates_too_old_from_simply_untested(): void
    {
        $compatibility = SuwayomiCompatibility::read($this->write([
            'tested' => '2.4.2366',
            'minimum' => '2.4.0',
        ]));

        $this->assertTrue($compatibility->isTooOld('v2.3.2243'), 'below the floor');
        $this->assertTrue($compatibility->isTooOld('v2.0.0'), 'well below the floor');

        // The floor itself is workable. A version at the boundary has to pass, or raising the floor
        // by a patch would break an install that was working.
        $this->assertFalse($compatibility->isTooOld('v2.4.0'), 'the floor is the oldest that works');
        $this->assertFalse($compatibility->isTooOld('v2.4.1'));
        $this->assertFalse($compatibility->isTooOld('v2.4.2366'));
        $this->assertFalse($compatibility->isTooOld('v2.5.100'), 'a newer server is not too old');
        $this->assertFalse($compatibility->isTooOld('v3.0.0'));

        $this->assertTrue($compatibility->isTested('v2.4.2366'));
        $this->assertFalse($compatibility->isTested('v2.4.2367'), 'one build on is untested, not too old');
        $this->assertFalse($compatibility->isTested('v2.5.100'));
    }

    /**
     * An unreadable version is not evidence for either verdict.
     */
    public function test_an_unreadable_version_gives_neither_verdict(): void
    {
        $compatibility = SuwayomiCompatibility::read($this->write([
            'tested' => '2.4.2366',
            'minimum' => '2.4.0',
        ]));

        $this->assertNull($compatibility->isTooOld(''));
        $this->assertNull($compatibility->isTested(''));
        $this->assertNull($compatibility->isTooOld('unknown'));
    }

    /**
     * The file this project actually ships, read as a reader's install would read it.
     *
     * So that the two numbers cannot be edited into something the comparison cannot handle without
     * a test failing. The build validates them too, but the build is not run on every edit and this
     * is.
     */
    public function test_the_repository_file_is_one_this_class_can_use(): void
    {
        $path = dirname(__DIR__, 2).'/packaging/suwayomi.json';

        $this->assertFileExists($path, 'packaging/suwayomi.json is the build’s source of truth');

        $compatibility = SuwayomiCompatibility::read($path);

        $this->assertTrue($compatibility->isKnown(), 'the shipped file must be usable');
        $this->assertFalse(
            $compatibility->isTooOld($compatibility->tested()),
            'the tested version can never be older than the floor, so this file cannot be self contradicting',
        );
    }
}
