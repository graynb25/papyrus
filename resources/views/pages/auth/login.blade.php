@php
    /*
     * Both answers this page needs, resolved once and above the form, because the sign-up link at the
     * bottom and the pre-filled address in the middle are the same question asked twice and must not
     * be able to disagree.
     *
     * The remembered address is read only while registration is open, which is the only state in
     * which it was ever written. A record read after the account exists could be stale: wipe the
     * database and it would offer an address that no longer belongs to anybody.
     */
    $registration = app(App\Support\Registration::class);
    $rememberedEmail = $registration->isOpen()
        ? app(App\Support\LocalReaderAccount::class)->email()
        : null;
@endphp

<x-layouts::auth.reading-room :title="__('Log in')">
    {{--
        A failed sign-in shakes the card. The animation runs once on the
        wrapper and settles at identity, so the form is never left tilted.
    --}}
    <div class="@if($errors->any()) animate-shake @endif flex flex-col gap-6">
        <x-auth-header :title="__('Log in to your account')" :description="__('Enter your email and password below to log in')" />

        <!-- Session Status -->
        <x-auth-session-status class="text-center" :status="session('status')" />

        <x-passkey-verify />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-6">
            @csrf

            <!-- Email Address -->
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email', $rememberedEmail)"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            <!-- Password -->
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                @if (Route::has('password.request'))
                    <flux:link class="absolute top-0 text-sm end-0" :href="route('password.request')" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            <!-- Remember Me -->
            <flux:checkbox name="remember" :label="__('Remember me')" :checked="old('remember')" />

            <div class="flex items-center justify-end">
                <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        {{--
            The sign-up link only while there is somebody who is allowed to sign up.

            It was unconditional, so a single-user install pointed at an endpoint that
            `RegistrationMustBeAllowed` answers with a 404. Which of the two was wrong is
            worth stating: the mode was the documented behaviour and the link was the bug,
            so the link goes rather than the rule.

            The question is now `Registration::isOpen()` rather than the deployment mode alone,
            because a `single` install opens sign-up until its first account exists. The same
            object answers for the middleware, so the link and the endpoint cannot disagree,
            which is lesson 227: two implementations of one question, disagreeing silently.
        --}}
        @if ($registration->isOpen())
            <div class="space-x-1 text-sm text-center rtl:space-x-reverse text-zinc-600 dark:text-zinc-400">
                <span>{{ __('Don\'t have an account?') }}</span>
                <flux:link :href="route('register')" wire:navigate>{{ __('Sign up') }}</flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth>
