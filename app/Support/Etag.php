<?php

namespace App\Support;

/**
 * Strong validators the app mints for image bytes.
 *
 * Upstream sends none (measured: Suwayomi answers neither ETag nor Last-Modified
 * on thumbnails or pages), so the browser has no way to revalidate a cached
 * cover cheaply and every lapsed window or hard reload pays the full download
 * again. These are the two halves of that: mint a quoted strong ETag from what
 * the representation actually is, and read a request's If-None-Match against it.
 */
final class Etag
{
    /**
     * A quoted strong ETag for a body.
     *
     * Content derived, so the digest follows the representation: two copies of
     * the same resource that differ in bytes differ in the minted value, which
     * is exactly why a `?format=webp` copy can never validate a baseline one.
     */
    public static function for(string $bytes): string
    {
        return '"'.hash('sha256', $bytes).'"';
    }

    /**
     * Does this request carry an If-None-Match that matches the validator?
     *
     * The comparison follows RFC 9110's If-None-Match: a list of candidates,
     * where weak forms (W/ prefix) match strong ones, and `*` matches
     * whatever the resource currently is.
     */
    public static function matched(?string $header, string $etag): bool
    {
        if ($header === null || trim($header) === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '*') {
                return true;
            }

            if (str_starts_with($candidate, 'W/')) {
                $candidate = trim(substr($candidate, 2));
            }

            if ($candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
