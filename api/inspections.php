<?php
// /api/inspections.php

header('Content-Type: application/json');

// Try to load env.php if present (don't fatally error if missing)
$envPath = __DIR__ . '/../env.php';
if (is_file($envPath)) { require_once $envPath; }

/* ---------- Lazy PDO: don't connect unless an action needs it ---------- */
function get_pdo() {
  // If your forms API already exposes db(), reuse it
  if (function_exists('db')) {
    $pdo = db();
    if ($pdo instanceof PDO) return $pdo;
  }
  // DSN via constants (if set), else sane defaults
  if (defined('CES_DB_DSN')) {
    $dsn = CES_DB_DSN;
    $usr = defined('CES_DB_USER') ? CES_DB_USER : null;
    $pwd = defined('CES_DB_PASS') ? CES_DB_PASS : null;
  } else {
    $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
    $name = defined('DB_NAME') ? DB_NAME : 'ces_stg';
    $usr  = defined('DB_USER') ? DB_USER : 'root';
    $pwd  = defined('DB_PASS') ? DB_PASS : '';
    $dsn  = "mysql:host=$host;dbname=$name;charset=utf8mb4";
  }
  return new PDO($dsn, $usr, $pwd, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
}

/* ---------- Router ---------- */
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// Fast path: ping should never touch the DB
if ($action === 'ping') {
  echo json_encode(['ok' => true, 'pong' => time()]);
  return;
}

try {
  // Connect only for actions that need DB
  $pdo = get_pdo();

  switch ($action) {
    case 'start':         return action_start($pdo);
    case 'save_step':     return action_save_step($pdo);
    case 'upload_photo':  return action_upload_photo($pdo);
    case 'finalize':      return action_finalize($pdo);
    default:              http_response_code(400); echo json_encode(['ok'=>false,'error'=>'unknown action']); return;
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
  return;
}


/* ---------- Actions ---------- */

function action_start(PDO $pdo) {
  $in = json_in();
  $form_key   = trim((string)($in['form_key'] ?? 'form_201'));
  $version    = (int)($in['version'] ?? 1);
  $employee_id= trim((string)($in['employee_id'] ?? '')); // your column is VARCHAR(64)
  $flow       = 'wizard';
  $source_flow= $form_key . '@' . $version;

  // reuse latest draft for same form/version/employee if exists
  $q = $pdo->prepare("SELECT id FROM inspections
                      WHERE (form_key<=>? OR ? IS NULL)
                        AND (version<=>? OR ? IS NULL)
                        AND (employee_id<=>? OR ? = '')
                        AND submitted_at IS NULL
                      ORDER BY created_at DESC LIMIT 1");
  $q->execute([$form_key,$form_key,$version,$version,$employee_id,$employee_id]);
  if ($row = $q->fetch()) {
    echo json_encode(['ok'=>true,'inspection_id'=>(int)$row['id']]);
    return;
  }

  // generate required inspect_id (varchar, NOT NULL)
  $inspect_id = gen_inspect_id();

  // Build column list that matches your table (minimal + our optional columns)
  $sql = "INSERT INTO inspections(inspect_id, form_key, version, employee_id, flow, source_flow)
          VALUES (?,?,?,?,?,?)";
  $st  = $pdo->prepare($sql);
  $st->execute([$inspect_id, $form_key, $version, ($employee_id!==''?$employee_id:null), $flow, $source_flow]);

  echo json_encode(['ok'=>true,'inspection_id'=>(int)$pdo->lastInsertId(),'inspect_id'=>$inspect_id]);
}

function action_save_step(PDO $pdo) {
  $in = json_in();
  $id = (int)($in['inspection_id'] ?? 0);
  $answers = is_array($in['answers'] ?? null) ? $in['answers'] : [];
  if (!$id) bad('inspection_id required');

  $pdo->beginTransaction();
  $stmt = $pdo->prepare("INSERT INTO inspection_answers
    (inspection_id, field_key, bind_key, value_text, value_num, value_date)
    VALUES (?,?,?,?,?,?)
    ON DUPLICATE KEY UPDATE
      field_key=VALUES(field_key),
      value_text=VALUES(value_text),
      value_num=VALUES(value_num),
      value_date=VALUES(value_date)");

  foreach ($answers as $a) {
    $field_key = substr((string)($a['field_key'] ?? ''), 0, 128);
    $bind_key  = substr((string)($a['bind_key']  ?? ''), 0, 128);
    if ($bind_key === '') continue;

    $vtxt = null; $vnum = null; $vdate = null;
    // Normalize any of value_text/value_num/value_date/value
    if (isset($a['value_date']) && $a['value_date']) {
      $vdate = (string)$a['value_date'];
    } elseif (isset($a['value_num']) && $a['value_num'] !== '') {
      $vnum = (float)$a['value_num'];
    } else {
      $raw = isset($a['value_text']) ? (string)$a['value_text'] : (isset($a['value']) ? (string)$a['value'] : '');
      if ($raw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) $vdate = $raw;
      elseif ($raw !== '' && is_numeric($raw)) $vnum = (float)$raw;
      else $vtxt = $raw;
    }

    $stmt->execute([$id, $field_key, $bind_key, $vtxt, $vnum, $vdate]);
  }
  $pdo->commit();
  echo json_encode(['ok'=>true]);
}

function action_upload_photo(PDO $pdo) {
  $id = isset($_POST['inspection_id']) ? (int)$_POST['inspection_id'] : 0;
  if (!$id || !isset($_FILES['file'])) bad('inspection_id and file required');

  $purpose = strtolower((string)($_POST['purpose'] ?? 'other'));
  $field   = isset($_POST['field_key']) ? (string)$_POST['field_key'] : null;

  $f = $_FILES['file'];
  if ($f['error'] !== UPLOAD_ERR_OK) bad('upload error '.$f['error']);

  $allowed = ['jpg','jpeg','png','webp','heic','heif'];
  $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
  if (!in_array($ext, $allowed, true)) $ext = 'jpg';

  $root = rtrim($_SERVER['DOCUMENT_ROOT'] ?: realpath(__DIR__.'/..'), '/');
  $dir  = $root . '/uploads/inspections/' . $id;
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $name = uniqid('', true) . '.' . $ext;
  $dst  = $dir . '/' . $name;
  if (!move_uploaded_file($f['tmp_name'], $dst)) bad('failed to save file');

  $rel  = '/uploads/inspections/' . $id . '/' . $name;
  $ins  = $pdo->prepare("INSERT INTO inspection_photos(inspection_id,field_key,purpose,file_path)
                         VALUES (?,?,?,?)");
  $ins->execute([$id, $field, $purpose, $rel]);

  echo json_encode(['ok'=>true,'photo_id'=>(int)$pdo->lastInsertId(),'file_path'=>$rel]);
}

function action_finalize(PDO $pdo) {
  $in = json_in();
  $id = (int)($in['inspection_id'] ?? 0);
  $recips = array_values(array_unique(array_filter(is_array($in['send_to'] ?? []) ? $in['send_to'] : [])));
  if (!$id) bad('inspection_id required');

  $ins    = row($pdo, "SELECT * FROM inspections WHERE id=?", [$id]);
  if (!$ins) bad('not found');

  $answers= rows($pdo, "SELECT * FROM inspection_answers WHERE inspection_id=? ORDER BY bind_key", [$id]);
  $photos = rows($pdo, "SELECT * FROM inspection_photos WHERE inspection_id=? ORDER BY photo_id", [$id]);

  $html   = render_summary_html($ins, $answers, $photos);
  $pdfUrl = save_pdf_or_html($id, $html);

  $u = $pdo->prepare("UPDATE inspections SET submitted_at=NOW() WHERE id=?");
  $u->execute([$id]);

  if (!empty($recips)) email_with_attachment($recips, "Inspection #$id", "See attached.", $pdfUrl);

  echo json_encode(['ok'=>true,'submitted_at'=>date('c'),'pdf_url'=>$pdfUrl]);
}

/* ---------- Helpers ---------- */
function json_in() {
  $raw = file_get_contents('php://input');
  if ($raw === '' || $raw === false) return [];
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}
function bad($msg){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }
function row(PDO $pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); return $s->fetch(); }
function rows(PDO $pdo,$sql,$p=[]){ $s=$pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }

function gen_inspect_id() {
  // human-ish unique value for your NOT NULL `inspect_id`
  return 'INS-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
}

function render_summary_html($ins,$answers,$photos){
  $kv = [];
  foreach ($answers as $a) {
    $val = $a['value_text'];
    if ($val === null && $a['value_num'] !== null) $val = (string)$a['value_num'];
    if ($val === null && $a['value_date'] !== null) $val = (string)$a['value_date'];
    $kv[$a['bind_key']] = $val;
  }
  $ph = '';
  foreach ($photos as $p) {
    $ph .= '<div style="margin:6px 0"><div style="font:12px monospace;color:#666">'
        . htmlspecialchars($p['purpose'].' · '.($p['field_key']?:''))
        . '</div><img src="'.htmlspecialchars($p['file_path']).'" style="max-width:240px;max-height:180px;object-fit:cover;border:1px solid #ccc"/></div>';
  }
  return '<html><head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif}table{border-collapse:collapse}td,th{border:1px solid #ddd;padding:6px}</style></head><body>'
       . '<h2>Inspection #'.(int)$ins['id'].'</h2>'
       . '<div>Inspect ID: '.htmlspecialchars($ins['inspect_id']).'</div>'
       . (isset($ins['form_key']) ? '<div>Form: '.htmlspecialchars($ins['form_key']).(isset($ins['version'])?' v'.(int)$ins['version']:'').'</div>' : '')
       . '<div>Created: '.htmlspecialchars($ins['created_at']).'</div>'
       . '<h3>Answers</h3><table><tbody>'
       . implode('', array_map(function($k) use ($kv){ return '<tr><th>'.htmlspecialchars($k).'</th><td>'.htmlspecialchars((string)($kv[$k]??'')) .'</td></tr>'; }, array_keys($kv)))
       . '</tbody></table>'
       . '<h3>Photos</h3>'.$ph
       . '</body></html>';
}

function save_pdf_or_html($id,$html){
  $root = rtrim($_SERVER['DOCUMENT_ROOT'] ?: realpath(__DIR__.'/..'), '/');
  $dir  = $root . '/pdf/inspections';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  if (class_exists('\\Dompdf\\Dompdf')) {
    $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled'=>true]);
    $dompdf->loadHtml($html); $dompdf->setPaper('letter','portrait'); $dompdf->render();
    file_put_contents($dir . "/$id.pdf", $dompdf->output());
    return '/pdf/inspections/' . $id . '.pdf';
  }
  file_put_contents($dir . "/$id.html", $html);
  return '/pdf/inspections/' . $id . '.html';
}

function email_with_attachment($to,$subject,$body,$publicPath){
  if (!is_array($to) || !count($to)) return;
  $root = rtrim($_SERVER['DOCUMENT_ROOT'] ?: realpath(__DIR__.'/..'), '/');
  $abs  = $root . $publicPath;

  if (class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
    $m = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
      $m->setFrom('no-reply@'.($_SERVER['SERVER_NAME'] ?? 'localhost'), 'CES');
      foreach ($to as $addr) $m->addAddress($addr);
      $m->Subject = $subject; $m->Body = $body;
      if (is_readable($abs)) $m->addAttachment($abs, basename($abs));
      $m->send(); return;
    } catch (\Throwable $e) { /* fallback */ }
  }
  @mail(implode(',', $to), $subject, $body . "\n\nAttachment: " . $publicPath);
}
