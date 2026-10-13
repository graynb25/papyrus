<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Remembers which account is this install's local reader, in the install's own `.env`.
 *
 * ## What this stores, and what it deliberately does not
 *
 * The address, and nothing else. Not the password, not a token, not a hash.
 *
 * The password is refused rather than declined because the alternative was considered and is a real
 * security regression. Laravel stores it as a hash, so anything that could sign the reader in
 * automatically would have to keep the **plaintext** password on disk, readable by anything running
 * as that user, forever, unrotatable. `users:create` goes out of its way to avoid exactly this: it
 * generates a password and prints it once, and warns on stderr when `--password` is used at all,
 * because a command line leaks into shell history and the process list. Writing the plaintext into a
 * file the launcher reads on every start is that mistake with more reach.
 *
 * So this is a convenience record, and the difference matters. What it buys is that the login form
 * already knows your address and the launcher can tell you who the install is for, so the one thing
 * nobody has to retype is the one thing nobody should have to remember.
 *
 * ## Why it is read only while nobody has registered
 *
 * Because that is the only state where it is written. A record that outlives the thing it describes
 * is a record that can be wrong: wipe the database, re-run setup, and a stale address in `.env`
 * would prefill a form for an account that no longer exists. So the login page consults this only
 * while registration is open, which is to say exactly when there is no account to contradict.
 *
 * The database is the authority on who exists. This is a note about who this install is for.
 */
final class LocalReaderAccount
{
    public const KEY = 'PAPYRUS_LOCAL_READER';

    public function __construct(private readonly EnvironmentFile $environment) {}

    /**
     * The remembered address, or null when there is nothing remembered.
     */
    public function email(): ?string
    {
        $value = trim((string) config('papyrus.local_reader', ''));

        return $value === '' ? null : $value;
    }

    /**
     * Record the address. Returns whether anything was written.
     *
     * Never throws. This runs in the tail of a registration request, and the account is already
     * created and already signed in by this point: failing the request because a convenience note
     * could not be written would take away a working account over a file permission, and the reader
     * would be left with a login that refuses for a reason they cannot act on.
     */
    public function remember(string $email): bool
    {
        $email = trim($email);

        if ($email === '') {
            return false;
        }

        /*
         * Update what this request sees, before attempting the write.
         *
         * Config was resolved when the application booted, so writing the file changes nothing for
         * the code running right now. Leaving it stale is how a feature that just recorded something
         * then reports that it never recorded anything, and the file and the running process
         * disagreeing about the same fact is exactly the confusing shape worth avoiding.
         *
         * This is safe to do before knowing whether the write lands: the account exists either way,
         * because this runs from the event that has already created it.
         */
        Config::set('papyrus.local_reader', $email);

        try {
            /*
             * A checkout's `.env` belongs to whoever is developing, so it is never written. Only the
             * file the launcher named as this install's own is, which is also the only one where this
             * note means anything: the install's data directory is what survives a reinstall of the
             * code, and a note written beside the source disappears with it.
             */
            if (! $this->environment->isPackaged() || ! $this->environment->exists()) {
                return false;
            }

            return $this->environment->set([self::KEY => $email]) !== [];
        } catch (Throwable) {
            return false;
        }
    }
}
