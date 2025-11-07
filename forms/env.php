<?php
// env.php — STAGING (drop-in)
// Single source of truth for staging DB + app settings.
// Compatible with both: (1) inc/bootstrap.php array-style config,
// and (2) legacy code that reads DB_* constants.

if (!defined('CES_ENV')) {
  define('CES_ENV', 'staging');
}

/* ---------- CORE DB CONFIG (staging) ---------- */
$cfg = [
  'dsn'  => 'mysql:host=localhost;dbname=ces_stg;charset=utf8mb4',
  'user' => 'ces_stg_user',
  'pass' => 'ces_password',

  // Optional app bits (safe defaults)
  'app_base_url' => 'https://staging.ces-inspections.com',

  // Logging path for APIs that honor it (optional)
  'api_log' => __DIR__ . '/../logs/inspections.log',
];

/* ---------- Define legacy constants for older includes ---------- */
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'ces_stg');
if (!defined('DB_USER')) define('DB_USER', 'ces_stg_user');
if (!defined('DB_PASS')) define('DB_PASS', 'ces_password');

// Optional: central app base URL (some pages reference it)
if (!defined('APP_BASE_URL')) define('APP_BASE_URL', $cfg['app_base_url']);

/* ---------- Imports / security keys ---------- */
if (!defined('CES_IMPORT_KEY')) define('CES_IMPORT_KEY', 'STAGE_LONG_RANDOM_SECRET');

/* ---------- Google / external IDs (staging) ---------- */
if (CES_ENV === 'staging') {
  if (!defined('DRIVE_ROOT_ID'))   define('DRIVE_ROOT_ID', 'STG_DRIVE_ROOT_ID');
  if (!defined('SCHEMA_API_URL'))  define('SCHEMA_API_URL', 'https://script.google.com/macros/s/AKfycbw-STG/exec');
} else {
  if (!defined('DRIVE_ROOT_ID'))   define('DRIVE_ROOT_ID', 'PROD_DRIVE_ROOT_ID');
  if (!defined('SCHEMA_API_URL'))  define('SCHEMA_API_URL', 'https://script.google.com/macros/s/AKfycbw-PROD/exec');
}

/* ---------- (Optional) Mail constants (harmless if unused) ---------- */
if (!defined('MAIL_MODE'))       define('MAIL_MODE', 'smtp'); // 'smtp' | 'mail'
if (!defined('MAIL_FROM'))       define('MAIL_FROM', 'no-reply@ces-inspections.com');
if (!defined('MAIL_FROM_NAME'))  define('MAIL_FROM_NAME', 'CES Inspections');
if (!defined('MAIL_TO_DEFAULT')) define('MAIL_TO_DEFAULT', 'equipment@clr-energy.com');

// If you wire SMTP later, these are here so senders don’t scatter config
if (!defined('SMTP_HOST'))   define('SMTP_HOST',  'mail.ces-inspections.com');
if (!defined('SMTP_PORT'))   define('SMTP_PORT',  465);
if (!defined('SMTP_USER'))   define('SMTP_USER',  'no-reply@ces-inspections.com');
if (!defined('SMTP_PASS'))   define('SMTP_PASS',  ''); // fill if you actually use SMTP from staging
if (!defined('SMTP_SECURE')) define('SMTP_SECURE','ssl'); // ssl for 465
if (!defined('SMTP_AUTH'))   define('SMTP_AUTH',  true);

/* ---------- Return array for new bootstrap ---------- */
return $cfg;
