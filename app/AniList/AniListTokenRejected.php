<?php

namespace App\AniList;

/**
 * AniList refused the token, so whatever it authorised no longer stands.
 *
 * Distinct from {@see AniListRefusedException} on purpose: a rate limit is
 * waited out, a rejected token is not. AniList issues no refresh token, so the
 * only recovery is connecting again, and the caller has to know which of the
 * two it is holding.
 */
class AniListTokenRejected extends \RuntimeException {}
