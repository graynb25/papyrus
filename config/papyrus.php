<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment mode
    |--------------------------------------------------------------------------
    |
    | "single"  - one user, registration disabled, quotas bypassed.
    | "shared"  - registration enabled, per-user storage quotas.
    | "public"  - shared mode plus abuse controls and moderation surfaces.
    |
    | The data model is multi-tenant regardless of mode. "single" is simply
    | one user with registration switched off, never a separate schema.
    |
    */

    'deployment_mode' => env('PAPYRUS_DEPLOYMENT_MODE', 'single'),

    /*
    |--------------------------------------------------------------------------
    | Suwayomi connection
    |--------------------------------------------------------------------------
    |
    | Suwayomi must never be bound to a public interface. Its CORS reflects any
    | origin with credentials, and AuthMode.NONE hands Admin(1) to every caller.
    | Bind it to 127.0.0.1 and let Herd's nginx proxy it (see public_path_prefix).
    |
    */

    'suwayomi' => [

        'url' => env('SUWAYOMI_URL', 'http://127.0.0.1:4567'),

        'graphql_path' => env('SUWAYOMI_GRAPHQL_PATH', '/api/graphql'),

        /*
         * Path the app serves Suwayomi page images from.
         *
         * Originally planned as an nginx reverse proxy that would keep PHP out
         * of the image path. Herd cannot do that: its config is a single
         * catch-all server block that rewrites everything, static assets
         * included, to valet/server.php. PHP is already in the path for every
         * request, so the proxy is a Laravel route and same-origin is a
         * property of the route table rather than of nginx.
         */
        'public_path_prefix' => env('SUWAYOMI_PUBLIC_PATH', '/suwayomi'),

        /*
         * Credentials for AuthMode.UI_LOGIN. The server hands back a JWT access
         * token and a refresh token; both are kept. Prefer UI_LOGIN over
         * SIMPLE_LOGIN, which is browser-only and unusable server-to-server.
         */
        'username' => env('SUWAYOMI_USERNAME'),
        'password' => env('SUWAYOMI_PASSWORD'),

        /*
         * The server enforces a hardcoded 30s GraphQL execution timeout
         * (Suwayomi-Server#2240). We stay just under it so we produce our own
         * structured timeout instead of letting the connection die.
         */
        'timeout' => (int) env('SUWAYOMI_TIMEOUT', 28),

        'connect_timeout' => (int) env('SUWAYOMI_CONNECT_TIMEOUT', 5),

        /*
         * No longer consulted for the access token. Measured against v2.3.2243:
         * the access token carries exp = issued + 300 and the refresh token
         * exp = issued + 5,184,000, both readable from the `exp` claim.
         *
         * This used to drive the cache lifetime at 3000, which was never
         * measured and was ten times the real access token life. Because
         * Suwayomi reports an expired token as HTTP 200 with a GraphQL error
         * rather than as a 401, nothing noticed: every authenticated call kept
         * using the dead token until someone cleared the cache by hand.
         *
         * Kept only as a fallback for a server that mints a token we cannot
         * decode, so that one bad response does not mean refreshing on every
         * call. Set it to the real access token life if you ever see it used.
         */
        'token_ttl' => (int) env('SUWAYOMI_TOKEN_TTL', 300),

        'jwt_audience' => env('SUWAYOMI_JWT_AUDIENCE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global search fan-out
    |--------------------------------------------------------------------------
    |
    | Suwayomi exposes no global search endpoint (MangaAPI.kt carries a literal
    | "TODO" comment). Searching everything is a client-side fan-out bounded by
    | the server's 30s ceiling, so per-source budgets have to be tight.
    |
    */

    'search' => [
        'fanout_concurrency' => (int) env('PAPYRUS_SEARCH_CONCURRENCY', 8),
        'per_source_timeout' => (int) env('PAPYRUS_SEARCH_SOURCE_TIMEOUT', 20),
        'results_per_source' => (int) env('PAPYRUS_SEARCH_PER_SOURCE', 15),
        /*
         * Fuzzy-merge threshold for cross-source dedupe.
         *
         * 0.95, not 0.92. Measured against this normaliser:
         *   "One Piece"      vs "One Piece G"            0.9412  (spinoff)
         *   "Jujutsu Kaisen" vs "Jujutsu Kaisen 0"       0.9630
         *   "Spy x Family"   vs "Spy Family"             0.9474  (same series)
         *   "Chainsaw Man"   vs "Chainsaw Man: Typhoon"  0.7586  (spinoff)
         *
         * At 0.92 "One Piece G" folds into "One Piece", which is wrong. The
         * length-ratio guard already scores long spinoffs at 0.0, so this
         * threshold only has to separate near-identical titles.
         */
        'dedupe_fuzzy_threshold' => (float) env('PAPYRUS_SEARCH_FUZZY', 0.95),
    ],

    /*
    |--------------------------------------------------------------------------
    | Source migration
    |--------------------------------------------------------------------------
    |
    | Suwayomi exposes no migration primitive: no GraphQL field across 32 query, 78
    | mutation and 4 subscription fields, and no REST v1 endpoint. So the wizard
    | searches, matches and copies read state itself, and these numbers are measured
    | rather than chosen.
    |
    */

    'migration' => [
        /*
         * Below this title similarity a hit is not offered.
         *
         * 0.75, and lower than the dedupe threshold on purpose. Dedupe must not
         * merge a series with a spin-off, so it wants near-identical titles. A
         * migration ranker wants the opposite: "Chainsaw Man: Typhoon" scores 0.7586
         * against "Chainsaw Man" and is exactly the kind of near neighbour a reader
         * would want shown, ranked low rather than hidden.
         *
         * 0.75 rather than 0.70 because a subtitle variant lands at 0.7586, which is
         * just over the line, and an arc listed as its own entry is not the series.
         * The arithmetic that puts it there is in TitleNormalizer::similarityOf().
         */
        'minimum_similarity' => (float) env('PAPYRUS_MIGRATION_MIN_SIMILARITY', 0.75),

        /*
         * How many sites to fetch a candidate's detail for.
         *
         * Measured: 72 candidates on 6 sites for one series, and the per-site source
         * fetch costs 370ms to 1346ms, so 8 sites is about 5 seconds on top of a
         * 22 second search. Capped because the fetch writes chapter rows into the
         * server's database whether or not a reader ever opens the series, and
         * searching every one of the 15 sites would fetch 72 chapter lists to show
         * the same decision.
         */
        'max_sites_to_fetch' => (int) env('PAPYRUS_MIGRATION_MAX_SITES', 8),

        /*
         * How many language variants of one site to try before calling it a dead end.
         *
         * Measured, and this fixes a real bug. Sources are swept in
         * `suwayomi_source_id` order and MangaFire's JA variant has a lower id than
         * its EN one, so a site whose variants tie on title similarity was represented
         * by whichever came first. That was the JA one, which answers "No chapters
         * found", and the reader lost the only exact chapter-count match available.
         *
         * Two is enough for the measured case and costs one extra fetch, 370ms to
         * 1346ms, only for a site that turns out to be a dead end.
         */
        'variants_per_site' => (int) env('PAPYRUS_MIGRATION_VARIANTS_PER_SITE', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | AniList
    |--------------------------------------------------------------------------
    |
    | AniList is a public database of manga and anime with a free, keyless GraphQL
    | API. Measured from this box: HTTP 200 with no auth, and a rate limit of **30
    | requests a minute**, not the 90 AniList documents for an authenticated client.
    |
    | That number is the design constraint. One fan-out produces 1,080 raw hits and 91
    | distinct titles, so resolving a run is 91 requests and over three minutes, which
    | is why lookups run in a job that re-books itself and the render path only ever
    | reads the cache.
    |
    | Nothing here is on by default. Whether a reader's titles leave the box is their
    | choice, per reader, and it lives in `user_settings` rather than here.
    |
    */

    'anilist' => [
        'url' => env('ANILIST_URL', 'https://graphql.anilist.co'),

        /*
         * Requests per second between lookups, which is 2.0 against a measured
         * 30 a minute. Sub-second precision buys nothing when the limit is a window
         * of a minute.
         */
        'gap_seconds' => 2,

        /*
         * How long one pass of the resolver may spend. Sized to sit inside the
         * worker's timeout with room to spare: the job's own `$timeout` is 90 and a
         * pass that overruns it is killed, and with `failOnTimeout` at its default it
         * would vanish with no record (trap 70).
         */
        'pass_budget_seconds' => 45,

        'timeout' => (int) env('ANILIST_TIMEOUT', 15),
        'retries' => (int) env('ANILIST_RETRIES', 2),
        'retry_delay_ms' => (int) env('ANILIST_RETRY_DELAY', 1500),

        /*
         * The ceiling on any backoff. Long enough to sit out a full rate limit
         * window, short enough that a chain does not stall for minutes.
         */
        'max_backoff_seconds' => (int) env('ANILIST_MAX_BACKOFF', 65),

        /*
         * OAuth, for tracking reading progress on a reader's own account.
         *
         * This is a bigger grant than the keyless lookups above: it acts as the
         * reader on AniList. The id and secret come from an application the
         * deployer creates at anilist.co/settings/developer, and the redirect
         * URI registered there must match what this app sends, byte for byte.
         * By default that is the anilist.callback route on APP_URL; set
         * ANILIST_OAUTH_REDIRECT_URI when the outside-facing address and the
         * configured APP_URL disagree.
         */
        'oauth_client_id' => env('ANILIST_OAUTH_CLIENT_ID'),
        'oauth_client_secret' => env('ANILIST_OAUTH_CLIENT_SECRET'),
        'oauth_redirect_uri' => env('ANILIST_OAUTH_REDIRECT_URI'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Library checks
    |--------------------------------------------------------------------------
    |
    | How many days a series goes unchecked before a library pass visits it again.
    |
    | Measured, and the reason the number is not larger: a re-check costs what the
    | first check cost, a ratio of 0.95x, at 361ms to 1617ms per refresh with an 876ms
    | median. Suwayomi does not cache, so every check is a real upstream request to
    | one of 82 sites, and a 300 series library is 4.4 minutes of that.
    |
    | One day, because that is what a reader expects of "check for new chapters" on a
    | series still running. `series_states.fetch_interval_days` overrides this per
    | series, and has done since migration 3 with nothing reading it, so an ongoing
    | series can be daily and a finished one never. Zero there means every pass, which
    | is the only way to say "always check this one".
    |
    */

    'library' => [
        'check_interval_days' => (int) env('LIBRARY_CHECK_INTERVAL_DAYS', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Downloads
    |--------------------------------------------------------------------------
    |
    | Suwayomi's queue exposes progress but no error text, throughput or ETA,
    | and /api/v1/chapter/{id}/download builds the whole zip in memory
    | (Suwayomi-Server#2353). We capture our own metrics and size-guard exports.
    |
    */

    'downloads' => [
        'cbz_export_max_bytes' => (int) env('PAPYRUS_CBZ_MAX_BYTES', 268_435_456),
        'poll_interval' => (int) env('PAPYRUS_DOWNLOAD_POLL', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Covers
    |--------------------------------------------------------------------------
    |
    | A reader's own cover art, which Suwayomi cannot store: ImageMutation exposes
    | only clearCachedImages. Files live on our disk and the app layer overrides
    | thumbnailUrl for this reader wherever it renders a cover.
    |
    | The long edge is not a taste decision. Measured against the sources themselves,
    | a Suwayomi cover is at most 800px on its long edge and weighs 77KB to 193KB, so
    | bounding an upload at 800px makes a custom cover indistinguishable in sharpness
    | from a proxied one and costs about the same. Raising it would store pixels no
    | slot on this app can show.
    |
    | The pixel ceiling is the memory guard, not the byte ceiling, and it is the one
    | that matters. memory_limit is 128M and a gd truecolor image costs four bytes a
    | pixel, so the ceiling has to come from what memory can actually hold rather than
    | from what a phone can photograph.
    |
    | 12 megapixels, which is four bytes for 48MB. That is 4000 by 3000, still a good
    | cover photo, and it leaves room inside 128M for the decoded original, the resized
    | copy, the file itself and a Laravel request that is already running. The number was
    | arrived at by being wrong twice: 40 megapixels is 160MB and 20 is 80MB, and both
    | were refused *by PHP* rather than by this check, which is the clearest possible
    | statement that a ceiling above what memory holds is not a ceiling at all.
    |
    | Two megabytes of PNG can also be 20000 by 20000, so a byte limit alone would not
    | stop any of this.
    |
    | The byte ceiling matches this server's own upload_max_filesize, which is 2M in
    | php.ini. Raising it is an ini change outside this project and affects every site
    | on the machine, so the number is read to agree with reality rather than to wish
    | it were larger, and the validation message says which limit was hit.
    |
    */

    'covers' => [
        'disk' => env('PAPYRUS_COVER_DISK', 'local'),
        'long_edge' => (int) env('PAPYRUS_COVER_LONG_EDGE', 800),
        'max_kilobytes' => (int) env('PAPYRUS_COVER_MAX_KB', 2048),
        'max_pixels' => (int) env('PAPYRUS_COVER_MAX_PIXELS', 12_000_000),
        'quality' => (int) env('PAPYRUS_COVER_QUALITY', 85),
    ],

    /*
    |--------------------------------------------------------------------------
    | Local source
    |--------------------------------------------------------------------------
    |
    | Suwayomi's local source has no upload API at all: measured, every GET on
    | `/api/v1/...` answers with the 4,484-byte WebUI catch-all and every other
    | method a 404. A file dropped into this folder *is* discovered, so the folder is
    | the interface, and H15 reads it rather than writing to it.
    |
    | The shape is one folder per series, measured from what Suwayomi actually picked
    | up: `local/<Series Name>/` holding a `.cbz` and optionally a `ComicInfo.xml`.
    |
    | This path is a filesystem path on whatever machine runs the server, so it is
    | only meaningful when Suwayomi and Papyrus are on the same machine. That is this
    | deployment, a native Windows JAR beside Laravel. Point it elsewhere and the
    | browse view says the folder is not visible from here rather than showing an
    | empty library that reads as "you have no local manga".
    |
    | The source's id is 0 and its language is `localsourcelang`. Neither is
    | derivable, and there is no `isLocal` field on `SourceType` to lean on.
    |
    */

    'local_source' => [
        /*
         * No default, on purpose.
         *
         * This pointed at one developer's Suwayomi data directory, which meant the
         * committed config carried a Windows username and a path that is wrong on every
         * other machine. An unset value reads as "not configured", and the sources page
         * already says the folder is not visible rather than showing an empty library,
         * so a missing default costs nothing and leaks nothing.
         */
        'path' => env('PAPYRUS_LOCAL_SOURCE_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reader
    |--------------------------------------------------------------------------
    */

    'reader' => [
        'preload_ahead' => 3,
        'preload_behind' => 2,
        'default_image_format' => env('PAPYRUS_IMAGE_FORMAT', 'webp'),

        /*
         * fetchChapterPages downloads every image in the chapter, so it is the
         * slowest call in the app. Measured: 2.0s for a 22 page chapter on a warm
         * connection, which is why it sits behind the reader opening a chapter and
         * never behind listing them.
         *
         * This budget is above the server's hardcoded 30s GraphQL ceiling, because
         * a long scan is a legitimate result rather than a hang. A chapter that
         * takes longer than this gets a timeout the reader can retry.
         */
        'page_fetch_timeout' => (int) env('PAPYRUS_PAGE_FETCH_TIMEOUT', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Schema snapshot
    |--------------------------------------------------------------------------
    |
    | Suwayomi's schema is generated at runtime and is unversioned. We commit a
    | snapshot so a server upgrade that breaks us is detectable rather than
    | mysterious. See the suwayomi:schema:dump / suwayomi:schema:diff commands.
    |
    */

    'schema_snapshot' => resource_path('schemas/suwayomi-introspection.json'),

];
