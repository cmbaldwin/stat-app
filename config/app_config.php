<?php
// Base URL configuration
define('BASE_URL', 'https://montoyahome.com/stat-app');

// Email configuration
define('FROM_EMAIL', 'noreply@boardgameclub.com');
define('EMAIL_SIGNATURE', 'Board Game Club StatApp Team');

// Token expiration time in hours
define('TOKEN_EXPIRATION_HOURS', 24);
// Agent results API (api/results.php).
// Set STATAPP_API_TOKEN in the environment (or define API_TOKEN here).
// Empty/missing = whole API disabled (fails closed). Treat like an admin password.
if (!defined('API_TOKEN')) {
    define('API_TOKEN', getenv('STATAPP_API_TOKEN') ?: '');
}
