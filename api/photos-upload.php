<?php
// /api/photos-upload.php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache'); header('Expires: 0');
header('Content-Type: application/json; charset=utf-8');

$inspect = preg_replace('~[^A-Za-z0-9\-\_\.]~','', $_POST['inspect'] ?? '');
$kind    = $_POST['kind'] ?? 'walk';
$kind    = in_array($kind,['walk','repair']) ? $kind : 'walk';

if (!$inspect || empty($_FILES['file']['tmp_name'])){
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'inspect and file are required']); exit;
}

$dir = dirname(__DIR__) . "/uploads/$inspect/$kind";
if (!is_dir($dir)) @mkdir($dir, 0775, true);

$ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION) ?: 'jpg';
$fname = $inspect . '_' . strtoupper($kind) . '_' . date('Ymd_His') . '.' . $ext;
$dest = $dir . '/' . $fname;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)){
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'move failed']); exit;
}

// Staging "folder" URL (viewer route)
$folder_url = "/photos.php?inspect=" . rawurlencode($inspect) . "&kind=" . rawurlencode($kind);

// Optional DB write
$dsn = getenv('CES_DSN') ?: '';
if ($dsn){
  try{
    $pdo = new PDO($dsn, getenv('CES_DBUSER')?:'', getenv('CES_DBPASS')?:'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $stmt = $pdo->prepare("INSERT INTO inspection_photos (inspect_id, kind, folder_url, created_at, updated_at) VALUES (?,?,?,NOW(),NOW())");
    $stmt->execute([$inspect, $kind, $folder_url]);
  }catch(Exception $e){
    // Non-fatal in staging
  }
}

echo json_encode(['ok'=>true,'folder_url'=>$folder_url]);
