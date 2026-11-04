<?php

namespace Tests\Unit\Search;

use App\Search\TitleNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * These strings are the actual decoration seen across sources. If normalisation
 * regresses, cross-source search silently degrades into "the same series five
 * times", which is exactly what Mihon does today.
 */
class TitleNormalizerTest extends TestCase
{
    private TitleNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new TitleNormalizer;
    }

    /**
     * @return array<int, array{0: string, 1: string}>
     */
    public static function variants(): array
    {
        return [
            ['One Piece', 'ONE PIECE'],
            ['One Piece', 'One Piece (1999)'],
            ['One Piece', '[Viz] One Piece [EN]'],
            ['One Piece', 'One Piece -raw'],
            ['Jujutsu Kaisen', 'Jujutsu-kaisen'],
            ['Jujutsu Kaisen', 'JUJUTSU KAISEN'],
            ['Shōnen no Kaguya', 'Shonen no Kaguya'],
            ['Berserk', 'BERSERK'],
            ['Kimetsu no Yaiba', 'Kimetsu no Yaiba (2020)'],
        ];
    }

    #[DataProvider('variants')]
    public function test_it_collapses_the_same_series(string $a, string $b): void
    {
        $this->assertSame(
            $this->normalizer->normalise($a),
            $this->normalizer->normalise($b),
            "\"{$a}\" and \"{$b}\" should normalise identically",
        );
    }

    public function test_it_keeps_genuinely_different_series_apart(): void
    {
        $this->assertNotSame(
            $this->normalizer->normalise('One Piece'),
            $this->normalizer->normalise('One Piece G'),
        );

        $this->assertNotSame(
            $this->normalizer->normalise('Berserk'),
            $this->normalizer->normalise('Akira'),
        );
    }

    public function test_it_handles_an_empty_title(): void
    {
        $this->assertSame('', $this->normalizer->normalise(''));
        $this->assertSame('', $this->normalizer->normalise('   '));
    }

    public function test_exact_matches_short_circuit_to_one(): void
    {
        $this->assertSame(1.0, $this->normalizer->similarity('onepiece', 'onepiece'));
    }

    public function test_similarity_is_zero_for_an_empty_input(): void
    {
        $this->assertSame(0.0, $this->normalizer->similarity('', 'onepiece'));
    }

    /**
     * A short title differing slightly must not match everything short,
     * or "xxx" would absorb unrelated series in the fuzzy pass.
     */
    public function test_a_large_length_gap_scores_zero(): void
    {
        $this->assertSame(0.0, $this->normalizer->similarity('abc', 'abcdefghijklmnop'));
    }

    public function test_it_reports_near_titles_as_similar(): void
    {
        $similarity = $this->normalizer->similarity(
            $this->normalizer->normalise('Jujutsu Kaisen'),
            $this->normalizer->normalise('Jujutsu Kaisen 0'),
        );

        $this->assertGreaterThan(0.8, $similarity);
    }

    public function test_threshold_decides_series_identity(): void
    {
        $this->assertTrue($this->normalizer->isSameSeries('ONE PIECE', '[Viz] One Piece', 0.95));
        $this->assertFalse($this->normalizer->isSameSeries('One Piece', 'Attack on Titan', 0.95));
    }

    /**
     * A KNOWN GAP, pinned so it cannot be forgotten.
     *
     * mb_convert_kana(..., 'as') folds full-width alphanumerics and spaces, not
     * kana. So a Japanese-script title and its English translation do not
     * collide.
     *
     * This is not a corner case: the live store catalogue has 252 `ja` sources
     * against 790 `en`, so the same series routinely appears in both scripts and
     * cross-language dedupe is a major case rather than an edge case. Closing it
     * needs kana-to-romaji transliteration, which is a dependency and a product
     * decision, not a tweak. If one is adopted, this test is the thing to
     * invert.
     */
    public function test_kana_titles_are_not_yet_transliterated(): void
    {
        $japanese = $this->normalizer->normalise('ｵﾝﾋﾟｰｽ');
        $english = $this->normalizer->normalise('One Piece');

        $this->assertSame('オンヒース', $japanese, 'Documented gap: no kana transliteration yet.');
        $this->assertNotSame($japanese, $english);
    }
}
