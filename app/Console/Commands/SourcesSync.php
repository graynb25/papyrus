<?php

namespace App\Console\Commands;

use App\Models\Source;
use App\Models\SourceHealth;
use App\Suwayomi\SourceCatalog;
use Illuminate\Console\Command;

class SourcesSync extends Command
{
    protected $signature = 'sources:sync
                            {--add-store= : Add an extension store by index URL before syncing}
                            {--refresh : Ask the server to re-read every store first}';

    protected $description = 'Mirror extension stores and the source catalogue from Suwayomi';

    public function handle(SourceCatalog $catalog): int
    {
        if ($url = $this->option('add-store')) {
            try {
                $store = $catalog->addStore($url);
                $format = $store->isProtobuf() ? 'protobuf' : 'legacy JSON';
                $key = $store->signing_key ? substr($store->signing_key, 0, 20).'…' : 'none published';

                $this->components->twoColumnDetail('Added store', "{$store->name} ({$format})");
                $this->components->twoColumnDetail('Signing key', $key);
            } catch (\Throwable $e) {
                $this->components->error("Could not add store: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        if ($this->option('refresh')) {
            $count = $catalog->refreshExtensions();
            $this->components->twoColumnDetail('Store refresh', "{$count} extensions available");
        }

        $result = $catalog->syncAll();

        $this->components->twoColumnDetail('Stores mirrored', (string) $result['stores']);
        $this->components->twoColumnDetail('Sources mirrored', (string) $result['sources']);

        $byLang = Source::query()
            ->installed()
            ->selectRaw('lang, count(*) as total')
            ->groupBy('lang')
            ->orderByDesc('total')
            ->limit(8)
            ->pluck('total', 'lang');

        if ($byLang->isNotEmpty()) {
            $this->line('');
            $this->components->twoColumnDetail(
                'By language',
                $byLang->map(fn ($n, $lang): string => "{$lang}={$n}")->implode(', '),
            );
        }

        $dead = SourceHealth::query()->where('is_dead', true)->count();
        $silent = SourceHealth::query()->where('consecutive_empty', '>=', 20)->count();

        if ($dead > 0 || $silent > 0) {
            $this->line('');
            $this->components->twoColumnDetail('Marked dead', (string) $dead);
            $this->components->twoColumnDetail('Returning nothing', (string) $silent);
        }

        $this->line('');
        $this->components->info('Sources are ready. Try: php artisan search:fanout "one piece"');

        return self::SUCCESS;
    }
}
