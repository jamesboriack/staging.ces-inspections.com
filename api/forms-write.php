<?php
// /staging/api/forms-write.php
// Purpose: allow browser-based writes without exposing CES_IMPORT_KEY.
// We set the header server-side and then include forms.php (which enforces the header).

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Load staging env (same one your QR flow uses)
$env = __DIR__ . '/../env.php';
if (!is_readable($env)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'env_not_loaded']); exit;
}
require_once $env;

// Ensure the secret is present
if (!defined('CES_IMPORT_KEY') || !CES_IMPORT_KEY) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'import_key_missing']); exit;
}

// Inject header so /api/forms.php will accept write actions
$_SERVER['HTTP_X_IMPORT_KEY'] = CES_IMPORT_KEY;

// Hand control to the real handler
require __DIR__ . '/forms.php';
