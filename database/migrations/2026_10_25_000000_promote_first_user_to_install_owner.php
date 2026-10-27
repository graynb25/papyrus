<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gives an existing install its owner without touching the schema.
 *
 * ## Why an install can have no owner at all
 *
 * The role column has existed since tenancy and `users:create --admin` could write it, but
 * the web sign-up path always wrote a reader. An install that was created through the form
 * therefore has accounts and no account that can manage the install, which would leave the
 * owner-only surfaces with no door once they require the owner ability.
 *
 * ## Why the earliest account, and only when nobody owns the install
 *
 * The rule has to be one rule. Sign-up promotes the earliest account when the install has no
 * owner, so this migration uses the same fact rather than promoting whichever row is first
 * by an accident of query order. If any account already has the role, nothing is written:
 * an existing owner is never demoted, even when a lower id exists, because the alternative
 * would take access away from the account that was using it.
 *
 * The update is idempotent by construction. A second run finds the owner it wrote and returns,
 * and a run on an install with no accounts has nothing to promote.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->where('role', 'admin')->exists()) {
            return;
        }

        $earliest = DB::table('users')->orderBy('id')->value('id');

        if ($earliest === null) {
            return;
        }

        DB::table('users')->where('id', $earliest)->update(['role' => 'admin']);
    }

    /**
     * No down step, deliberately.
     *
     * The role this migration writes is how an install gets its owner. Reversing it would
     * either demote an account that may have created things as the owner, or guess which
     * admin predated the migration, and a wrong guess locks the install out of the surfaces
     * that require the ability. A data promotion with no safe inverse is not undone.
     */
    public function down(): void
    {
        // Intentionally empty. See the method comment.
    }
};
