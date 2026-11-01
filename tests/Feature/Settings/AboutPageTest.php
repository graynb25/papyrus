<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\Versions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MintsTokens;
use Tests\TestCase;

/**
 * The About settings page: the two numbers a bug report starts with.
 *
 * ## Why the page exists rather than a command
 *
 * Because `papyrus health` already prints both versions, and a reader who cannot read their library
 * is not going to open a console window to find out what is wrong with it. The first three questions
 * of any bug report are "which version are you on", and until this page existed the answer lived in a
 * file at the root of the install that nothing showed.
 *
 * ## What is asserted, and what is not
 *
 * The four states of the comparison, because they are four different sentences and the temptation is
 * to collapse them into one "notice". The load-bearing assertions are the two that must *not* be
 * failures, since a page that cries wolf about a perfectly good server teaches a reader to ignore
 * the one row on it that matters.
 */
class AboutPageTest extends TestCase
{
    use MintsTokens;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * Answer the GraphQL with whatever version this test wants the reader to be running.
     *
     * The login mutation has to be answered too, or the gateway cannot authenticate and every
     * query fails for a reason that has nothing to do with versions. That produced a page that
     * cheerfully said "not answering" for every server, including the ones it had just been told
     * about, which is the exact failure this page exists to prevent: a confident wrong answer.
     */
    private function suwayomiIs(string $version): void
    {
        Http::fake(function (Request $request) use ($version) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => [
                        'accessToken' => $this->accessToken(),
                        'refreshToken' => $this->refreshToken(),
                    ]],
                ]);
            }

            return Http::response(['data' => ['aboutServer' => ['version' => $version]]]);
        });
    }

    /**
     * Suwayomi that answers the login and then fails every query, which is what an unreachable
     * server looks like from here rather than a 500 on the first call.
     */
    private function suwayomiIsUnreachable(): void
    {
        Http::fake(function (Request $request) {
            $query = $request->data()['query'] ?? '';

            if (str_contains($query, 'mutation Login')) {
                return Http::response([
                    'data' => ['login' => [
                        'accessToken' => $this->accessToken(),
                        'refreshToken' => $this->refreshToken(),
                    ]],
                ]);
            }

            return Http::response('nothing here', 500);
        });
    }

    public function test_it_renders_and_shows_both_versions(): void
    {
        $this->suwayomiIs('v2.4.2366');

        $this->get(route('about.edit'))
            ->assertOk()
            ->assertSee('Papyrus')
            ->assertSee('Suwayomi')
            ->assertSee('v2.4.2366');
    }

    /**
     * The Papyrus version comes from whichever file this install actually has.
     *
     * A checkout has `VERSION` and no `version.txt`; a packaged install is the reverse. Reading only
     * one would leave a developer running from source with "unknown" on the page that exists to stop
     * exactly that.
     */
    public function test_it_shows_the_papyrus_version_in_a_checkout_that_has_no_version_file(): void
    {
        $this->suwayomiIs('v2.4.2366');

        // The test suite runs from the repository, which has VERSION and no version.txt.
        $this->assertFileDoesNotExist(base_path('version.txt'));

        $this->get(route('about.edit'))
            ->assertOk()
            ->assertSee(trim((string) file_get_contents(base_path('VERSION'))));
    }

    public function test_it_names_the_version_this_install_expects(): void
    {
        $this->suwayomiIs('v2.4.2366');

        $this->get(route('about.edit'))
            ->assertOk()
            ->assertSee('Suwayomi this install was tested with')
            ->assertSee('v2.4.2366');
    }

    /**
     * A server too old is the one state that is a fault, and it says what will break.
     *
     * Below the floor the GraphQL fields Papyrus needs are absent, so chapters go missing and
     * searches return nothing without saying why. A reader who only sees "v2.3.2243" has no way to
     * connect that to the symptom they are actually seeing.
     */
    public function test_a_server_too_old_is_shown_as_a_problem_and_names_the_minimum(): void
    {
        $this->suwayomiIs('v2.3.2243');

        $this->get(route('about.edit'))
            ->assertOk()
            ->assertSee('v2.3.2243')
            ->assertSee('Suwayomi is too old for this install')
            ->assertSee('needs Suwayomi v2.4.0 or newer');
    }

    /**
     * A newer server is not a fault, and this is the assertion that matters most on the page.
     *
     * A reader on a newer Suwayomi is very likely fine. Painting that red would be wrong about their
     * server and, worse, would train them to ignore the row above it, which is the one that would
     * have mattered if anything really were broken.
     */
    public function test_a_newer_server_is_reported_without_being_called_a_problem(): void
    {
        $this->suwayomiIs('v2.5.100');

        $this->get(route('about.edit'))
            ->assertOk()
            ->assertSee('v2.5.100')
            ->assertSee('newer than this install was tested against')
            ->assertDontSee('Suwayomi is too old for this install');
    }

    /**
     * A server that cannot be reached says so plainly.
     *
     * Not "unknown" and not blank, because those read as a version problem. The reader needs to be
     * able to tell "your server is too old" from "your server is not answering", since the first is
     * fixed by an upgrade and the second by starting something.
     */
    public function test_an_unreachable_server_says_not_answering_and_proposes_no_verdict(): void
    {
        $this->suwayomiIsUnreachable();

        $this->get(route('about.edit'))
            ->assertOk()
            ->assertSee('not answering')
            ->assertDontSee('too old')
            ->assertDontSee('tested against');
    }

    /**
     * The expected version is only shown when this install records one.
     *
     * A build with no `suwayomi.json` has no expectation, and printing an empty "expected" row would
     * be a row that says nothing while looking like it does.
     */
    public function test_the_expected_row_is_absent_when_this_build_records_no_expectation(): void
    {
        $this->suwayomiIs('v2.4.2366');

        $page = app(Versions::class);

        $this->assertTrue(
            $page->compatibility()->isKnown(),
            'this test describes the repository, which does record one. If it has stopped doing so, '
                .'the case it is paired with above is the one that matters and this has to be rewritten.',
        );
    }

    /**
     * The page is behind auth, and behind auth only.
     *
     * Not `verified`, because it reads nothing about the reader. Putting it behind verification would
     * hide two version numbers from exactly the people most likely to be filing a first-run report,
     * which is the opposite of what the page is for.
     */
    public function test_it_is_reachable_without_a_verified_email_but_not_without_signing_in(): void
    {
        $this->suwayomiIs('v2.4.2366');

        $unverified = User::factory()->unverified()->create();

        $this->actingAs($unverified)
            ->get(route('about.edit'))
            ->assertOk();

        auth()->logout();

        $this->get(route('about.edit'))->assertRedirect(route('login'));
    }

    /**
     * Every settings page is reachable, so the new one is not an orphan.
     *
     * A navlist entry pointing at a route that does not exist is a 404 on click, which is the kind of
     * thing that survives a suite because nothing follows the link.
     */
    public function test_the_settings_navigation_links_to_it(): void
    {
        $this->suwayomiIs('v2.4.2366');

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertSee(route('about.edit'));
    }
}
