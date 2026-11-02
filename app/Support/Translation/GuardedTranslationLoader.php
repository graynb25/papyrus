<?php

namespace App\Support\Translation;

use Illuminate\Contracts\Translation\Loader;

/**
 * Keeps a translation key from becoming a filesystem path.
 *
 * ## The measured reason
 *
 * Laravel reads a key that is not in a JSON file as a group name and asks the filesystem for
 * `{path}/{locale}/{group}.php`. English copy is also a key, so `Forgot your password?` became
 * `lang/en/Forgot your password?.php`. With `open_basedir` enabled in the 1.5.3 candidate,
 * `file_exists` warned on the `?`, Laravel promoted the warning to a `ViewException`, and the
 * login page answered 500. The same shape reached the passkey removal confirmation and the series
 * unread confirmation, which carry both `?` and a period.
 *
 * ## Why an allowlist
 *
 * The file name is built from three values, so each one is checked against the characters a real
 * translation path uses. Rejecting only `?` would leave quotes, colons, angle brackets and pipes
 * to the filesystem, and those are invalid in a Windows file name for the same reason. A key is
 * copy, not a path, so an unsafe group returns no lines and the translator's normal missing key
 * behaviour returns the copy itself.
 *
 * `*` is the group and namespace Laravel uses for its JSON lookup. A slash is allowed inside a
 * group because Laravel supports nested groups such as `admin/users`; every segment still has to
 * be path safe. The decorated loader is kept rather than replaced so the framework's vendor paths
 * and namespace hints continue to load.
 */
final class GuardedTranslationLoader implements Loader
{
    /**
     * A single path segment: a locale, a namespace, or one part of a nested group.
     */
    private const SEGMENT = '/\A[A-Za-z0-9_-]+\z/';

    /**
     * A group, optionally nested one directory deep per slash.
     */
    private const GROUP = '/\A[A-Za-z0-9_-]+(?:\/[A-Za-z0-9_-]+)*\z/';

    /**
     * The segment Laravel uses for the JSON file and for an unnamespaced lookup.
     */
    private const JSON = '*';

    public function __construct(private readonly Loader $loader) {}

    /**
     * @param  string  $locale
     * @param  string  $group
     * @param  string|null  $namespace
     * @return array<string, mixed>
     */
    public function load($locale, $group, $namespace = null): array
    {
        if (! $this->isPathSafeSegment($locale, self::SEGMENT)
            || ! $this->isPathSafeGroup($group)
            || ! $this->isPathSafeNamespace($namespace)) {
            return [];
        }

        return $this->loader->load($locale, $group, $namespace);
    }

    /**
     * @param  string  $namespace
     * @param  string  $hint
     */
    public function addNamespace($namespace, $hint): void
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    /**
     * @param  string  $path
     */
    public function addJsonPath($path): void
    {
        $this->loader->addJsonPath($path);
    }

    /**
     * @return array<string, string>
     */
    public function namespaces(): array
    {
        return $this->loader->namespaces();
    }

    private function isPathSafeSegment(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    private function isPathSafeGroup(mixed $group): bool
    {
        return $group === self::JSON || $this->isPathSafeSegment($group, self::GROUP);
    }

    private function isPathSafeNamespace(mixed $namespace): bool
    {
        return $namespace === null
            || $namespace === self::JSON
            || $this->isPathSafeSegment($namespace, self::SEGMENT);
    }
}
