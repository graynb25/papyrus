<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Cache\LockProvider;

/**
 * Whether the sign-up form is open right now, which is not the same question as which mode this is.
 *
 * ## Why this exists
 *
 * Because `single` mode disables registration, which is right: it is a one-reader install and an
 * open sign-up form is a hole in it. But it also meant a fresh install had no way to make its first
 * account at all, since the only route to one was the form the mode had just closed. The answer
 * given was `users:create`, a console command, so the reader had to open a terminal and run something
 * before the application they had just installed was usable.
 *
 * The fix is not to turn registration on, which would reopen the hole permanently. It is to open it
 * only while nobody has registered yet. The first account can be made from the browser; the moment it
 * exists the endpoints answer 404 again and the install is closed to everyone else, which is exactly
 * the posture `single` mode exists to guarantee.
 *
 * ## Why the count check is not in `Deployment`
 *
 * Because that class is configuration and not schema, deliberately, and a user count is neither. Its
 * whole reason for existing is that promoting a self-hosted install from one reader to several is an
 * env change and never a migration. Putting a database read inside it would make the mode depend on
 * state, and the next person to ask "is registration on in this deployment" would get two answers
 * depending on whether they had a row count to hand.
 *
 * ## Why `claim()` exists alongside `isOpen()`
 *
 * Because reading a count and then writing a row is not atomic, and on a loopback install two browser
 * tabs submitting the sign-up form at the same moment is not a contrived thing to imagine. Two
 * requests both see zero users, both pass the check, and a `single` mode install ends up with two
 * readers, which breaks the invariant the mode is named for and quietly enables the per-user quota
 * and showcase behaviour that mode exists to switch off.
 *
 * So the POST path takes a lock, re-reads the count while holding it, and only the holder proceeds.
 * `isOpen()` is for asking the question. `claim()` is for answering it once.
 *
 * ## Why `LockProvider` and not `Cache`
 *
 * Because `lock()` is not on the cache contract. It is on the provider, which every store that can
 * lock implements, and asking for the narrower interface says what is actually used here: this class
 * takes a lock, it never reads or writes a cached value.
 */
final class Registration
{
    /**
     * The lock name. Prefixed so it cannot collide with a cache key the application uses for anything
     * else, which is the usual way a lock becomes a production incident.
     */
    public const LOCK = 'papyrus:registration-claim';

    /**
     * How long a claim is held. Long enough that a slow request cannot have its lock expire
     * mid-registration and let a second one in, short enough that a process killed mid-claim does
     * not leave the install permanently unable to register.
     */
    public const LOCK_SECONDS = 15;

    /**
     * @var (callable(): int)|null
     */
    private $userCount;

    /**
     * @param  (callable(): int)|null  $userCount  Counts accounts. Injected so a test can put the
     *                                             install in "nobody has registered yet" or its opposite
     *                                             without creating rows to do it.
     */
    public function __construct(
        private readonly Deployment $deployment,
        private readonly LockProvider $cache,
        ?callable $userCount = null,
    ) {
        $this->userCount = $userCount;
    }

    /**
     * Is the form worth showing?
     *
     * True in a mode that has registration at all, and true on a single-user install that nobody has
     * claimed yet. This is the answer for the link on the login page and for GET on the form.
     */
    public function isOpen(): bool
    {
        return $this->deployment->allowsRegistration() || $this->count() === 0;
    }

    /**
     * Take the single first account, if there is still one to take.
     *
     * The count is read *inside* the lock. Reading it before taking the lock is the bug this method
     * exists to not have: that is a check and a use with a gap between them, and the gap is where the
     * second reader arrives.
     *
     * In a mode that allows registration this is unconditionally true and takes no lock, because the
     * invariant it protects does not exist there and serialising every sign-up would be a cost with
     * no benefit.
     */
    public function claim(): bool
    {
        if ($this->deployment->allowsRegistration()) {
            return true;
        }

        return (bool) $this->cache
            ->lock(self::LOCK, self::LOCK_SECONDS)
            ->get(fn (): bool => $this->count() === 0);
    }

    private function count(): int
    {
        if ($this->userCount !== null) {
            return (int) ($this->userCount)();
        }

        return User::query()->count();
    }
}
