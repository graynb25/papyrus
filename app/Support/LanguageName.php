<?php

namespace App\Support;

/**
 * The human name for a source language code.
 *
 * Sources report `lang` as a BCP 47 tag (`en`, `pt-BR`, `es-419`, `zh-Hant`), and
 * the search page used to render those raw, so a reader choosing between languages
 * was choosing between `es` and `es-419` with nothing to tell them apart. The map
 * below is the English spelling for the codes this project has actually seen.
 * Another interface language asks intl for its own spelling first, then falls back
 * through this map and the raw code, so a catalogued language reads naturally and
 * an unknown tag still shows something a reader can match against the source list.
 */
final class LanguageName
{
    /**
     * Codes this codebase has observed on installed sources, plus the local
     * source's pseudo-language. Keys match Suwayomi's casing (`zh-Hant`, not
     * `zh-hant`), and the lookup is case sensitive because the fallback covers
     * whatever casing this map misses.
     *
     * The first three are not languages at all: they are the codes extension stores
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
     * The ISO country flag that represents a language, when one is available.
     */
    public static function flagRegion(string $code): ?string
    {
        $code = trim($code);

        $regions = [
            'en' => 'gb', 'ja' => 'jp', 'ko' => 'kr', 'zh' => 'cn',
            'zh-Hans' => 'cn', 'zh-Hant' => 'tw', 'pt' => 'pt', 'pt-BR' => 'br',
            'es' => 'es', 'es-419' => 'mx', 'fr' => 'fr', 'it' => 'it',
            'de' => 'de', 'nl' => 'nl', 'ca' => 'es', 'eu' => 'es', 'ga' => 'ie',
            'ru' => 'ru', 'uk' => 'ua', 'be' => 'by', 'pl' => 'pl', 'cs' => 'cz',
            'sk' => 'sk', 'hu' => 'hu', 'ro' => 'ro', 'bg' => 'bg', 'el' => 'gr',
            'sr' => 'rs', 'hr' => 'hr', 'sl' => 'si', 'ar' => 'sa', 'he' => 'il',
            'tr' => 'tr', 'fa' => 'ir', 'ur' => 'pk', 'hi' => 'in', 'bn' => 'bd',
            'ta' => 'in', 'te' => 'in', 'ml' => 'in', 'th' => 'th', 'vi' => 'vn',
            'id' => 'id', 'ms' => 'my', 'jv' => 'id', 'tl' => 'ph', 'fil' => 'ph',
            'km' => 'kh', 'my' => 'mm', 'sv' => 'se', 'fi' => 'fi', 'da' => 'dk',
            'no' => 'no', 'nb' => 'no', 'is' => 'is', 'et' => 'ee', 'lv' => 'lv',
            'lt' => 'lt', 'af' => 'za', 'sq' => 'al', 'az' => 'az', 'ka' => 'ge',
            'hy' => 'am', 'kk' => 'kz', 'mn' => 'mn', 'ne' => 'np', 'si' => 'lk',
            'sw' => 'tz', 'cv' => 'ru', 'sh' => 'rs',
        ];

        if (isset($regions[$code])) {
            return $regions[$code];
        }

        return null;
    }

    /**
     * The codes that are labels rather than language tags.
     *
     * Extension stores and Suwayomi use these three for a source that publishes
     * in every language, in no particular language, or from a local folder. They
     * are translated as UI copy in {@see self::name()} rather than passed to
     * intl, which does not know them.
     *
     * @var list<string>
     */
    private const PSEUDO = ['localsourcelang', 'all', 'other'];

    /**
     * The display name for a language code, mapped, then intl, then the code.
     *
     * A named language is asked of intl in the reader's language, so the same
     * catalogue reads as "Portuguese (Brazil)" in English and "portugués
     * (Brasil)" in Spanish. The three pseudo-languages are translated instead,
     * because they are labels and intl has no name for them. An unknown code
     * falls back through the English map and intl before the raw code, which is
     * the value a reader can still match against the source list.
     */
    public static function name(string $code, ?string $locale = null): string
    {
        $code = trim($code);

        if ($code === '') {
            return '';
        }

        $locale = $locale ?? self::currentLocale();

        if (in_array($code, self::PSEUDO, true)) {
            return (string) trans(self::MAP[$code], [], $locale);
        }

        if ($locale !== 'en') {
            $name = self::intlName($code, $locale);

            if ($name !== null) {
                return $name;
            }
        }

        if (array_key_exists($code, self::MAP)) {
            return self::MAP[$code];
        }

        return self::intlName($code, 'en') ?? $code;
    }

    /**
     * The locale the current request is being rendered in.
     *
     * `name()` is also called from unit contexts without an application, so the
     * fallback is explicit rather than an uncaught container error.
     */
    private static function currentLocale(): string
    {
        if (function_exists('app') && app()->bound('translator')) {
            return app()->getLocale();
        }

        return 'en';
    }

    /**
     * An intl display name that is worth showing, or null.
     *
     * intl answers a well-formed tag it cannot name with something like
     * "qq (Región desconocida)", which is worse than the code it was asked
     * about. When the display language is still the tag's primary subtag, the
     * name carries no information, so it is rejected. The English "unknown"
     * check stays because the English fallback is what the previous behaviour
     * promised and what an unmapped code must keep.
     */
    private static function intlName(string $code, string $locale): ?string
    {
        if (! class_exists(\Locale::class)) {
            return null;
        }

        $name = \Locale::getDisplayName($code, $locale);
        $language = \Locale::getDisplayLanguage($code, $locale);
        $primary = explode('-', str_replace('_', '-', $code))[0];

        if (! is_string($name) || ! is_string($language)
            || $name === $code
            || strtolower($language) === strtolower($primary)
            || ($locale === 'en' && stripos($name, 'unknown') !== false)) {
            return null;
        }

        return $name;
    }
}
