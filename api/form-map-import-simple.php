<?php
// form-map-import-simple.php — import catalog.csv -> form_map
// Uses config/env.php for DB creds. GET = probe (no auth), POST = import (X-IMPORT-KEY)

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// JSON errors
set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
set_exception_handler(function(Throwable $e){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>get_class($e),'msg'=>$e->getMessage()]);
  exit;
});

// Load env + connect
$env = __DIR__ . '/../config/env.php';
if (!is_readable($env)) throw new RuntimeException('ENV_MISSING: '.$env);
$cfg = require $env;

$dsn  = $cfg['dsn']  ?? ('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4');
$user = $cfg['user'] ?? DB_USER;
$pass = $cfg['pass'] ?? DB_PASS;

$pdo  = new PDO($dsn,$user,$pass,[
  PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
]);

// Ensure table (new schema)
$pdo->exec(
  "CREATE TABLE IF NOT EXISTS form_map (
     category_key    VARCHAR(100)  NOT NULL,
     category_label  VARCHAR(150)  NULL,
     type_key        VARCHAR(150)  NOT NULL,
     type_label      VARCHAR(150)  NULL,
     form_key        VARCHAR(150)  NOT NULL,
     s_form_num      VARCHAR(50)   NULL,
     active          TINYINT(1)    NOT NULL DEFAULT 1,
     updated_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     PRIMARY KEY (category_key, type_key),
     KEY idx_form_key (form_key),
     KEY idx_active  (active)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);

// GET = probe (no auth)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  $csv = realpath(__DIR__ . '/../catalog.csv');
  echo json_encode([
    'ok'=>true,'stage'=>'probe',
    'php'=>PHP_VERSION,
    'db'=>$pdo->query('SELECT DATABASE()')->fetchColumn(),
    'csv_exists'=>(bool)$csv,'csv_path'=>$csv?:null
  ]);
  exit;
}

// POST = import (auth)
$hdr = $_SERVER['HTTP_X_IMPORT_KEY'] ?? '';
if (!defined('CES_IMPORT_KEY')) throw new RuntimeException('IMPORT_KEY_UNDEFINED');
if (!hash_equals(CES_IMPORT_KEY, $hdr)) {
  http_response_code(401);
  echo json_encode(['ok'=>false,'error'=>'UNAUTHORIZED']);
  exit;
}

// Open CSV
$csv = realpath(__DIR__ . '/../catalog.csv');
if (!$csv || !is_readable($csv)) throw new RuntimeException('CSV_NOT_FOUND: '.($csv ?: '(null)'));
$fh = fopen($csv,'r'); if(!$fh) throw new RuntimeException('OPEN_FAIL');

// Header mapping (tolerant to old/new names)
$header = fgetcsv($fh); if(!$header) throw new RuntimeException('HEADER_MISSING');
$norm = function(string $s): string { return preg_replace('/[\s_\-]+/','',strtolower($s)); };
$h = []; foreach ($header as $i=>$name) { $h[$norm((string)$name)] = $i; }

$need = [
  'category_key' => ['category_key','category'],
  'type_key'     => ['type_key','type'],
  'form_key'     => ['form_key','form','formid','form_id','formnumber','formnum','sform','sformnum'],
];
$opt  = [
  'category_label' => ['category_label','categoryname','categorytitle'],
  'type_label'     => ['type_label','typename','typetitle'],
  's_form_num'     => ['s_form_num','sform','sformnum','formnumber','form_num'],
  'active'         => ['active'],
];

$col = []; $missing = [];
foreach ($need as $k=>$aliases){
  $idx=null; foreach($aliases as $a){ $kk=$norm($a); if(array_key_exists($kk,$h)){ $idx=$h[$kk]; break; } }
  if($idx===null){ $missing[$k]=$aliases; } else { $col[$k]=$idx; }
}
if ($missing) throw new RuntimeException('MISSING_REQUIRED_COLS:'.json_encode($missing).' header='.json_encode($header));
foreach ($opt as $k=>$aliases){
  foreach($aliases as $a){ $kk=$norm($a); if(array_key_exists($kk,$h)){ $col[$k]=$h[$kk]; break; } }
}

// Import (truncate + insert)
$inserted = 0;
$pdo->beginTransaction();
try {
  $pdo->exec('TRUNCATE TABLE form_map');
  $ins = $pdo->prepare('INSERT INTO form_map (category_key,category_label,type_key,type_label,form_key,s_form_num,active) VALUES (?,?,?,?,?,?,?)');

  while (($row = fgetcsv($fh)) !== false) {
    $category_key = trim((string)($row[$col['category_key']] ?? ''));
    $type_key     = trim((string)($row[$col['type_key']]     ?? ''));
    $form_key     = trim((string)($row[$col['form_key']]     ?? ''));
    if ($category_key === '' || $type_key === '' || $form_key === '') continue;

    $category_label = isset($col['category_label']) ? trim((string)($row[$col['category_label']] ?? '')) : '';
    $type_label     = isset($col['type_label'])     ? trim((string)($row[$col['type_label']] ?? ''))     : '';
    $s_form_num     = isset($col['s_form_num'])     ? trim((string)($row[$col['s_form_num']] ?? ''))     : '';

    if ($category_label === '') $category_label = $category_key;
    if ($type_label === '')     $type_label     = $type_key;

    $active = 1;
    if (isset($col['active'])) {
      $raw = strtolower(trim((string)($row[$col['active']] ?? '1')));
      $active = in_array($raw, ['0','false','no','n'], true) ? 0 : 1;
    }

    $ins->execute([$category_key,$category_label,$type_key,$type_label,$form_key,$s_form_num,$active]);
    $inserted++;
  }
  fclose($fh);
  if ($pdo->inTransaction()) $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $e;
}

echo json_encode(['ok'=>true,'stage'=>'import-ok','csv'=>basename($csv),'rows_imported'=>$inserted]);