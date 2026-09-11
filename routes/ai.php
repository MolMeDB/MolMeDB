<?php

use App\Mcp\Servers\MolMeDBServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/molmedb', MolMeDBServer::class)
    ->middleware(['throttle:public-api']);
