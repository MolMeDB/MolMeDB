<?php

namespace App\Mcp\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-IP limits of the expensive (Bingo) searches, mirroring the
 * public-api-substructure and public-api-similarity limiters of the REST
 * API (AppServiceProvider). Those are route middleware of single REST
 * routes; the MCP server has one HTTP route for all tools, so its tools
 * enforce them themselves.
 */
trait ThrottlesExpensiveSearches
{
    protected function passesThrottle(string $search, ?string $ip, int $perMinute, int $perHour): bool
    {
        $key = "mcp-public-api-{$search}:".($ip ?? 'unknown');

        if (RateLimiter::tooManyAttempts("{$key}:minute", $perMinute) || RateLimiter::tooManyAttempts("{$key}:hour", $perHour)) {
            return false;
        }

        RateLimiter::hit("{$key}:minute", 60);
        RateLimiter::hit("{$key}:hour", 3600);

        return true;
    }
}
