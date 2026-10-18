<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A source, mirrored from Suwayomi.
 *
 * Global: which sources exist is a property of which extensions are installed
 * on the server. Per-user preferences (pinned, disabled, incognito, migration)
 * live in {@see UserSource}; observed health lives in {@see SourceHealth}.
 *
 * @property int $id
 * @property int $suwayomi_source_id
 * @property string $name
 * @property string $display_name
 * @property string $extension_pkg_name
 * @property string $lang
 * @property string $content_warning
 * @property string|null $home_url
 * @property string|null $icon_url
 * @property bool $supports_latest
 * @property bool $is_configurable
 * @property bool $is_installed
 * @property Carbon|null $last_synced_at
 */
#[Fillable([
    'suwayomi_source_id',
    'name',
    'display_name',
    'extension_pkg_name',
    'lang',
    'content_warning',
    'home_url',
    'icon_url',
    'supports_latest',
    'is_configurable',
    'is_installed',
    'last_synced_at',
])]
class Source extends Model
{
    public const SAFE = 'SAFE';

    public const MIXED = 'MIXED';

    public const NSFW = 'NSFW';

    /**
     * @return HasMany<UserSource, $this>
     */
    public function preferences(): HasMany
    {
        return $this->hasMany(UserSource::class, 'source_id', 'suwayomi_source_id');
    }

    /**
     * @return HasMany<SourceHealth, $this>
     */
    public function health(): HasMany
    {
        return $this->hasMany(SourceHealth::class, 'source_id', 'suwayomi_source_id');
    }

    /**
     * Every source of one site, when the site is named the way the health page
     * names it: by the raw `home_url` string, or the no home_url bucket.
     *
     * One rule for the health page's skip, incognito and pin toggles and its
     * drill-down, all of which key rows by the raw home_url string. The sources
     * page does not ride this: its cards key on the home_url's bare host, so
     * that page derives members through its own host rule instead, and a shared
     * name for two keyings would make one of them quietly wrong.
     *
     * The bucket has two spellings because each page built its own before the
     * rule was shared: the health page keys it `unknown`, the sources page
     * keys it `local`, and both mean the same rows, a source that publishes
     * no home_url. Only the health spelling reaches this scope.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOfSite(Builder $query, string $site): Builder
    {
        if ($site === 'unknown' || $site === 'local') {
            return $query->where(fn ($w) => $w->whereNull('home_url')->orWhere('home_url', ''));
        }

        return $query->where('home_url', $site);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeInstalled(Builder $query): Builder
    {
        return $query->where('is_installed', true);
    }

    /**
     * Sources that will actually run something when asked: installed on the
     * mirror, not switched off by this reader, and not dead in the ledger.
     *
     * There is one definition of "installed and will run", because two surfaces
     * schedule searches and they must not walk different rows: the search fanout,
     * and the migration sweep. The fanout has skipped both kinds by default since
     * long before this existed, for measured reasons that hold anywhere the costs
     * repeat: a disabled source is a reader decision, and a dead one spends its
     * entire timeout answering nothing on every run. Dead sources can be asked
     * for explicitly by name, and that ask keeps its escape hatch here.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRunnableFor(Builder $query, int $readerId, bool $includeDead = false): Builder
    {
        $disabled = UserSource::forUser($readerId)
            ->where('disabled', true)
            ->pluck('source_id')
            ->all();

        $dead = $includeDead
            ? []
            : SourceHealth::query()
                ->where('is_dead', true)
                ->pluck('source_id')
                ->all();

        return $query
            ->installed()
            ->when(
                $disabled !== [],
                fn (Builder $q): Builder => $q->whereNotIn('suwayomi_source_id', $disabled),
            )
            ->when(
                $dead !== [],
                fn (Builder $q): Builder => $q->whereNotIn('suwayomi_source_id', $dead),
            );
    }

    /**
     * Filter by allowed content ratings.
     *
     * @param  Builder<static>  $query
     * @param  array<int, string>  $warnings
     * @return Builder<static>
     */
    public function scopeAllowingContent(Builder $query, array $warnings): Builder
    {
        return $query->whereIn('content_warning', $warnings);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where('name', 'like', '%'.$term.'%');
    }

    protected function casts(): array
    {
        return [
            'supports_latest' => 'boolean',
            'is_configurable' => 'boolean',
            'is_installed' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Human-facing label. Falls back to the internal name because some
     * extensions register a source with an empty display name.
     */
    public function label(): string
    {
        return $this->display_name !== '' ? $this->display_name : $this->name;
    }
}
