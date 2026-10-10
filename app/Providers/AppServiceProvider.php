<?php

namespace App\Providers;

use App\Import\BackupReaders;
use App\Import\Tachimanga\TachimangaReader;
use App\Search\Deduplicator;
use App\Search\TitleNormalizer;
use App\Support\Deployment;
use App\Support\Library;
use App\Support\Suwayomi\Timestamps;
use App\Suwayomi\AuthManager;
use App\Suwayomi\Gateway;
use App\Suwayomi\Transport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Scoped, not singleton, so it is shared within one request and fresh for the next,
         * which is what a class carrying request state wants.
         *
         * Auto resolution handed out a new instance per `app()` call, so the failure the
         * fetch recorded was invisible to whoever asked about it afterwards. Measured on the
         * library page: the fetch knew why it returned nothing and the view could not see it.
         */
        $this->app->scoped(Library::class);

        $this->app->singleton(Timestamps::class);
        $this->app->singleton(Transport::class);
        $this->app->singleton(AuthManager::class);
        $this->app->singleton(Gateway::class);
        $this->app->singleton(Deployment::class);
        $this->app->singleton(TitleNormalizer::class);

        /*
         * The one place the supported backup formats are listed.
         *
         * Both the inspect command and the importer take a list of readers, so a second
         * format has to be registered here once rather than remembered in two places. The
         * order is the order they are asked in, and `recognises()` sniffs magic bytes, so a
         * file two readers both claim goes to the first. Nothing claims two files today.
         */
        $this->app->singleton(BackupReaders::class, fn ($app): BackupReaders => new BackupReaders([
            $app->make(TachimangaReader::class),
        ]));

        // The fuzzy-merge threshold is a primitive, so the container cannot
        // infer it. Binding it here keeps the value in config, where it can be
        // tuned against the measured similarities documented there.
        $this->app->singleton(Deduplicator::class, fn ($app): Deduplicator => new Deduplicator(
            $app->make(TitleNormalizer::class),
            (float) config('papyrus.search.dedupe_fuzzy_threshold', 0.95),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
