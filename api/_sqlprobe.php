<?php
header('Content-Type: text/plain');

// Load the same env + DSN as forms.php
require_once __DIR__.'/../env.php';
$dsn = 'mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';

try {
  $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  echo "DB: OK\n";
} catch (Throwable $e) {
  echo "DB: FAIL\n".$e->getMessage()."\n";
  exit;
}

// 1) List tables (verify you’re on the DB you think you are)
try {
  $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM);
  echo "TABLES:\n";
  foreach ($rows as $r) echo " - ".$r[0]."\n";
} catch (Throwable $e) {
  echo "SHOW TABLES error: ".$e->getMessage()."\n";
}

// 2) Describe the catalog table
try {
  $cols = $pdo->query('DESCRIBE form_catalog')->fetchAll(PDO::FETCH_ASSOC);
  echo "\nform_catalog columns:\n";
  foreach ($cols as $c) echo " - ".$c['Field']." (".$c['Type'].")\n";
} catch (Throwable $e) {
  echo "\nDESCRIBE form_catalog error: ".$e->getMessage()."\n";
}

// 3) Run the same SELECT your endpoint uses
try {
  $sql = "SELECT id,sFormNum,form_key,title,gform_id,response_sheet_id,active,last_imported_at
          FROM form_catalog ORDER BY sFormNum, form_key";
  $st = $pdo->query($sql);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  echo "\nSELECT ok. Rows: ".count($rows)."\n";
} catch (Throwable $e) {
  echo "\nSELECT error: ".$e->getMessage()."\n";
}
