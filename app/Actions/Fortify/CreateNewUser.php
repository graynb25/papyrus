<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Support\Deployment;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        /*
         * Verify the account immediately on a single-reader install, and only there.
         *
         * ## Why this exists
         *
         * Because without it, signing up through the browser produced an account that could not use
         * the application. Measured, not reasoned: the POST succeeded, the reader was authenticated,
         * and then every route redirected to `/email/verify`. The library, the dashboard, every
         * settings page except the two deliberately exempt ones. A signed-in reader who sees nothing
         * but a "please verify" page on an app with no mail transport is a reader who cannot read
         * anything at all, and the app never says why.
         *
         * ## Why verification is meaningless here and genuinely meaningful elsewhere
         *
         * Because verification exists to prove you control an address that some other party might be
         * able to reach you at. On a loopback install there is no other party: nobody else can sign up
         * (`Registration` closes the form behind the first account), the server is reachable only from
         * this machine, and `MAIL_MAILER` ships defaulting to `log`, so the message would be written to
         * a file nobody reads. There is nothing to verify against and no way to complete the check.
         *
         * In `shared` and `public` that reasoning inverts. Those installs are reachable by people the
         * reader has never met, and an address nobody controls is exactly what you do not want on
         * your instance. So those accounts stay unverified and the mail goes out for real.
         *
         * ## Why this matches `users:create` rather than inventing a rule
         *
         * Because that command already does this, and its comment says why: the address has just been
         * invented, an unverified account cannot sign in, and this box may have no mail transport at
         * all. Two ways of making the same account had disagreed about whether it worked, and the one
         * the reader is steered to by a link on the login page was the broken one.
         *
         * `role` and `status` stay absent from the create call above, deliberately, so a web request
         * cannot hand itself an administrator role by posting a field. `email_verified_at` is set here
         * with the same care for the same reason, and only under a condition no request can choose.
         *
         * ## The caveat this creates, stated rather than hidden
         *
         * An account auto verified in `single` mode was never proven to control its address. Promoting
         * that install to `shared` or `public` later inherits that assumption. It is the right default
         * for the deployment it was made in, and the promotion is a deliberate act by somebody who
         * knows what the install was, but it is a real consequence and it belongs in the docs rather
         * than in a comment nobody reads.
         */
        if (app(Deployment::class)->isSingle()) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        return $user;
    }
}
