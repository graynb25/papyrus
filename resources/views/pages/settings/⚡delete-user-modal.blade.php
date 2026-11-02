<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     *
     * The last account is kept rather than deleted. Deleting it leaves zero users, and
     * `Registration::isOpen()` treats zero users on a single-reader install as an unclaimed
     * install, so the sign-up form would silently reopen. On every install it would also
     * leave nobody holding the owner role. Refusing here is the rule that needs no second
     * warning screen and no way to close registration again afterwards.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        if (User::query()->count() === 1) {
            $this->addError('password', __(
                'This is the only account. Deleting it would leave this install with no owner '
                .'and let the sign-up form claim it again. Create another account first if you '
                .'really want to remove this one.'
            ));

            return;
        }

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form method="POST" wire:submit="deleteUser" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

            <flux:subheading>
                {{ __('This permanently removes this account and its saved settings and reading activity. It does not remove manga files stored by Papyrus. Enter your password to continue.') }}
            </flux:subheading>
        </div>

        <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
