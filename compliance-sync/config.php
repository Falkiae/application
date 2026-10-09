<?php
define('APP_NAME', 'Keepnew [Sync test]');
define('APP_VERSION', '1.0.0-sync');

// Paths — isolated from both prod AND compliance
define('ROOT_PATH', __DIR__);
define('DB_PATH', dirname(__DIR__) . '/data/keepnew_sync.sqlite');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('BACKUP_PATH', dirname(__DIR__) . '/backups_sync');

// Security — distinct token
define('BACKUP_TOKEN', 'kn_bkp_sync_' . hash('sha256', 'keepnew_secure_backup_sync_2026'));
define('CSRF_SESSION_KEY', '_csrf_token');

// App settings
define('MAX_PHOTO_SIZE', 5 * 1024 * 1024);
define('BACKUP_KEEP_DAYS', 30);
define('NOTIFICATION_POLL_INTERVAL', 30000);

define('DEFAULT_ADMIN_NAME', 'Admin');
define('DEFAULT_ADMIN_PIN', '1234');

// Session — distinct cookie so prod / compliance / sync don't collide
session_name('KEEPNEW_SYNC');
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Load API secrets from a gitignored file (never commit API keys).
// If config_secrets.php is missing, the sync is disabled by design.
$secretsPath = __DIR__ . '/config_secrets.php';
if (is_file($secretsPath)) {
    require_once $secretsPath;
}
// Fallback defaults so the app runs even without secrets (dry_run)
if (!defined('ZENBOOKER_API_KEY'))     define('ZENBOOKER_API_KEY', '');
if (!defined('ODOO_URL'))              define('ODOO_URL',          '');
if (!defined('ODOO_DB'))               define('ODOO_DB',           '');
if (!defined('ODOO_API_KEY'))          define('ODOO_API_KEY',      '');
if (!defined('ODOO_USERNAME'))         define('ODOO_USERNAME',     '');
