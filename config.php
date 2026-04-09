<?php
define('APP_NAME', 'Keepnew');
define('APP_VERSION', '1.0.0');

// Paths
define('ROOT_PATH', __DIR__);
define('DB_PATH', ROOT_PATH . '/data/keepnew.sqlite');
define('UPLOAD_PATH', ROOT_PATH . '/uploads');
define('BACKUP_PATH', ROOT_PATH . '/backups');

// Security
define('BACKUP_TOKEN', 'kn_bkp_' . hash('sha256', 'keepnew_secure_backup_2024')); // Change this!
define('CSRF_SESSION_KEY', '_csrf_token');

// App settings
define('MAX_PHOTO_SIZE', 5 * 1024 * 1024); // 5MB
define('BACKUP_KEEP_DAYS', 30);
define('NOTIFICATION_POLL_INTERVAL', 30000); // ms

// Default admin credentials (change after first login!)
define('DEFAULT_ADMIN_NAME', 'Admin');
define('DEFAULT_ADMIN_PIN', '1234');

// Session config
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
