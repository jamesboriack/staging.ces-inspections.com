<?php
/* === STAGING short-circuit for action=upsert === */
if ((($_GET['action'] ?? '') === 'upsert') || (($_POST['action'] ?? '') === 'upsert')) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

  @ini_set('log_errors','1');
  @ini_set('error_log', __DIR__ . '/logs/api.log');
  @mkdir(__DIR__ . '/logs', 0755, true);

  $raw  = file_get_contents('php://input') ?: '{}';
  $body = json_decode($raw, true) ?: [];

  @file_put_contents(__DIR__ . '/logs/inspections.log',
    '['.date('c')."] upsert ".json_encode($body)."\n", FILE_APPEND);

  echo json_encode([
    'ok'  => true,
    'id'  => $body['inspect'] ?? null,
    'hint'=> 'stg-short'
  ]);
  return;
}
/* === END STAGING short-circuit === */

// /api/inspections.php — session upsert + idempotent photo folder saves (+ simple stubs)
// - action=ping             -> { ok: true, pong: <ts> }
// - action=upsert           -> upsert session metadata; also accepts optional photo folder URLs
// - action=photo_upsert     -> idempotent insert of folder_url for (inspect_id, kind)
// - action=get              -> fetch inspection + photo folders (diagnostics)
// - action=finalize         -> stub (return ok)
// - action=email_summary    -> stub (return ok)
// - action=email_pdf        -> stub (return ok)

declare(strict_types=1);

/* ---------- Logging bootstrap ---------- */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
if (!is_dir(__DIR__ . '/logs')) { @mkdir(__DIR__ . '/logs', 0777, true); }
@ini_set('error_log', __DIR__ . '/logs/api.log');
error_reporting(E_ALL);
error_log("HIT inspections.php " . ($_SERVER['REQUEST_METHOD'] ?? '?') . " " . ($_SERVER['REQUEST_URI'] ?? '?'));

/* ---------- Headers ---------- */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* ---------- Bootstrap (finds config/env.php in multiple locations) ---------- */
require __DIR__ . '/../inc/bootstrap.php';

/* ---------- Helpers ---------- */
function j($a, int $code = 200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}
function read_json_body(): array {
  $raw = file_get_contents('php://input') ?: '';
  if ($raw === '') return [];
  $d = json_decode($raw, true);
  return is_array($d) ? $d : [];
}
function s($v): string { return trim((string)$v); }

/* ---------- Quick health ---------- */
$action = isset($_GET['action']) ? (string)$_GET['action'] : '';
if (($action === 'ping') || (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['ping']))) {
  j(['ok'=>true,'pong'=>time()]);
}
if ($action === '') j(['ok'=>false,'error'=>'missing action'], 400);

/* ---------- DB connect (via bootstrap; fallback if needed) ---------- */
$cfg = $GLOBALS['CFG'] ?? null;
$pdo = ces_pdo();
if (!$pdo) {
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
    error_log("DB connect failed: " . $e->getMessage());
    j(['ok'=>false,'error'=>'db_connect_failed'], 500);
  }
}

/* ---------- Ensure tables (safe if already exist) ---------- */
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS inspections (
      id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
      inspect_id    VARCHAR(80)  NOT NULL UNIQUE,
      code          VARCHAR(80)  NULL,
      unit_id       VARCHAR(80)  NULL,
      employee_id   VARCHAR(80)  NULL,
      device_id     VARCHAR(120) NULL,
      location_link TEXT         NULL,
      flow          VARCHAR(20)  NULL,
      created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX idx_code(code),
      INDEX idx_unit(unit_id),
      INDEX idx_emp(employee_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS inspection_photos (
      id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
      inspect_id  VARCHAR(80)  NOT NULL,
      kind        VARCHAR(20)  NOT NULL,       -- 'walk' | 'repair' | 'generic'
      folder_url  TEXT         NOT NULL,
      created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_inspect_kind_url (inspect_id, kind, folder_url(255)),
      INDEX idx_inspect (inspect_id),
      CONSTRAINT fk_photos_inspect
        FOREIGN KEY (inspect_id) REFERENCES inspections(inspect_id)
        ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
} catch (Throwable $e) {
  error_log("ensure tables failed: " . $e->getMessage());
  // continue — later ops will throw if truly broken
}

/* ---------- Auto-migrate: add missing columns on inspections ---------- */
try {
  $cols = [];
  $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'inspections'");
  $stmt->execute();
  foreach ($stmt as $r) { $cols[strtolower($r['COLUMN_NAME'])] = true; }

  $alters = [];
  if (!isset($cols['code']))          $alters[] = "ADD COLUMN code VARCHAR(80) NULL AFTER inspect_id";
  if (!isset($cols['unit_id']))       $alters[] = "ADD COLUMN unit_id VARCHAR(80) NULL AFTER code";
  if (!isset($cols['employee_id']))   $alters[] = "ADD COLUMN employee_id VARCHAR(80) NULL AFTER unit_id";
  if (!isset($cols['device_id']))     $alters[] = "ADD COLUMN device_id VARCHAR(120) NULL AFTER employee_id";
  if (!isset($cols['location_link'])) $alters[] = "ADD COLUMN location_link TEXT NULL AFTER device_id";
  if (!isset($cols['flow']))          $alters[] = "ADD COLUMN flow VARCHAR(20) NULL AFTER location_link";

  if ($alters) {
    $sql = "ALTER TABLE inspections " . implode(", ", $alters);
    $pdo->exec($sql);
    error_log("inspections.php: applied column migrations: " . implode(", ", $alters));
  }
} catch (Throwable $e) {
  error_log("column migration check failed: " . $e->getMessage());
}

/* ---------- Internal: idempotent folder save ---------- */
function save_photo_folder(PDO $pdo, string $inspect, string $kind, string $url): void {
  if ($inspect === '' || $kind === '' || $url === '') return;
  $kind = strtolower($kind);
  $sql = "
    INSERT INTO inspection_photos (inspect_id, kind, folder_url, created_at, updated_at)
    VALUES (:inspect_id, :kind, :folder_url, NOW(), NOW())
    ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':inspect_id' => $inspect,
    ':kind'       => $kind,
    ':folder_url' => $url,
  ]);
}

/* ---------- ACTION: UPSERT (session metadata) ---------- */
if ($action === 'upsert') {
  $in = read_json_body();
  if (!$in && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) $in = $_POST;

  $inspect      = s($in['inspect']      ?? ($in['inspectId'] ?? ''));
  $code         = s($in['code']         ?? '');
  $unitId       = s($in['unitId']       ?? '');
  $employeeId   = s($in['employeeId']   ?? '');
  $deviceId     = s($in['deviceId']     ?? '');
  $locationLink = s($in['locationLink'] ?? '');
  $flow         = s($in['flow']         ?? '');

  // Optional folder urls (various client aliases)
  $walk360Url            = s($in['walk360Url']            ?? ($in['photosWalkFolderUrl']   ?? ''));
  $photosRepairFolderUrl = s($in['photosRepairFolderUrl'] ?? ($in['repairPhotosUrl']       ?? ''));
  $genericPhotosUrl      = s($in['photosFolderUrl']       ?? '');

  if ($inspect === '') j(['ok'=>false,'error'=>'missing inspect'], 400);

  try {
    $sql = "
      INSERT INTO inspections (inspect_id, code, unit_id, employee_id, device_id, location_link, flow)
      VALUES (:inspect_id, :code, :unit_id, :employee_id, :device_id, :location_link, :flow)
      ON DUPLICATE KEY UPDATE
        code          = VALUES(code),
        unit_id       = VALUES(unit_id),
        employee_id   = VALUES(employee_id),
        device_id     = VALUES(device_id),
        location_link = VALUES(location_link),
        flow          = VALUES(flow),
        updated_at    = CURRENT_TIMESTAMP
    ";
    $st = $pdo->prepare($sql);
    $st->execute([
      ':inspect_id'    => $inspect,
      ':code'          => ($code !== '' ? $code : null),
      ':unit_id'       => ($unitId !== '' ? $unitId : null),
      ':employee_id'   => ($employeeId !== '' ? $employeeId : null),
      ':device_id'     => ($deviceId !== '' ? $deviceId : null),
      ':location_link' => ($locationLink !== '' ? $locationLink : null),
      ':flow'          => ($flow !== '' ? $flow : null),
    ]);

    if ($walk360Url !== '')            save_photo_folder($pdo, $inspect, 'walk',    $walk360Url);
    if ($photosRepairFolderUrl !== '') save_photo_folder($pdo, $inspect, 'repair',  $photosRepairFolderUrl);
    if ($genericPhotosUrl !== '')      save_photo_folder($pdo, $inspect, 'generic', $genericPhotosUrl);

    j(['ok'=>true]);
  } catch (Throwable $e) {
    error_log("upsert error: " . $e->getMessage());
    j(['ok'=>false,'error'=>'upsert_failed'], 500);
  }
}

/* ---------- ACTION: PHOTO_UPSERT (idempotent) ---------- */
if ($action === 'photo_upsert') {
  $in = read_json_body();
  if (!$in && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) $in = $_POST;

  $inspect   = s($in['inspect']   ?? ($in['inspectId'] ?? ''));
  $kind      = strtolower(s($in['kind'] ?? ''));
  $folderUrl = s($in['folderUrl'] ?? '');

  if ($inspect === '' || $kind === '' || $folderUrl === '') {
    j(['ok'=>false,'error'=>'missing inspect/kind/folderUrl'], 400);
  }

  $reqId = bin2hex(random_bytes(4));
  error_log("[photos-upsert:$reqId] id={$inspect} kind={$kind} url={$folderUrl}");

  try {
    save_photo_folder($pdo, $inspect, $kind, $folderUrl);
    j(['ok'=>true, 'reqId'=>$reqId]);
  } catch (Throwable $e) {
    error_log("[photos-upsert:$reqId] error: " . $e->getMessage());
    j(['ok'=>false,'error'=>'photo_upsert_failed','reqId'=>$reqId], 500);
  }
}

/* ---------- ACTION: GET (diagnostics) ---------- */
if ($action === 'get') {
  $inspect = s($_GET['inspect'] ?? ($_GET['inspectId'] ?? ''));
  if ($inspect === '') j(['ok'=>false,'error'=>'missing inspect'], 400);

  try {
    $st = $pdo->prepare("SELECT * FROM inspections WHERE inspect_id = :id LIMIT 1");
    $st->execute([':id'=>$inspect]);
    $row = $st->fetch();

    $st2 = $pdo->prepare("SELECT kind, folder_url, created_at, updated_at FROM inspection_photos WHERE inspect_id = :id ORDER BY kind, id");
    $st2->execute([':id'=>$inspect]);
    $photos = $st2->fetchAll();

    j(['ok'=>true, 'inspection'=>$row, 'photos'=>$photos]);
  } catch (Throwable $e) {
    error_log("get error: " . $e->getMessage());
    j(['ok'=>false,'error'=>'get_failed'], 500);
  }
}

/* ---------- OPTIONAL STUBS ---------- */
if ($action === 'finalize')      { j(['ok'=>true]); }
if ($action === 'email_summary') { j(['ok'=>true]); }
if ($action === 'email_pdf')     { j(['ok'=>true]); }

/* ---------- Unknown action ---------- */
j(['ok'=>false,'error'=>'unknown action'], 400);
