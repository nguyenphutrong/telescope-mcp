<?php

return [
    'enabled' => env('TELESCOPE_MCP_ENABLED', false),

    // Allow unredacted access, including stored credentials and personal data.
    // Clients must also explicitly request unredacted=true for each read.
    'allow_sensitive_data' => env('TELESCOPE_MCP_ALLOW_SENSITIVE_DATA', false),
];
