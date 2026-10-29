<?php
require __DIR__ . '/lib.php';
kg_require_user();
kg_json([
  'php' => PHP_VERSION,
  'imap' => extension_loaded('imap'),
  'openssl' => extension_loaded('openssl'),
  'curl' => extension_loaded('curl'),
  'mbstring' => extension_loaded('mbstring'),
  'sodium' => extension_loaded('sodium'),
  'allow_url_fopen' => (bool)ini_get('allow_url_fopen'),
  'data_dir' => basename(kg_data_dir()),
  'outside_webroot' => strpos(kg_data_dir(), '/httpdocs/') === false,
  'stream_socket' => function_exists('stream_socket_client'),
  'max_exec' => ini_get('max_execution_time'),
]);
