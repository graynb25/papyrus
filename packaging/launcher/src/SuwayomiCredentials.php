<?php

/**
 * Whether this install can talk to its Suwayomi, and what to say when it cannot.
 *
 * ## The failure this exists for
 *
 * A reader installs Papyrus, starts it, and sees a red banner saying Papyrus cannot reach the
 * manga server. Every card reads "Not on the server". The launcher says it is running, the
 * readiness probe passes, and `papyrus health` reports every line green.
 *
 * The cause is that Suwayomi runs in `AuthMode.UI_LOGIN`, which puts `@RequireAuth` on the queries
 * the app actually uses, and the generated `.env` carries `SUWAYOMI_USERNAME=''`. Nothing wrote
 * those two values, because the launcher knows Suwayomi's URL and port and has no way to know a
 * password. The library is intact and fully visible; it simply cannot be matched to the server.
 *
 * Every health check that existed passed throughout, because `aboutServer` is not `@RequireAuth`.
 * A check that only exercises the one endpoint which never needed credentials cannot see this.
 *
 * ## Why it refuses rather than asking
 *
 * Because PHP on Windows cannot mask console input without a dependency, so a prompt would put the
 * password on screen and in the reader's scrollback. A secret that is typed into a console is a
 * secret that ends up in a screenshot. So the launcher says exactly which two keys to fill in and
 * where, and stops.
 *
 * ## Why it refuses only when it is certain
 *
 * A false positive here stops a working install, which is worse than the bug: the reader has a
 * perfectly good library and now cannot start anything. So all three of these must hold before it
 * refuses:
 *
 *   1. Suwayomi answered an unauthenticated query, positively saying Unauthorized
 *   2. This install has no credentials in its environment
 *   3. Its `.env` has no credentials either
 *
 * If the probe cannot reach Suwayomi, or the answer is not recognisable, the launcher says it could
 * not check and carries on. An install that might work should start.
 */

declare(strict_types=1);

namespace Papyrus\Launcher;

final class SuwayomiCredentials
{
    /**
     * @param  array<string, string>  $environment  The launcher's own environment, passed in by the
     *                                              caller rather than read from `getenv()` here.
     *                                              See the note on fromEnvironment().
     * @param  (callable(string): ?string)|null  $probe  Performs one GraphQL POST and returns the
     *                                                   body, or null when it could not be reached.
     */
    public function __construct(
        private readonly string $environmentFile,
        private readonly array $environment = [],
        private $probe = null,
    ) {}

    /**
     * The username in force, or null when there is not a usable pair.
     *
     * Environment before file, because an unattended install has no other way to supply one and a
     * value already in the environment is the more deliberate of the two.
     */
    public function username(): ?string
    {
        return $this->pair()['username'];
    }

    /**
     * The password in force, or null when there is not a usable pair.
     */
    public function password(): ?string
    {
        return $this->pair()['password'];
    }

    /**
     * Whether this install has a complete credential pair.
     *
     * Both or neither. A username with no password is not a partial success, it is an install that
     * will fail its first query, and counting it as configured is how the silent case survives.
     */
    public function configured(): bool
    {
        return $this->username() !== null && $this->password() !== null;
    }

    /**
     * The variables to hand the application so it reads the same credentials this launcher did.
     *
     * Empty when there is nothing to hand on, rather than a pair of empty strings. `applicationEnvironment()`
     * spreads this into the web server's environment, and two empty values there would look to
     * Laravel like a deliberate override of whatever the `.env` holds.
     *
     * @return array<string, string>
     */
    public function childEnvironment(): array
    {
        if (! $this->configured()) {
            return [];
        }

        return [
            'SUWAYOMI_USERNAME' => (string) $this->username(),
            'SUWAYOMI_PASSWORD' => (string) $this->password(),
        ];
    }

    /**
     * Whether a GraphQL body says this query needs a signed-in user.
     *
     * Pure string inspection on the body rather than a GraphQL parse, because the launcher is
     * required to keep working when the application does not and has no autoloader to help it.
     *
     * Strictly this cannot tell "Suwayomi requires auth" from "the token you sent was rejected".
     * That is fine here and nowhere else: the only caller probes with no token at all, so the two
     * are not distinguishable and do not need to be.
     */
    public function bodyRequiresAuth(?string $body): bool
    {
        return is_string($body) && str_contains($body, 'Unauthorized');
    }

    /**
     * The reason this install cannot reach its server, or null when it can, or when we cannot tell.
     *
     * Null is doing real work in the second and third cases. The caller refuses only on a string,
     * so "could not check" and "no problem" both let the install start.
     */
    public function refusal(): ?string
    {
        if ($this->configured()) {
            return null;
        }

        if ($this->probe === null) {
            return null;
        }

        /*
         * `sources` is used rather than `aboutServer`, and that choice is the whole detection.
         *
         * `aboutServer` carries no `@RequireAuth`, so it answers 200 with a version string on a
         * Suwayomi that will refuse every query the app makes. Probing with it says "reachable"
         * about an install that cannot read a single series. That is precisely the check that let
         * this go unnoticed, so it is not used here.
         */
        $body = ($this->probe)('{ sources { totalCount } }');

        if ($body === null) {
            return null;
        }

        if (! $this->bodyRequiresAuth($body)) {
            return null;
        }

        return $this->message();
    }

    /**
     * The sentence a reader is shown, naming the two keys and where they belong.
     *
     * Written out in full rather than assembled from fragments, because this is the one message
     * that has to be understood by somebody who has never read a `.env` and is currently looking
     * at a library that says every series is missing.
     */
    private function message(): string
    {
        return implode(PHP_EOL, [
            'Suwayomi is running in a mode that requires signing in, and this install has no',
            'credentials for it. Everything Papyrus can show you without Suwayomi works, but every',
            'series will read "Not on the server" and no cover, chapter count or new chapter can',
            'be fetched. Your library is safe and untouched.',
            '',
            'The credentials are the ones you registered with Suwayomi, and they belong in this file:',
            '',
            '    '.$this->environmentFile,
            '',
            'Add or fill in these two lines:',
            '',
            '    SUWAYOMI_USERNAME=',
            '    SUWAYOMI_PASSWORD=',
            '',
            'For an unattended install, set them in the environment instead and they will be picked',
            'up from there.',
            '',
            'Papyrus will not start until this is settled, because starting would give you a reader',
            'that looks broken rather than one that says what is wrong.',
        ]);
    }

    /**
     * The username and password in force.
     *
     * @return array{username: string|null, password: string|null}
     */
    private function pair(): array
    {
        $fromEnvironment = $this->fromEnvironment();

        if ($fromEnvironment !== null) {
            return $fromEnvironment;
        }

        return $this->fromFile();
    }

    /**
     * @return array{username: string|null, password: string|null}|null
     */
    private function fromEnvironment(): ?array
    {
        /*
         * Read only from what the caller passed, never from `getenv()`.
         *
         * The first version fell back to `getenv()` and it was wrong in a way worth recording.
         * Under `artisan test` the application's own `.env` is loaded into the process, so
         * `getenv('SUWAYOMI_USERNAME')` returned a value that had nothing to do with the launcher's
         * environment, and every test asserting "this install has no credentials" passed or failed
         * according to whatever the developer's `.env` happened to say.
         *
         * That is not only untestable. It is a second, invisible source of credentials: the caller
         * decides what the launcher's environment is, in one place, on purpose, and having the
         * object quietly consult global state as well means the two can disagree and the code that
         * uses it has no way to tell.
         */
        $username = $this->clean($this->environment['SUWAYOMI_USERNAME'] ?? null);
        $password = $this->clean($this->environment['SUWAYOMI_PASSWORD'] ?? null);

        if ($username === null || $password === null) {
            return null;
        }

        return ['username' => $username, 'password' => $password];
    }

    /**
     * @return array{username: string|null, password: string|null}
     */
    private function fromFile(): array
    {
        $contents = @file_get_contents($this->environmentFile);

        if (! is_string($contents)) {
            return ['username' => null, 'password' => null];
        }

        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if ($line === '' || str_starts_with(ltrim($line), '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);

            $values[trim($name)] = $this->clean($value);
        }

        return [
            'username' => $values['SUWAYOMI_USERNAME'] ?? null,
            'password' => $values['SUWAYOMI_PASSWORD'] ?? null,
        ];
    }

    /**
     * A value that carries no credentials is null, not an empty string.
     *
     * The generated `.env` ships `SUWAYOMI_USERNAME=''`, and `''` is the shape this whole defect
     * took. Treating it as present is how an install reports itself configured while every query
     * it makes is refused.
     */
    private function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim(trim($value), "\"' ");

        return $value === '' ? null : $value;
    }
}
