<?php
// API entrypoint used by both the embedded 45Drives UI shim and the fallback
// in-plugin renderer. This file orchestrates data generation and serves
// normalized JSON payloads from /var/local/45d.
$plugin = '45d-drivemap';
$base_dir = getenv('DRIVEMAP_OUTPUT_DIR') ?: '/var/local/45d';
$map_file = getenv('DRIVEMAP_OUTPUT_FILE') ?: ($base_dir . '/drivemap.json');
$log_file = getenv('DRIVEMAP_LOG_FILE') ?: ($base_dir . '/drivemap.log');
$default_generator = "/usr/local/emhttp/plugins/$plugin/scripts/45d-generate-map";
if (!is_file($default_generator)) {
  $default_generator = dirname(__DIR__) . '/scripts/45d-generate-map';
}
$generator = getenv('DRIVEMAP_GENERATOR') ?: $default_generator;
$default_server_info_generator = "/usr/local/emhttp/plugins/$plugin/scripts/45d-generate-server-info";
if (!is_file($default_server_info_generator)) {
  $default_server_info_generator = dirname(__DIR__) . '/scripts/45d-generate-server-info';
}
$server_info_generator = getenv('DRIVEMAP_SERVER_INFO_GENERATOR') ?: $default_server_info_generator;
$refresh_seconds = getenv('DRIVEMAP_REFRESH_SECONDS');
if ($refresh_seconds === false || $refresh_seconds === '') {
  // Default to always attempt regeneration on read; fall back to cached data
  // if generation fails.
  $refresh_seconds = 0;
} else {
  $refresh_seconds = (int)$refresh_seconds;
}
if ($refresh_seconds < 0) {
  $refresh_seconds = 0;
}
$server_info_paths = [];
$server_info_override = getenv('DRIVEMAP_SERVER_INFO');
if ($server_info_override) {
  $server_info_paths[] = $server_info_override;
}
$server_info_paths[] = '/etc/45drives/server_info/server_info.json';
$server_info_paths[] = $base_dir . '/server_info.json';
$simulation_state_file = getenv('DRIVEMAP_SIM_STATE_FILE') ?: ($base_dir . '/dev-sim-backup/state.json');

require_once __DIR__ . '/zfs_info.php';

function respond_json($data, $status = 200)
{
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
  exit;
}

function load_json($path)
{
  if (!is_file($path)) {
    return null;
  }

  $contents = @file_get_contents($path);
  if ($contents === false) {
    return null;
  }

  $data = json_decode($contents, true);
  if (!is_array($data)) {
    return null;
  }

  return $data;
}

function ensure_last_updated($data, $path)
{
  if (!is_array($data)) {
    return $data;
  }

  if (!isset($data['lastUpdated']) && is_file($path)) {
    $data['lastUpdated'] = gmdate('c', filemtime($path));
  }

  return $data;
}

function normalize_disk_info_rows($rows)
{
  // disk_info callers may expect a flat slot list, while drivemap/lsdev uses
  // row-grouped slots. Accept either and always return flat rows here.
  if (!is_array($rows)) {
    return [];
  }

  if (isset($rows[0]) && is_array($rows[0]) && array_key_exists('bay-id', $rows[0])) {
    return $rows;
  }

  $flat = [];
  foreach ($rows as $row) {
    if (!is_array($row)) {
      continue;
    }
    foreach ($row as $slot) {
      if (is_array($slot)) {
        $flat[] = $slot;
      }
    }
  }

  return $flat;
}

function load_server_info($paths)
{
  foreach ($paths as $path) {
    $data = load_json($path);
    if (is_array($data)) {
      return $data;
    }
  }

  return null;
}

function script_command($script)
{
  // Some runtimes execute this API under php-fpm/apache SAPI; if the target is
  // a PHP script we force invocation through a CLI PHP binary.
  if (!is_file($script)) {
    return null;
  }
  $first_line = '';
  $fh = @fopen($script, 'r');
  if ($fh) {
    $first_line = (string)fgets($fh);
    fclose($fh);
  }
  $is_php = (bool)preg_match('/php/i', $first_line);
  if ($is_php) {
    $php = '/usr/bin/php';
    if (
      PHP_SAPI === 'cli' &&
      defined('PHP_BINARY') &&
      PHP_BINARY &&
      is_executable(PHP_BINARY)
    ) {
      $php = PHP_BINARY;
    } elseif (!is_executable($php)) {
      $php = 'php';
    }
    return escapeshellarg($php) . ' ' . escapeshellarg($script);
  }
  if (is_executable($script)) {
    return escapeshellarg($script);
  }
  return null;
}

function run_script($script, $log_file, $not_found_error)
{
  $command = script_command($script);
  if ($command === null) {
    return [
      'ok' => false,
      'error' => $not_found_error,
    ];
  }

  $output = [];
  $code = 0;
  exec($command . ' 2>&1', $output, $code);

  if ($log_file) {
    @file_put_contents($log_file, implode("\n", $output) . "\n", FILE_APPEND);
  }

  return [
    'ok' => $code === 0,
    'exitCode' => $code,
    'output' => $output,
  ];
}

function run_generator($generator, $log_file)
{
  return run_script($generator, $log_file, 'Generator script not found');
}

function run_server_info_generator($generator, $log_file)
{
  return run_script($generator, $log_file, 'Server info generator not found');
}

function should_refresh_map($map_file, $refresh_seconds)
{
  if (!is_file($map_file)) {
    return true;
  }
  if ($refresh_seconds <= 0) {
    return true;
  }
  $age = time() - filemtime($map_file);
  return $age >= $refresh_seconds;
}

function ensure_map_data($map_file, $generator, $log_file, $refresh_seconds)
{
  $cached = load_json($map_file);
  $has_cached = is_array($cached);

  if (!should_refresh_map($map_file, $refresh_seconds)) {
    if ($has_cached) {
      return ['ok' => true, 'data' => $cached];
    }
    return ['ok' => false, 'error' => 'Drive map data unavailable'];
  }

  $result = run_generator($generator, $log_file);
  if (!$result['ok']) {
    if ($has_cached) {
      return ['ok' => true, 'data' => $cached, 'stale' => true];
    }
    return $result;
  }

  $fresh = load_json($map_file);
  if (is_array($fresh)) {
    return ['ok' => true, 'data' => $fresh];
  }

  if ($has_cached) {
    return ['ok' => true, 'data' => $cached, 'stale' => true];
  }

  return ['ok' => false, 'error' => 'Drive map data unavailable'];
}

$simulation_state = load_json($simulation_state_file);
if (is_array($simulation_state)) {
  $simulation_disk_mode = strtolower(trim((string)($simulation_state['diskMode'] ?? '')));
  if ($simulation_disk_mode !== '' && $simulation_disk_mode !== 'passthrough') {
    // Dev simulator synthetic overlays mutate drivemap.json directly. Skip
    // read-time regeneration so those overlays survive API reads.
    $refresh_seconds = 31536000;
  }
}

$action = $_REQUEST['action'] ?? 'drivemap';

if ($action === 'drivemap' || $action === 'lsdev') {
  $result = ensure_map_data($map_file, $generator, $log_file, $refresh_seconds);
  if (!$result['ok']) {
    respond_json($result, 500);
  }
  $data = $result['data'];
  if ($data === null) {
    respond_json(['error' => 'Drive map data unavailable'], 500);
  }
  respond_json(ensure_last_updated($data, $map_file));
}

if ($action === 'disk_info') {
  // Legacy "disk_info" consumers expect a flattened list of bays.
  $result = ensure_map_data($map_file, $generator, $log_file, $refresh_seconds);
  if (!$result['ok']) {
    respond_json($result, 500);
  }
  $data = $result['data'];
  if (!$data || !isset($data['rows'])) {
    respond_json(['error' => 'Drive map data unavailable'], 500);
  }
  respond_json([
    'rows' => normalize_disk_info_rows($data['rows']),
  ]);
}

if ($action === 'zfs_info') {
  respond_json(generate_zfs_info());
}

if ($action === 'server_info') {
  // Prefer existing vendor server_info first; generate only as fallback.
  $data = load_server_info($server_info_paths);
  if ($data === null) {
    $result = run_server_info_generator($server_info_generator, $log_file);
    if (!$result['ok']) {
      respond_json($result, 500);
    }
    $data = load_server_info($server_info_paths);
  }
  if ($data === null) {
    respond_json(['error' => 'server_info not available'], 500);
  }
  respond_json($data);
}

if ($action === 'status') {
  $data = load_json($map_file);
  $last_updated = null;

  if ($data && isset($data['lastUpdated'])) {
    $last_updated = $data['lastUpdated'];
  } elseif (is_file($map_file)) {
    $last_updated = gmdate('c', filemtime($map_file));
  }

  respond_json([
    'exists' => is_file($map_file),
    'lastUpdated' => $last_updated,
  ]);
}

if ($action === 'refresh') {
  // Force regeneration without waiting for cache miss.
  $result = run_generator($generator, $log_file);
  respond_json($result, $result['ok'] ? 200 : 500);
}

respond_json([
  'error' => 'Unknown action',
  'action' => $action,
], 400);
