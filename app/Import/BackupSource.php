<?php

namespace App\Import;

/**
 * One source a backup named, with the package that identifies it.
 *
 * ## Why this travels with the series rather than being discovered later
 *
 * An import cannot ask Suwayomi "what sources should I have?" and get an answer. What it
 * needs is the list the *backup* names, each with the package that identifies it, so it can
 * compare that against what this server has and install the difference. The chain through
 * the backup's own database is:
 *
 * ```
 * Manga.source        -> Source.id
 * Source.extension    -> Extension.id      (a number, not a package name)
 * Extension.pkg_name  -> eu.kanade.tachiyomi.extension.en.thunderscans
 * Extension.is_obsolete
 * ```
 *
 * Two of those three lookups were guessed wrong before they were measured: `Source.extension`
 * looks like it should hold the package and holds a foreign key, and `Extension` has no
 * `pkg_name` unless you look for it.
 *
 * ## Obsolete and not-installed are different facts
 *
 * {@see $obsolete} means the reader's own app had already marked the extension obsolete,
 * which is how they knew the source had gone. Measured on a real backup: 1,277 series across
 * 56 sources, 11 of them absent from the configured repo, and every one of those 11 that still
 * had an extension row carried `is_obsolete = 1`. Those cannot be brought back by anything,
 * so conflating them with "not installed here" would tell a reader to give up on something
 * they could have installed with one click.
 */
final class BackupSource
{
    /**
     * @param  int  $sourceId  A source id shared with Suwayomi, and past 2^53.
     * @param  string|null  $package  What Suwayomi identifies an extension by, from Extension.pkg_name.
     * @param  int  $seriesCount  How many of this reader's shelf sit on it.
     * @param  bool  $obsolete  The reader's own app had marked it obsolete.
     * @param  string|null  $versionName  The version the reader had, for the report.
     */
    public function __construct(
        public readonly int $sourceId,
        public readonly ?string $package,
        public readonly int $seriesCount,
        public readonly bool $obsolete = false,
        public readonly ?string $name = null,
        public readonly ?string $versionName = null,
    ) {}

    /**
     * Whether this server could be asked about the source at all.
     *
     * Obsolete means no, and that is a fact about the reader's source rather than about this
     * server's configuration.
     */
    public function isRecoverable(): bool
    {
        return ! $this->obsolete;
    }

    /**
     * A sentence for a report, naming what the reader can do.
     */
    public function summary(): string
    {
        $label = $this->name ?? 'This source';
        $count = number_format($this->seriesCount);

        if ($this->obsolete) {
            return sprintf('%s no longer exists. %s of your series were on it.', $label, $count);
        }

        if (! $this->isInstalled) {
            return sprintf('%s was not installed here. %s of your series were on it.', $label, $count);
        }

        return sprintf('%s: %s of your series.', $label, $count);
    }

    /**
     * Filled in by the installer once it has asked the server, rather than guessed at.
     *
     * Not a constructor argument, because "is it installed" is a fact about this server at
     * this moment and not a property of the backup.
     */
    public bool $isInstalled = false;
}
