<?php

namespace App\Support;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Locale as IntlLocale;
use Throwable;

/**
 * The reader's interface language, and the one place it is applied.
 *
 * ## Why the resolution order is explicit
 *
 * A reader may choose a language, a browser may ask for one, and an install may
 * have a default. Those are three different claims, and the first one a reader
 * made has to win. The order here is stored preference, session, browser header,
 * `APP_LOCALE`, then English.
 *
 * ## Why every request applies the locale
 *
 * FrankenPHP keeps a worker alive across requests, and both Carbon and
 * `Number` hold their locale in static state. A request that only set the
 * locale when it was not English would inherit the previous reader's language.
 * So the middleware calls {@see self::apply()} on every request, including
 * English.
 */
final class Locale
{
    /**
     * The full target list.
     *
     * Resolution, storage, and the switcher use {@see self::catalogued()}.
     *
     * @return list<string>
     */
    public static function supported(): array
    {
        return self::configured('supported');
    }

    /**
     * The codes that may be resolved, stored, or offered today.
     *
     * English is the source language, so it is available without a JSON file.
     *
     * @return list<string>
     */
    public static function catalogued(): array
    {
        return self::configured('catalogued');
    }

    /**
     * The configured fallback, but only when it has a catalog.
     *
     * A fallback is still a page the reader will see. Returning an
     * uncatalogued code would label English copy with another language, so an
     * uncatalogued value becomes English.
     */
    public static function fallback(): string
    {
        $fallback = config('papyrus.locales.fallback', config('app.fallback_locale', 'en'));
        $normalized = self::normalize(is_string($fallback) ? $fallback : null);

        return $normalized !== null && in_array($normalized, self::catalogued(), true)
            ? $normalized
            : 'en';
    }

    /**
     * Turn a browser or stored tag into one of the application's codes.
     *
     * `pt-BR` and `pt` both normalize to the Brazilian code. `es-419` and
     * every other Spanish tag mean the generic `es` code. A regional
     * Portuguese tag such as `pt-PT` is deliberately not folded into `pt_BR`;
     * that is a different language decision.
     */
    public static function normalize(?string $locale): ?string
    {
        if ($locale === null) {
            return null;
        }

        $tag = strtolower(trim(str_replace('-', '_', $locale)));

        if ($tag === '') {
            return null;
        }

        if ($tag === 'en' || str_starts_with($tag, 'en_')) {
            return 'en';
        }

        if ($tag === 'es' || str_starts_with($tag, 'es_')) {
            return 'es';
        }

        if ($tag === 'fr' || str_starts_with($tag, 'fr_')) {
            return 'fr';
        }

        if ($tag === 'pt' || $tag === 'pt_br') {
            return 'pt_BR';
        }

        return null;
    }

    /**
     * Resolve the locale for this request without changing any global state.
     *
     * The browser header is consulted only for catalogued locales, because a
     * page labelled in a language it cannot render is worse than the default.
     */
    public static function resolve(Request $request, ?User $user = null): string
    {
        $user ??= self::user();
        $catalogued = self::catalogued();

        $candidates = [
            $user instanceof User ? app(LocalePreference::class)->read($user) : null,
            $request->hasSession() ? $request->session()->get('locale') : null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = self::normalize(is_string($candidate) ? $candidate : null);

            if ($normalized !== null && in_array($normalized, $catalogued, true)) {
                return $normalized;
            }
        }

        if ((bool) config('papyrus.locales.negotiate', true)) {
            foreach ($request->getLanguages() as $preferred) {
                $normalized = self::normalize($preferred);

                if ($normalized !== null && in_array($normalized, $catalogued, true)) {
                    return $normalized;
                }
            }
        }

        $configured = self::normalize(is_string(config('app.locale')) ? config('app.locale') : null);

        if ($configured !== null && in_array($configured, $catalogued, true)) {
            return $configured;
        }

        return self::fallback();
    }

    /**
     * Put the locale into every formatter the reader will see.
     */
    public static function apply(string $locale): void
    {
        app()->setLocale($locale);

        /*
         * Carbon and Number are static, so both are set on every request rather
         * than only when the locale changes. See the class docblock.
         */
        Carbon::setLocale($locale);
        CarbonImmutable::setLocale($locale);
        Number::useLocale($locale);
    }

    /**
     * The BCP 47 spelling used by the `lang` attribute.
     */
    public static function htmlLang(string $locale): string
    {
        return str_replace('_', '-', $locale);
    }

    /**
     * The language's name in its own language, for the switcher.
     */
    public static function nativeName(string $locale): string
    {
        $normalized = self::normalize($locale) ?? $locale;

        return self::intlName($normalized, $normalized) ?? self::fallbackName($normalized);
    }

    /**
     * The language's name in another interface language.
     */
    public static function displayName(string $locale, ?string $in = null): string
    {
        $normalized = self::normalize($locale) ?? $locale;
        $inLocale = self::normalize($in ?? app()->getLocale()) ?? self::fallback();

        return self::intlName($normalized, $inLocale) ?? self::fallbackName($normalized);
    }

    /**
     * @return list<string>
     */
    private static function configured(string $key): array
    {
        $values = config('papyrus.locales.'.$key, []);

        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            $code = self::normalize(is_string($value) ? $value : null);

            if ($code !== null && ! in_array($code, $normalized, true)) {
                $normalized[] = $code;
            }
        }

        return $normalized;
    }

    private static function intlName(string $locale, string $in): ?string
    {
        if (! class_exists(IntlLocale::class)) {
            return null;
        }

        try {
            $name = IntlLocale::getDisplayName(self::htmlLang($locale), self::htmlLang($in));
        } catch (Throwable) {
            return null;
        }

        if (! is_string($name) || $name === $locale) {
            return null;
        }

        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    private static function fallbackName(string $locale): string
    {
        return [
            'en' => 'English',
            'es' => 'Español',
            'fr' => 'Français',
            'pt_BR' => 'Português (Brasil)',
        ][$locale] ?? $locale;
    }

    private static function user(): ?User
    {
        try {
            $user = Auth::user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }
}
