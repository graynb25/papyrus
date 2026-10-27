<?php

namespace App\Providers;

use App\Backup\BackupLibrary;
use App\Import\BackupReaders;
use App\Import\Tachibk\TachibkReader;
use App\Import\Tachimanga\TachimangaReader;
use App\Models\User;
use App\Search\Deduplicator;
use App\Search\TitleNormalizer;
use App\Support\Deployment;
use App\Support\Library;
use App\Support\LocalReaderAccount;
use App\Support\Suwayomi\Timestamps;
use App\Support\UploadLimit;
use App\Suwayomi\AuthManager;
use App\Suwayomi\Gateway;
use App\Suwayomi\StoreHostResolver;
use App\Suwayomi\SystemStoreHostResolver;
use App\Suwayomi\Transport;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use RuntimeException;

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

        /*
         * The backups folder, resolved once per request.
         *
         * Bound rather than constructed at the call site, because a page that news up the folder itself
         * cannot be tested: the test would point at the checkout's own directory, which nothing writes
         * to, and every assertion on the page would be vacuously true against an empty folder.
         *
         * This is the same mistake the `Library` binding above exists to fix, and it is worth naming:
         * the object knows where things are, so the temptation is for the caller to ask it directly,
         * which puts the knowledge back in two places.
         */
        $this->app->scoped(BackupLibrary::class, static fn (): BackupLibrary => BackupLibrary::forThisInstall());

        $this->app->singleton(Timestamps::class);
        $this->app->singleton(Transport::class);
        $this->app->singleton(AuthManager::class);
        $this->app->singleton(Gateway::class);
        $this->app->singleton(StoreHostResolver::class, SystemStoreHostResolver::class);
        $this->app->singleton(Deployment::class);
        $this->app->singleton(TitleNormalizer::class);

        /*
         * The cache lock provider, bound to the resolved store.
         *
         * ## Why it cannot simply be `cache`
         *
         * Because `CacheManager` implements the `Factory` contract and not `LockProvider`. Its
         * `lock()` works anyway, through `__call` forwarding to the driver, which is the dangerous
         * shape: the call succeeds at runtime and the type system says nothing is there. Depending on
         * it would work in every test and in production, and would mean this container entry was a
         * claim rather than a fact.
         *
         * So it resolves the driver behind the repository and checks it, because "the store can take
         * locks" is the only property `Registration` actually depends on. A cache driver configured
         * to something that cannot lock fails here with a sentence saying so, rather than producing
         * an install where two simultaneous sign-ups are both allowed through.
         */
        $this->app->bind(LockProvider::class, function ($app): LockProvider {
            $store = $app->make('cache')->driver()->getStore();

            if (! $store instanceof LockProvider) {
                throw new RuntimeException(sprintf(
                    'The cache store [%s] cannot take locks, so first-run registration cannot be '
                        .'made safe. Configure a store that implements %s.',
                    $store::class,
                    LockProvider::class,
                ));
            }

            return $store;
        });

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
            $app->make(TachibkReader::class),
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
        $this->allowTemporaryUploadsToTheServerCeiling();
        $this->rememberTheLocalReader();
        $this->defineInstallOwnerGate();
    }

    /**
     * Let Livewire receive a file as large as PHP itself will take.
     *
     * ## Why the framework default is the wrong ceiling here
     *
     * Livewire validates every temporary upload against `max:12288` kilobytes unless the
     * application names rules, and that is 12MB. A measured real backup is 41MB, so a
     * file the import page would accept was refused by the upload endpoint before the
     * page's own check ever saw it. The page reports the server's ceiling and the
     * endpoint that receives the file has to enforce the same number, or the two
     * disagree in the one place a reader can reach.
     *
     * ## Why an unlimited server drops the rule rather than capping at zero
     *
     * `post_max_size = 0` or `upload_max_filesize = 0` means no limit, which
     * {@see UploadLimit::bytes()} reports as zero. A `max:0` rule would read as "nothing
     * may be uploaded", the opposite. Without a max rule PHP's own ini still decides
     * what arrives, which is what "no limit" was asking for.
     */
    private function allowTemporaryUploadsToTheServerCeiling(): void
    {
        $ceiling = UploadLimit::bytes();
        $rules = ['required', 'file'];

        if ($ceiling > 0) {
            $rules[] = 'max:'.intdiv($ceiling, 1024);
        }

        config(['livewire.temporary_file_upload.rules' => $rules]);
    }

    /**
     * Record the first account as this install's local reader.
     *
     * ## Why this hangs off the event rather than off the sign-up action
     *
     * Because the action is the wrong seam. `CreateNewUser` is also used by the console command and
     * by anything else that makes an account, and the thing worth remembering is "this install now
     * has its reader", which is true whichever way the account arrived. Listening to the event means
     * a reader who signs themselves up in the browser gets the same treatment as one created by
     * `users:create`, without either path knowing the other exists.
     *
     * ## Why the handler writes the address and nothing else
     *
     * See `LocalReaderAccount`. The short version is that the password is hashed in the database, so
     * anything that could sign the reader in unattended would have to keep the plaintext on disk,
     * which is the leak `users:create` refuses on purpose.
     */
    private function rememberTheLocalReader(): void
    {
        Event::listen(Registered::class, function (Registered $event): void {
            /*
             * The event carries an `Authenticatable`, not a `User`, and the address is what is being
             * recorded. Anything that is not one of this app's user models has no address to record,
             * so it is passed over rather than assumed to.
             */
            if ($event->user instanceof User) {
                app(LocalReaderAccount::class)->remember($event->user->email);
            }
        });
    }

    /**
     * Define the abilities that belong to the install rather than to one reader.
     *
     * One definition rather than a role comparison at each call site, because the whole
     * point of the gate is that every screen asks the same question. `manage-install`
     * names the install-wide actions; it does not make the account an administrator of
     * anything outside this database.
     */
    private function defineInstallOwnerGate(): void
    {
        Gate::define('manage-install', fn (User $user): bool => $user->isInstallOwner());
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

        /*
         * The margins, stated once as data and consumed from that one place. `Password::defaults()`
         * builds the validating rule from them, and the register page's margin meter lists them from
         * them, so the browser can never know a different password law than the server enforces.
         *
         * One posture in every environment, because a meter that shows the reader what the app
         * enforced "depending on APP_ENV" gave the checkout a floor the installs do not have; the
         * meter has to show what the server asks, whatever environment it renders in.
         *
         * The breach check is the one margin a browser cannot run: it phones the compromise
         * database over the network, so it stays a server-only step. `breach_on_submit` carries
         * that fact to the meter, which renders it as a pending line rather than faking a tick,
         * and stays off here so neither the suite nor a dev machine owes the call.
         */
        config(['papyrus.password_margins' => [
            'min' => 12,
            'mixedCase' => true,
            'letters' => true,
            'numbers' => true,
            'symbols' => true,
            'breach_on_submit' => app()->isProduction(),
        ]]);

        Password::defaults(function (): Password {
            $rule = Password::min((int) config('papyrus.password_margins.min'))
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols();

            if ((bool) config('papyrus.password_margins.breach_on_submit')) {
                $rule->uncompromised();
            }

            return $rule;
        });
    }
}
