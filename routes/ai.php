<?php

use App\Mcp\Servers\MolMeDBServer;
use Laravel\Mcp\Facades\Mcp;

// Loaded by laravel/mcp outside the api/v1 route group, so the public API
// CORS middleware has to be attached here explicitly (only to the POST route
// - the package answers GET/DELETE with a bare 405).
Mcp::web('api/v1/mcp', MolMeDBServer::class)
    ->middleware(['public-cors', 'throttle:public-api']);
