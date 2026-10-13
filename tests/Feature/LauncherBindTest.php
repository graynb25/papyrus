<?php

namespace Tests\Feature;

use Papyrus\Launcher\Supervisor;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one thing that confines a reader's Papyrus to their own machine.
 *
 * ## What was broken, measured on a running install
 *
 * The Caddyfile said `http://127.0.0.1:{{APP_PORT}}`, the installer guide said nothing outside the
 * machine could be reached, and the launcher said the same. None of that was true:
 *
 *     Caddyfile said                         http://127.0.0.1:8000
 *     the listener was actually bound on     ::             the IPv6 wildcard, every interface
 *     http://192.0.2.1:8000/ready         200
 *     http://192.0.2.2:8000/ready          200
 *
 * Windows Firewall had inbound-allow rules for `frankenphp.exe` as well, so nothing local was
 * holding the line back. A reader's library, their reading history and their account were answering on
 * the local network while every document in this project said they were not.
 *
 * ## Why the site address was never the thing to rely on
 *
 * Because naming a loopback host in a Caddy site address does not restrict the listener. That is the
 * whole bug, and it survived because it looks correct: the address says loopback, so the listener is
 * loopback, and a reader of the file has no reason to doubt it. The only directive that restricts a
 * Caddy listener is an explicit `bind`, which is why this test asserts on that line specifically
 * rather than on the address, which was already right and already useless.
 *
 * ## Why it is asserted here and not by starting the app
 *
 * Because a real listener check needs the built install, a bound port and a network, none of which
 * belong in the unit suite. What is cheap is the property that makes the bind correct in the first
 * place, and that is enough to stop the line being tidied away by somebody who reads it as redundant.
 *
 * The live check was run by hand against a real install and is written up above. After the fix the
 * bound addresses were `::1` and `127.0.0.1` only, and both LAN addresses refused.
 */
class LauncherBindTest extends TestCase
{
    private const CADDYFILE = 'packaging/frankenphp/Caddyfile';

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function caddyfile(): string
    {
        $path = $this->root().'/'.self::CADDYFILE;

        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The Caddyfile with its comment lines removed.
     *
     * Everything below that reads the file as configuration reads this instead, so the blocks of prose
     * that explain these directives cannot be mistaken for the directives themselves.
     */
    private function caddyfileCode(): string
    {
        return preg_replace('/^\s*#.*$/m', '', $this->caddyfile()) ?? '';
    }

    /**
     * The load-bearing assertion.
     *
     * A test asserting the site address is loopback would have passed against the broken file, which
     * is the specific failure that shipped. This one fails against it.
     */
    public function test_the_listener_is_confined_by_an_explicit_loopback_bind(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\s*bind\s+127\.0\.0\.1\s+::1\s*$/m',
            $this->caddyfileCode(),
            'an explicit bind is the only thing that restricts a Caddy listener. Without it the site '
                .'address reads as loopback while the socket is bound to every interface, which is '
                .'exactly what a live install did.',
        );
    }

    /**
     * Every address any bind names is loopback, and there is at least one bind.
     *
     * Written as an extraction rather than a list of forbidden patterns because the forbidden-pattern
     * version was wrong: it matched the legitimate `bind 127.0.0.1 ::1` line as well, since every
     * candidate it was handed began with the same word. Pulling the real directives out and checking
     * their addresses reports what the file does rather than what somebody might have typed into it.
     *
     * @return list<array{string}>
     */
    public static function nonLoopbackAddresses(): array
    {
        return [
            'the ipv4 wildcard' => ['0.0.0.0'],
            'the ipv6 wildcard' => ['::'],
            'a real interface address' => ['192.0.2.2'],
            'a host name' => ['papyrus.test'],
        ];
    }

    #[DataProvider('nonLoopbackAddresses')]
    public function test_a_bind_naming_something_routable_is_not_what_this_file_does(string $address): void
    {
        foreach ($this->bindDirectives() as $addresses) {
            foreach ($addresses as $named) {
                $this->assertNotSame(
                    $address,
                    $named,
                    'a bind directive names ['.$named.']. This install is documented as reachable '
                        .'only from the machine it runs on, and the bind is what makes that true.',
                );
            }
        }
    }

    public function test_there_is_a_bind_directive_at_all(): void
    {
        $this->assertNotEmpty(
            $this->bindDirectives(),
            'no bind directive at all. The site address alone does not restrict a Caddy listener, which '
                .'is the defect this file guards, so its absence is the failure.',
        );
    }

    /**
     * Both spellings of loopback are listed on the one block.
     *
     * `localhost` is there because the passkey relying-party id is `parse_url(APP_URL, PHP_URL_HOST)`
     * and an IP literal cannot be a relying-party id, so a passkey on `127.0.0.1` is refused by the
     * browser before Papyrus is involved. `127.0.0.1` is there because the launcher's own readiness
     * probe sends it, and a site address is also the Host it matches.
     *
     * @return list<array{string}>
     */
    public static function siteAddresses(): array
    {
        return [
            ['http://127.0.0.1:{{APP_PORT}}'],
            ['http://localhost:{{APP_PORT}}'],
        ];
    }

    #[DataProvider('siteAddresses')]
    public function test_the_site_accepts_both_spellings_of_loopback(string $address): void
    {
        $this->assertStringContainsString(
            $address,
            $this->caddyfileCode(),
            'readers are given localhost, probes send 127.0.0.1, and a site matches on the Host it is '
                .'given, so both have to be listed on the one block',
        );
    }

    /**
     * Nothing in the shipped configuration names a routable site.
     *
     * The bind confines the socket, so this is belt and braces rather than the security boundary
     * itself. It is here because the two failures look identical from the outside and only one of
     * them is fatal.
     */
    public function test_no_site_address_names_something_routable(): void
    {
        preg_match_all('/^\s*(https?:\/\/[^\s{]+)/m', $this->caddyfileCode(), $matches);

        $this->assertNotEmpty(
            $matches[1],
            'no site address was found at all, which means this test is not looking at anything',
        );

        foreach ($matches[1] as $address) {
            $this->assertContains(
                parse_url($address, PHP_URL_HOST),
                ['127.0.0.1', 'localhost'],
                $address.' names a host that is not loopback. The listener is confined by the bind '
                    .'directive so this would not expose anything by itself, but a site reachable from '
                    .'the network on an install documented as loopback only is a trap for whoever reads '
                    .'it next.',
            );
        }
    }

    /**
     * The two addresses a reader is ever told are both loopback, and they are not the same string.
     *
     * Asserted as a pair on purpose. A change that made them identical would still pass either half,
     * and the reason they differ is the point: one matches the relying-party id, the other is what the
     * internal probes send.
     */
    public function test_the_reader_url_and_the_probe_url_differ_and_both_are_loopback(): void
    {
        $supervisor = new Supervisor(
            root: '/nowhere',
            dataDirectory: '/nowhere/data',
            logDirectory: '/nowhere/logs',
            appPort: 8000,
        );

        $this->assertSame('http://localhost:8000', $supervisor->readerUrl());

        // The readiness probe is the one that broke, so it is asserted on the source rather than
        // inferred: with only localhost listed as a site it sent a Host that matched nothing, and
        // Caddy's log filled with msg "NOP" and status 0.
        $source = (string) file_get_contents($this->root().'/packaging/launcher/src/Supervisor.php');

        $this->assertMatchesRegularExpression(
            "#/ready', \\\$this->appPort#",
            $source,
            'the readiness probe still addresses the literal loopback address, which is why the site '
                .'block has to keep listing it',
        );
    }

    /**
     * Every bind directive in the file, as the addresses each one names.
     *
     * @return list<list<string>>
     */
    private function bindDirectives(): array
    {
        preg_match_all('/^\s*bind\s+(.+?)\s*$/m', $this->caddyfileCode(), $matches);

        return array_map(
            static fn (string $line): array => preg_split('/\s+/', trim($line)) ?: [],
            $matches[1],
        );
    }
}
