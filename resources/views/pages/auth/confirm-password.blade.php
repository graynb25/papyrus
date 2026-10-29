<x-layouts::auth :title="__('Confirm password')">
    <div class="flex flex-col gap-6">
        <x-auth-header
            :title="__('Confirm password')"
            :description="__('For your security, Papyrus needs your password again before this change.')"
        />

        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify
            options-route="passkey.confirm-options"
            submit-route="passkey.confirm"
            :label="__('Confirm with passkey')"
            :loading-label="__('Confirming...')"
            :separator="__('Or confirm with password')"
        />

        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-6">
            @csrf

            <flux:input
                name="password"
                :label="__('Password')"
                type="password"
                required
                autocomplete="current-password"
                :placeholder="__('Password')"
                viewable
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="confirm-password-button">
                {{ __('Confirm') }}
            </flux:button>
        </form>

        <p class="text-center text-sm text-zinc-500 dark:text-zinc-400">
            <a
                href="{{ route('library') }}"
                wire:navigate
                class="font-medium text-vermilion-600 hover:text-vermilion-500 dark:text-vermilion-400 dark:hover:text-vermilion-300"
            >
                {{ __('Back to your library') }}
            </a>
        </p>
    </div>
</x-layouts::auth>
