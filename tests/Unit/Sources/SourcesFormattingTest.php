<?php

namespace Tests\Unit\Sources;

use App\Live\Sources\SourcesFormatting;
use App\Models\Source;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The sources page's pure rules, exercised without mounting the page.
 *
 * Each case is a boundary the page used to carry inside the Livewire
 * component: the five view labels and their order, the two site notices, the
 * parsed grouping host, the site name recovered from a language variant, and
 * the catalogue's last page.
 */
class SourcesFormattingTest extends TestCase
{
    private SourcesFormatting $formatting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formatting = new SourcesFormatting;
    }

    public function test_the_view_labels_keep_the_four_slices_then_migrate(): void
    {
        $this->assertSame(
            [
                'installed' => 'Installed',
                'available' => 'Available',
                'updates' => 'Updates',
                'all' => 'All',
                'migrate' => 'Migrate',
            ],
            $this->formatting->views(),
        );
    }

    public function test_a_ban_notice_names_the_conduct_and_its_icon(): void
    {
        $this->assertSame(
            [
                'icon' => 'exclamation-triangle',
                'text' => 'This site is known to block readers that search aggressively. Space searches out or pin it; expect long bans, not errors you can retry away.',
            ],
            $this->formatting->noticeFor(['name' => 'Kayn Scans']),
        );
    }

    public function test_a_scramble_notice_names_the_conduct_and_its_icon(): void
    {
        $this->assertSame(
            [
                'icon' => 'arrow-path',
                'text' => 'Searches from extensions here come back scrambled and pages can arrive broken. It does not ban: it sends you back to its own ad-laden site.',
            ],
            $this->formatting->noticeFor(['name' => 'Comix (all)']),
        );
    }

    public function test_a_clean_site_has_no_notice(): void
    {
        $this->assertNull($this->formatting->noticeFor(['name' => 'Example Scan']));
        $this->assertNull($this->formatting->noticeFor([]));
    }

    public function test_the_grouping_host_is_parsed_and_lowercased(): void
    {
        $this->assertSame(
            'lhtranslation.net',
            $this->formatting->hostOf('https://lhtranslation.net/manga/?m_orderby=views'),
        );

        $this->assertSame('example.test', $this->formatting->hostOf('https://Example.TEST/path'));
        $this->assertSame('example.test', $this->formatting->hostOf('  https://example.test  '));
    }

    public function test_a_missing_or_unparseable_url_has_no_host(): void
    {
        $this->assertNull($this->formatting->hostOf(null));
        $this->assertNull($this->formatting->hostOf(''));
        $this->assertNull($this->formatting->hostOf(" \t "));
        $this->assertNull($this->formatting->hostOf('http://'));
        $this->assertNull($this->formatting->hostOf('not a url'));
    }

    public function test_a_site_name_drops_the_language_suffix(): void
    {
        $this->assertSame(
            'MangaDex',
            $this->formatting->siteName($this->sources([
                ['display_name' => 'MangaDex (EN)', 'lang' => 'en', 'home_url' => 'https://mangadex.org'],
            ]), 'mangadex.org'),
        );

        $this->assertSame(
            'MangaDex',
            $this->formatting->siteName($this->sources([
                ['display_name' => 'MangaDex (EN)', 'lang' => 'ja', 'home_url' => 'https://mangadex.org'],
            ]), 'mangadex.org'),
            'the fallback suffix rule strips a language code the row does not claim',
        );

        $this->assertSame(
            'Asura Scans',
            $this->formatting->siteName($this->sources([
                ['display_name' => 'Asura Scans', 'lang' => 'en', 'home_url' => 'https://asurascans.com'],
            ]), 'asurascans.com'),
        );
    }

    public function test_a_site_with_no_variant_name_falls_back_to_its_host(): void
    {
        $this->assertSame(
            'example.test',
            $this->formatting->siteName($this->sources([]), 'example.test'),
        );
    }

    public function test_the_last_page_rounds_up_with_a_minimum_of_one(): void
    {
        $this->assertSame(1, $this->formatting->lastPage(0, 50));
        $this->assertSame(1, $this->formatting->lastPage(1, 50));
        $this->assertSame(1, $this->formatting->lastPage(50, 50));
        $this->assertSame(2, $this->formatting->lastPage(51, 50));
        $this->assertSame(2, $this->formatting->lastPage(100, 50));
        $this->assertSame(3, $this->formatting->lastPage(101, 50));
        $this->assertSame(5, $this->formatting->lastPage(120, 25));
    }

    /**
     * @param  array<int, array{display_name: string, lang: string, home_url: string}>  $rows
     * @return Collection<int, Source>
     */
    private function sources(array $rows): Collection
    {
        return new Collection(array_map(
            static fn (array $row): Source => new Source($row),
            $rows,
        ));
    }
}
