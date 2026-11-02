<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\DB;

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
 * `isOpen()` is for asking the question. `claim()` is for answering it once, and the answer is only
 * true while the lock is still held. The middleware that takes the claim releases it after the
 * request that creates the account has finished, which is what puts the count and the insert inside
 * one critical section rather than leaving a gap between them.
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
     * The lock that serialises the single owner decision, kept apart from the registration
     * claim because the two protect different things and the claim is taken in only one mode.
     */
    public const OWNER_LOCK = 'papyrus:install-owner-claim';

    /**
     * @var (callable(): int)|null
     */
    private $userCount;

    /**
     * The claim lock this request is holding, if it won one.
     *
     * A claim is not a boolean answer the moment it returns: it is a critical
     * section that has to stay open until the account is written. Keeping the
     * lock object here is what lets the middleware release it after the request
     * that creates the account has finished, rather than releasing it as soon as
     * `claim()` found zero users.
     */
    private ?Lock $heldClaim = null;

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
     * The lock is deliberately not released on a successful claim. Releasing it here was the other
     * half of the same bug: two requests could both win the claim, because the first had not written
     * its account yet, and both would then read a count of zero. The caller holds the claim until the
     * account exists and calls `releaseClaim()` afterwards, so the second request waits at the lock
     * and finds one user when it finally reads.
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

        if ($this->heldClaim !== null) {
            return true;
        }

        $lock = $this->cache->lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            return false;
        }

        if ($this->count() > 0) {
            $lock->release();

            return false;
        }

        $this->heldClaim = $lock;

        return true;
    }

    /**
     * Give the claim back once the request that took it has finished.
     *
     * Called from a `finally` around the sign-up request, so a validation failure, a refusal or an
     * unexpected error cannot leave the install unable to register until the lock expires. Releasing
     * a lock this instance does not hold is a no-op, which is what the other registration routes and
     * the modes that allow registration need.
     */
    public function releaseClaim(): void
    {
        if ($this->heldClaim === null) {
            return;
        }

        $this->heldClaim->release();
        $this->heldClaim = null;
    }

    /**
     * Create an account and settle who owns the install in one serialized step.
     *
     * ## Why the lock is taken around the write rather than at a call site
     *
     * `claim()` above is the single-reader guard: in `single` it refuses the second sign-up and
     * holds its claim until the request finishes, so the count it read and the row this method
     * writes are in one critical section. The owner question still has to be answered once in
     * every mode. Two first sign-ups that both read "no owner" and both promote themselves would
     * leave the install with two owners, and one that read "no owner" and then lost the write
     * would leave it with none.
     *
     * ## Why the lock is held across the commit
     *
     * Because the promotion and the account are one transaction. Releasing the lock before that
     * transaction commits lets a second sign-up read the pre-promotion state through a cache
     * store that is not the database, promote itself, and produce the two-owner install this
     * lock exists to prevent. The callback returns only after the transaction has committed.
     *
     * ## Why the earliest account rather than the one that just arrived
     *
     * That is the rule the data migration applies to an existing install. Using the same rule
     * here means an install where an account already existed and an install whose first account
     * arrives by sign-up cannot disagree about who the first account is.
     *
     * ## Why an explicit owner is a flag rather than a role
     *
     * `users:create --admin` must still be able to add an owner when one already exists. The
     * command passes a boolean; no request field reaches this parameter, and `role` stays out
     * of the model's fillable list. Every account that does not ask explicitly still follows
     * the shared earliest-account rule.
     *
     * @param  callable(): User  $create
     * @param  bool  $explicitOwner  Write the owner role to this account even when another owner
     *                               exists. Only the console `--admin` flag may pass true; no
     *                               request input reaches it.
     */
    public function createAccount(callable $create, bool $explicitOwner = false): User
    {
        /** @var User $user */
        $user = $this->cache
            ->lock(self::OWNER_LOCK, self::LOCK_SECONDS)
            ->block(self::LOCK_SECONDS, function () use ($create, $explicitOwner): User {
                return DB::transaction(function () use ($create, $explicitOwner): User {
                    $user = $create();

                    if ($explicitOwner) {
                        $user->forceFill(['role' => User::ROLE_INSTALL_OWNER])->save();
                    } elseif (! User::query()->where('role', User::ROLE_INSTALL_OWNER)->exists()) {
                        $earliest = User::query()->orderBy('id')->first();

                        if ($earliest !== null) {
                            $earliest->forceFill(['role' => User::ROLE_INSTALL_OWNER])->save();
                        }
                    }

                    /*
                     * The promotion queries its own model, so the account this method returns
                     * would otherwise carry no role at all. Refresh it here, inside the same
                     * transaction, so the caller sees the state the commit is about to write.
                     */
                    $user->refresh();

                    return $user;
                });
            });

        return $user;
    }

    private function count(): int
    {
        if ($this->userCount !== null) {
            return (int) ($this->userCount)();
        }

        return User::query()->count();
    }
}
