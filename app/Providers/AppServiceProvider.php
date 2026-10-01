<?php

namespace App\Providers;

use App\Search\Deduplicator;
use App\Search\TitleNormalizer;
use App\Support\Deployment;
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
        $this->app->singleton(Timestamps::class);
        $this->app->singleton(Transport::class);
        $this->app->singleton(AuthManager::class);
        $this->app->singleton(Gateway::class);
        $this->app->singleton(Deployment::class);
        $this->app->singleton(TitleNormalizer::class);

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
