<?php

namespace App\Live\Series;

/**
 * The cover upload rules the series page validates against.
 *
 * The byte ceiling is the config's product limit, not this server's
 * `upload_max_filesize`: a packaged install takes a 256M backup, and a cover is
 * not a backup. The number is printed in the message so a reader knows which
 * rule stopped them.
 *
 * The methods are static because the rules are a constant of the page: no
 * reader state and no collaborator goes into deciding whether a file is an
 * image of an accepted type under the configured size.
 */
final class SeriesCoverRules
{
    /**
     * The Livewire property, the format gate and the product size ceiling.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'coverUpload' => [
                'required',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:'.(int) config('papyrus.covers.max_kilobytes'),
            ],
        ];
    }

    /**
     * The two refusals a reader can act on.
     *
     * Both pass through `__()` so a localised install can translate them; the
     * page tests replace these exact sentences and assert the replacement.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'coverUpload.max' => __('A cover has to be under :max KB. Resize it and try again.'),
            'coverUpload.mimes' => __('A cover has to be a JPEG, PNG or WebP.'),
        ];
    }
}
