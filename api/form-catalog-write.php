<?php
// api/form-catalog-write.php — write API for form catalog (category/type mapping)
// Uses CES_IMPORT_KEY from env.php (X-IMPORT-KEY header) to authorize writes.
//
// Endpoints:
//   POST /api/form-catalog-write.php?action=upsert   JSON: { s_form_num, title?, form_key?, category_key?, category_label?, type_key?, type_label?, active? }
//   POST /api/form-catalog-write.php?action=delete   JSON: { s_form_num }
//
// Reads (still use): GET  /api/forms.php?action=catalog_list

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$env = __DIR__ . '/../env.php';
if (!is_readable($env)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'env_not_loaded']); exit; }
require_once $env;

if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>'env_constants_missing']); exit;
}
$IMPORT_KEY_ENV = defined('CES_IMPORT_KEY') ? CES_IMPORT_KEY : 'CHANGE_ME';

$action = $_GET['action'] ?? '';
if (!in_array($action, ['upsert','delete'], true)) { echo json_encode(['ok'=>false,'error'=>'unknown_action']); exit; }

// Guard
$hdr = $_SERVER['HTTP_X_IMPORT_KEY'] ?? '';
if (!$hdr || $hdr !== $IMPORT_KEY_ENV) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'forbidden']); exit; }

// Helpers
function body(){ return json_decode(file_get_contents('php://input'), true) ?: []; }
function norm_key($s){ $s = strtolower(preg_replace('/[^a-z0-9]+/','_', (string)$s)); return trim($s,'_'); }
function norm_form_key($s){ return 'form_'.norm_key(preg_replace('/^form_/i','',(string)$s)); }

$dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
try {
  $pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) {
  http_response_code(500); echo json_encode(['ok'=>false,'error'=>'db_connect_failed']); exit;
}

// Make columns if missing (safe to ignore errors)
$ddl = [
  "ALTER TABLE form_catalog ADD COLUMN category_key   VARCHAR(80)  NULL",
  "ALTER TABLE form_catalog ADD COLUMN category_label VARCHAR(120) NULL",
  "ALTER TABLE form_catalog ADD COLUMN type_key       VARCHAR(80)  NULL",
  "ALTER TABLE form_catalog ADD COLUMN type_label     VARCHAR(120) NULL",
  "ALTER TABLE form_catalog ADD UNIQUE KEY IF NOT EXISTS u_sform (s_form_num)"
];
foreach ($ddl as $sql){ try { $pdo->exec($sql); } catch (Throwable $e) {} }

if ($action === 'upsert') {
  $b = body();
  $sForm = trim((string)($b['s_form_num'] ?? ''));
  if ($sForm === '') { echo json_encode(['ok'=>false,'error'=>'s_form_num required']); exit; }

  $title = isset($b['title']) ? trim((string)$b['title']) : null;
  $formKey = isset($b['form_key']) ? trim((string)$b['form_key']) : null;
  if (!$formKey) $formKey = norm_form_key($sForm);

  $ckey = isset($b['category_key']) ? norm_key($b['category_key']) : null;
  $clab = isset($b['category_label']) ? trim((string)$b['category_label']) : null;
  $tkey = isset($b['type_key']) ? norm_key($b['type_key']) : null;
  $tlab = isset($b['type_label']) ? trim((string)$b['type_label']) : null;
  $active = (int)!!($b['active'] ?? 1);

  try {
    // Exists?
    $st = $pdo->prepare("SELECT id FROM form_catalog WHERE s_form_num=? LIMIT 1");
    $st->execute([$sForm]);
    $row = $st->fetch();

    if ($row) {
      $sql = "UPDATE form_catalog
                 SET form_id = :form_key,
                     form_url = form_url,        -- untouched
                     sform_id = sform_id,        -- untouched
                     sform_insp_id = sform_insp_id,
                     prefill_template = prefill_template,
                     inspect_entry_id = inspect_entry_id,
                     active = :active,
                     category_key = :ckey,
                     category_label = :clab,
                     type_key = :tkey,
                     type_label = :tlab,
                     updated_at = NOW()
               WHERE s_form_num = :sform";
      $u = $pdo->prepare($sql);
      $u->execute([
        ':form_key'=>$formKey, ':active'=>$active,
        ':ckey'=>$ckey, ':clab'=>$clab, ':tkey'=>$tkey, ':tlab'=>$tlab, ':sform'=>$sForm
      ]);

      if ($title !== null) {
        // If you keep a separate "forms" table for metadata, touch it here if desired.
        try {
          $pdo->prepare("INSERT INTO forms(form_key,title,version,active)
                         VALUES(?,?,1,?)
                         ON DUPLICATE KEY UPDATE title=VALUES(title), active=VALUES(active)")
              ->execute([$formKey, $title, $active]);
        } catch (Throwable $e) {}
      }
      echo json_encode(['ok'=>true,'updated'=>true]); exit;

    } else {
      $sql = "INSERT INTO form_catalog (s_form_num, form_id, active, category_key, category_label, type_key, type_label, created_at, updated_at)
              VALUES (:sform, :form_key, :active, :ckey, :clab, :tkey, :tlab, NOW(), NOW())";
      $i = $pdo->prepare($sql);
      $i->execute([
        ':sform'=>$sForm, ':form_key'=>$formKey, ':active'=>$active,
        ':ckey'=>$ckey, ':clab'=>$clab, ':tkey'=>$tkey, ':tlab'=>$tlab
      ]);

      if ($title !== null) {
        try {
          $pdo->prepare("INSERT INTO forms(form_key,title,version,active)
                         VALUES(?,?,1,?)
                         ON DUPLICATE KEY UPDATE title=VALUES(title), active=VALUES(active)")
              ->execute([$formKey, $title, $active]);
        } catch (Throwable $e) {}
      }
      echo json_encode(['ok'=>true,'inserted'=>true]); exit;
    }

  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'upsert_failed','detail'=>$e->getMessage()]);
    exit;
  }
}

if ($action === 'delete') {
  $b = body();
  $sForm = trim((string)($b['s_form_num'] ?? ''));
  if ($sForm === '') { echo json_encode(['ok'=>false,'error'=>'s_form_num required']); exit; }
  try {
    $pdo->prepare("DELETE FROM form_catalog WHERE s_form_num=?")->execute([$sForm]);
    echo json_encode(['ok'=>true]); exit;
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'delete_failed','detail'=>$e->getMessage()]);
    exit;
  }
}

echo json_encode(['ok'=>false,'error'=>'unhandled']);
