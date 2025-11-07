<?php
declare(strict_types=1);
header('Content-Type: application/json; 
charset=utf-8');
try {
  $env = __DIR__ . '/../config/env.php';
  if (!is_readable($env)) { throw new 
RuntimeException('ENV_MISSING: '.$env); }
  $cfg = require $env;
  $csv = realpath(__DIR__.'/../catalog.csv');
  echo json_encode([
    'ok'=>true,'stage'=>'probe',
    'php'=>PHP_VERSION,
    
'csv_exists'=>(bool)$csv,'csv_path'=>$csv?:null
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo 
json_encode(['ok'=>false,'error'=>get_class($e),'msg'=>$e->getMessage()]);
}
<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
try {
  $env = __DIR__ . '/../config/env.php';
  if (!is_readable($env)) { throw new RuntimeException('ENV_MISSING: '.$env); }
  $cfg = require $env;
  $csv = realpath(__DIR__.'/../catalog.csv');
  echo json_encode([
    'ok'=>true,'stage'=>'probe',
    'php'=>PHP_VERSION,
    'csv_exists'=>(bool)$csv,'csv_path'=>$csv?:null
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>get_class($e),'msg'=>$e->getMessage()]);
}
^/

