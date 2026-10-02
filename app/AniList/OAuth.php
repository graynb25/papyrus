<?php

namespace App\AniList;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

/**
 * The OAuth half of tracking: send a reader to AniList, take the code back, get
 * a token.
 *
 * ## Why this is not the same door as the lookups
 *
 * {@see Client} talks to `graphql.anilist.co` with no credentials, because a
 * title lookup reads public data. Writing progress acts as the reader, and that
 * needs a token only they can grant. So this class speaks to `anilist.co`, the
 * site itself, and holds the client id and secret an account holder creates at
 * anilist.co/settings/developer.
 *
 * ## One year, and no refresh
 *
 * AniList's OAuth issues an access token that lives about 365 days and issues
 * no refresh token. There is nothing to rotate and nothing to renew from, so
 * the connection stores the token with its expiry and a dead token means
 * connecting again. The settings page says so rather than implying otherwise.
 */
class OAuth
{
    private const AUTHORIZE = 'https://anilist.co/api/v2/oauth/authorize';

    private const TOKEN = 'https://anilist.co/api/v2/oauth/token';

    /**
     * Whether tracking can be offered at all on this deployment.
     *
     * Without a client id and secret there is no authorization URL to send
     * anyone to, and the settings page says what to create rather than showing
     * a button that cannot work.
     */
    public function configured(): bool
    {
        return (string) config('papyrus.anilist.oauth_client_id') !== ''
            && (string) config('papyrus.anilist.oauth_client_secret') !== '';
    }

    /**
     * The URL that asks the reader to grant access.
     *
     * The state travels to AniList and back, and the callback refuses to trade
     * a code unless it matches what was put in the session, so a crafted link
     * cannot bind someone else's AniList id to a reader.
     */
    public function authorizeUrl(string $state): string
    {
        return self::AUTHORIZE.'?'.http_build_query([
            'client_id' => (string) config('papyrus.anilist.oauth_client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    /**
     * Trade an authorization code for a token.
     *
     * Sent as a form post: AniList's token endpoint predates the JSON habit and
     * this is the shape every OAuth2 server accepts.
     *
     * @return array{access_token: string, expires_at: CarbonInterface|null}
     *
     * @throws AniListRefusedException when AniList refuses the trade
     */
    public function exchange(string $code): array
    {
        $response = Http::asForm()
            ->timeout((int) config('papyrus.anilist.timeout', 15))
            ->post(self::TOKEN, [
                'grant_type' => 'authorization_code',
                'client_id' => (string) config('papyrus.anilist.oauth_client_id'),
                'client_secret' => (string) config('papyrus.anilist.oauth_client_secret'),
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
            ]);

        if (! $response->successful()) {
            throw new AniListRefusedException(
                'AniList refused the token exchange (HTTP '.$response->status().').',
                (int) config('papyrus.anilist.max_backoff_seconds', 65),
            );
        }

        $token = (string) ($response->json('access_token') ?? '');

        if ($token === '') {
            throw new AniListRefusedException(
                'AniList answered the exchange without a token.',
                (int) config('papyrus.anilist.max_backoff_seconds', 65),
            );
        }

        /*
         * `expires_in` is seconds, and `expires` the epoch second it ends. Either
         * may arrive, so both are read and the epoch wins when present.
         */
        $expiresIn = $response->json('expires_in');
        $expires = $response->json('expires');

        $expiresAt = is_numeric($expires)
            ? Date::createFromTimestamp((int) $expires)
            : (is_numeric($expiresIn)
                ? now()->addSeconds((int) $expiresIn)
                : null);

        return ['access_token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * Who the token belongs to, straight from AniList.
     *
     * Asked here rather than trusted from the exchange, because the id and name
     * are what the settings page shows and a name read from the token's own
     * server is the only one worth showing.
     *
     * @return array{id: int, name: string}
     *
     * @throws AniListRefusedException when AniList refuses the query
     */
    public function viewer(string $accessToken): array
    {
        $data = app(Client::class)->forUser($accessToken, 'query Viewer { Viewer { id name } }');

        $viewer = $data['Viewer'] ?? null;

        if (! is_array($viewer) || ! isset($viewer['id'])) {
            throw new AniListRefusedException(
                'AniList answered the viewer query with nothing in it.',
                (int) config('papyrus.anilist.max_backoff_seconds', 65),
            );
        }

        return ['id' => (int) $viewer['id'], 'name' => (string) ($viewer['name'] ?? '')];
    }

    /**
     * Where AniList must send the reader back to.
     *
     * AniList compares this byte for byte against what the app's page at
     * anilist.co/settings/developer registered, so it is derived from the
     * callback route (which follows APP_URL) rather than guessed from the
     * request, and a mismatch is a configuration fact to document rather than
     * an error to survive.
     */
    private function redirectUri(): string
    {
        return (string) (config('papyrus.anilist.oauth_redirect_uri') ?: route('anilist.callback'));
    }
}
