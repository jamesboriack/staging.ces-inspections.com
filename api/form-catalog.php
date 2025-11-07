<?php
declare(strict_types=1);

/**
 * /api/form-catalog.php (STAGING)
 * Actions:
 *  - GET  action=list
 *  - GET  action=categories
 *  - GET  action=types&category_key=2
 *  - POST action=upsert_many  (multipart: csv=@file.csv  OR raw text/csv with ?action=upsert_many)
 *  - POST action=upsert_one   (JSON body: {category_label,category_key,type_label,type_key,s_form_num})
 */

require __DIR__ . '/../inc/bootstrap.php';

$db = ces_pdo();

function out($data, $code=200){ ces_json_out($data,$code); }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
  if ($action === 'list') {
    $rows = $db->query("
      SELECT category_label, category_key, type_label, type_key, s_form_num, active
      FROM form_catalog
      ORDER BY COALESCE(category_label,''), COALESCE(type_label,'')
    ")->fetchAll();
    out(['ok'=>true, 'rows'=>$rows]);

  } elseif ($action === 'categories') {
    $rows = $db->query("
      SELECT DISTINCT category_label, category_key
      FROM form_catalog
      WHERE active=1
      ORDER BY COALESCE(category_label,'')
    ")->fetchAll();
    out(['ok'=>true, 'categories'=>$rows]);

  } elseif ($action === 'types') {
    $catKey = trim((string)($_GET['category_key'] ?? ''));
    if ($catKey === '') out(['ok'=>false,'error'=>'MISSING_PARAM','detail'=>'category_key required'],400);
    $st = $db->prepare("
      SELECT type_label, type_key, s_form_num
      FROM form_catalog
      WHERE active=1 AND category_key=?
      ORDER BY COALESCE(type_label,'')
    ");
    $st->execute([$catKey]);
    $rows = $st->fetchAll();
    out(['ok'=>true, 'types'=>$rows]);

  } elseif ($action === 'upsert_one') {
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    if (!is_array($json)) out(['ok'=>false,'error'=>'BAD_JSON'],400);

    $row = [
      'category_label' => trim((string)($json['category_label'] ?? '')),
      'category_key'   => trim((string)($json['category_key']   ?? '')),
      'type_label'     => trim((string)($json['type_label']     ?? '')),
      'type_key'       => trim((string)($json['type_key']       ?? '')),
      's_form_num'     => trim((string)($json['s_form_num']     ?? '')),
      'active'         => (int)($json['active'] ?? 1),
    ];
    if ($row['category_key']==='' || $row['type_key']==='' || $row['s_form_num']==='') {
      out(['ok'=>false,'error'=>'MISSING_FIELDS','need'=>'category_key,type_key,s_form_num'],400);
    }

    $sql = "
      INSERT INTO form_catalog (category_label,category_key,type_label,type_key,s_form_num,active)
      VALUES (:category_label,:category_key,:type_label,:type_key,:s_form_num,:active)
      ON DUPLICATE KEY UPDATE
        category_label=VALUES(category_label),
        type_label=VALUES(type_label),
        s_form_num=VALUES(s_form_num),
        active=VALUES(active),
        updated_at=NOW()
    ";
    $st = $db->prepare($sql);
    $st->execute($row);
    out(['ok'=>true, 'row'=>$row]);

  } elseif ($action === 'upsert_many') {
    // Accept CSV either from multipart (csv=@file) or raw body (?action=upsert_many)
    $csvText = '';
    if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
      $csvText = file_get_contents($_FILES['csv']['tmp_name']);
    } else {
      $csvText = file_get_contents('php://input') ?: '';
    }
    if ($csvText === '') out(['ok'=>false,'error'=>'EMPTY_CSV'],400);

    // Normalize line endings
    $csvText = str_replace("\r\n", "\n", $csvText);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $csvText)), fn($l)=>$l!==''));
    if (count($lines) < 2) out(['ok'=>false,'error'=>'CSV_NO_ROWS'],400);

    // header
    $hdr = str_getcsv(array_shift($lines));
    $want = ['category_label','category_key','type_label','type_key','s_form_num'];
    $idx = [];
    foreach ($want as $w) {
      $pos = array_search($w, $hdr, true);
      if ($pos === false) out(['ok'=>false,'error'=>'CSV_HEADER_MISSING','need'=>$want,'got'=>$hdr],400);
      $idx[$w] = $pos;
    }

    $db->beginTransaction();
    $sql = "
      INSERT INTO form_catalog (category_label,category_key,type_label,type_key,s_form_num,active)
      VALUES (:category_label,:category_key,:type_label,:type_key,:s_form_num,:active)
      ON DUPLICATE KEY UPDATE
        category_label=VALUES(category_label),
        type_label=VALUES(type_label),
        s_form_num=VALUES(s_form_num),
        active=VALUES(active),
        updated_at=NOW()
    ";
    $st = $db->prepare($sql);

    $n=0; $errors=[];
    foreach ($lines as $lnum => $line) {
      $cols = str_getcsv($line);
      $row = [
        'category_label' => trim((string)($cols[$idx['category_label']] ?? '')),
        'category_key'   => trim((string)($cols[$idx['category_key']]   ?? '')),
        'type_label'     => trim((string)($cols[$idx['type_label']]     ?? '')),
        'type_key'       => trim((string)($cols[$idx['type_key']]       ?? '')),
        's_form_num'     => trim((string)($cols[$idx['s_form_num']]     ?? '')),
        'active'         => 1,
      ];
      if ($row['category_key']==='' || $row['type_key']==='' || $row['s_form_num']==='') {
        $errors[] = ['line'=>$lnum+2,'error'=>'MISSING_REQUIRED','row'=>$row];
        continue;
      }
      try { $st->execute($row); $n++; }
      catch (Throwable $e) { $errors[] = ['line'=>$lnum+2,'error'=>$e->getMessage(),'row'=>$row]; }
    }
    $db->commit();

    out(['ok'=>true,'imported'=>$n,'errors'=>$errors]);

  } else {
    out(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'],405);
  }

} catch (Throwable $e) {
  out(['ok'=>false,'error'=>'FATAL','detail'=>$e->getMessage()],500);
}
