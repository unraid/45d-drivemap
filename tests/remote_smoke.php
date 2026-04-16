<?php
$root = dirname(__DIR__);
$fixtures = __DIR__ . '/fixtures';
$map_script = $root . '/scripts/45d-generate-map';
$server_script = $root . '/scripts/45d-generate-server-info';
$dmap_script = $root . '/scripts/45d-generate-vdev-id';

$failures = 0;
$cleanup_dirs = [];
$cli_opts = getopt('', ['simulate-fixture:']);
$simulate_fixture = getenv('DRIVEMAP_SIM_ALIAS_FIXTURE');
if ((!is_string($simulate_fixture) || $simulate_fixture === '') && isset($cli_opts['simulate-fixture'])) {
  $simulate_fixture = (string)$cli_opts['simulate-fixture'];
}

function assert_true($condition, $message)
{
  global $failures;
  if (!$condition) {
    $failures++;
    fwrite(STDERR, "FAIL: $message\n");
  }
}

function assert_equal($actual, $expected, $message)
{
  global $failures;
  if ($actual !== $expected) {
    $failures++;
    fwrite(
      STDERR,
      "FAIL: $message\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n"
    );
  }
}

function ensure_dir($path)
{
  if (!is_dir($path)) {
    mkdir($path, 0755, true);
  }
}

function rrmdir($dir)
{
  if (!is_dir($dir)) {
    return;
  }
  $items = scandir($dir);
  if (!$items) {
    return;
  }
  foreach ($items as $item) {
    if ($item === '.' || $item === '..') {
      continue;
    }
    $path = $dir . '/' . $item;
    if (is_dir($path) && !is_link($path)) {
      rrmdir($path);
    } else {
      @unlink($path);
    }
  }
  @rmdir($dir);
}

function create_context($name = '')
{
  global $cleanup_dirs;
  $suffix = $name !== '' ? '-' . $name : '';
  $tmp = sys_get_temp_dir() . '/45d-non45d-smoke-' . uniqid() . $suffix;
  $ctx = [
    'tmp' => $tmp,
    'dev_dir' => $tmp . '/dev',
    'by_path_dir' => $tmp . '/by-path',
    'out_dir' => $tmp . '/out',
    'sys_block_dir' => $tmp . '/sys/block',
    'alias_file' => $tmp . '/vdev_id.conf',
  ];
  ensure_dir($ctx['dev_dir']);
  ensure_dir($ctx['by_path_dir']);
  ensure_dir($ctx['out_dir']);
  ensure_dir($ctx['sys_block_dir']);

  foreach (['sda' => '1', 'sdb' => '0', 'sdc' => '1'] as $dev => $rota) {
    ensure_fake_device($ctx, $dev, $rota);
  }

  $cleanup_dirs[] = $tmp;
  return $ctx;
}

function ensure_fake_device($ctx, $dev, $rotational)
{
  $dev_path = $ctx['dev_dir'] . '/' . $dev;
  if (!is_file($dev_path)) {
    file_put_contents($dev_path, '');
  }
  $queue_dir = $ctx['sys_block_dir'] . '/' . $dev . '/queue';
  ensure_dir($queue_dir);
  file_put_contents($queue_dir . '/rotational', ((string)$rotational === '1' ? '1' : '0') . "\n");
}

function device_name_for_index($index)
{
  $letters = 'abcdefghijklmnopqrstuvwxyz';
  if ($index < 26) {
    return 'sd' . $letters[$index];
  }
  $first = intdiv($index, 26) - 1;
  $second = $index % 26;
  return 'sd' . $letters[$first] . $letters[$second];
}

function sorted_alias_keys($alias_map)
{
  $keys = array_keys($alias_map);
  usort($keys, function ($a, $b) {
    if (!preg_match('/^(\d+)-(\d+)$/', (string)$a, $ma)) {
      return 1;
    }
    if (!preg_match('/^(\d+)-(\d+)$/', (string)$b, $mb)) {
      return -1;
    }
    $ra = (int)$ma[1];
    $rb = (int)$mb[1];
    if ($ra !== $rb) {
      return $ra <=> $rb;
    }
    return ((int)$ma[2]) <=> ((int)$mb[2]);
  });
  return $keys;
}

function symlink_map_for_aliases($ctx, $alias_map)
{
  $symlinks = [];
  $keys = sorted_alias_keys($alias_map);
  foreach ($keys as $index => $bay_id) {
    $dev = device_name_for_index($index);
    ensure_fake_device($ctx, $dev, $index % 2 === 0 ? '1' : '0');
    $symlinks[$bay_id] = $ctx['dev_dir'] . '/' . $dev;
  }
  return $symlinks;
}

function write_alias_file($ctx, $alias_map, $symlinks = [])
{
  $alias_lines = [];
  foreach ($alias_map as $bay_id => $path) {
    $full_path = $ctx['by_path_dir'] . '/' . $path;
    if (isset($symlinks[$bay_id])) {
      @symlink($symlinks[$bay_id], $full_path);
    } else {
      @touch($full_path);
    }
    [$card, $drive] = explode('-', $bay_id, 2);
    $alias_lines[] = "alias $card-$drive $full_path";
  }
  file_put_contents($ctx['alias_file'], implode("\n", $alias_lines) . "\n");
}

function set_common_env($ctx, $fixtures)
{
  putenv('DRIVEMAP_OUTPUT_DIR=' . $ctx['out_dir']);
  putenv('DRIVEMAP_ALIAS_FILE=' . $ctx['alias_file']);
  putenv('DRIVEMAP_LSBLK=' . $fixtures . '/lsblk.txt');
  putenv('DRIVEMAP_DISKS_INI=' . $fixtures . '/disks.ini');
  putenv('DRIVEMAP_DEVS_INI=' . $fixtures . '/devs.ini');
  putenv('DRIVEMAP_PROC_PARTITIONS=' . $fixtures . '/proc_partitions');
  putenv('DRIVEMAP_SYS_BLOCK=' . $ctx['sys_block_dir']);
  putenv('DRIVEMAP_SERVER_INFO_INPUT=' . $ctx['tmp'] . '/missing_server_info.json');
  putenv('DRIVEMAP_VENDOR_SERVER_IDENTIFIER=/bin/false');
  putenv('DRIVEMAP_SERVER_INFO=');
  putenv('DRIVEMAP_SMARTCTL_DIR=');
  putenv('DRIVEMAP_DISABLE_SMART=');
}

function run_php_script($script, $env = [])
{
  $prefix = [];
  foreach ($env as $k => $v) {
    $prefix[] = $k . '=' . escapeshellarg((string)$v);
  }
  $command = (count($prefix) ? implode(' ', $prefix) . ' ' : '')
    . 'php ' . escapeshellarg($script) . ' 2>&1';
  $output = [];
  $code = 0;
  exec($command, $output, $code);
  return [$code, $output];
}

function run_api_action($root, $action)
{
  $script = $root . '/php/api.php';
  $code = 0;
  $output = [];
  $snippet = '$_REQUEST["action"]="' . addslashes($action) . '"; include "' . addslashes($script) . '";';
  exec('php -r ' . escapeshellarg($snippet), $output, $code);
  return [$code, implode("\n", $output)];
}

function load_json_file($path)
{
  $raw = @file_get_contents($path);
  if ($raw === false) {
    return null;
  }
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : null;
}

function alias_map_from_fixture($path)
{
  $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!is_array($lines)) {
    return [];
  }
  $map = [];
  foreach ($lines as $line) {
    if (preg_match('/^alias\s+(\d+-\d+)\s+(\S+)/', trim($line), $matches)) {
      $map[$matches[1]] = $matches[2];
    }
  }
  return $map;
}

function alias_lines_from_file($path)
{
  $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!is_array($lines)) {
    return [];
  }
  $out = [];
  foreach ($lines as $line) {
    $line = trim((string)$line);
    if (strpos($line, 'alias ') === 0) {
      $out[] = $line;
    }
  }
  return $out;
}

function validate_alias_lines($lines, $label)
{
  assert_true(is_array($lines), "$label alias lines are an array");
  if (!is_array($lines) || !$lines) {
    return;
  }
  $rows = [];
  $seen = [];
  foreach ($lines as $line) {
    $ok = preg_match('/^alias\s+(\d+)-(\d+)\s+\/dev\/disk\/by-path\/\S+$/', $line, $m) === 1;
    assert_true($ok, "$label line shape");
    if (!$ok) {
      continue;
    }
    $bay = $m[1] . '-' . $m[2];
    assert_true(!isset($seen[$bay]), "$label unique bay $bay");
    $seen[$bay] = true;
    $row = (int)$m[1];
    $drive = (int)$m[2];
    if (!isset($rows[$row])) {
      $rows[$row] = [];
    }
    $rows[$row][] = $drive;
  }
  ksort($rows);
  foreach ($rows as $row => $drives) {
    sort($drives);
    assert_equal($drives, range(1, count($drives)), "$label contiguous drives row $row");
  }
}

function resolve_fixture_path($candidate, $fixtures_dir)
{
  if (!is_string($candidate) || trim($candidate) === '') {
    return '';
  }
  $candidate = trim($candidate);
  if (is_file($candidate)) {
    $real = realpath($candidate);
    return $real !== false ? $real : $candidate;
  }
  $fixture_candidate = rtrim($fixtures_dir, '/') . '/' . ltrim($candidate, '/');
  if (is_file($fixture_candidate)) {
    $real = realpath($fixture_candidate);
    return $real !== false ? $real : $fixture_candidate;
  }
  return '';
}

function slot_count($rows)
{
  if (!is_array($rows)) {
    return 0;
  }
  $total = 0;
  foreach ($rows as $row) {
    if (!is_array($row)) {
      continue;
    }
    foreach ($row as $slot) {
      if (is_array($slot) && isset($slot['bay-id'])) {
        $total++;
      }
    }
  }
  return $total;
}

register_shutdown_function(function () use (&$cleanup_dirs) {
  foreach ($cleanup_dirs as $dir) {
    rrmdir($dir);
  }
});

// Scenario 1: generate map with local fixtures.
$ctx = create_context('map');
$alias_map = [
  '2-1' => 'pci-0000:02:00.0-sas-phy0-lun-0',
  '2-2' => 'pci-0000:02:00.0-sas-phy1-lun-0',
  '1-1' => 'pci-0000:01:00.0-sas-phy0-lun-0',
  '1-2' => 'pci-0000:01:00.0-sas-phy1-lun-0',
  '1-3' => 'pci-0000:01:00.0-sas-phy2-lun-0',
];
$symlinks = [
  '1-1' => $ctx['dev_dir'] . '/sda',
  '1-2' => $ctx['dev_dir'] . '/sdb',
  '2-1' => $ctx['dev_dir'] . '/sdc',
];
write_alias_file($ctx, $alias_map, $symlinks);
set_common_env($ctx, $fixtures);
putenv('DRIVEMAP_CHASSIS_SIZE=S45');
putenv('DRIVEMAP_SERVER_MODEL=Smoke-Model');
putenv('DRIVEMAP_ALIAS_STYLE=STORINATOR');

[$map_code] = run_php_script($map_script);
assert_equal($map_code, 0, 'map generator exits successfully');
$map = load_json_file($ctx['out_dir'] . '/drivemap.json');
assert_true(is_array($map), 'drivemap.json parses as JSON');
assert_equal(count($map['rows'] ?? []), 2, 'map row count');
assert_equal(count($map['rows'][0] ?? []), 3, 'map row 1 count');
assert_equal(count($map['rows'][1] ?? []), 2, 'map row 2 count');
assert_equal($map['rows'][0][0]['bay-id'] ?? '', '1-1', 'map first bay id');
assert_equal($map['rows'][0][0]['occupied'] ?? false, true, 'map first bay occupied');

// Scenario 2: server_info fallback inference from aliases.
$ctx_server = create_context('server');
write_alias_file($ctx_server, alias_map_from_fixture($fixtures . '/vdev_id_h16_q30.conf'));
set_common_env($ctx_server, $fixtures);
putenv('DRIVEMAP_SERVER_MODEL');
putenv('DRIVEMAP_CHASSIS_SIZE');
putenv('DRIVEMAP_ALIAS_STYLE');
[$server_code] = run_php_script($server_script);
assert_equal($server_code, 0, 'server_info generator exits successfully');
$server_info = load_json_file($ctx_server['out_dir'] . '/server_info.json');
assert_true(is_array($server_info), 'server_info parses as JSON');
assert_equal($server_info['Alias Style'] ?? '', 'H16', 'server_info inferred style');
assert_equal($server_info['Chassis Size'] ?? '', 'Q30', 'server_info inferred chassis');

// Scenario 3: API smoke.
putenv('DRIVEMAP_ZFS_FORCE=1');
putenv('DRIVEMAP_ZFS_FIXTURE_DIR=' . $fixtures . '/zfs');
[$lsdev_code, $lsdev_body] = run_api_action($root, 'lsdev');
assert_equal($lsdev_code, 0, 'api lsdev exits successfully');
$lsdev = json_decode($lsdev_body, true);
assert_true(is_array($lsdev), 'api lsdev returns json');
assert_true(isset($lsdev['rows']) && is_array($lsdev['rows']), 'api lsdev rows');

[$disk_code, $disk_body] = run_api_action($root, 'disk_info');
assert_equal($disk_code, 0, 'api disk_info exits successfully');
$disk_info = json_decode($disk_body, true);
assert_true(is_array($disk_info) && isset($disk_info['rows']), 'api disk_info returns rows');

[$srv_code, $srv_body] = run_api_action($root, 'server_info');
assert_equal($srv_code, 0, 'api server_info exits successfully');
$srv = json_decode($srv_body, true);
assert_true(is_array($srv), 'api server_info returns json');

[$zfs_code, $zfs_body] = run_api_action($root, 'zfs_info');
assert_equal($zfs_code, 0, 'api zfs_info exits successfully');
$zfs = json_decode($zfs_body, true);
assert_true(is_array($zfs), 'api zfs_info returns json');
assert_equal($zfs['zfs_installed'] ?? false, true, 'api zfs_info fixture installed');

// Scenario 4: API uses stale cache when refresh-on-read regeneration fails.
putenv('DRIVEMAP_REFRESH_SECONDS=0');
putenv('DRIVEMAP_GENERATOR=/bin/false');
[$stale_code, $stale_body] = run_api_action($root, 'disk_info');
assert_equal($stale_code, 0, 'api stale disk_info exits successfully');
$stale = json_decode($stale_body, true);
assert_true(is_array($stale) && isset($stale['rows']) && is_array($stale['rows']), 'api stale disk_info returns rows');
assert_equal(count($stale['rows'] ?? []), count($disk_info['rows'] ?? []), 'api stale disk_info row count matches cache');
putenv('DRIVEMAP_GENERATOR');
putenv('DRIVEMAP_REFRESH_SECONDS');

// Scenario 5: simulation overlay survives disk_info reads.
set_common_env($ctx, $fixtures);
$sim_state_dir = $ctx['out_dir'] . '/dev-sim-backup';
ensure_dir($sim_state_dir);
$sim_state_file = $sim_state_dir . '/state.json';
$map_path = $ctx['out_dir'] . '/drivemap.json';
$sim_overlay = load_json_file($map_path);
assert_true(is_array($sim_overlay), 'simulation overlay base drivemap parses as JSON');
if (is_array($sim_overlay) && isset($sim_overlay['rows'][0][0]) && is_array($sim_overlay['rows'][0][0])) {
  $sim_overlay['rows'][0][0]['occupied'] = true;
  $sim_overlay['rows'][0][0]['model-name'] = 'SIMULATED-MODEL';
  $sim_overlay['rows'][0][0]['serial'] = 'SIM-0001';
  file_put_contents($map_path, json_encode($sim_overlay, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
file_put_contents(
  $sim_state_file,
  json_encode(['diskMode' => 'healthy', 'occupied' => 1], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
putenv('DRIVEMAP_REFRESH_SECONDS=0');
putenv('DRIVEMAP_GENERATOR=' . $map_script);
[$sim_overlay_code, $sim_overlay_body] = run_api_action($root, 'disk_info');
assert_equal($sim_overlay_code, 0, 'api simulation overlay disk_info exits successfully');
$sim_overlay_rows = json_decode($sim_overlay_body, true);
assert_true(is_array($sim_overlay_rows) && isset($sim_overlay_rows['rows']) && is_array($sim_overlay_rows['rows']), 'api simulation overlay disk_info returns rows');
assert_equal($sim_overlay_rows['rows'][0]['model-name'] ?? '', 'SIMULATED-MODEL', 'api simulation overlay preserves model-name');
assert_equal($sim_overlay_rows['rows'][0]['serial'] ?? '', 'SIM-0001', 'api simulation overlay preserves serial');
putenv('DRIVEMAP_GENERATOR');
putenv('DRIVEMAP_REFRESH_SECONDS');
@unlink($sim_state_file);

// Scenario 6: dmap port smoke for a non-45d test host.
$ctx_dmap = create_context('dmap');
$server_json = $ctx_dmap['tmp'] . '/server_info.json';
$dmap_output = $ctx_dmap['tmp'] . '/vdev_id.conf';
$server = [
  'Motherboard' => ['Manufacturer' => 'Supermicro', 'Product Name' => 'X11DPL-i', 'Serial Number' => 'SMOKE'],
  'HBA' => [
    ['Model' => 'SAS9305-16i', 'Bus Address' => '0000:01:00.0'],
  ],
  'Hybrid' => false,
  'Serial' => 'SMOKE',
  'Model' => 'Smoke-Model',
  'Alias Style' => 'STORINATOR',
  'Chassis Size' => 'AV15',
  'VM' => false,
  'Edit Mode' => false,
  'OS NAME' => 'Unraid',
  'OS VERSION_ID' => '6',
];
file_put_contents($server_json, json_encode($server, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
[$dmap_code] = run_php_script($dmap_script, [
  'DRIVEMAP_DMAP_SERVER_INFO' => $server_json,
  'DRIVEMAP_DMAP_OUTPUT' => $dmap_output,
]);
assert_equal($dmap_code, 0, 'dmap generator exits successfully');
assert_true(is_file($dmap_output), 'dmap output file exists');
$raw = (string)@file_get_contents($dmap_output);
assert_true(strpos($raw, 'generated using dmap') !== false, 'dmap output includes header');
$aliases = alias_lines_from_file($dmap_output);
assert_equal(count($aliases), 15, 'dmap alias count for AV15');
validate_alias_lines($aliases, 'dmap smoke');

// Scenario 7: unsupported alias style should fail safely.
$server['Alias Style'] = 'UNSUPPORTED_STYLE';
file_put_contents($server_json, json_encode($server, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
[$invalid_code] = run_php_script($dmap_script, [
  'DRIVEMAP_DMAP_SERVER_INFO' => $server_json,
  'DRIVEMAP_DMAP_OUTPUT' => $dmap_output,
]);
assert_true($invalid_code !== 0, 'dmap rejects unsupported alias style');

// Scenario 8: optional custom fixture simulation for non-45d host testing.
$fixture_path = resolve_fixture_path((string)$simulate_fixture, $fixtures);
if (is_string($simulate_fixture) && $simulate_fixture !== '' && $fixture_path === '') {
  assert_true(false, 'simulation fixture path exists: ' . $simulate_fixture);
}
if ($fixture_path !== '') {
  $ctx_sim = create_context('fixture-sim');
  $fixture_alias_map = alias_map_from_fixture($fixture_path);
  assert_true(count($fixture_alias_map) > 0, 'simulation fixture contains aliases');
  if ($fixture_alias_map) {
    $fixture_symlinks = symlink_map_for_aliases($ctx_sim, $fixture_alias_map);
    write_alias_file($ctx_sim, $fixture_alias_map, $fixture_symlinks);
    set_common_env($ctx_sim, $fixtures);
    [$sim_map_code] = run_php_script($map_script);
    assert_equal($sim_map_code, 0, 'simulation map generator exits successfully');
    $sim_map = load_json_file($ctx_sim['out_dir'] . '/drivemap.json');
    assert_true(is_array($sim_map), 'simulation drivemap parses as JSON');
    $expected_slots = count($fixture_alias_map);
    assert_equal(slot_count($sim_map['rows'] ?? []), $expected_slots, 'simulation drivemap contains all fixture aliases');

    [$sim_api_code, $sim_api_body] = run_api_action($root, 'disk_info');
    assert_equal($sim_api_code, 0, 'simulation api disk_info exits successfully');
    $sim_api = json_decode($sim_api_body, true);
    assert_true(is_array($sim_api) && isset($sim_api['rows']) && is_array($sim_api['rows']), 'simulation api disk_info returns rows');
    assert_equal(count($sim_api['rows'] ?? []), $expected_slots, 'simulation api disk_info contains all fixture aliases');
  }
}

// Scenario 9: HL15 fallback detection + auto alias generation without vendor tooling.
$ctx_hl15 = create_context('hl15-fallback');
set_common_env($ctx_hl15, $fixtures);
@unlink($ctx_hl15['alias_file']);
putenv('DRIVEMAP_SERVER_MODEL');
putenv('DRIVEMAP_CHASSIS_SIZE');
putenv('DRIVEMAP_ALIAS_STYLE');
$hl15_lspci = implode("\n", [
  '02:00.0 Serial Attached SCSI controller: Broadcom / LSI SAS3416 Fusion-MPT Tri-Mode I/O Controller Chip (IOC) (rev 01)',
  "\tSubsystem: Broadcom / LSI HBA 9400-16i",
  "\tKernel driver in use: mpt3sas",
  "\tKernel modules: mpt3sas",
]) . "\n";
[$hl15_code] = run_php_script($map_script, [
  'DRIVEMAP_PRODUCT_NAME' => 'MW34-SP0-00',
  'DRIVEMAP_BOARD_NAME' => 'MW34-SP0-00',
  'DRIVEMAP_BOARD_VENDOR' => '45Drives',
  'DRIVEMAP_LSPCI_VERBOSE' => $hl15_lspci,
]);
assert_equal($hl15_code, 0, 'hl15 fallback map generator exits successfully');
$hl15_aliases = alias_lines_from_file($ctx_hl15['alias_file']);
assert_equal(count($hl15_aliases), 15, 'hl15 fallback generates fifteen aliases');
$hl15_server = load_json_file($ctx_hl15['out_dir'] . '/server_info.json');
assert_true(is_array($hl15_server), 'hl15 fallback server_info parses as JSON');
assert_equal($hl15_server['Model'] ?? '', 'Unraid >< 45Homelab X-15', 'hl15 fallback model');
assert_equal($hl15_server['Alias Style'] ?? '', 'HOMELAB', 'hl15 fallback alias style');
assert_equal($hl15_server['Chassis Size'] ?? '', 'HL15', 'hl15 fallback chassis');
assert_equal($hl15_server['HBA'][0]['Model'] ?? '', 'HBA 9400-16i', 'hl15 fallback hba model');
assert_equal($hl15_server['HBA'][0]['Bus Address'] ?? '', '0000:02:00.0', 'hl15 fallback hba bus');
$hl15_map = load_json_file($ctx_hl15['out_dir'] . '/drivemap.json');
assert_true(is_array($hl15_map), 'hl15 fallback drivemap parses as JSON');
assert_equal(slot_count($hl15_map['rows'] ?? []), 15, 'hl15 fallback drivemap contains 15 bays');

if ($failures > 0) {
  fwrite(STDERR, "\n$failures test(s) failed.\n");
  exit(1);
}

fwrite(STDOUT, "Remote smoke tests passed.\n");
