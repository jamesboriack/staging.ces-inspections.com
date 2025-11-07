<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok' => true,
  '__FILE__' => __FILE__,
  'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? '(none)',
  'PWD' => getcwd(),
  'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? '(none)',
], JSON_UNESCAPED_SLASHES);
