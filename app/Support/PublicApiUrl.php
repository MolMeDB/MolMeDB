<?php

namespace App\Support;

/**
 * Canonical absolute URLs of the public REST API (config fair.public_api_url).
 *
 * Persistent identifiers and self-links must not depend on the host a request
 * came through (the backend host, the public frontend host, or none at all in
 * console commands), so they are always built from the configured base.
 */
class PublicApiUrl
{
    public static function base(): string
    {
        return rtrim((string) config('fair.public_api_url'), '/');
    }

    public static function to(string $path = ''): string
    {
        $path = ltrim($path, '/');

        return $path === '' ? self::base() : self::base().'/'.$path;
    }
}
