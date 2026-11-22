<?php

namespace App\Support;

/**
 * Base URLs of the services the application calls inside the Docker network.
 *
 * Such a service used to be set by its bare name (RDKIT_HOST=rdkit), which
 * the HTTP client no longer accepts: a request needs a scheme. A name without
 * one is therefore read as plain HTTP, the way it always worked.
 */
class ServiceUrl
{
    public static function fromHost(?string $host): string
    {
        $host = rtrim(trim((string) $host), '/');

        if ($host === '' || preg_match('#^https?://#i', $host) === 1) {
            return $host;
        }

        return 'http://'.ltrim($host, '/');
    }
}
