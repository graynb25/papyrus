<?php

namespace App\Http\Controllers;

use App\AniList\OAuth;
use App\AniList\Tracking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The end of the AniList connect flow.
 *
 * AniList sends the reader back here with the code and the state this app put
 * in the URL when it sent them away. Three things are refused rather than
 * survived: a state that does not match the session, a missing code, and an
 * exchange AniList will not honour. Each sends the reader to settings with a
 * sentence they can act on, because a silent redirect to a still-disconnected
 * page reads as a broken button.
 */
class AniListCallbackController extends Controller
{
    public function __invoke(Request $request, OAuth $oauth, Tracking $tracking): RedirectResponse
    {
        $expected = (string) session()->pull('anilist_oauth_state', '');
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($expected === '' || ! hash_equals($expected, $state)) {
            return redirect()
                ->route('connections.edit')
                ->with('anilist_error', __('The connect request expired before AniList answered. Start again.'));
        }

        if ($code === '') {
            return redirect()
                ->route('connections.edit')
                ->with('anilist_error', __('AniList did not approve the connection, so nothing was saved.'));
        }

        try {
            $token = $oauth->exchange($code);
            $viewer = $oauth->viewer($token['access_token']);
        } catch (\Throwable $e) {
            report($e);

            return redirect()
                ->route('connections.edit')
                ->with('anilist_error', __('AniList refused the connection: :reason', ['reason' => $e->getMessage()]));
        }

        $tracking->connect(
            $request->user(),
            $token['access_token'],
            $viewer['id'],
            $viewer['name'],
            $token['expires_at'],
        );

        return redirect()
            ->route('connections.edit')
            ->with('anilist_connected', $viewer['name']);
    }
}
