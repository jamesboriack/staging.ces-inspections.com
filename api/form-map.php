<?php
// form-map.php — list/lookup for form_map (new columns + legacy aliases)
// v2025-11-03
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
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

  $action = $_GET['action'] ?? 'list';

  if ($action === 'list') {
    $rows = $pdo->query("
      SELECT
        category_key,
        COALESCE(category_label, category_key) AS category_label,
        type_key,
        COALESCE(type_label, type_key)         AS type_label,
        form_key,
        s_form_num,
        active,
        updated_at,
        /* legacy aliases so older UI doesn't break */
        COALESCE(category_label, category_key) AS category,
        COALESCE(type_label, type_key)         AS type
      FROM form_map
      ORDER BY category_label, type_label
    ")->fetchAll();
    echo json_encode(['ok'=>true,'rows'=>$rows]); exit;
  }

  if ($action === 'lookup') {
    $cat = trim((string)($_GET['category'] ?? ''));
    $typ = trim((string)($_GET['type'] ?? ''));
    if ($cat === '' || $typ === '') { echo json_encode(['ok'=>false,'error'=>'missing_params']); exit; }

    // Accept either keys or labels (case-insensitive)
    $sql = "
      SELECT form_key
      FROM form_map
      WHERE active = 1
        AND (LOWER(category_key) = LOWER(:cat) OR LOWER(COALESCE(category_label, category_key)) = LOWER(:cat))
        AND (LOWER(type_key)     = LOWER(:typ) OR LOWER(COALESCE(type_label, type_key))       = LOWER(:typ))
      LIMIT 1
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':cat'=>$cat, ':typ'=>$typ]);
    $row = $st->fetch();
    if (!$row) { echo json_encode(['ok'=>false,'error'=>'not_found']); exit; }

    echo json_encode(['ok'=>true,'form_key'=>$row['form_key']]); exit;
  }

  echo json_encode(['ok'=>false,'error'=>'bad_action']);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'SERVER_ERROR','detail'=>$e->getMessage()]);
}
