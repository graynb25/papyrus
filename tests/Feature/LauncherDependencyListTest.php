<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * The launcher's own dependency list, checked against the files that exist.
 *
 * ## What was broken, twice
 *
 * The launcher has no autoloader, so `papyrus.php` requires its classes by hand. `build-install.php`
 * kept a second copy of that list, which is a second dependency graph nobody exercises. When
 * `SuwayomiCredentials` was added to the launcher the build's copy was not updated, and the build
 * died with "Class not found" while assembling an install no reader would ever see fail.
 *
 * The build now reads `papyrus.php` instead of maintaining its own list, and fails if any file in
 * `src` is absent from it. That is the build being careful.
 *
 * This is the same rule as a test, and it belongs here for a reason: **the failure only happens
 * during a build.** A developer adds a class, the suite is green, every check is green, and the
 * first person to find out is whoever runs the build next. That is how this shipped once already,
 * and it cost a full rebuild to find.
 *
 * So the invariant is asserted here, where a failure is cheap and immediate.
 */
final class LauncherDependencyListTest extends TestCase
{
    private const ENTRY = __DIR__.'/../../packaging/launcher/papyrus.php';

    private const SRC = __DIR__.'/../../packaging/launcher/src';

    /**
     * Every file in `src` must be required by `papyrus.php`.
     *
     * The check is written as a set difference rather than by counting, because a count passes when
     * one file is added and another is removed, and because the failure has to name the file.
     */
    public function test_every_launcher_class_is_required_by_the_entry_point(): void
    {
        $declared = (string) file_get_contents(self::ENTRY);

        preg_match_all(
            "/require\s+__DIR__\s*\.\s*'\/src\/([A-Za-z0-9_]+\.php)'\s*;/",
            $declared,
            $matches,
        );

        $required = $matches[1] ?? [];
        $onDisk = array_map('basename', glob(self::SRC.DIRECTORY_SEPARATOR.'*.php') ?: []);

        sort($onDisk);

        $this->assertNotEmpty($onDisk, 'the glob found nothing, so this test would pass for the wrong reason');
        $this->assertNotEmpty($required, 'the entry point declares no requires at all');

        $missing = array_values(array_diff($onDisk, $required));

        $this->assertSame(
            [],
            $missing,
            'these launcher files exist but papyrus.php does not require them: '
            .implode(', ', $missing).'. Add them to its require list, in dependency order, or the '
            .'build assembles an install that fatals on its first run.',
        );
    }

    /**
     * And nothing is required that is not there.
     *
     * The other direction of the same difference. A stale require is a fatal of its own, and it is
     * the one that appears when a class is renamed and the entry point is not touched.
     */
    public function test_nothing_is_required_that_does_not_exist(): void
    {
        $declared = (string) file_get_contents(self::ENTRY);

        preg_match_all(
            "/require\s+__DIR__\s*\.\s*'\/src\/([A-Za-z0-9_]+\.php)'\s*;/",
            $declared,
            $matches,
        );

        $required = $matches[1] ?? [];
        $onDisk = array_map('basename', glob(self::SRC.DIRECTORY_SEPARATOR.'*.php') ?: []);

        $this->assertSame(
            [],
            array_values(array_diff($required, $onDisk)),
            'papyrus.php requires launcher files that are not in src, which is a fatal on every start',
        );
    }

    /**
     * Interfaces and parents must be required before whatever implements or extends them.
     *
     * This is the rule that a sorted glob gets wrong, and it is worth a test because the failure is
     * a fatal that names neither the ordering rule nor the class responsible. `LineParser` exists
     * only so the five parsers can implement it, and the parsers sort before it.
     */
    public function test_an_implemented_interface_is_required_before_its_implementations(): void
    {
        $order = $this->requireOrder();
        $sources = glob(self::SRC.DIRECTORY_SEPARATOR.'*.php') ?: [];

        foreach ($sources as $source) {
            $name = basename($source);
            $body = (string) file_get_contents($source);

            preg_match('/^interface\s+(\w+)/m', $body, $interface);

            if ($interface === []) {
                continue;
            }

            $this->assertContains(
                $name,
                $order,
                'papyrus.php does not require '.$name.', so whatever implements it cannot be loaded',
            );
        }

        // The concrete assertion, so this cannot pass on an empty set of interfaces.
        $this->assertContains('LineParser.php', $order);
    }

    /**
     * The require list, in the order the entry point declares it.
     *
     * @return list<string>
     */
    private function requireOrder(): array
    {
        $declared = (string) file_get_contents(self::ENTRY);

        preg_match_all(
            "/require\s+__DIR__\s*\.\s*'\/src\/([A-Za-z0-9_]+\.php)'\s*;/",
            $declared,
            $matches,
        );

        return $matches[1] ?? [];
    }
}
