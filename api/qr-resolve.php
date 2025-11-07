<?php
// /api/qr-resolve.php — Robust QR resolver: returns unit even if form_catalog doesn't match.
// Attaches secondary form info only if present & active.

declare(strict_types=1);

/**
 * --- API logging bootstrap ---
 * Place logging immediately after declare(strict_types=1) and before any output.
 * Ensure /api/logs/ exists and is writable by the web server user.
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/api.log'); // unified API log file
error_reporting(E_ALL);
// trace every hit
error_log("HIT " . __FILE__ . " " . ($_SERVER['REQUEST_METHOD'] ?? '?') . " " . ($_SERVER['REQUEST_URI'] ?? '?'));

// ---- standard headers ----
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ---- quick health check ----
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['ping'])) {
  echo json_encode(['ok' => true, 'pong' => time()]);
  exit;
}

try {
  $cfg = require __DIR__ . '/../config/env.php';
  $pdo = new PDO(
    $cfg['dsn'],
    $cfg['user'],
    $cfg['pass'],
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Throwable $e) {
  error_log("DB connect failed: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'DB connect failed']);
  exit;
}

$code = isset($_GET['code']) ? trim((string)$_GET['code']) : '';
if ($code === '') {
  echo json_encode(['ok'=>false,'error'=>'Missing code']);
  exit;
}
$codeU = strtoupper($code);

try {
  // 1) Find unit by QR code (case-insensitive)
  $sql = "SELECT id, unit_id, display_id, category, unit_type, s_form_num, qr_code
          FROM units
          WHERE UPPER(qr_code) = ? LIMIT 1";
  $st  = $pdo->prepare($sql);
  $st->execute([$codeU]);
  $u = $st->fetch();

  if (!$u) {
    echo json_encode(['ok'=>false,'error'=>'Not found']);
    exit;
  }

  // Build base response
  $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'ces-inspections.com';
  $landing = 'https://' . $host . '/start?code=' . urlencode((string)($u['qr_code'] ?? $codeU));

  $resp = [
    'ok' => true,
    'unit' => [
      'unitId'       => (string)($u['unit_id'] ?? ''),
      'displayId'    => (string)($u['display_id'] ?? ''),
      'unitCategory' => (string)($u['category'] ?? ''),
      'unitType'     => (string)($u['unit_type'] ?? ''),
      'sFormNum'     => (string)($u['s_form_num'] ?? ''),
      'qrCode'       => (string)($u['qr_code'] ?? $codeU),
      'landingUrl'   => $landing,
    ],
  ];

  // 2) Optionally attach secondary (Google Form) info if s_form_num is present & active
  if (!empty($u['s_form_num'])) {
    $sqlF = "SELECT s_form_num, form_url, form_id, sform_id, inspect_entry_id, sform_insp_id, active
             FROM form_catalog
             WHERE s_form_num = ? LIMIT 1";
    $sf = $pdo->prepare($sqlF);
    $sf->execute([$u['s_form_num']]);
    $f = $sf->fetch();

    if ($f && (string)$f['active'] === '1') {
      // Prefer the /d/e/{id}/viewform id if present (sform_id), else fallback to form_id
      $secId    = (string)($f['sform_id'] ?? '');
      if ($secId === '') $secId = (string)($f['form_id'] ?? '');
      $inspEntry = (string)($f['sform_insp_id'] ?? '');
      if ($inspEntry === '') $inspEntry = (string)($f['inspect_entry_id'] ?? '');

      $secUrl = (string)($f['form_url'] ?? '');
      if ($secUrl === '' && $secId !== '') {
        $secUrl = 'https://docs.google.com/forms/d/e/' . $secId . '/viewform?usp=header';
      }

      if ($secId !== '')           { $resp['unit']['secondaryFormId'] = $secId; }
      if ($inspEntry !== '')       { $resp['unit']['secInspectionIdEntry'] = $inspEntry; }
      if ($secUrl !== '')          { $resp['unit']['secondaryFormUrl'] = $secUrl; }
    }
  }

  echo json_encode($resp);
} catch (Throwable $e) {
  error_log("Query failed: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Query failed','detail'=>$e->getMessage()]);
}
