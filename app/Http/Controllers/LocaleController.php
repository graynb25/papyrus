<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Locale;
use App\Support\LocalePreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Record a language choice and send the reader back to the page they were on.
 *
 * A POST rather than a link because it changes the session and, for a signed-in
 * reader, a stored preference. The redirect is a full page load so the
 * server-rendered `html lang` changes with the copy.
 */
final class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'max:16'],
            'return' => ['nullable', 'string', 'max:2048'],
        ]);

        $requested = $data['locale'] ?? null;
        $locale = Locale::normalize(is_string($requested) ? $requested : null);

        if ($locale === null || ! in_array($locale, Locale::catalogued(), true)) {
            throw ValidationException::withMessages([
                'locale' => __('The selected language is not available.'),
            ]);
        }

        $request->session()->put('locale', $locale);

        $user = $request->user();

        if ($user instanceof User) {
            app(LocalePreference::class)->write($user, $locale);
        }

        Locale::apply($locale);

        $return = $data['return'] ?? null;

        return redirect()->to($this->returnPath(is_string($return) ? $return : null));
    }

    /**
     * A same-origin path, or the application's entry point.
     *
     * The form sends the current request URI, but a request can carry anything.
     * Only a relative path that cannot be read as a scheme, a host, or a
     * backslash escape is allowed.
     */
    private function returnPath(?string $return): string
    {
        if ($return === null || $return === '') {
            return route('home');
        }

        if (! str_starts_with($return, '/')
            || str_starts_with($return, '//')
            || str_contains($return, '\\')
            || stripos($return, '%5c') !== false
            || stripos($return, '%2f%2f') !== false) {
            return route('home');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $return) === 1) {
            return route('home');
        }

        $parts = parse_url($return);

        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return route('home');
        }

        return $return;
    }
}
