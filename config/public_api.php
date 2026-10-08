<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Rate limits of the public API and the MCP server
    |--------------------------------------------------------------------------
    |
    | Requests per client IP address. "requests" applies to every request of
    | the public API (routes/public/v1.php) and the MCP server; the expensive
    | Bingo searches have their own limits on top of it. Used by the rate
    | limiters (AppServiceProvider), the MCP tools and the documentation.
    |
    */

    'rate_limits' => [
        'requests' => ['per_minute' => 60, 'per_day' => 5000],
        'substructure' => ['per_minute' => 6, 'per_hour' => 30],
        'similarity' => ['per_minute' => 10, 'per_hour' => 60],
    ],

];
