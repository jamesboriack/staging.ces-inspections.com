<?php
header('Content-Type: application/json');
echo json_encode([
  'ok'   => true,
  'pong' => time(),
  'file' => __FILE__,
  'php'  => PHP_VERSION,
]);
