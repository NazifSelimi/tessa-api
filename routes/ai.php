<?php

use App\Mcp\Servers\ShopServer;
use Laravel\Mcp\Facades\Mcp;

// AI assistants (Claude, Cursor, …) connect here with an admin "AI assistant" token
// created on Admin → Features. It lives under /api so the web server forwards it to Laravel.
Mcp::web('/api/mcp', ShopServer::class)
    ->middleware(['auth:sanctum', 'role:admin', 'feature:mcp', 'throttle:api']);
