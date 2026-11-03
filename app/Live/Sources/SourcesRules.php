<?php

namespace App\Live\Sources;

use App\Suwayomi\ExtensionManager;
use Illuminate\Support\Collection;

/**
 * The sources page's per-row and selection rules, kept out of the component.
 *
 * The catalogue row is not evidence: the list predates the press, so every
 * install, update and bulk action re-reads the row and this reader's trust
 * before it acts. Taking the extension collection, the trusted ids and the
 * selected or pending names as arguments makes those decisions direct to test.
 */
final class SourcesRules
{
    public function __construct(
        private readonly ExtensionManager $extensions,
        private readonly SourcesFormatting $formatting,
    ) {}

    /**
     * Whether this extension's publisher has been trusted by this user.
     *
     * Used for the lock icon. The refusal itself is enforced in the action, so
     * hiding the control is presentation rather than the security boundary.
     *
     * @param  array<string, mixed>  $extension
     * @param  array<int, int>  $trustedIds
     */
    public function isTrusted(array $extension, array $trustedIds): bool
    {
        $store = $this->extensions->storeFor($extension);

        return $store !== null && in_array($store->id, $trustedIds, true);
    }

    /**
     * Whether this extension can be installed from here.
     *
     * @param  Collection<int, array<string, mixed>>  $extensions
     * @param  array<int, int>  $trustedIds
     */
    public function canInstallName(string $pkgName, Collection $extensions, array $trustedIds): bool
    {
        $row = $this->rowFor($pkgName, $extensions);

        return $row !== null
            && ($row['isInstalled'] ?? false) !== true
            && $this->isTrusted($row, $trustedIds);
    }

    /**
     * Whether this installed extension can be updated from here.
     *
     * Same three facts the row was rendered from, re-checked in the action: the
     * list predates the click, so the badge is not evidence.
     *
     * @param  Collection<int, array<string, mixed>>  $extensions
     * @param  array<int, int>  $trustedIds
     */
    public function canUpdateName(string $pkgName, Collection $extensions, array $trustedIds): bool
    {
        $row = $this->rowFor($pkgName, $extensions);

        return $row !== null
            && ($row['isInstalled'] ?? false) === true
            && ($row['hasUpdate'] ?? false) === true
            && $this->isTrusted($row, $trustedIds);
    }

    /**
     * The catalogue row for one package name, or null when the list predates it.
     *
     * Every install and update guard reads the row again for the same reason this
     * page re-checks trust: the rendered list is not evidence.
     *
     * @param  Collection<int, array<string, mixed>>  $extensions
     * @return array<string, mixed>|null
     */
    public function rowFor(string $pkgName, Collection $extensions): ?array
    {
        $row = $extensions->firstWhere('pkgName', $pkgName);

        return is_array($row) ? $row : null;
    }

    /**
     * The pressed names whose site the notice list covers, in the pressed order.
     *
     * @param  array<int, string>  $names
     * @param  Collection<int, array<string, mixed>>  $extensions
     * @return array<int, string>
     */
    public function flaggedAmong(array $names, Collection $extensions): array
    {
        return array_values(array_filter(
            $names,
            fn (string $pkgName): bool => $this->formatting->noticeFor($this->rowFor($pkgName, $extensions) ?? []) !== null,
        ));
    }

    /**
     * One row per name the warning asks about, read from the list at render time
     * rather than captured at ask time, so the dialog states what the config says
     * now rather than what it said when the press dispatched.
     *
     * Names left out by a catalogue refresh between ask and render are carried by
     * the pages' pending state and installed on confirm, but they have nothing to
     * warn about once their notice is gone.
     *
     * @param  array<int, string>  $pendingInstall
     * @param  Collection<int, array<string, mixed>>  $extensions
     * @return array<int, array<string, string>>
     */
    public function warningRows(array $pendingInstall, Collection $extensions): array
    {
        $rows = [];

        foreach ($pendingInstall as $pkgName) {
            $row = $this->rowFor($pkgName, $extensions);

            if ($row === null) {
                continue;
            }

            $notice = $this->formatting->noticeFor($row);

            if ($notice === null) {
                continue;
            }

            $rows[] = [
                'name' => (string) $row['name'],
                'icon' => $notice['icon'],
                'text' => $notice['text'],
            ];
        }

        return $rows;
    }

    /**
     * Trust is re-checked here, not just for the button. The list was rendered
     * before the user changed their mind, so the tick is not evidence.
     *
     * @param  array<int, string>  $selected
     * @param  Collection<int, array<string, mixed>>  $extensions
     * @param  array<int, int>  $trustedIds
     * @return array<int, string>
     */
    public function installableFromSelection(array $selected, Collection $extensions, array $trustedIds): array
    {
        $available = $extensions
            ->filter(fn (array $row): bool => ($row['isInstalled'] ?? false) !== true && $this->isTrusted($row, $trustedIds))
            ->map(fn (array $row): string => (string) $row['pkgName'])
            ->values()
            ->all();

        return array_values(array_intersect($selected, $available));
    }
}
