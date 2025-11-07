<?php
// /api/forms.php — CES Checklist Schema API + Field Catalog
// ---------------------------------------------------------
// Quick tests:
//   /api/forms.php?action=list
//   /api/forms.php?action=get&form=form_200
//
// Field Catalog (NEW):
//   GET  /api/forms.php?action=field_catalog_list
//   GET  /api/forms.php?action=field_catalog_get&key=<catalog_key>
//   POST /api/forms.php?action=field_catalog_upsert   (JSON body, X-IMPORT-KEY required)
//   POST /api/forms.php?action=field_catalog_delete   (JSON body, X-IMPORT-KEY required)
//
// Form writes guarded by X-IMPORT-KEY via env.php (CES_IMPORT_KEY):
//   POST /api/forms.php?action=upsert_form
//   POST /api/forms.php?action=write_schema
//   GET  /api/forms.php?action=clone&form=form_200
//   GET  /api/forms.php?action=delete_form&form=form_200
//
// Optional (run once) — MySQL DDL reference for the field catalog:
//   CREATE TABLE IF NOT EXISTS field_catalog (
//     catalog_key   VARCHAR(80) PRIMARY KEY,
//     label         VARCHAR(255) NOT NULL,
//     type          VARCHAR(32)  NULL,
//     required_def  TINYINT(1)   NOT NULL DEFAULT 0,
//     placeholder   VARCHAR(255) NULL,
//     min_val       VARCHAR(64)  NULL,
//     max_val       VARCHAR(64)  NULL,
//     pattern       VARCHAR(255) NULL,
//     default_val   VARCHAR(255) NULL,
//     updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
//   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ---- Env ----
$envPath = __DIR__ . '/../env.php';
if (!is_readable($envPath)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'env_not_loaded','detail'=>$envPath.' not readable']); exit;
}
require_once $envPath;

if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS')) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'env_constants_missing']); exit;
}

$dsn  = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$user = DB_USER;
$pass = DB_PASS;

$IMPORT_KEY_ENV = defined('CES_IMPORT_KEY') ? CES_IMPORT_KEY : 'CHANGE_ME';

// ---- Helpers ----
function j($x){ echo json_encode($x, JSON_UNESCAPED_SLASHES); exit; }
function body(){ return json_decode(file_get_contents('php://input'), true) ?: []; }
function ces_norm_key($s){ $s = strtolower(preg_replace('/[^a-z0-9]+/','_', (string)$s)); return trim($s,'_'); }

// ---- DB ----
try {
  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  j(['ok'=>false,'error'=>'db_connect_failed']);
}

// Create field_catalog table if it doesn't exist (safe/idempotent)
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS field_catalog (
      catalog_key   VARCHAR(80) PRIMARY KEY,
      label         VARCHAR(255) NOT NULL,
      type          VARCHAR(32)  NULL,
      required_def  TINYINT(1)   NOT NULL DEFAULT 0,
      placeholder   VARCHAR(255) NULL,
      min_val       VARCHAR(64)  NULL,
      max_val       VARCHAR(64)  NULL,
      pattern       VARCHAR(255) NULL,
      default_val   VARCHAR(255) NULL,
      updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");
} catch (Throwable $e) {
  // Do not hard-fail API if DDL fails
}

// ---- Routing / Guard ----
$WRITE_ACTIONS = ['upsert_form','write_schema','clone','delete_form','field_catalog_upsert','field_catalog_delete'];
$action = $_GET['action'] ?? 'list';
if (in_array($action, $WRITE_ACTIONS, true)) {
  $tok = $_SERVER['HTTP_X_IMPORT_KEY'] ?? '';
  if (!$tok || $tok !== $IMPORT_KEY_ENV) {
    http_response_code(403);
    j(['ok'=>false,'error'=>'forbidden']);
  }
}

// =============================
//            READ
// =============================

if ($action === 'list') {
  try {
    $st = $pdo->query("SELECT form_key,title,version,active,updated_at FROM forms ORDER BY form_key");
    j(['ok'=>true,'forms'=>$st->fetchAll()]);
  } catch (Throwable $e) {
    j(['ok'=>false,'error'=>'list_failed','detail'=>$e->getMessage()]);
  }
}

if ($action === 'get') {
  $form = $_GET['form'] ?? '';
  $ver  = isset($_GET['version']) ? (int)$_GET['version'] : null;
  if (!$form) j(['ok'=>false,'error'=>'form required']);

  try {
    if ($ver === null) {
      $st = $pdo->prepare("SELECT version FROM forms WHERE form_key=?");
      $st->execute([$form]);
      $row = $st->fetch();
      if (!$row) j(['ok'=>false,'error'=>'not found']);
      $ver = (int)$row['version'];
    }

    // Sections
    $sec = $pdo->prepare("SELECT section_key,label,ord
                            FROM sections
                           WHERE form_key=? AND version=?
                           ORDER BY ord");
    $sec->execute([$form,$ver]);
    $sections = $sec->fetchAll();

    // Fields
    $fld = $pdo->prepare("SELECT section_key,field_key,label,type,required,placeholder,min_val,max_val,pattern,default_val,ord,options_key,bind_key
                            FROM fields
                           WHERE form_key=? AND version=?
                           ORDER BY ord");
    $fld->execute([$form,$ver]);
    $fields = $fld->fetchAll();

    // Options (only sets referenced by fields)
    $opt = $pdo->prepare("
      SELECT o.options_key, o.value, o.label, o.ord
        FROM options o
       WHERE o.options_key IN (
              SELECT DISTINCT f.options_key
                FROM fields f
               WHERE f.form_key = ? AND f.version = ? AND f.options_key IS NOT NULL
            )
       ORDER BY o.options_key, o.ord
    ");
    $opt->execute([$form,$ver]);
    $optRows = $opt->fetchAll();

    $optMap = [];
    foreach ($optRows as $o) {
      $k = $o['options_key'];
      if (!isset($optMap[$k])) $optMap[$k] = [];
      $optMap[$k][] = [
        'value' => $o['value'],
        'label' => $o['label'],
        'ord'   => (int)$o['ord'],
      ];
    }
    if (empty($optMap)) $optMap = (object)[];

    // Conditions
    $cond = $pdo->prepare("SELECT field_key,show_when,require_when
                             FROM conditions
                            WHERE form_key=? AND version=?");
    $cond->execute([$form,$ver]);
    $conditions = $cond->fetchAll();

    j([
      'ok'         => true,
      'form_key'   => $form,
      'version'    => (int)$ver,
      'sections'   => $sections,
      'fields'     => $fields,
      'options'    => $optMap,
      'conditions' => $conditions
    ]);
  } catch (Throwable $e) {
    j(['ok'=>false,'error'=>'get_failed','detail'=>$e->getMessage()]);
  }
}

// =============================
//            WRITE
// =============================

if ($action === 'upsert_form') {
  $b = body();
  $key     = $b['form_key'] ?? '';
  $title   = $b['title']    ?? '';
  $version = (int)($b['version'] ?? 1);
  $active  = (int)($b['active']  ?? 1);

  if (!$key || !$title) j(['ok'=>false,'error'=>'form_key and title required']);

  try {
    $pdo->prepare("
      INSERT INTO forms(form_key,title,version,active)
      VALUES(?,?,?,?)
      ON DUPLICATE KEY UPDATE
        title  = VALUES(title),
        version= VALUES(version),
        active = VALUES(active)
    ")->execute([$key,$title,$version,$active]);

    j(['ok'=>true]);
  } catch (Throwable $e) {
    j(['ok'=>false,'error'=>'upsert_form_failed','detail'=>$e->getMessage()]);
  }
}

if ($action === 'write_schema') {
  $b    = body();
  $form = $b['form_key'] ?? '';
  $ver  = (int)($b['version'] ?? 1);
  if (!$form) j(['ok'=>false,'error'=>'form_key required']);

  try {
    $pdo->beginTransaction();

    // Clear current schema rows (sections/fields/conditions) for this form/version
    $pdo->prepare("DELETE FROM sections   WHERE form_key=? AND version=?")->execute([$form,$ver]);
    $pdo->prepare("DELETE FROM fields     WHERE form_key=? AND version=?")->execute([$form,$ver]);
    $pdo->prepare("DELETE FROM conditions WHERE form_key=? AND version=?")->execute([$form,$ver]);

    // Options: upsert (global)
    if (!empty($b['options']) && is_array($b['options'])) {
      $insOpt = $pdo->prepare("
        INSERT INTO options(options_key,value,label,ord)
        VALUES(?,?,?,?)
        ON DUPLICATE KEY UPDATE
          label = VALUES(label),
          ord   = VALUES(ord)
      ");
      foreach ($b['options'] as $optKey => $arr) {
        if (!is_array($arr)) continue;
        foreach ($arr as $i => $o) {
          $val = $o['value'] ?? ('opt_'.($i+1));
          $lab = $o['label'] ?? $val;
          $ord = (int)($o['ord'] ?? ($i+1));
          $insOpt->execute([$optKey, $val, $lab, $ord]);
        }
      }
    }

    // Sections
    if (!empty($b['sections']) && is_array($b['sections'])) {
      $insSec = $pdo->prepare("
        INSERT INTO sections(form_key,version,section_key,label,ord)
        VALUES(?,?,?,?,?)
      ");
      foreach ($b['sections'] as $i => $s) {
        $insSec->execute([
          $form, $ver,
          $s['section_key'] ?? ('sec_'.($i+1)),
          $s['label']       ?? ('Section '.($i+1)),
          (int)($s['ord']   ?? ($i+1))
        ]);
      }
    }

    // Fields
    if (!empty($b['fields']) && is_array($b['fields'])) {
      $insFld = $pdo->prepare("
        INSERT INTO fields(
          form_key,version,section_key,field_key,label,type,required,
          placeholder,min_val,max_val,pattern,default_val,ord,options_key,bind_key
        )
        VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
      ");
      foreach ($b['fields'] as $i => $f) {
        $insFld->execute([
          $form, $ver,
          $f['section_key'] ?? 'main',
          $f['field_key']   ?? ('field_'.($i+1)),
          $f['label']       ?? ('Field '.($i+1)),
          isset($f['type']) ? strtolower((string)$f['type']) : null,
          (int)!!($f['required'] ?? 0),
          $f['placeholder'] ?? null,
          $f['min']         ?? null,
          $f['max']         ?? null,
          $f['pattern']     ?? null,
          $f['default']     ?? null,
          (int)($f['ord']   ?? ($i+1)),
          $f['options_key'] ?? null,
          $f['bind']        ?? null
        ]);
      }
    }

    // Conditions
    if (!empty($b['conditions']) && is_array($b['conditions'])) {
      $insCond = $pdo->prepare("
        INSERT INTO conditions(form_key,version,field_key,show_when,require_when)
        VALUES(?,?,?,?,?)
      ");
      foreach ($b['conditions'] as $c) {
        $insCond->execute([
          $form, $ver,
          $c['field_key'] ?? null,
          json_encode($c['show_when']    ?? null),
          json_encode($c['require_when'] ?? null),
        ]);
      }
    }

    $pdo->commit();
    j(['ok'=>true]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    j(['ok'=>false,'error'=>'write_schema_failed','detail'=>$e->getMessage()]);
  }
}

if ($action === 'clone') {
  $form = $_GET['form'] ?? '';
  if (!$form) j(['ok'=>false,'error'=>'form required']);

  try {
    $pdo->beginTransaction();

    $st = $pdo->prepare("SELECT version FROM forms WHERE form_key=?");
    $st->execute([$form]);
    $v  = (int)($st->fetchColumn() ?: 1);
    $nv = $v + 1;

    $pdo->prepare("UPDATE forms SET version=? WHERE form_key=?")->execute([$nv,$form]);

    $pdo->exec("INSERT INTO sections(form_key,version,section_key,label,ord)
                SELECT form_key, {$nv}, section_key, label, ord
                  FROM sections WHERE form_key=".$pdo->quote($form)." AND version={$v}");

    $pdo->exec("INSERT INTO fields(form_key,version,section_key,field_key,label,type,required,placeholder,min_val,max_val,pattern,default_val,ord,options_key,bind_key)
                SELECT form_key, {$nv}, section_key, field_key, label, type, required, placeholder, min_val, max_val, pattern, default_val, ord, options_key, bind_key
                  FROM fields WHERE form_key=".$pdo->quote($form)." AND version={$v}");

    $pdo->exec("INSERT INTO conditions(form_key,version,field_key,show_when,require_when)
                SELECT form_key, {$nv}, field_key, show_when, require_when
                  FROM conditions WHERE form_key=".$pdo->quote($form)." AND version={$v}");

    $pdo->commit();
    j(['ok'=>true,'new_version'=>$nv]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    j(['ok'=>false,'error'=>'clone_failed','detail'=>$e->getMessage()]);
  }
}

if ($action === 'delete_form') {
  $form = $_GET['form'] ?? '';
  if (!$form) j(['ok'=>false,'error'=>'form required']);

  try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM sections   WHERE form_key=?")->execute([$form]);
    $pdo->prepare("DELETE FROM fields     WHERE form_key=?")->execute([$form]);
    $pdo->prepare("DELETE FROM conditions WHERE form_key=?")->execute([$form]);
    $pdo->prepare("DELETE FROM forms      WHERE form_key=?")->execute([$form]);
    $pdo->commit();
    j(['ok'=>true]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    j(['ok'=>false,'error'=>'delete_failed','detail'=>$e->getMessage()]);
  }
}

// =============================
//     FIELD CATALOG (NEW)
// =============================

if ($action === 'field_catalog_list') {
  try {
    // Load catalog rows
    $st = $pdo->query("SELECT catalog_key,label,type,required_def,placeholder,min_val,max_val,pattern,default_val,updated_at
                         FROM field_catalog
                        ORDER BY catalog_key");
    $rows = $st->fetchAll();

    // Load options for any catalog_key that has an options set
    $opt = $pdo->query("SELECT options_key,value,label,ord FROM options ORDER BY options_key, ord");
    $optRows = $opt->fetchAll();
    $optMap = [];
    foreach ($optRows as $o) {
      $k = $o['options_key'];
      if (!isset($optMap[$k])) $optMap[$k] = [];
      $optMap[$k][] = ['value'=>$o['value'],'label'=>$o['label'],'ord'=>(int)$o['ord']];
    }

    // Bundle options by catalog_key
    $out = [];
    foreach ($rows as $r) {
      $k = $r['catalog_key'];
      $out[] = [
        'catalog_key'  => $k,
        'label'        => $r['label'],
        'type'         => $r['type'],
        'required_def' => (int)$r['required_def'],
        'placeholder'  => $r['placeholder'],
        'min_val'      => $r['min_val'],
        'max_val'      => $r['max_val'],
        'pattern'      => $r['pattern'],
        'default_val'  => $r['default_val'],
        'updated_at'   => $r['updated_at'],
        'options'      => $optMap[$k] ?? []
      ];
    }
    j(['ok'=>true,'catalog'=>$out]);
  } catch (Throwable $e) {
    j(['ok'=>false,'error'=>'catalog_list_failed','detail'=>$e->getMessage()]);
  }
}

if ($action === 'field_catalog_get') {
  $key = $_GET['key'] ?? '';
  if (!$key) j(['ok'=>false,'error'=>'key required']);
  try {
    $st = $pdo->prepare("SELECT catalog_key,label,type,required_def,placeholder,min_val,max_val,pattern,default_val,updated_at
                           FROM field_catalog WHERE catalog_key=?");
    $st->execute([$key]);
    $row = $st->fetch();
    if (!$row) j(['ok'=>false,'error'=>'not found']);

// options set uses the catalog_key as options_key
    $opt = $pdo->prepare("SELECT value,label,ord FROM options WHERE options_key=? ORDER BY ord");
    $opt->execute([$key]);
    $opts = $opt->fetchAll();

    j(['ok'=>true,'item'=>$row,'options'=>$opts]);
  } catch (Throwable $e) {
    j(['ok'=>false,'error'=>'catalog_get_failed','detail'=>$e->getMessage()]);
  }
}

if ($action === 'field_catalog_upsert') {
  $b = body();
  $catalog_key  = ces_norm_key($b['catalog_key'] ?? '');
  $label        = $b['label'] ?? '';
  $type         = isset($b['type']) ? strtolower((string)$b['type']) : null;
  $required_def = (int)!!($b['required_def'] ?? 0);
  $placeholder  = $b['placeholder'] ?? null;
  $min_val      = $b['min_val'] ?? null;
  $max_val      = $b['max_val'] ?? null;
  $pattern      = $b['pattern'] ?? null;
  $default_val  = $b['default_val'] ?? null;
  $options      = is_array($b['options'] ?? null) ? $b['options'] : null;

  if (!$catalog_key || !$label) j(['ok'=>false,'error'=>'catalog_key and label required']);

  try {
    $pdo->beginTransaction();

    $pdo->prepare("
      INSERT INTO field_catalog(catalog_key,label,type,required_def,placeholder,min_val,max_val,pattern,default_val)
      VALUES(?,?,?,?,?,?,?,?,?)
      ON DUPLICATE KEY UPDATE
        label=VALUES(label),
        type=VALUES(type),
        required_def=VALUES(required_def),
        placeholder=VALUES(placeholder),
        min_val=VALUES(min_val),
        max_val=VALUES(max_val),
        pattern=VALUES(pattern),
        default_val=VALUES(default_val)
    ")->execute([$catalog_key,$label,$type,$required_def,$placeholder,$min_val,$max_val,$pattern,$default_val]);

    // Replace options set for this catalog_key if provided
    if ($options !== null) {
      $pdo->prepare("DELETE FROM options WHERE options_key=?")->execute([$catalog_key]);
      $insOpt = $pdo->prepare("INSERT INTO options(options_key,value,label,ord) VALUES(?,?,?,?)");
      $i=0;
      foreach ($options as $o) {
        $i++;
        $val = $o['value'] ?? ('opt_'.$i);
        $lab = $o['label'] ?? $val;
        $ord = (int)($o['ord'] ?? $i);
        $insOpt->execute([$catalog_key,$val,$lab,$ord]);
      }
    }

    $pdo->commit();
    j(['ok'=>true]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    j(['ok'=>false,'error'=>'catalog_upsert_failed','detail'=>$e->getMessage()]);
  }
}

if ($action === 'field_catalog_delete') {
  $b = body();
  $catalog_key = ces_norm_key($b['catalog_key'] ?? '');
  if (!$catalog_key) j(['ok'=>false,'error'=>'catalog_key required']);
  try {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM field_catalog WHERE catalog_key=?")->execute([$catalog_key]);
    // Also remove its options set (safe, scoped to this key)
    $pdo->prepare("DELETE FROM options WHERE options_key=?")->execute([$catalog_key]);
    $pdo->commit();
    j(['ok'=>true]);
  } catch (Throwable $e) {
    $pdo->rollBack();
    j(['ok'=>false,'error'=>'catalog_delete_failed','detail'=>$e->getMessage()]);
  }
}

// =============================
//     LEGACY CATALOG (read-only)
// =============================

if ($action === 'catalog_list') {
  try {
    $st   = $pdo->query("SELECT s_form_num, form_id, form_url, sform_id, active, updated_at FROM form_catalog");
    $rows = $st->fetchAll();
    $out  = [];
    foreach ($rows as $r) {
      $sFormNum = $r['s_form_num'] ?? '';
      $out[] = [
        'sFormNum'         => $sFormNum,
        'form_key'         => 'form_' . ces_norm_key($sFormNum),
        'title'            => 'Form ' . $sFormNum,
        'gform_id'         => $r['form_id'] ?? null,
        'response_sheet_id'=> null,
        'active'           => (int)($r['active'] ?? 1),
        'last_imported_at' => $r['updated_at'] ?? null,
        'form_url'         => $r['form_url'] ?? null,
        'sform_id'         => $r['sform_id'] ?? null,
      ];
    }
    j(['ok'=>true,'catalog'=>$out]);
  } catch (Throwable $e) {
    http_response_code(500);
    j(['ok'=>false,'error'=>'catalog_query_failed','detail'=>$e->getMessage()]);
  }
}

if ($action === 'catalog_upsert') { j(['ok'=>false,'error'=>'not_supported']); }
if ($action === 'catalog_touch')  { j(['ok'=>true]); }
if ($action === 'catalog_delete') { j(['ok'=>false,'error'=>'not_supported']); }

// Fallback
j(['ok'=>false,'error'=>'unknown action']);
