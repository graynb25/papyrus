<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Creates an account without going through the browser.
 *
 * ## Why this had to exist
 *
 * `single` mode is the default and it disables registration, which is correct: it is a
 * one-reader install and an open sign-up form is a hole in it. But that left a fresh
 * install with no way to make its first account at all, because the only route to one was
 * the form the mode just closed.
 *
 * The gap was found the way these usually are: someone followed the setup instructions,
 * opened the site, and tried to log in with the Suwayomi username from `.env`. That account
 * does not exist here, and the mode that would have let them create one is closed by
 * design. The answer has to be a command, and the docs have to say which of the two
 * credentials is which.
 *
 * ## Why the password is generated and printed once
 *
 * Accepting `--password` on a command line puts it in the shell history and in the process
 * list, where anything on the box can read it. The password is generated instead and shown
 * exactly once, which is also the only way a reader can be forced onto something unique.
 *
 * `--password` still exists, for an unattended install, and it says so on stderr when used.
 */
class UsersCreate extends Command
{
    protected $signature = 'users:create
                            {--email= : The account address. Required.}
                            {--name= : Display name. Defaults to the part of the address before the @.}
                            {--password= : Use this instead of a generated one. Visible in shell history.}
                            {--admin : Create as an administrator.}';

    protected $description = 'Create a reader account without the browser, for a single-user install';

    public function handle(): int
    {
        $email = trim((string) $this->option('email'));

        if ($email === '') {
            $this->components->error('An --email is required.');

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->components->error("That is not an email address: {$email}");

            return self::FAILURE;
        }

        if (User::query()->where('email', $email)->exists()) {
            $this->components->error("An account already exists for {$email}.");

            return self::FAILURE;
        }

        /*
         * The same validator Fortify applies to the form, so an account made here cannot
         * be one the form would have refused. A command that accepts a password the app
         * would reject is a way to lock yourself out of your own install.
         */
        $password = (string) ($this->option('password') ?: Str::password(16, symbols: false));

        $validator = validator(
            ['password' => $password],
            ['password' => ['required', 'string', Password::min(8)->uncompromised()]],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        if ($this->option('password')) {
            $this->components->warn('That password was passed on the command line, so it is in your shell history.');
        }

        $name = trim((string) $this->option('name'));

        if ($name === '') {
            // The local part is a better default than the whole address, which wraps.
            $name = Str::before($email, '@');
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        /*
         * `role` and `status` are deliberately absent from the model's fillable list, so
         * passing them to create() is silently dropped and the column default applies.
         * That is the right posture for a web request, where nobody should be able to
         * hand themselves an admin role by posting a field. A console command is the one
         * place where setting it deliberately is the whole point, so it is written here
         * rather than by widening the model's fillable list for everyone.
         *
         * `password` goes in unhashed because the model casts it with 'hashed', so
         * hashing it here as well would double-hash it.
         */
        $user->forceFill([
            'role' => $this->option('admin') ? 'admin' : 'user',
            'status' => 'active',
            'email_verified_at' => now(),
        ])->save();

        $this->components->twoColumnDetail('Created', $user->email);
        $this->components->twoColumnDetail('Name', $user->name);
        $this->components->twoColumnDetail('Role', (string) $user->role);

        if (! $this->option('password')) {
            $this->line('');
            $this->line('  <fg=yellow>Password, shown once:</> <options=bold>'.$password.'</>');
            $this->line('  <fg=gray>Store it somewhere now. It is not recoverable from here.</>');
        }

        /*
         * No message follows.
         *
         * The address on a command line is invented as often as it is real, and
         * the password this command just printed is the only thing the reader
         * needs to sign in. A welcome note would be mail to somebody who may not
         * exist. The account is marked verified above for the same reason: there
         * is no address to prove control of, and no reader waiting on a link.
         */
        return self::SUCCESS;
    }
}
