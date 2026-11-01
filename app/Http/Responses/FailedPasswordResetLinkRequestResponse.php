<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as Contract;

/**
 * Answer a failed reset request exactly as a successful one.
 *
 * Fortify's stock failure response returns 422 with "we cannot find a user with that email",
 * which is a free account-enumeration oracle: one request tells a caller whether an address is
 * registered. The broker status is deliberately ignored here. The reader who mistyped an address
 * sees the same sentence as the reader whose link was sent, and an attacker learns nothing.
 *
 * The per-IP throttle in ThrottleAuthPosts is what stops the spray; this response only removes
 * the confirmation. Fortify passes the broker status as a construction parameter, but this class
 * deliberately has no constructor: the status is the thing that must not reach the reader.
 */
final class FailedPasswordResetLinkRequestResponse implements Contract
{
    public function toResponse($request)
    {
        $message = trans('passwords.sent');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
