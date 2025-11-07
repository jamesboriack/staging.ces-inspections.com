<?php
declare(strict_types=1);

/*
  /api/main-profile.php
  Inputs (GET):
    - employee: employee_id (e.g., TEST001)
    - code:     unit QR code (e.g., U001)
    - ping=1:   health check -> {"ok":true,"pong":<ts>}
  Output: { ok, employee:{...}, admin, unit:{...} }
*/

@ini_set('display_errors','0');
@ini_set('log_errors','1');
@ini_set('error_log', __DIR__ . '/logs/api.log');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (isset($_GET['ping'])) { echo json_encode(['ok'=>true,'pong'=>time()]); exit; }

$cfg = require __DIR__ . '/../config/env.php';
try {
  $pdo = new PDO($cfg['dsn'], $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'DB connect failed']); exit;
}

$empId = isset($_GET['employee']) ? trim((string)$_GET['employee']) : '';
$code  = isset($_GET['code']) ? strtoupper(trim((string)$_GET['code'])) : '';

$out = ['ok'=>true];

// ---- Employee (per your schema) ----
// id BIGINT PK, employee_id VARCHAR(64), preferred_name, status ENUM('active','inactive'), admin TINYINT(1) NULL
if ($empId !== '') {
  $st = $pdo->prepare("
    SELECT
      employee_id,
      name,
      preferred_name,
      email,
      phone,
      COALESCE(admin, 0)           AS admin,
      (status = 'active')          AS active
    FROM employees
    WHERE employee_id = ?
    LIMIT 1
  ");
  $st->execute([$empId]);
  if ($row = $st->fetch()) {
    $out['employee'] = [
      'id'            => (string)$row['employee_id'],
      'name'          => (string)($row['name'] ?? ''),
      'preferredName' => (string)($row['preferred_name'] ?? ''),
      'email'         => (string)($row['email'] ?? ''),
      'phone'         => (string)($row['phone'] ?? ''),
      'admin'         => (int)$row['admin'],
      'active'        => (int)$row['active'],
    ];
    $out['admin'] = (int)$row['admin']; // top-level convenience for the UI
  }
}

// ---- Unit by QR code (matches earlier qr-resolve) ----
if ($code !== '') {
  $st = $pdo->prepare("
    SELECT unit_id, display_id, category, unit_type, s_form_num
    FROM units
    WHERE UPPER(qr_code) = ?
    LIMIT 1
  ");
  $st->execute([$code]);
  if ($u = $st->fetch()) {
    $out['unit'] = [
      'unitId'       => (string)$u['unit_id'],
      'display_id'   => (string)($u['display_id'] ?? ''),
      'category'     => (string)($u['category'] ?? ''),
      'unit_type'    => (string)($u['unit_type'] ?? ''),
      's_form_num'   => (string)($u['s_form_num'] ?? ''),
    ];
  }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
