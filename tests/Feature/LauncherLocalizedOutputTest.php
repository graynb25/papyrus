<?php

namespace Tests\Feature;

use LogicException;
use Papyrus\Launcher\InstallControl;
use Papyrus\Launcher\InstallFacts;
use Papyrus\Launcher\RestoreCommand;
use Papyrus\Launcher\SnapshotCommand;
use Papyrus\Launcher\Translator;
use Tests\TestCase;

/**
 * A command a reader runs under a non-English locale.
 *
 * These use the help and usage paths, which return before anything is started. No process, port or
 * data directory is touched, which is the point: the translation layer must be testable without a
 * live install.
 */
final class LauncherLocalizedOutputTest extends TestCase
{
    public function test_the_restore_usage_is_spanish_from_papyrus_locale(): void
    {
        $this->withLocale('es-ES', function (): void {
            $lines = [];

            $command = new RestoreCommand(
                $this->controlThatMustNotRun(),
                sys_get_temp_dir(),
                sys_get_temp_dir().DIRECTORY_SEPARATOR.'database.sqlite',
                translator: Translator::fromEnvironment(),
            );

            $code = $command->run([], static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

            $output = implode("\n", $lines);

            $this->assertSame(1, $code);
            $this->assertStringContainsString('papyrus restore <backup.json>', $output);
            $this->assertStringContainsString('Devuelve ambas bases de datos', $output);
            $this->assertStringContainsString('No preguntar primero', $output);
        });
    }

    public function test_the_snapshot_usage_is_brazilian_portuguese_from_a_regional_tag(): void
    {
        $this->withLocale('pt-BR', function (): void {
            $lines = [];

            $command = new SnapshotCommand(
                $this->controlThatMustNotRun(),
                $this->factsThatMustNotRun(),
                sys_get_temp_dir(),
                sys_get_temp_dir().DIRECTORY_SEPARATOR.'database.sqlite',
                translator: Translator::fromEnvironment(),
            );

            $code = $command->run(['--help'], static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

            $output = implode("\n", $lines);

            $this->assertSame(0, $code);
            $this->assertStringContainsString('Pega os dois bancos de dados como um par', $output);
            $this->assertStringContainsString('Onde gravar', $output);
        });
    }

    public function test_the_snapshot_usage_is_french(): void
    {
        $this->withLocale('fr-FR', function (): void {
            $lines = [];

            $command = new SnapshotCommand(
                $this->controlThatMustNotRun(),
                $this->factsThatMustNotRun(),
                sys_get_temp_dir(),
                sys_get_temp_dir().DIRECTORY_SEPARATOR.'database.sqlite',
                translator: Translator::fromEnvironment(),
            );

            $code = $command->run(['--help'], static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

            $output = implode("\n", $lines);

            $this->assertSame(0, $code);
            $this->assertStringContainsString('Prend les deux bases de données comme une paire', $output);
            $this->assertStringContainsString('Ne pas demander', $output);
        });
    }

    /**
     * @param  callable(): void  $body
     */
    private function withLocale(string $locale, callable $body): void
    {
        $previous = getenv('PAPYRUS_LOCALE');

        try {
            putenv('PAPYRUS_LOCALE='.$locale);
            $body();
        } finally {
            if (is_string($previous)) {
                putenv('PAPYRUS_LOCALE='.$previous);
            } else {
                putenv('PAPYRUS_LOCALE');
            }
        }
    }

    private function controlThatMustNotRun(): InstallControl
    {
        return new class implements InstallControl
        {
            public function stop(callable $say): array
            {
                throw new LogicException('a usage path must not stop anything');
            }

            public function start(callable $say): array
            {
                throw new LogicException('a usage path must not start anything');
            }
        };
    }

    private function factsThatMustNotRun(): InstallFacts
    {
        return new class implements InstallFacts
        {
            public function supervisorPid(): ?int
            {
                throw new LogicException('a usage path must not ask for a supervisor');
            }

            public function suwayomiPids(): array
            {
                throw new LogicException('a usage path must not ask for Suwayomi');
            }

            public function suwayomiVersion(): string
            {
                throw new LogicException('a usage path must not ask for a version');
            }

            public function phpExecutable(): string
            {
                throw new LogicException('a usage path must not ask for PHP');
            }

            public function iniPath(): string
            {
                throw new LogicException('a usage path must not ask for an ini');
            }
        };
    }
}
