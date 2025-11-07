<?php
declare(strict_types=1);

require __DIR__ . '/../inc/bootstrap.php';

try {
  $db  = ces_pdo();
  $dbName = (string)$db->query('SELECT DATABASE()')->fetchColumn();

  ces_json_out([
    'ok'        => true,
    'php'       => PHP_VERSION,
    'file'      => __FILE__,
    'bootstrap' => __DIR__ . '/../inc/bootstrap.php',
    'configDir' => dirname(CES_STAGING_ROOT . '/config/env.php'),
    'db_name'   => $dbName,
  ]);
} catch (Throwable $e) {
  ces_json_out(['ok'=>false,'error'=>'INIT_FAIL','detail'=>$e->getMessage()], 500);
}