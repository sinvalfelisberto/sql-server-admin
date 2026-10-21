<?php
// Default configuration. To override values locally, create config.local.php
// returning an array with the keys you want (that file is not versioned).
return [
    'app_name'    => 'SQL Web Manager',
    'version'     => '1.0.0',

    // Server pre-filled on the login screen (e.g., "localhost,1433" or "host:1433").
    'default_server' => 'localhost,1433',

    // Allowed servers. Empty = any server can be entered.
    // Recommended in exposed environments, to prevent use as a network proxy.
    'allowed_servers' => [],

    // Connection
    'trust_server_certificate' => true,  // initial value of the login checkbox
    'encrypt'                  => null,  // null = driver default; true/false to force it
    'login_timeout'            => 10,    // seconds

    // Execution
    'query_timeout' => 120,    // seconds
    'max_rows'      => 10000,  // hard cap on rows returned per result (per page, when paginated)
    'csv_separator' => ';',    // default CSV separator: ";", ",", "|" or "tab" (users can change it on screen)
    'export_max_rows' => 0,    // row cap for the complete export (CSV/JSON/Excel); 0 = no cap

    // Session
    'session_timeout' => 3600, // seconds of inactivity before sign-out
];
