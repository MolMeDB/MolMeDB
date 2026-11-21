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
    /**
     * @param  string  $search  a key of config('public_api.rate_limits'), e.g. "similarity"
     */
    protected function passesThrottle(string $search, ?string $ip): bool
    {
        $key = "mcp-public-api-{$search}:".($ip ?? 'unknown');
        $perMinute = config("public_api.rate_limits.{$search}.per_minute");
        $perHour = config("public_api.rate_limits.{$search}.per_hour");

        if (RateLimiter::tooManyAttempts("{$key}:minute", $perMinute) || RateLimiter::tooManyAttempts("{$key}:hour", $perHour)) {
            return false;
        }

        RateLimiter::hit("{$key}:minute", 60);
        RateLimiter::hit("{$key}:hour", 3600);

        return true;
    }
}
