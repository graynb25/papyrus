<?php

namespace Tests\Feature\Settings;

use App\AniList\OAuth;
use App\AniList\Tracking;
use App\Models\AniListAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The connections settings page and the connect flow around it.
 *
 * The button the page draws is the start of an OAuth dance whose end is the
 * callback controller, which is tested in AniListTrackingTest. Here: that the
 * page tells the truth about what connecting costs, that an unconfigured
 * server offers instructions rather than a dead button, and that
 * disconnecting really removes the credential.
 */
class ConnectionsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_the_page_renders_and_says_what_connecting_costs(): void
    {
        $this->get(route('connections.edit'))
            ->assertOk()
            ->assertSee('AniList')
            ->assertSee('Connecting sends your reading progress to AniList under your account.');
    }

    public function test_an_unconfigured_server_offers_instructions_not_a_dead_button(): void
    {
        // No client id or secret in this environment, so the OAuth config is off.
        config(['papyrus.anilist.oauth_client_id' => null]);

        $html = Livewire::test('pages::settings.connections')->html();

        $this->assertStringContainsString('ANILIST_OAUTH_CLIENT_ID', $html);
        $this->assertStringContainsString('wire:click="connect"', $html, 'The button stays, disabled rather than hidden, so the reader can see what they are one step away from.');
        $this->assertStringContainsString('disabled', $html);
    }

    public function test_connect_redirects_to_anilist_with_a_state(): void
    {
        config([
            'papyrus.anilist.oauth_client_id' => 'client-1',
            'papyrus.anilist.oauth_client_secret' => 'secret-1',
        ]);

        Http::assertNothingSent();

        Livewire::test('pages::settings.connections')
            ->call('connect')
            ->assertRedirect();

        $this->assertNotEmpty(session('anilist_oauth_state'));

        // The URL itself is the OAuth class's job, checked directly.
        $uri = app(OAuth::class)->authorizeUrl('abc');

        $this->assertStringStartsWith('https://anilist.co/api/v2/oauth/authorize?', $uri);
        $this->assertStringContainsString('client_id=client-1', $uri);
        $this->assertStringContainsString('response_type=code', $uri);
        $this->assertStringContainsString('state=abc', $uri);
    }

    public function test_disconnect_deletes_the_credential(): void
    {
        app(Tracking::class)->connect($this->user, 'tok', 1, 'Reader', null);

        Livewire::test('pages::settings.connections')->call('disconnect');

        $this->assertNull(AniListAccount::forUser($this->user->id)->first());
    }

    public function test_a_connected_reader_can_test_the_connection(): void
    {
        app(Tracking::class)->connect($this->user, 'secret-token-value', 4242, 'Reader', null);

        Http::fake([
            'https://graphql.anilist.co*' => Http::response([
                'data' => ['Viewer' => ['id' => 4242, 'name' => 'Reader']],
            ], 200),
        ]);

        Livewire::test('pages::settings.connections')
            ->call('testConnection')
            ->assertDispatched('toast-show', function (string $name, array $params): bool {
                return ($params['slots']['heading'] ?? null) === 'Connection looks good'
                    && ($params['slots']['text'] ?? null) === 'AniList accepted the saved token for Reader.';
            });
    }

    public function test_a_connected_reader_sees_who_they_are_and_not_their_token(): void
    {
        app(Tracking::class)->connect($this->user, 'secret-token-value', 4242, 'Reader', null);

        $this->get(route('connections.edit'))
            ->assertOk()
            ->assertSee('Reader')
            ->assertDontSee('secret-token-value');
    }

    /**
     * Disconnecting deletes the row, but AniList publishes no token revocation endpoint.
     * The page has to say that rather than imply the credential dies here.
     */
    public function test_disconnecting_says_anilist_cannot_revoke_the_token(): void
    {
        app(Tracking::class)->connect($this->user, 'tok', 1, 'Reader', null);

        $this->get(route('connections.edit'))
            ->assertOk()
            ->assertSee('AniList does not publish a way for this app to revoke it')
            ->assertSee('the token stays valid on AniList until it expires');
    }
}
