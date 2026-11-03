<?php

namespace Tests\Unit\Series;

use App\Live\Series\SeriesCoverRules;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\Support\UsesTranslations;
use Tests\TestCase;

/**
 * The cover upload rules, exercised without mounting the series page.
 *
 * The outcomes and both refusal sentences are the contract: an accepted image
 * stores, a GIF is refused by format, an oversized file is refused by the
 * product ceiling, and each refusal names the rule that stopped the reader.
 */
class SeriesCoverRulesTest extends TestCase
{
    use UsesTranslations;

    protected function setUp(): void
    {
        parent::setUp();

        config(['papyrus.covers.max_kilobytes' => 2048]);
    }

    private function validator(UploadedFile $file): \Illuminate\Validation\Validator
    {
        return Validator::make(
            ['coverUpload' => $file],
            SeriesCoverRules::rules(),
            SeriesCoverRules::messages(),
        );
    }

    public function test_a_jpeg_within_the_ceiling_is_accepted(): void
    {
        $validator = $this->validator(UploadedFile::fake()->image('cover.jpg', 400, 600));

        $this->assertTrue($validator->passes(), (string) $validator->errors()->first('coverUpload'));
    }

    public function test_a_gif_is_refused_by_format_with_the_pages_sentence(): void
    {
        $validator = $this->validator(UploadedFile::fake()->image('a.gif', 10, 10));

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'A cover has to be a JPEG, PNG or WebP.',
            $validator->errors()->first('coverUpload'),
        );
    }

    public function test_a_file_over_the_product_ceiling_is_refused_with_the_limit(): void
    {
        $validator = $this->validator(
            UploadedFile::fake()->image('huge.jpg', 400, 600)->size(4096),
        );

        $this->assertTrue($validator->fails());
        $this->assertSame(
            'A cover has to be under 2048 KB. Resize it and try again.',
            $validator->errors()->first('coverUpload'),
        );
    }

    public function test_both_refusal_sentences_are_translation_keys(): void
    {
        $this->useTranslations([
            'A cover has to be a JPEG, PNG or WebP.' => 'That cover is not a JPEG, PNG or WebP.',
            'A cover has to be under :max KB. Resize it and try again.' => 'That cover is over :max KB. Resize it and try again.',
        ]);

        $wrongType = $this->validator(UploadedFile::fake()->image('a.gif', 10, 10));
        $tooBig = $this->validator(UploadedFile::fake()->image('huge.jpg', 400, 600)->size(4096));

        $this->assertSame(
            'That cover is not a JPEG, PNG or WebP.',
            $wrongType->errors()->first('coverUpload'),
        );
        $this->assertSame(
            'That cover is over 2048 KB. Resize it and try again.',
            $tooBig->errors()->first('coverUpload'),
        );
    }
}
