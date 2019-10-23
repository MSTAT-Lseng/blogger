<?php
// Copy to config/local.php, then set your own database and installation token.
return [
    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'blogger',
    'DB_USER' => 'blogger',
    'DB_PASS' => '',
    'APP_DEBUG' => '0', // Use '1' only with APP_ENV=local or development.
    'APP_ENV' => 'production',
    'APP_URL' => '', // For a subdirectory deployment, e.g. /blogger. Empty auto-detects it.
    'INSTALL_TOKEN' => '', // Set a random secret (at least 24 characters) for the first install.
];
