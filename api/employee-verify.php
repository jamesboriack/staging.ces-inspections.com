<?php
// /api/employee-verify.php — FAILSAFE VERSION (with phone in response)
declare(strict_types=1);

// ---------- Headers ----------
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ---------- Logging ----------
@ini_set('display_errors','0');
@ini_set('log_errors','1');
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0777, true); }
@ini_set('error_log', $logDir . '/api.log');

// ---------- Helpers ----------
function j($data, int $code=200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

// ---------- PING ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['ping'])) {
  j(['ok'=>true,'pong'=>time()]);
}

// ---------- SELFTEST ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['selftest'])) {
  $hasPDO = extension_loaded('PDO');
  $hasMy  = extension_loaded('pdo_mysql');
  $cfgPath= __DIR__ . '/../config/env.php';
  j([
    'ok' => true,
    'phpVersion' => PHP_VERSION,
    'extensions' => ['PDO'=>$hasPDO,'pdo_mysql'=>$hasMy],
    'env_exists' => is_file($cfgPath),
    'cwd' => getcwd(),
  ]);
}

// ---------- Method guard ----------
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
  j(['ok'=>false,'error'=>'use POST with JSON body: {"employeeId":"55560"}'], 405);
}

// ---------- Parse JSON body ----------
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true);
if (!is_array($in)) $in = [];
$input = trim((string)($in['employeeId'] ?? $in['employee'] ?? $in['email'] ?? ''));
if ($input === '') j(['ok'=>false,'error'=>'missing employeeId'], 400);

// ---------- Validate shape (email OR 3+ digits) ----------
$isEmail = (bool)filter_var($input, FILTER_VALIDATE_EMAIL);
if (!$isEmail && !preg_match('/^\d{3,}$/', $input)) {
  j(['ok'=>false,'error'=>'employeeId must be 3+ digits (or a valid email)'], 422);
}

// ---------- Load config ----------
$cfgPath = __DIR__ . '/../config/env.php';
if (!is_file($cfgPath)) {
  j(['ok'=>false,'error'=>'env_missing','hint'=>'Create /config/env.php returning ["dsn","user","pass"]'], 500);
}
$cfg = include $cfgPath;
if (!is_array($cfg) || empty($cfg['dsn'])) {
  j(['ok'=>false,'error'=>'env_invalid','hint'=>'env.php must return ["dsn","user","pass"]'], 500);
}

// ---------- DB connect ----------
try {
  $pdo = new PDO(
    $cfg['dsn'],
    $cfg['user'] ?? null,
    $cfg['pass'] ?? null,
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
  );
} catch (Throwable $e) {
  error_log('[emp-verify] DB connect failed: ' . $e->getMessage());
  j(['ok'=>false,'error'=>'db_connect_failed'], 500);
}

// ---------- Query helper ----------
function fetch_employee(PDO $pdo, string $input, bool $isEmail): ?array {
  $tries = [];

  if ($isEmail) {
    $tries[] = [
      "SELECT employee_id,
              COALESCE(name,'')            AS name,
              COALESCE(preferred_name,'')  AS preferred_name,
              COALESCE(email,'')           AS email,
              COALESCE(phone,'')           AS phone,
              COALESCE(status,'active')    AS status
       FROM employees
       WHERE LOWER(email)=LOWER(:inp)
       LIMIT 1",
      [':inp'=>$input]
    ];
    $tries[] = [
      "SELECT employee_id,
              COALESCE(name,'')            AS name,
              COALESCE(preferred_name,'')  AS preferred_name,
              COALESCE(email_address,'')   AS email,
              COALESCE(phone,'')           AS phone,
              COALESCE(status,'active')    AS status
       FROM employees
       WHERE LOWER(email_address)=LOWER(:inp)
       LIMIT 1",
      [':inp'=>$input]
    ];
  } else {
    $tries[] = [
      "SELECT employee_id,
              COALESCE(name,'')            AS name,
              COALESCE(preferred_name,'')  AS preferred_name,
              COALESCE(email,'')           AS email,
              COALESCE(phone,'')           AS phone,
              COALESCE(status,'active')    AS status
       FROM employees
       WHERE employee_id=:inp
       LIMIT 1",
      [':inp'=>$input]
    ];
  }

  foreach ($tries as [$sql, $params]) {
    try {
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $row = $st->fetch();
      if ($row) return $row;
    } catch (Throwable $e) {
      error_log('[emp-verify] query variant failed: ' . $e->getMessage());
      continue;
    }
  }
  return null;
}

// ---------- Execute ----------
try {
  $row = fetch_employee($pdo, $input, $isEmail);
  if (!$row) j(['ok'=>false,'error'=>'employee_not_found'], 404);

  $status = strtolower(trim((string)($row['status'] ?? 'active')));
  if ($status !== '' && $status !== 'active') {
    j(['ok'=>false,'error'=>'employee_inactive'], 403);
  }

  $id     = (string)$row['employee_id'];
  $name   = trim((string)$row['name']);
  $pname  = trim((string)$row['preferred_name']);
  $email  = trim((string)$row['email']);
  $phone  = trim((string)$row['phone']);   // <-- include phone

  j([
    'ok' => true,
    'employee' => [
      'id'            => $id,
      'name'          => ($name !== '' ? $name : ('Employee ' . $id)),
      'preferredName' => $pname,   // may be ''
      'email'         => $email,   // may be ''
      'phone'         => $phone    // may be ''
    ]
  ]);
} catch (Throwable $e) {
  error_log('[emp-verify] fatal: ' . $e->getMessage());
  j(['ok'=>false,'error'=>'server_error'], 500);
}
