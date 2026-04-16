<?php
$root = dirname(__DIR__);
$fixtures = __DIR__ . '/fixtures';
$map_script = $root . '/scripts/45d-generate-map';
$server_script = $root . '/scripts/45d-generate-server-info';

$failures = 0;
$cleanup_dirs = [];

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
    $actual_text = var_export($actual, true);
    $expected_text = var_export($expected, true);
    fwrite(STDERR, "FAIL: $message\n  expected: $expected_text\n  actual:   $actual_text\n");
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
  $tmp = sys_get_temp_dir() . '/45d-drivemap-tests-' . uniqid() . $suffix;
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
    file_put_contents($ctx['dev_dir'] . '/' . $dev, "");
    $queue_dir = $ctx['sys_block_dir'] . '/' . $dev . '/queue';
    ensure_dir($queue_dir);
    file_put_contents($queue_dir . '/rotational', $rota . "\n");
  }

  $cleanup_dirs[] = $tmp;
  return $ctx;
}

function write_alias_file($ctx, $alias_map, $symlinks = [])
{
  $alias_lines = [];
  foreach ($alias_map as $bay_id => $path) {
    $full_path = $ctx['by_path_dir'] . '/' . $path;
    if (isset($symlinks[$bay_id])) {
      @symlink($symlinks[$bay_id], $full_path);
    }
    [$card, $drive] = explode('-', $bay_id, 2);
    $alias_lines[] = "alias $card-$drive $full_path";
  }
  file_put_contents($ctx['alias_file'], implode("\n", $alias_lines) . "\n");
}

function alias_map_from_fixture($path)
{
  $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!is_array($lines)) {
    return [];
  }
  $map = [];
  foreach ($lines as $line) {
    if (preg_match('/^alias\\s+(\\d+-\\d+)\\s+(\\S+)/', trim($line), $matches)) {
      $map[$matches[1]] = $matches[2];
    }
  }
  return $map;
}

function alias_lines_from_fixture($path)
{
  $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!is_array($lines)) {
    return [];
  }
  $result = [];
  foreach ($lines as $line) {
    $line = trim((string)$line);
    if ($line === '' || strpos($line, '#') === 0) {
      continue;
    }
    if (strpos($line, 'alias ') === 0) {
      $result[] = $line;
    }
  }
  return $result;
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
  foreach ($env as $key => $value) {
    $prefix[] = $key . '=' . escapeshellarg((string)$value);
  }
  $output = [];
  $code = 0;
  $cmd = (count($prefix) ? implode(' ', $prefix) . ' ' : '') . 'php ' . escapeshellarg($script);
  exec($cmd, $output, $code);
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

function find_slot($rows, $bay_id)
{
  foreach ($rows as $row) {
    if (!is_array($row)) {
      continue;
    }
    foreach ($row as $slot) {
      if (is_array($slot) && (($slot['bay-id'] ?? '') === $bay_id)) {
        return $slot;
      }
    }
  }
  return null;
}

function vendor_template_lengths($root, $style, $chassis)
{
  $script = $root . '/tests/vendor_template.py';
  $vendor_lsdev = $root . '/vendor/45drives/tools/tools/lsdev';
  $cmd = 'python3 ' . escapeshellarg($script) . ' ' . escapeshellarg($vendor_lsdev) . ' ' . escapeshellarg($style) . ' ' . escapeshellarg($chassis);
  $output = [];
  $code = 0;
  exec($cmd, $output, $code);
  if ($code !== 0) {
    return null;
  }
  $decoded = json_decode(implode("\n", $output), true);
  if (!is_array($decoded)) {
    return null;
  }
  return array_values(array_map('intval', $decoded));
}

function vendor_dmap_alias_lines($root, $case_name)
{
  $script = $root . '/tests/vendor_dmap_case.py';
  $vendor_dmap = $root . '/vendor/45drives/tools/tools/dmap';
  $cmd = 'python3 '
    . escapeshellarg($script) . ' '
    . escapeshellarg($vendor_dmap) . ' '
    . escapeshellarg($case_name);
  $output = [];
  $code = 0;
  exec($cmd, $output, $code);
  if ($code !== 0) {
    return null;
  }
  $decoded = json_decode(implode("\n", $output), true);
  if (!is_array($decoded)) {
    return null;
  }
  return array_values(array_map('strval', $decoded));
}

function vendor_dmap_cases($root)
{
  $script = $root . '/tests/vendor_dmap_case.py';
  $cmd = 'python3 ' . escapeshellarg($script) . ' --list';
  $output = [];
  $code = 0;
  exec($cmd, $output, $code);
  if ($code !== 0) {
    return null;
  }
  $decoded = json_decode(implode("\n", $output), true);
  if (!is_array($decoded)) {
    return null;
  }
  return array_values(array_map('strval', $decoded));
}

function vendor_dmap_case_server($root, $case_name)
{
  $script = $root . '/tests/vendor_dmap_case.py';
  $vendor_dmap = $root . '/vendor/45drives/tools/tools/dmap';
  $cmd = 'python3 '
    . escapeshellarg($script) . ' '
    . escapeshellarg($vendor_dmap) . ' '
    . escapeshellarg($case_name) . ' --server';
  $output = [];
  $code = 0;
  exec($cmd, $output, $code);
  if ($code !== 0) {
    return null;
  }
  $decoded = json_decode(implode("\n", $output), true);
  return is_array($decoded) ? $decoded : null;
}

function vendor_dmap_case_local_env($root, $case_name)
{
  $script = $root . '/tests/vendor_dmap_case.py';
  $vendor_dmap = $root . '/vendor/45drives/tools/tools/dmap';
  $cmd = 'python3 '
    . escapeshellarg($script) . ' '
    . escapeshellarg($vendor_dmap) . ' '
    . escapeshellarg($case_name) . ' --local-env';
  $output = [];
  $code = 0;
  exec($cmd, $output, $code);
  if ($code !== 0) {
    return [];
  }
  $decoded = json_decode(implode("\n", $output), true);
  return is_array($decoded) ? $decoded : [];
}

function vendor_dmap_case_full_text($root, $case_name)
{
  $script = $root . '/tests/vendor_dmap_case.py';
  $vendor_dmap = $root . '/vendor/45drives/tools/tools/dmap';
  $cmd = 'python3 '
    . escapeshellarg($script) . ' '
    . escapeshellarg($vendor_dmap) . ' '
    . escapeshellarg($case_name) . ' --full';
  $output = [];
  $code = 0;
  exec($cmd, $output, $code);
  if ($code !== 0) {
    return null;
  }
  $decoded = json_decode(implode("\n", $output), true);
  if ($decoded === null) {
    return null;
  }
  return is_string($decoded) ? $decoded : null;
}

function validate_alias_lines($lines, $label)
{
  assert_true(is_array($lines), "$label alias lines are an array");
  if (!is_array($lines) || !$lines) {
    return;
  }

  $seen = [];
  $rows = [];
  foreach ($lines as $idx => $line) {
    $ok = preg_match('/^alias\s+(\d+)-(\d+)\s+\/dev\/disk\/by-path\/\S+$/', $line, $m) === 1;
    assert_true($ok, "$label line format #" . ($idx + 1));
    if (!$ok) {
      continue;
    }
    $bay = $m[1] . '-' . $m[2];
    $row = (int)$m[1];
    $drive = (int)$m[2];
    assert_true(!isset($seen[$bay]), "$label unique bay-id $bay");
    $seen[$bay] = true;
    if (!isset($rows[$row])) {
      $rows[$row] = [];
    }
    $rows[$row][] = $drive;
  }

  ksort($rows);
  foreach ($rows as $row => $drives) {
    sort($drives);
    $expected = range(1, count($drives));
    assert_equal($drives, $expected, "$label contiguous drive numbering for row $row");
  }
}

function run_ported_dmap($root, $ctx, $server, $env = [])
{
  $script = $root . '/scripts/45d-generate-vdev-id';
  $server_file = $ctx['tmp'] . '/server_info.json';
  $output_file = $ctx['tmp'] . '/vdev_id.conf';
  file_put_contents($server_file, json_encode($server, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

  $env_pairs = [
    'DRIVEMAP_DMAP_SERVER_INFO' => $server_file,
    'DRIVEMAP_DMAP_OUTPUT' => $output_file,
  ];
  foreach ($env as $key => $value) {
    if (!is_string($key) || $key === '') {
      continue;
    }
    $env_pairs[$key] = (string)$value;
  }
  $prefix = [];
  foreach ($env_pairs as $key => $value) {
    $prefix[] = $key . '=' . escapeshellarg($value);
  }

  $cmd = implode(' ', $prefix) . ' php ' . escapeshellarg($script) . ' 2>&1';
  $output = [];
  $code = 0;
  exec($cmd, $output, $code);

  $raw = is_file($output_file) ? (string)@file_get_contents($output_file) : '';
  $lines = is_file($output_file) ? alias_lines_from_fixture($output_file) : null;
  return [
    'code' => $code,
    'stdout' => $output,
    'output_file' => $output_file,
    'raw' => $raw,
    'aliases' => $lines,
  ];
}

function run_ported_dmap_alias_lines($root, $ctx, $case_name)
{
  $server = vendor_dmap_case_server($root, $case_name);
  if (!is_array($server)) {
    return null;
  }
  $env = vendor_dmap_case_local_env($root, $case_name);
  $result = run_ported_dmap($root, $ctx, $server, $env);
  if (($result['code'] ?? 1) !== 0) {
    return null;
  }
  return is_array($result['aliases'] ?? null) ? $result['aliases'] : null;
}

register_shutdown_function(function () use (&$cleanup_dirs) {
  foreach ($cleanup_dirs as $dir) {
    rrmdir($dir);
  }
});

// Scenario 1: baseline generator + API checks.
$ctx = create_context('baseline');
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
putenv('DRIVEMAP_SERVER_MODEL=Storinator-S45');
putenv('DRIVEMAP_ALIAS_STYLE=STORINATOR');

[$gen_code] = run_php_script($map_script);
assert_equal($gen_code, 0, 'generator exits successfully');

$map_path = $ctx['out_dir'] . '/drivemap.json';
assert_true(is_file($map_path), 'drivemap.json is created');
$map = load_json_file($map_path);
assert_true(is_array($map), 'drivemap.json parses as JSON');

assert_true(isset($map['rows']) && is_array($map['rows']), 'rows array exists');
assert_equal(count($map['rows']), 2, 'rows are grouped by controller');
assert_equal(count($map['rows'][0]), 3, 'first row has three bays');
assert_equal(count($map['rows'][1]), 2, 'second row has two bays');

$slot_1_1 = $map['rows'][0][0];
assert_equal($slot_1_1['bay-id'], '1-1', 'bay id 1-1');
assert_equal($slot_1_1['occupied'], true, '1-1 is occupied');
assert_equal($slot_1_1['dev'], realpath($ctx['dev_dir'] . '/sda'), '1-1 dev path');
assert_equal($slot_1_1['model-name'], 'ST12000NM0007', '1-1 model name');
assert_equal($slot_1_1['serial'], 'SAMPLE0001', '1-1 serial');
assert_equal($slot_1_1['capacity'], '1 TiB', '1-1 capacity');
assert_equal($slot_1_1['disk_type'], 'HDD', '1-1 disk type');
assert_equal($slot_1_1['temp-c'], '34 C', '1-1 temperature');
assert_equal($slot_1_1['partitions'], '2', '1-1 partition count');
assert_equal($slot_1_1['storage-role'], 'array', '1-1 storage role');
assert_equal($slot_1_1['storage-label'], 'disk1', '1-1 storage label');
assert_equal($slot_1_1['fs-type'], 'xfs', '1-1 filesystem type');
assert_equal($slot_1_1['fs-status'], 'Mounted', '1-1 filesystem status');
assert_equal($slot_1_1['fs-mountpoint'], '/mnt/disk1', '1-1 filesystem mountpoint');

$slot_1_2 = $map['rows'][0][1];
assert_equal($slot_1_2['bay-id'], '1-2', 'bay id 1-2');
assert_equal($slot_1_2['occupied'], true, '1-2 is occupied');
assert_equal($slot_1_2['dev'], realpath($ctx['dev_dir'] . '/sdb'), '1-2 dev path');
assert_equal($slot_1_2['model-name'], 'Samsung SSD', '1-2 model name');
assert_equal($slot_1_2['serial'], 'SAMPLE0002', '1-2 serial');
assert_equal($slot_1_2['capacity'], '512 GiB', '1-2 capacity');
assert_equal($slot_1_2['disk_type'], 'SSD', '1-2 disk type');
assert_equal($slot_1_2['partitions'], '1', '1-2 partition count');
assert_equal($slot_1_2['storage-role'], 'pool', '1-2 storage role');
assert_equal($slot_1_2['storage-label'], 'cache', '1-2 storage label');
assert_equal($slot_1_2['fs-type'], 'btrfs', '1-2 filesystem type');
assert_equal($slot_1_2['fs-status'], 'Mounted', '1-2 filesystem status');
assert_equal($slot_1_2['fs-mountpoint'], '/mnt/cache', '1-2 filesystem mountpoint');

$slot_1_3 = $map['rows'][0][2];
assert_equal($slot_1_3['bay-id'], '1-3', 'bay id 1-3');
assert_equal($slot_1_3['occupied'], false, '1-3 is empty');
assert_equal($slot_1_3['dev'], '', '1-3 dev empty');

$slot_2_1 = $map['rows'][1][0];
assert_equal($slot_2_1['bay-id'], '2-1', 'bay id 2-1');
assert_equal($slot_2_1['occupied'], true, '2-1 is occupied');
assert_equal($slot_2_1['dev'], realpath($ctx['dev_dir'] . '/sdc'), '2-1 dev path');
assert_equal($slot_2_1['serial'], 'SAMPLE0003', '2-1 serial');
assert_equal($slot_2_1['capacity'], '1 TiB', '2-1 capacity');
assert_equal($slot_2_1['disk_type'], 'HDD', '2-1 disk type');
assert_equal($slot_2_1['partitions'], '1', '2-1 partition count');
assert_equal($slot_2_1['temp-c'], '29 C', '2-1 temperature');
assert_equal($slot_2_1['storage-role'], 'unassigned', '2-1 storage role');
assert_equal($slot_2_1['storage-label'], 'dev3', '2-1 storage label');
assert_equal($slot_2_1['fs-type'], '', '2-1 filesystem type empty');
assert_equal($slot_2_1['fs-status'], '', '2-1 filesystem status empty');
assert_equal($slot_2_1['fs-mountpoint'], '', '2-1 filesystem mountpoint empty');

assert_true(isset($map['lastUpdated']), 'lastUpdated present');
assert_true(isset($map['lsdevDuration']), 'lsdevDuration present');

$server_info_path = $ctx['out_dir'] . '/server_info.json';
assert_true(is_file($server_info_path), 'server_info.json is created');
$server_info = load_json_file($server_info_path);
assert_true(is_array($server_info), 'server_info.json parses as JSON');
assert_equal($server_info['Model'] ?? '', 'Storinator-S45', 'server_info model');

[$lsdev_code, $lsdev_body] = run_api_action($root, 'lsdev');
assert_equal($lsdev_code, 0, 'lsdev endpoint exits successfully');
$lsdev = json_decode($lsdev_body, true);
assert_true(is_array($lsdev), 'lsdev endpoint returns JSON');
assert_equal(count($lsdev['rows']), 2, 'lsdev endpoint returns rows');

[$disk_code, $disk_body] = run_api_action($root, 'disk_info');
assert_equal($disk_code, 0, 'disk_info endpoint exits successfully');
$disk_info = json_decode($disk_body, true);
assert_true(is_array($disk_info) && isset($disk_info['rows']), 'disk_info endpoint returns rows');
assert_equal(count($disk_info['rows']), 5, 'disk_info row count matches slots');
assert_equal($disk_info['rows'][0]['bay-id'], '1-1', 'disk_info first row matches');
$disk_info_by_bay = array_column($disk_info['rows'], null, 'bay-id');
assert_equal($disk_info_by_bay['1-1']['storage-role'], 'array', 'disk_info 1-1 storage role');
assert_equal($disk_info_by_bay['1-1']['storage-label'], 'disk1', 'disk_info 1-1 storage label');
assert_equal($disk_info_by_bay['1-1']['fs-type'], 'xfs', 'disk_info 1-1 filesystem type');
assert_equal($disk_info_by_bay['1-1']['fs-status'], 'Mounted', 'disk_info 1-1 filesystem status');
assert_equal($disk_info_by_bay['1-1']['fs-mountpoint'], '/mnt/disk1', 'disk_info 1-1 filesystem mountpoint');
assert_equal($disk_info_by_bay['1-2']['storage-role'], 'pool', 'disk_info 1-2 storage role');
assert_equal($disk_info_by_bay['1-2']['storage-label'], 'cache', 'disk_info 1-2 storage label');
assert_equal($disk_info_by_bay['1-2']['fs-type'], 'btrfs', 'disk_info 1-2 filesystem type');
assert_equal($disk_info_by_bay['1-2']['fs-status'], 'Mounted', 'disk_info 1-2 filesystem status');
assert_equal($disk_info_by_bay['1-2']['fs-mountpoint'], '/mnt/cache', 'disk_info 1-2 filesystem mountpoint');
assert_equal($disk_info_by_bay['2-1']['storage-role'], 'unassigned', 'disk_info 2-1 storage role');
assert_equal($disk_info_by_bay['2-1']['storage-label'], 'dev3', 'disk_info 2-1 storage label');
assert_equal($disk_info_by_bay['2-1']['fs-type'], '', 'disk_info 2-1 filesystem type empty');
assert_equal($disk_info_by_bay['2-1']['fs-status'], '', 'disk_info 2-1 filesystem status empty');
assert_equal($disk_info_by_bay['2-1']['fs-mountpoint'], '', 'disk_info 2-1 filesystem mountpoint empty');

[$server_code, $server_body] = run_api_action($root, 'server_info');
assert_equal($server_code, 0, 'server_info endpoint exits successfully');
$server_info = json_decode($server_body, true);
assert_true(is_array($server_info), 'server_info endpoint returns JSON');
assert_equal($server_info['Model'] ?? null, 'Storinator-S45', 'server_info model');

putenv('DRIVEMAP_ZFS_FORCE=1');
putenv('DRIVEMAP_ZFS_FIXTURE_DIR=' . $fixtures . '/zfs');
[$zfs_code, $zfs_body] = run_api_action($root, 'zfs_info');
assert_equal($zfs_code, 0, 'zfs_info endpoint exits successfully');
$zfs_info = json_decode($zfs_body, true);
assert_true(is_array($zfs_info), 'zfs_info endpoint returns JSON');
assert_equal($zfs_info['zfs_installed'], true, 'zfs_info reports installed');
assert_true(isset($zfs_info['zpools']) && count($zfs_info['zpools']) === 1, 'zfs_info reports one zpool');
assert_equal($zfs_info['zpools'][0]['name'], 'tank', 'zfs_info pool name');
assert_true(isset($zfs_info['zfs_disks']), 'zfs_info includes disk map');
assert_true(isset($zfs_info['zfs_disks']['1-1']), 'zfs_info includes disk 1-1');

putenv('DRIVEMAP_ZFS_FIXTURE_DIR=' . $fixtures . '/zfs_raw');
[$zfs_raw_code, $zfs_raw_body] = run_api_action($root, 'zfs_info');
assert_equal($zfs_raw_code, 0, 'zfs_info raw-device endpoint exits successfully');
$zfs_raw_info = json_decode($zfs_raw_body, true);
assert_true(is_array($zfs_raw_info), 'zfs_info raw-device endpoint returns JSON');
assert_true(isset($zfs_raw_info['zfs_disks']['1-1']), 'raw-device zfs_info maps sda1 to bay 1-1');
assert_true(isset($zfs_raw_info['zfs_disks']['1-2']), 'raw-device zfs_info maps sdb1 to bay 1-2');
assert_true(!isset($zfs_raw_info['zfs_disks']['sda1']), 'raw-device zfs_info does not expose raw sda1 key');
assert_true(empty($zfs_raw_info['warnings']), 'raw-device zfs_info suppresses alias warning when drivemap can resolve devices');

require_once $root . '/php/zfs_info.php';
$lookup_refresh_path = $ctx['out_dir'] . '/zfs_lookup_refresh.json';
file_put_contents($lookup_refresh_path, json_encode([
  'rows' => [[
    ['bay-id' => '9-1', 'dev' => '/dev/sdz', 'dev-by-path' => '/dev/disk/by-path/test-zfs-refresh'],
  ]],
]));
putenv('DRIVEMAP_OUTPUT_FILE=' . $lookup_refresh_path);
$first_lookup = zfs_drivemap_lookup();
assert_equal($first_lookup['/dev/sdz'] ?? null, '9-1', 'zfs drivemap lookup reads initial map');
file_put_contents($lookup_refresh_path, json_encode([
  'rows' => [[
    ['bay-id' => '9-2', 'dev' => '/dev/sdz', 'dev-by-path' => '/dev/disk/by-path/test-zfs-refresh'],
  ]],
]));
$second_lookup = zfs_drivemap_lookup();
assert_equal($second_lookup['/dev/sdz'] ?? null, '9-2', 'zfs drivemap lookup refreshes regenerated map');
putenv('DRIVEMAP_OUTPUT_FILE');

// Scenario 2: SMART-derived fields.
putenv('DRIVEMAP_SMARTCTL_DIR=' . $fixtures . '/smart');
[$smart_code] = run_php_script($map_script);
assert_equal($smart_code, 0, 'generator exits successfully with smart fixtures');
$smart_map = load_json_file($ctx['out_dir'] . '/drivemap.json');
assert_true(is_array($smart_map), 'smart map parses as JSON');
$smart_1_1 = find_slot($smart_map['rows'] ?? [], '1-1');
$smart_1_2 = find_slot($smart_map['rows'] ?? [], '1-2');
assert_true(is_array($smart_1_1), 'smart slot 1-1 exists');
assert_true(is_array($smart_1_2), 'smart slot 1-2 exists');
assert_equal($smart_1_1['model-family'] ?? '', 'Seagate Exos X16', 'smart model-family from fixture');
assert_equal($smart_1_1['firm-ver'] ?? '', 'SC60', 'smart firmware from fixture');
assert_equal($smart_1_1['start-stop-count'] ?? '', '12', 'smart start-stop count');
assert_equal($smart_1_1['power-cycle-count'] ?? '', '3', 'smart power-cycle count');
assert_equal($smart_1_1['temp-c'] ?? '', '35 C', 'smart ata temperature');
assert_equal($smart_1_1['health'] ?? '', 'OK', 'smart health');
assert_equal($smart_1_1['power-on-time'] ?? '', '12345', 'smart power on time');
assert_equal($smart_1_2['temp-c'] ?? '', '30 C', 'smart non-ata temperature');

// Scenario 3: H16/Q30 row parity against upstream lsdev alias_template.
$ctx_h16_q30 = create_context('h16q30');
$h16_q30_map = alias_map_from_fixture($fixtures . '/vdev_id_h16_q30.conf');
write_alias_file($ctx_h16_q30, $h16_q30_map, []);
set_common_env($ctx_h16_q30, $fixtures);
putenv('DRIVEMAP_CHASSIS_SIZE=Q30');
putenv('DRIVEMAP_ALIAS_STYLE=H16');
putenv('DRIVEMAP_SERVER_MODEL=Storinator-H16-Q30');
[$h16_q30_code] = run_php_script($map_script);
assert_equal($h16_q30_code, 0, 'h16/q30 map generation succeeds');
$h16_q30_json = load_json_file($ctx_h16_q30['out_dir'] . '/drivemap.json');
assert_true(is_array($h16_q30_json), 'h16/q30 map parses as JSON');
$vendor_h16_q30 = vendor_template_lengths($root, 'H16', 'Q30');
assert_true(is_array($vendor_h16_q30), 'vendor template H16/Q30 is available');
assert_equal($vendor_h16_q30, [15, 23], 'vendor template H16/Q30 matches expected layout');
assert_equal(count($h16_q30_json['rows'] ?? []), 2, 'h16/q30 row count');
assert_equal(count($h16_q30_json['rows'][0] ?? []), $vendor_h16_q30[0] ?? -1, 'h16/q30 first row length parity');
assert_equal(count($h16_q30_json['rows'][1] ?? []), $vendor_h16_q30[1] ?? -1, 'h16/q30 second row length parity');
assert_equal($h16_q30_json['rows'][0][0]['bay-id'] ?? '', '1-1', 'h16/q30 first bay id');
assert_equal($h16_q30_json['rows'][0][14]['bay-id'] ?? '', '1-15', 'h16/q30 first row tail bay id');
assert_equal($h16_q30_json['rows'][1][0]['bay-id'] ?? '', '2-1', 'h16/q30 second row first bay id');
assert_equal($h16_q30_json['rows'][1][22]['bay-id'] ?? '', '2-23', 'h16/q30 second row tail bay id');

// Scenario 4: Full S45 row parity against upstream template.
$ctx_s45_full = create_context('s45full');
$s45_map = alias_map_from_fixture($fixtures . '/vdev_id_s45_full.conf');
write_alias_file($ctx_s45_full, $s45_map, []);
set_common_env($ctx_s45_full, $fixtures);
putenv('DRIVEMAP_CHASSIS_SIZE=S45');
putenv('DRIVEMAP_ALIAS_STYLE=STORINATOR');
putenv('DRIVEMAP_SERVER_MODEL=Storinator-S45');
[$s45_code] = run_php_script($map_script);
assert_equal($s45_code, 0, 's45 full map generation succeeds');
$s45_json = load_json_file($ctx_s45_full['out_dir'] . '/drivemap.json');
assert_true(is_array($s45_json), 's45 full map parses as JSON');
$vendor_s45 = vendor_template_lengths($root, 'STORINATOR', 'S45');
assert_true(is_array($vendor_s45), 'vendor template STORINATOR/S45 is available');
assert_equal($vendor_s45, [15, 15, 15], 'vendor template STORINATOR/S45 matches expected layout');
assert_equal(count($s45_json['rows'] ?? []), 3, 's45 full row count');
assert_equal(count($s45_json['rows'][0] ?? []), $vendor_s45[0] ?? -1, 's45 row 1 parity');
assert_equal(count($s45_json['rows'][1] ?? []), $vendor_s45[1] ?? -1, 's45 row 2 parity');
assert_equal(count($s45_json['rows'][2] ?? []), $vendor_s45[2] ?? -1, 's45 row 3 parity');

// Scenario 5: server_info inference for H16/Q30 without force flags.
$ctx_server_info = create_context('serverinfo');
$server_alias_map = alias_map_from_fixture($fixtures . '/vdev_id_h16_q30.conf');
write_alias_file($ctx_server_info, $server_alias_map, []);
set_common_env($ctx_server_info, $fixtures);
putenv('DRIVEMAP_SERVER_MODEL');
putenv('DRIVEMAP_CHASSIS_SIZE');
putenv('DRIVEMAP_ALIAS_STYLE');
[$server_gen_code] = run_php_script($server_script);
assert_equal($server_gen_code, 0, 'server_info generator exits successfully');
$inferred_server_info = load_json_file($ctx_server_info['out_dir'] . '/server_info.json');
assert_true(is_array($inferred_server_info), 'inferred server_info parses as JSON');
assert_equal($inferred_server_info['Alias Style'] ?? '', 'H16', 'inferred alias style from aliases');
assert_equal($inferred_server_info['Chassis Size'] ?? '', 'Q30', 'inferred chassis size from aliases');
assert_true(strpos((string)($inferred_server_info['Model'] ?? ''), 'H16-Q30') !== false, 'inferred model includes H16-Q30');

// Scenario 6: fixture parity against vendored upstream dmap output.
$dmap_cases = [
  ['name' => 'h16_q30', 'fixture' => $fixtures . '/vdev_id_h16_q30.conf'],
  ['name' => 'h16_s45', 'fixture' => $fixtures . '/vdev_id_h16_s45.conf'],
  ['name' => 'storinator_s45', 'fixture' => $fixtures . '/vdev_id_s45_full.conf'],
  ['name' => 'f8_x1', 'fixture' => $fixtures . '/dmap_f8_x1.conf'],
  ['name' => 'c8', 'fixture' => $fixtures . '/dmap_c8.conf'],
];
foreach ($dmap_cases as $case) {
  $name = $case['name'];
  $fixture_lines = alias_lines_from_fixture($case['fixture']);
  assert_true(count($fixture_lines) > 0, "dmap fixture has alias lines ($name)");
  validate_alias_lines($fixture_lines, "fixture $name");
  $vendor_lines = vendor_dmap_alias_lines($root, $name);
  assert_true(is_array($vendor_lines), "vendor dmap case resolved ($name)");
  if (is_array($vendor_lines)) {
    validate_alias_lines($vendor_lines, "vendor $name");
    assert_equal($fixture_lines, $vendor_lines, "dmap fixture parity ($name)");
  }
}

// Scenario 7: ported dmap contract. This should fail until the local
// dmap-equivalent generator is implemented.
$ported_dmap_script = $root . '/scripts/45d-generate-vdev-id';
$ported_dmap_cases = vendor_dmap_cases($root);
assert_true(is_array($ported_dmap_cases), 'ported dmap case list available');
if (!is_file($ported_dmap_script)) {
  assert_true(false, 'ported dmap script exists (scripts/45d-generate-vdev-id)');
} elseif (is_array($ported_dmap_cases)) {
  foreach ($ported_dmap_cases as $name) {
    $vendor_lines = vendor_dmap_alias_lines($root, $name);
    assert_true(is_array($vendor_lines), "ported vendor case resolved ($name)");
    $ctx_case = create_context('ported-dmap-' . $name);
    $server = vendor_dmap_case_server($root, $name);
    assert_true(is_array($server), "ported case server definition available ($name)");
    $env = vendor_dmap_case_local_env($root, $name);
    $ported_result = is_array($server) ? run_ported_dmap($root, $ctx_case, $server, $env) : ['code' => 1, 'aliases' => null, 'raw' => ''];
    $ported_lines = is_array($ported_result['aliases'] ?? null) && ($ported_result['code'] ?? 1) === 0
      ? $ported_result['aliases']
      : null;
    assert_true(is_array($ported_lines), "ported dmap output generated ($name)");
    if (is_array($ported_lines)) {
      validate_alias_lines($ported_lines, "ported $name");
      assert_true(strpos((string)($ported_result['raw'] ?? ''), 'generated using dmap') !== false, "ported dmap header present ($name)");
      $vendor_full = vendor_dmap_case_full_text($root, $name);
      if (is_string($vendor_full)) {
        $ported_raw = (string)($ported_result['raw'] ?? '');
        $ported_body = preg_replace('/^# This file was generated using dmap .*?\n/', '', $ported_raw);
        if (strpos($vendor_full, '# This file was generated using dmap ') === 0) {
          assert_equal($ported_raw, $vendor_full, "ported dmap full text parity vs vendor ($name)");
        } else {
          assert_equal($ported_body, $vendor_full, "ported dmap body parity vs vendor (headerless upstream branch) ($name)");
        }
      }
      if (is_array($vendor_lines)) {
        assert_equal($ported_lines, $vendor_lines, "ported dmap direct parity vs vendor ($name)");
      }
      $ctx_repeat = create_context('ported-dmap-repeat-' . $name);
      $ported_repeat = run_ported_dmap_alias_lines($root, $ctx_repeat, $name);
      assert_true(is_array($ported_repeat), "ported dmap repeat output generated ($name)");
      if (is_array($ported_repeat)) {
        assert_equal($ported_repeat, $ported_lines, "ported dmap deterministic output ($name)");
      }
    }
  }
}

// Scenario 8: unsupported alias style should fail.
$ctx_invalid = create_context('ported-dmap-invalid');
$invalid_server = vendor_dmap_case_server($root, 'h16_q30');
if (is_array($invalid_server)) {
  $invalid_server['Alias Style'] = 'UNSUPPORTED_STYLE';
  $invalid_result = run_ported_dmap($root, $ctx_invalid, $invalid_server, []);
  assert_true(($invalid_result['code'] ?? 0) !== 0, 'ported dmap rejects unsupported alias style');
  $invalid_aliases = $invalid_result['aliases'] ?? null;
  assert_true($invalid_aliases === null || $invalid_aliases === [], 'ported dmap does not emit aliases on invalid style');
}

// Scenario 9: HL15 fallback detection + auto alias generation without vendor tools.
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
assert_equal($hl15_code, 0, 'hl15 fallback map generation succeeds');
$hl15_alias_lines = alias_lines_from_fixture($ctx_hl15['alias_file']);
assert_equal(count($hl15_alias_lines), 15, 'hl15 fallback generates fifteen aliases');
$hl15_server = load_json_file($ctx_hl15['out_dir'] . '/server_info.json');
assert_true(is_array($hl15_server), 'hl15 fallback server_info parses as JSON');
assert_equal($hl15_server['Model'] ?? '', 'Unraid >< 45Homelab X-15', 'hl15 fallback model');
assert_equal($hl15_server['Alias Style'] ?? '', 'HOMELAB', 'hl15 fallback alias style');
assert_equal($hl15_server['Chassis Size'] ?? '', 'HL15', 'hl15 fallback chassis');
assert_equal($hl15_server['HBA'][0]['Model'] ?? '', 'HBA 9400-16i', 'hl15 fallback hba model');
assert_equal($hl15_server['HBA'][0]['Bus Address'] ?? '', '0000:02:00.0', 'hl15 fallback hba bus');
$hl15_map = load_json_file($ctx_hl15['out_dir'] . '/drivemap.json');
assert_true(is_array($hl15_map), 'hl15 fallback drivemap parses as JSON');
assert_equal(count($hl15_map['rows'] ?? []), 1, 'hl15 fallback row count');
assert_equal(count($hl15_map['rows'][0] ?? []), 15, 'hl15 fallback bay count');

if ($failures > 0) {
  fwrite(STDERR, "\n$failures test(s) failed.\n");
  exit(1);
}

fwrite(STDOUT, "All tests passed.\n");
