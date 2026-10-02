<?php

use App\AniList\OAuth;
use App\AniList\Tracking;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Connection settings')] class extends Component {
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
     * The row is deleted outright. AniList issues no refresh token, so there is
     * nothing stored here that could be revoked remotely anyway; what this ends
     * is this app's ability to act as the reader.
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
                        @if ($this->account->token_expires_at !== null)
                            {{ __('The token AniList issued expires :when, and there is no refresh: when it dies, connect again.', ['when' => $this->account->token_expires_at->format('M j, Y')]) }}
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
