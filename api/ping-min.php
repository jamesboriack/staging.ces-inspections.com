<?php
declare(strict_types=1);
@ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode(['ok'=>true,'php'=>PHP_VERSION,'__FILE__'=>__FILE__]);
