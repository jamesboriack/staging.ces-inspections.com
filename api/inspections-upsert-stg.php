<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
@ini_set('log_errors','1');
@ini_set('error_log', __DIR__ . '/logs/api.log');
@mkdir(__DIR__ . '/logs', 0755, true);

$raw  = file_get_contents('php://input') ?: '{}';
$body = json_decode($raw, true) ?: [];
@file_put_contents(__DIR__ . '/logs/inspections.log',
  '['.date('c')."] UP-SHORT ".json_encode($body)."\n", FILE_APPEND);

echo json_encode([
  'ok'  => true,
  'id'  => $body['inspect'] ?? null,
  'hint'=> 'rewritten-to-inspections-upsert-stg'
]);
