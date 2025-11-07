<?php

/* === STAGING DB BOOTSTRAP (added) === */
@ini_set("display_errors","0");
@ini_set("log_errors","1");
@ini_set("error_log", __DIR__."/api/logs/api.log");
$cfg = @require __DIR__."/config/env.php"; // returns ["dsn","user","pass"] from staging adapter
if (!is_array($cfg)) {
  // fallback: old style env.php with DB_* constants
  @require_once __DIR__."/env.php";
  $cfg = [
    "dsn"  => "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
    "user" => defined("DB_USER") ? DB_USER : "",
    "pass" => defined("DB_PASS") ? DB_PASS : "",
  ];
}
try {
  $pdo = new PDO($cfg["dsn"], $cfg["user"], $cfg["pass"], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  echo "<!doctype html><meta charset=utf-8><title>DB error</title>
        <body style=\"font:14px system-ui;background:#0b0f14;color:#e6edf3\">
        <h3>Database connection failed (staging)</h3>
        <p>Check staging credentials/config.</p></body>";
  error_log("label-install DB connect failed: ".$e->getMessage());
  exit;
}
/* === END STAGING DB BOOTSTRAP === */

// label-install.php — quick label install/record screen
// - Lookup unit by ?code (units.qr_code) OR ?unit (units.unit_id)
// - Editable: display_id, notes, installed_by, qrlable_installed (checkbox)
// - POST writes to units; installed_by update best-effort
// - Add ?diag=1 for diagnostics

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache'); header('Expires: 0');

require __DIR__ . '/inc/bootstrap.php';   // <-- single source of truth for env + PDO

$codeQS = isset($_GET['code']) ? strtoupper(trim((string)$_GET['code'])) : '';
$unitQS = isset($_GET['unit']) ? trim((string)$_GET['unit']) : '';
$empQS  = isset($_GET['emp'])  ? trim((string)$_GET['emp'])  : '';
$diagQS = isset($_GET['diag']) ? trim((string)$_GET['diag']) : '';

$pdo = null;
$err = null;
$row = null;
$postResult = null;

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function S($v){ return trim((string)$v); }

try {
  // Use PDO from bootstrap (resolves /home/..../config/env.php for you)
  $pdo = ces_pdo();
} catch (Throwable $e) {
  $err = 'DB connect failed';
  error_log('label-install DB connect failed: '.$e->getMessage());
}

function fetch_unit(PDO $pdo, string $code, string $unit){
  if ($code !== '') {
    $q = $pdo->prepare("
      SELECT id, unit_id, display_id, category, unit_type, qr_code, landing_url, s_form_num,
             active, insured, make, year, model, description, vin, sn, plate, reg_exp,
             notes, qrlable_installed
      FROM units
      WHERE UPPER(qr_code)=:c
      LIMIT 1
    ");
    $q->execute([':c'=>$code]);
    if ($r=$q->fetch()) return $r;
  }
  if ($unit !== '') {
    $q = $pdo->prepare("
      SELECT id, unit_id, display_id, category, unit_type, qr_code, landing_url, s_form_num,
             active, insured, make, year, model, description, vin, sn, plate, reg_exp,
             notes, qrlable_installed
      FROM units
      WHERE unit_id=:u OR UPPER(unit_id)=:u_uc
      LIMIT 1
    ");
    $q->execute([':u'=>$unit, ':u_uc'=>strtoupper($unit)]);
    if ($r=$q->fetch()) return $r;
  }
  return null;
}

function update_installed_by(PDO $pdo, string $unitId, string $installedBy){
  if ($installedBy === '') return;
  try {
    // best-effort: if column missing, catch and continue
    $st = $pdo->prepare("
      UPDATE units
         SET installed_by = :by
       WHERE unit_id = :uid OR UPPER(unit_id)=:uid_uc
       LIMIT 1
    ");
    $st->execute([':by'=>$installedBy, ':uid'=>$unitId, ':uid_uc'=>strtoupper($unitId)]);
  } catch (Throwable $e) {
    error_log('label-install: installed_by update skipped: '.$e->getMessage());
  }
}

if ($pdo) {
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
      $unitId    = S($_POST['unit_id'] ?? '');
      $dispId    = S($_POST['display_id'] ?? '');
      $notes     = S($_POST['notes'] ?? '');
      $installed = isset($_POST['qrlable_installed']) ? '1' : '0';
      $byParam   = S($_POST['installed_by'] ?? '');

      if ($unitId === '') throw new RuntimeException('Missing unit_id on POST');

      $u = $pdo->prepare("
        UPDATE units
           SET display_id = :d,
               notes = :n,
               qrlable_installed = :q
         WHERE unit_id = :uid OR UPPER(unit_id)=:uid_uc
         LIMIT 1
      ");
      $u->execute([
        ':d'=>$dispId,
        ':n'=>$notes,
        ':q'=>$installed,
        ':uid'=>$unitId,
        ':uid_uc'=>strtoupper($unitId),
      ]);

      if ($byParam !== '') update_installed_by($pdo, $unitId, $byParam);

      $postResult = ['ok'=>true, 'msg'=>'Saved'];
      $row = fetch_unit($pdo, $codeQS, $unitQS);
    } catch (Throwable $e) {
      error_log('label-install POST failed: '.$e->getMessage());
      $postResult = ['ok'=>false, 'msg'=>'Save failed'];
    }
  }

  if (!$row) {
    $row = fetch_unit($pdo, $codeQS, $unitQS);
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"/>
<title>Install Label</title>
<style>
  :root{color-scheme:light dark}
  body{margin:0;background:#0b0f14;color:#e6edf3;font:14px/1.5 system-ui,Segoe UI,Roboto,Arial}
  .wrap{max-width:900px;margin:6vh auto;padding:16px}
  .card{background:#121821;border-radius:16px;padding:18px;box-shadow:0 8px 24px rgba(0,0,0,.35);margin-bottom:16px}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  @media (max-width:800px){ .grid{grid-template-columns:1fr} }
  label{display:block;margin:6px 0 4px;color:#9fb0c2}
  input[type=text],textarea{width:100%;padding:10px;border-radius:10px;border:1px solid #29445f;background:#0b131d;color:#e6edf3}
  textarea{min-height:90px}
  .row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .btn{cursor:pointer;background:#1a2939;border:1px solid #29445f;border-radius:10px;padding:9px 12px;color:#e6edf3;text-decoration:none}
  .btn:hover{border-color:#6ab0ff}
  .muted{color:#9fb0c2}
  .ok{color:#29c07a}
  .bad{color:#ff6b6b}
  .readonly{opacity:.9}
  .note{font-size:12px;color:#9fb0c2}
  .diag{background:#0b0f14;border:1px solid #1e2a3a;border-radius:8px;padding:10px;overflow:auto;font:12px/1.4 ui-monospace, SFMono-Regular, Menlo, Consolas}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h2 style="margin:0 0 6px;">Install Label</h2>
    <div class="muted">
      Lookup: <?= $codeQS ? 'QR ' . h($codeQS) : ($unitQS ? 'Unit ' . h($unitQS) : '—') ?>
      <?= $empQS ? ' · Installed By: ' . h($empQS) : '' ?>
    </div>
    <?php if ($postResult): ?>
      <div class="<?= $postResult['ok']?'ok':'bad' ?>" style="margin-top:8px"><?= h($postResult['msg']) ?></div>
    <?php endif; ?>
  </div>

  <?php if ($err): ?>
    <div class="card bad"><?= h($err) ?></div>
  <?php elseif (!$row): ?>
    <div class="card bad">No unit found for the given QR code/unit id.</div>
  <?php else: ?>
    <form class="card" method="post" action="">
      <div class="grid">
        <div>
          <label>QR Code</label>
          <input type="text" class="readonly" value="<?= h($row['qr_code']) ?>" readonly>
        </div>
        <div>
          <label>Unit ID</label>
          <input type="text" name="unit_id" class="readonly" value="<?= h($row['unit_id']) ?>" readonly>
        </div>

        <div>
          <label>Display ID <span class="note">(editable)</span></label>
          <input type="text" name="display_id" value="<?= h($row['display_id']) ?>" placeholder="As printed on unit">
        </div>

        <div>
          <label>Category</label>
          <input type="text" class="readonly" value="<?= h($row['category']) ?>" readonly>
        </div>
        <div>
          <label>Unit Type</label>
          <input type="text" class="readonly" value="<?= h($row['unit_type']) ?>" readonly>
        </div>

        <div>
          <label>Active</label>
          <input type="text" class="readonly" value="<?= h($row['active']) ?>" readonly>
        </div>
        <div>
          <label>Insured</label>
          <input type="text" class="readonly" value="<?= h($row['insured']) ?>" readonly>
        </div>

        <div>
          <label>Make</label>
          <input type="text" class="readonly" value="<?= h($row['make']) ?>" readonly>
        </div>
        <div>
          <label>Year</label>
          <input type="text" class="readonly" value <?= $row['year']!==null ? '"'.h($row['year']).'"' : '""' ?> readonly>
        </div>

        <div>
          <label>Model</label>
          <input type="text" class="readonly" value="<?= h($row['model']) ?>" readonly>
        </div>
        <div>
          <label>VIN</label>
          <input type="text" class="readonly" value="<?= h($row['vin']) ?>" readonly>
        </div>

        <div>
          <label>Serial #</label>
          <input type="text" class="readonly" value="<?= h($row['sn']) ?>" readonly>
        </div>
        <div>
          <label>Plate</label>
          <input type="text" class="readonly" value="<?= h($row['plate']) ?>" readonly>
        </div>

        <div>
          <label>Reg Exp</label>
          <input type="text" class="readonly" value="<?= h($row['reg_exp']) ?>" readonly>
        </div>
        <div>
          <label>Google Form #</label>
          <input type="text" class="readonly" value="<?= h($row['s_form_num']) ?>" readonly>
        </div>

        <div style="grid-column:1/-1">
          <label>Notes <span class="note">(editable)</span></label>
          <textarea name="notes" placeholder="Install notes…"><?= h($row['notes']) ?></textarea>
        </div>

        <div>
          <label>Installed By <span class="note">(optional)</span></label>
          <input type="text" name="installed_by" value="<?= h($empQS) ?>" placeholder="Employee ID or name">
        </div>

        <div class="row" style="align-items:center;margin-top:8px;grid-column:1/-1">
          <input type="checkbox" id="qri" name="qrlable_installed" <?= (string)$row['qrlable_installed']==='1'?'checked':'' ?>>
          <label for="qri" style="margin:0 0 0 6px">QR label installed</label>
        </div>
      </div>

      <div class="row" style="margin-top:12px">
        <button class="btn" type="submit">Save</button>
        <a class="btn" href="javascript:history.back()">Back</a>
      </div>
    </form>
  <?php endif; ?>

  <?php if ($diagQS === '1'): ?>
    <div class="card">
      <h3 style="margin:0 0 8px;">Diagnostics</h3>
      <div class="diag">
        <strong>Incoming</strong>
        <pre><?= h(json_encode([
          'href'  => (isset($_SERVER['REQUEST_SCHEME'])?$_SERVER['REQUEST_SCHEME'].'://':'')
                     . ($_SERVER['HTTP_HOST'] ?? '')
                     . ($_SERVER['REQUEST_URI'] ?? ''),
          'query' => $_GET,
        ], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>

        <strong>Loaded Row</strong>
        <pre><?= h(json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>

        <strong>Last POST Result</strong>
        <pre><?= h(json_encode($postResult, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)) ?></pre>
      </div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
