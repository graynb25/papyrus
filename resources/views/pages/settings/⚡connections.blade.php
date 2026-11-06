<?php

use App\AniList\AniListRefusedException;
use App\AniList\AniListTokenRejected;
use App\AniList\OAuth;
use App\AniList\Tracking;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * The browser tab follows the selected language.
     *
     * `#[Title]` is a PHP attribute and cannot call `__()`.
     */
    public function render(): \Illuminate\View\View
    {
        return $this->view()->title(__('Connection settings'));
    }

    /**
     * Send this reader to AniList to approve the connection.
     *
     * A full redirect rather than anything in-page: the grant happens on
     * AniList's site, and coming back through the callback route is what makes
     * the token theirs. The state that travels with the URL is what the callback
     * checks the answer against.
     */
    public function connect(): mixed
    {
        $oauth = app(OAuth::class);

        if (! $oauth->configured()) {
            Flux::toast(
                text: __('No AniList client id and secret are configured on this server. See the deployment notes.'),
                heading: __('Tracking is not set up'),
                variant: 'danger',
            );

            return null;
        }

        $state = bin2hex(random_bytes(16));

        session()->put('anilist_oauth_state', $state);

        return $this->redirect($oauth->authorizeUrl($state), navigate: false);
    }

    /**
     * Forget the connection.
     *
     * The row is deleted outright. AniList publishes no token revocation endpoint,
     * so this app cannot invalidate the token on AniList's side; what this ends is
     * this app's ability to act as the reader, and the token itself lasts until its
     * own expiry.
     */
    public function disconnect(): void
    {
        app(Tracking::class)->disconnect(auth()->user());

        Flux::toast(
            text: __('Reading progress stays in this app. AniList stops being updated.'),
            heading: __('Disconnected'),
            variant: 'info',
        );
    }

    /**
     * Check whether the saved AniList token still works.
     */
    public function testConnection(): void
    {
        $account = $this->account;

        if ($account === null) {
            Flux::toast(
                text: __('There is no AniList connection to test.'),
                heading: __('No connection'),
                variant: 'info',
            );

            return;
        }

        try {
            $viewer = app(OAuth::class)->viewer($account->access_token);

            Flux::toast(
                text: __('AniList accepted the saved token for :name.', ['name' => $viewer['name'] !== '' ? $viewer['name'] : ($account->anilist_user_name ?? __('this account'))]),
                heading: __('Connection looks good'),
                variant: 'success',
            );
        } catch (AniListTokenRejected) {
            app(Tracking::class)->disconnect(auth()->user());

            Flux::toast(
                text: __('The saved token was rejected. Connect again to keep AniList synced.'),
                heading: __('Connection failed'),
                variant: 'danger',
            );
        } catch (AniListRefusedException) {
            Flux::toast(
                text: __('AniList did not answer the connection check. Try again in a moment.'),
                heading: __('Connection check failed'),
                variant: 'danger',
            );
        }
    }

    /**
     * Begin pulling AniList progress into the library.
     */
    public function startImport(): void
    {
        $started = app(\App\AniList\Import::class)->start(auth()->user());

        if (! $started) {
            Flux::toast(
                text: __('An import is already running, or there is no connection to import with.'),
                heading: __('Nothing started'),
                variant: 'info',
            );

            return;
        }

        Flux::toast(
            text: __('Progress arrives series by series. This page shows the tally as it goes.'),
            heading: __('Import started'),
            variant: 'success',
        );
    }

    /**
     * The import's tally, polled while it runs.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function importState(): ?array
    {
        return app(\App\AniList\Import::class)->state((int) auth()->id());
    }

    #[Computed]
    public function account(): ?\App\Models\AniListAccount
    {
        return app(Tracking::class)->accountFor(auth()->user());
    }

    #[Computed]
    public function configured(): bool
    {
        return app(OAuth::class)->configured();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Connection settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Connections')" :subheading="__('Track your reading on outside services')">
        <div class="my-6 w-full space-y-6">
            @if (session('anilist_connected') !== null)
                <flux:callout variant="success" icon="check-circle">
                    {{ __('Connected as :name.', ['name' => session('anilist_connected')]) }}
                </flux:callout>
            @endif

            @if (session('anilist_error') !== null)
                <flux:callout variant="danger" icon="exclamation-triangle">
                    {{ session('anilist_error') }}
                </flux:callout>
            @endif

            {{--
                AniList.

                The card says what connecting costs before it offers the button,
                because this is the one place in the app where reading activity
                leaves the machine on purpose: the progress number moves on
                AniList's servers, under the reader's own account.
            --}}
            <flux:card class="space-y-4 p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <flux:heading level="3">AniList</flux:heading>

                            @if ($this->account !== null)
                                <flux:badge size="sm" color="green" icon="check">
                                    {{ __('Connected as :name', ['name' => $this->account->anilist_user_name ?? __('unknown')]) }}
                                </flux:badge>
                            @endif
                        </div>

                        <p class="mt-1.5 max-w-md text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('When a chapter is finished, its number is written to your AniList list for that series. Chapters you mark read by hand are pushed the same way.') }}
                        </p>

                        <p class="mt-1.5 max-w-md text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('Connecting sends your reading progress to AniList under your account. Nothing is read back except your own list.') }}
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        @if ($this->account !== null)
                            <flux:button variant="outline" wire:click="testConnection">
                                {{ __('Test connection') }}
                            </flux:button>

                            <flux:button wire:click="disconnect">
                                {{ __('Disconnect') }}
                            </flux:button>
                        @else
                            <flux:button
                                variant="primary"
                                icon="arrow-top-right-on-square"
                                wire:click="connect"
                                :disabled="! $this->configured"
                            >
                                {{ __('Connect AniList') }}
                            </flux:button>
                        @endif
                    </div>
                </div>

                @if ($this->account !== null)
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('Disconnecting deletes the saved token here. AniList does not publish a way for this app to revoke it, so the token stays valid on AniList until it expires.') }}
                    </p>

                    @php($import = $this->importState)

                    {{--
                        The import, the other direction.

                        The limits are stated here rather than left to be
                        discovered: nothing is added to the library, nothing is
                        ever marked unread, and a series AniList does not know
                        is left alone.
                    --}}
                    <div class="space-y-3 border-t border-zinc-200 pt-4 dark:border-white/10">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="max-w-md">
                                <flux:heading level="3">{{ __('Import from AniList') }}</flux:heading>

                                <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ __('Pull the progress AniList already holds into the series in your library. It marks chapters read, never unread, and it never adds series.') }}
                                </p>
                            </div>

                            <flux:button
                                wire:click="startImport"
                                wire:loading.attr="disabled"
                                :disabled="$import !== null && $import['phase'] === 'running'"
                            >
                                {{ __('Import progress') }}
                            </flux:button>
                        </div>

                        @if ($import !== null)
                            <div @if ($import['phase'] === 'running') wire:poll.3s @endif>
                                @if ($import['phase'] === 'running')
                                    {{--
                                        Two states, not one.

                                        `total` is only assigned once the AniList list has
                                        come back, so while it reads 0 the pass has not
                                        worked out how much there is to do yet. Saying
                                        "Checking 0 of 0 series" in that window reports a
                                        total the code does not have, and a reader with a
                                        full library sees it on every single run. It is
                                        also exactly what a reader with an empty library
                                        sees forever, which is how an import that correctly
                                        does nothing comes to look broken.
                                    --}}
                                    <p class="text-sm text-vermilion-600 dark:text-vermilion-400" aria-live="polite">
                                        @if ($import['total'] === 0)
                                            {{ __('Reading your AniList list...') }}
                                        @else
                                            {{ __('Checking :processed of :total series...', ['processed' => $import['processed'], 'total' => $import['total']]) }}
                                        @endif
                                    </p>
                                @elseif ($import['phase'] === 'done')
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400" aria-live="polite">
                                        @if ($import['total'] === 0)
                                            {{ __('Nothing to import: your library holds no series. Import pulls AniList progress into series you already have, and it never adds series.') }}
                                        @else
                                            {{ __('Checked :total series: :marked updated, :chapters chapters marked read, :unmatched not on AniList.', [
                                                'total' => $import['total'],
                                                'marked' => $import['series_marked'],
                                                'chapters' => $import['chapters_marked'],
                                                'unmatched' => count($import['unmatched']),
                                            ]) }}
                                        @endif
                                    </p>
                                @elseif ($import['phase'] === 'failed')
                                    <p class="text-sm text-red-600 dark:text-red-400" aria-live="polite">
                                        {{ $import['error'] ?? __('The import did not finish.') }}
                                    </p>
                                @endif

                                @if ($import['unmatched'] !== [] && $import['phase'] !== 'running')
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ trans_choice(
                                                '{1} :count series had no AniList entry|[2,*] :count series had no AniList entry',
                                                count($import['unmatched']),
                                                ['count' => count($import['unmatched'])],
                                            ) }}
                                        </summary>

                                        <ul class="mt-1 space-y-0.5 text-xs text-zinc-400 dark:text-zinc-500">
                                            @foreach (array_slice($import['unmatched'], 0, 15) as $title)
                                                <li>{{ $title }}</li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </div>
                        @endif
                    </div>

                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        @if ($this->account->token_expires_at !== null)
                            {{ __('The token AniList issued expires :when, and there is no refresh: when it dies, connect again.', ['when' => $this->account->token_expires_at->isoFormat('LL')]) }}
                        @else
                            {{ __('AniList issued no expiry for this token. When it stops working, connect again.') }}
                        @endif
                    </p>
                @elseif (! $this->configured)
                    <div class="rounded-md bg-zinc-50 p-3 text-sm text-zinc-600 dark:bg-white/5 dark:text-zinc-300">
                        <p class="font-medium">{{ __('This server has no AniList application registered yet.') }}</p>
                        <p class="mt-1 text-xs leading-relaxed">
                            {{ __('Create one at anilist.co/settings/developer, set its redirect URL to the address below, then put the id and secret in the environment:') }}
                        </p>
                        <ul class="mt-2 space-y-1 font-mono text-xs">
                            <li>ANILIST_OAUTH_CLIENT_ID</li>
                            <li>ANILIST_OAUTH_CLIENT_SECRET</li>
                            <li class="break-all">{{ route('anilist.callback') }}</li>
                        </ul>
                    </div>
                @endif
            </flux:card>
        </div>
    </x-pages::settings.layout>
</section>
