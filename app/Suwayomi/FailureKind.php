<?php

namespace App\Suwayomi;

enum FailureKind: string
{
    /** Our client-side timeout fired, or the server hit its 30s execution cap. */
    case Timeout = 'timeout';

    /** 401 - the JWT was missing, expired, or rejected. */
    case Unauthenticated = 'unauthenticated';

    /** The server was unreachable, or not listening. */
    case Unavailable = 'unavailable';

    /** GraphQL returned an `errors` array. */
    case Query = 'query';

    /** Anything else. */
    case Unknown = 'unknown';
}
