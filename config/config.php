<?php
declare(strict_types=1);

// An optional local.php returns deployment values. Keep it out of version control.
$localConfig = is_file(__DIR__ . '/local.php') ? require __DIR__ . '/local.php' : [];
function config_value(string $key, string $fallback = ''): string {
	global $localConfig;
    $environment = getenv('BLOGGER_' . $key);
    return $environment !== false ? (string) $environment: (string) ($localConfig[$key] ?? $fallback);
}

define('DB_HOST', config_value('DB_HOST', '127.0.0.1'));
define('DB_PORT', config_value('DB_PORT', '3306'));
define('DB_NAME', config_value('DB_NAME', 'blogger'));
define('DB_USER', config_value('DB_USER', 'root'));
define('DB_PASS', config_value('DB_PASS'));
define('APP_NAME', config_value('APP_NAME', 'Bangumi'));
define('APP_URL', rtrim(config_value('APP_URL'), '/'));
define('INSTALL_TOKEN', config_value('INSTALL_TOKEN'));
define('SESSION_NAME', config_value('SESSION_NAME', 'blogger_session'));
