<?php

namespace App\Support;

/**
 * The human name for a source language code.
 *
 * Sources report `lang` as a BCP 47 tag (`en`, `pt-BR`, `es-419`, `zh-Hant`), and
 * the search page used to render those raw, so a reader choosing between languages
 * was choosing between `es` and `es-419` with nothing to tell them apart. The map
 * below is explicit rather than delegated to intl for the codes this project has
 * actually seen: Keiyoushi ships one extension per language, so the long tail is
 * real, and an unmapped code falls back to intl's display name before falling back
 * to the raw code itself.
 */
final class LanguageName
{
    /**
     * Codes this codebase has observed on installed sources, plus the local
     * source's pseudo-language. Keys match Suwayomi's casing (`zh-Hant`, not
     * `zh-hant`), and the lookup is case sensitive because the fallback covers
     * whatever casing this map misses.
     *
     * The first three are not languages at all: they are the codes Keiyoushi
     * and Suwayomi use for the three cases where a source publishes in every
     * language, in no particular language, or from a local folder. They were
     * read off the live catalogue on 2026-10-21, where 56 extensions carry
     * `all` and one carries `other`, and they are named here rather than left
     * to fall through to the raw code, because a row of language pills that
     * says "all" next to "English" reads as a label rather than as a language.
     */
    private const MAP = [
        'localsourcelang' => 'Local files',
        'all' => 'All languages',
        'other' => 'Other',

        'en' => 'English',
        'ja' => 'Japanese',
        'ko' => 'Korean',
        'zh' => 'Chinese',
        'zh-Hans' => 'Chinese (Simplified)',
        'zh-Hant' => 'Chinese (Traditional)',

        'pt' => 'Portuguese',
        'pt-BR' => 'Portuguese (Brazil)',
        'es' => 'Spanish',
        'es-419' => 'Spanish (Latin America)',
        'fr' => 'French',
        'it' => 'Italian',
        'de' => 'German',
        'nl' => 'Dutch',
        'ca' => 'Catalan',
        'eu' => 'Basque',
        'ga' => 'Irish',

        'ru' => 'Russian',
        'uk' => 'Ukrainian',
        'be' => 'Belarusian',
        'pl' => 'Polish',
        'cs' => 'Czech',
        'sk' => 'Slovak',
        'hu' => 'Hungarian',
        'ro' => 'Romanian',
        'bg' => 'Bulgarian',
        'el' => 'Greek',
        'sr' => 'Serbian',
        'hr' => 'Croatian',
        'sl' => 'Slovenian',

        'ar' => 'Arabic',
        'he' => 'Hebrew',
        'tr' => 'Turkish',
        'fa' => 'Persian',
        'ur' => 'Urdu',
        'hi' => 'Hindi',
        'bn' => 'Bengali',
        'ta' => 'Tamil',
        'te' => 'Telugu',
        'ml' => 'Malayalam',

        'th' => 'Thai',
        'vi' => 'Vietnamese',
        'id' => 'Indonesian',
        'ms' => 'Malay',
        'jv' => 'Javanese',
        'tl' => 'Filipino',
        'fil' => 'Filipino',
        'km' => 'Khmer',
        'my' => 'Burmese',

        'sv' => 'Swedish',
        'fi' => 'Finnish',
        'da' => 'Danish',
        'no' => 'Norwegian',
        'nb' => 'Norwegian (Bokmål)',
        'is' => 'Icelandic',
        'et' => 'Estonian',
        'lv' => 'Latvian',
        'lt' => 'Lithuanian',

        'af' => 'Afrikaans',
        'sq' => 'Albanian',
        'az' => 'Azerbaijani',
        'ka' => 'Georgian',
        'hy' => 'Armenian',
        'kk' => 'Kazakh',
        'mn' => 'Mongolian',
        'ne' => 'Nepali',
        'si' => 'Sinhala',
        'sw' => 'Swahili',
        'eo' => 'Esperanto',
        'cv' => 'Chuvash',
        'sh' => 'Serbo-Croatian',
    ];

    /**
     * The display name for a language code, mapped, then intl, then the code.
     */
    public static function name(string $code): string
    {
        $code = trim($code);

        if ($code === '') {
            return '';
        }

        if (array_key_exists($code, self::MAP)) {
            return self::MAP[$code];
        }

        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayName($code, 'en');

            /*
             * intl answers a well-formed tag it cannot name with something like
             * "qq (Unknown Region)", which is worse than the code it was asked
             * about. The raw code is what a reader can at least match against
             * the source list, so an unhelpful intl answer is not an answer.
             */
            if (is_string($name) && $name !== $code
                && stripos($name, 'unknown') === false) {
                return $name;
            }
        }

        return $code;
    }
}
