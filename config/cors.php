<?php

/*
 * The framework default is allowed_origins ['*'] on all api/* paths.
 * Buddy is not a browser API: CORS stays closed unless a browser-based
 * MCP client origin is explicitly allowlisted via
 * BUDDY_MCP_ALLOWED_ORIGINS, which also feeds the mcp.origin
 * middleware so both layers agree. ADR 0006.
 */
return [

    'paths' => ['api/mcp'],

    'allowed_methods' => ['POST', 'GET'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('BUDDY_MCP_ALLOWED_ORIGINS', '')))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Mcp-Session-Id', 'MCP-Protocol-Version'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
