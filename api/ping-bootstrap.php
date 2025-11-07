<?php
declare(strict_types=1);

// staging/api/ping-bootstrap.php
require __DIR__ . '/../inc/bootstrap.php';

try {
  $db = ces_pdo();
  $dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();
  $cfgFile = dirname(__DIR__, 2) . '/config/env.php';
  ces_json_out([
    'ok'        => true,
    'php'       => PHP_VERSION,
    'file'      => __FILE__,
    'bootstrap' => __DIR__ . '/../inc/bootstrap.php',
    'configDir' => dirname($cfgFile),
    'db_name'   => $dbName,
  ]);
} catch (Throwable $e) {
  ces_json_out(['ok'=>false,'error'=>'INIT_FAIL','detail'=>$e->getMessage()], 500);
}