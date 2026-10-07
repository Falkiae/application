<?php
define('APP_NAME', 'Keepnew [Compliance test]');
define('APP_VERSION', '1.0.0-compliance');

// Paths — isolated from production
define('ROOT_PATH', __DIR__);
define('DB_PATH', dirname(__DIR__) . '/data/keepnew_compliance.sqlite');     // own DB file next to prod
define('UPLOAD_PATH', ROOT_PATH . '/uploads');                                // own uploads folder
define('BACKUP_PATH', dirname(__DIR__) . '/backups_compliance');              // own backups folder

// Security — distinct token so a leaked prod token can't trigger backups here
define('BACKUP_TOKEN', 'kn_bkp_compliance_' . hash('sha256', 'keepnew_secure_backup_compliance_2026'));
define('CSRF_SESSION_KEY', '_csrf_token');

// App settings
define('MAX_PHOTO_SIZE', 5 * 1024 * 1024); // 5MB
define('BACKUP_KEEP_DAYS', 30);
define('NOTIFICATION_POLL_INTERVAL', 30000); // ms

// Default admin credentials (change after first login!)
define('DEFAULT_ADMIN_NAME', 'Admin');
define('DEFAULT_ADMIN_PIN', '1234');

// Session config — distinct cookie name so prod and compliance sessions don't collide
session_name('KEEPNEW_COMPLIANCE');
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_samesite', 'Lax');

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
