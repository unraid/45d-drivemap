<?php
$root = dirname(__DIR__);
$fixtures = __DIR__ . '/fixtures';
$map_script = $root . '/scripts/45d-generate-map';
$server_script = $root . '/scripts/45d-generate-server-info';
$hba_paths_script = $root . '/scripts/45d-list-hba-paths';

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
  $tmp = sys_get_temp_dir() . '/45homelab-tests-' . uniqid() . $suffix;
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
  putenv('DRIVEMAP_ATA_PORTS_JSON=');
  putenv('DRIVEMAP_ATA_PORT_DIR=');
  putenv('DRIVEMAP_SMARTCTL_DIR=');
  putenv('DRIVEMAP_DISABLE_SMART=');
}

function run_php_script($script, $env = [])
{
  return run_php_script_args($script, [], $env);
}

function run_php_script_args($script, $args = [], $env = [])
{
  $prefix = [];
  foreach ($env as $key => $value) {
    $prefix[] = $key . '=' . escapeshellarg((string)$value);
  }
  $parts = [];
  foreach ($args as $arg) {
    $parts[] = escapeshellarg((string)$arg);
  }
  $output = [];
  $code = 0;
  $cmd = (count($prefix) ? implode(' ', $prefix) . ' ' : '') . 'php ' . escapeshellarg($script);
  if ($parts) {
    $cmd .= ' ' . implode(' ', $parts);
  }
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

// Scenario 1b: HBA path inventory helper lists by-path, sdX, serial, and port.
$ctx_hba_paths = create_context('hba-paths');
@symlink($ctx_hba_paths['dev_dir'] . '/sda', $ctx_hba_paths['by_path_dir'] . '/pci-0000:01:00.0-sas-phy2-lun-0');
@symlink($ctx_hba_paths['dev_dir'] . '/sdb', $ctx_hba_paths['by_path_dir'] . '/pci-0000:01:00.0-scsi-0:0:59:0');
@symlink($ctx_hba_paths['dev_dir'] . '/sdc', $ctx_hba_paths['by_path_dir'] . '/pci-0000:02:00.0-sas-phy1-lun-0');
@symlink($ctx_hba_paths['dev_dir'] . '/sda', $ctx_hba_paths['by_path_dir'] . '/pci-0000:00:17.0-ata-1');
[$hba_paths_code, $hba_paths_output] = run_php_script_args($hba_paths_script, ['--json'], [
  'DRIVEMAP_HBA_PATH_DIR' => $ctx_hba_paths['by_path_dir'],
  'DRIVEMAP_LSBLK' => $fixtures . '/lsblk.txt',
]);
assert_equal($hba_paths_code, 0, 'hba path helper exits successfully');
$hba_paths_rows = json_decode(implode("\n", $hba_paths_output), true);
assert_true(is_array($hba_paths_rows), 'hba path helper emits JSON rows');
assert_equal(count($hba_paths_rows), 4, 'hba path helper includes HBA and SATA paths');
$hba_paths_by_port = array_column($hba_paths_rows, null, 'port');
assert_equal($hba_paths_by_port['phy2']['hba_path'] ?? '', $ctx_hba_paths['by_path_dir'] . '/pci-0000:01:00.0-sas-phy2-lun-0', 'hba path helper includes by-path');
assert_equal($hba_paths_by_port['phy2']['device'] ?? '', '/dev/sda', 'hba path helper resolves sdX');
assert_equal($hba_paths_by_port['phy2']['serial'] ?? '', 'SAMPLE0001', 'hba path helper includes serial');
assert_equal($hba_paths_by_port['phy2']['model'] ?? '', 'ST12000NM0007', 'hba path helper includes model');
assert_equal($hba_paths_by_port['phy2']['port_type'] ?? '', 'sas', 'hba path helper parses sas phy');
assert_equal($hba_paths_by_port['phy2']['path_source'] ?? '', 'by-path', 'hba path helper marks by-path source');
assert_equal($hba_paths_by_port['target59']['device'] ?? '', '/dev/sdb', 'hba path helper resolves scsi sdX');
assert_equal($hba_paths_by_port['target59']['port_type'] ?? '', 'scsi', 'hba path helper parses scsi target');
assert_equal($hba_paths_by_port['ata1']['device'] ?? '', '/dev/sda', 'hba path helper resolves sata sdX');
assert_equal($hba_paths_by_port['ata1']['port_type'] ?? '', 'ata', 'hba path helper parses sata port');

// Scenario 1c: HBA path inventory helper includes lsblk-visible SATA disks
// even when udev does not publish /dev/disk/by-path ata symlinks.
$ctx_hba_paths_sata_fallback = create_context('hba-paths-sata-fallback');
@symlink($ctx_hba_paths_sata_fallback['dev_dir'] . '/sda', $ctx_hba_paths_sata_fallback['by_path_dir'] . '/pci-0000:01:00.0-sas-phy2-lun-0');
$lsblk_sata_fallback = implode("\n", [
  'NAME="sda" TYPE="disk" TRAN="sas" HCTL="0:0:0:0" MODEL="ST12000NM0007" SERIAL="SAMPLE0001" SIZE="1099511627776" ROTA="1"',
  'NAME="sdg" TYPE="disk" TRAN="sata" HCTL="3:0:0:0" MODEL="WDC WUH721414ALE6L4" SERIAL="9JH7KJXT" SIZE="14000519643136" ROTA="1"',
  'NAME="sdg1" TYPE="part" TRAN="" HCTL="" MODEL="" SERIAL="" SIZE="14000518594560" ROTA="1"',
]);
[$hba_paths_sata_code, $hba_paths_sata_output] = run_php_script_args($hba_paths_script, ['--json'], [
  'DRIVEMAP_HBA_PATH_DIR' => $ctx_hba_paths_sata_fallback['by_path_dir'],
  'DRIVEMAP_LSBLK' => $lsblk_sata_fallback,
  'DRIVEMAP_UDEVADM_PROPS_JSON' => json_encode([
    'sdg' => [
      'DEVPATH' => '/devices/pci0000:00/0000:00:17.0/ata3/host3/target3:0:0/3:0:0:0/block/sdg',
      'ID_BUS' => 'ata',
    ],
  ]),
]);
assert_equal($hba_paths_sata_code, 0, 'hba path helper with sata fallback exits successfully');
$hba_paths_sata_rows = json_decode(implode("\n", $hba_paths_sata_output), true);
assert_true(is_array($hba_paths_sata_rows), 'hba path helper with sata fallback emits JSON rows');
assert_equal(count($hba_paths_sata_rows), 2, 'hba path helper includes by-path and lsblk-only SATA rows');
$hba_paths_sata_by_device = array_column($hba_paths_sata_rows, null, 'device');
assert_equal($hba_paths_sata_by_device['/dev/sdg']['hba_path'] ?? '', 'udev:devpath:pci-0000:00:17.0-ata-3', 'hba path helper marks udev DEVPATH SATA source');
assert_equal($hba_paths_sata_by_device['/dev/sdg']['port_type'] ?? '', 'ata', 'hba path helper marks udev DEVPATH SATA port type');
assert_equal($hba_paths_sata_by_device['/dev/sdg']['path_source'] ?? '', 'udev-devpath', 'hba path helper marks udev DEVPATH fallback source');
assert_equal($hba_paths_sata_by_device['/dev/sdg']['bus'] ?? '', '0000:00:17.0', 'hba path helper derives SATA PCI bus from udev DEVPATH');
assert_equal($hba_paths_sata_by_device['/dev/sdg']['port'] ?? '', 'ata3', 'hba path helper derives SATA port from udev DEVPATH');
assert_equal($hba_paths_sata_by_device['/dev/sdg']['serial'] ?? '', '9JH7KJXT', 'hba path helper includes lsblk-only SATA serial');

// Scenario 1d: HBA path inventory helper still reports fallback rows when /dev/disk/by-path is absent.
$ctx_hba_paths_missing_dir = create_context('hba-paths-missing-dir');
$missing_by_path_dir = $ctx_hba_paths_missing_dir['tmp'] . '/missing-by-path';
[$hba_paths_missing_dir_code, $hba_paths_missing_dir_output] = run_php_script_args($hba_paths_script, ['--json'], [
  'DRIVEMAP_HBA_PATH_DIR' => $missing_by_path_dir,
  'DRIVEMAP_LSBLK' => $lsblk_sata_fallback,
  'DRIVEMAP_UDEVADM_PROPS_JSON' => json_encode([
    'sdg' => [
      'DEVPATH' => '/devices/pci0000:00/0000:00:17.0/ata3/host3/target3:0:0/3:0:0:0/block/sdg',
      'ID_BUS' => 'ata',
    ],
  ]),
]);
assert_equal($hba_paths_missing_dir_code, 0, 'hba path helper tolerates missing by-path directory');
$hba_paths_missing_dir_rows = json_decode(implode("\n", $hba_paths_missing_dir_output), true);
assert_true(is_array($hba_paths_missing_dir_rows), 'hba path helper emits JSON with missing by-path directory');
$hba_paths_missing_dir_by_device = array_column($hba_paths_missing_dir_rows, null, 'device');
assert_equal($hba_paths_missing_dir_by_device['/dev/sdg']['hba_path'] ?? '', 'udev:devpath:pci-0000:00:17.0-ata-3', 'hba path helper includes udev fallback when by-path directory is missing');
assert_true(!isset($hba_paths_missing_dir_by_device['/dev/sda']), 'hba path helper does not invent SAS topology without by-path evidence');

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

$original_zfs_fixture_dir = getenv('DRIVEMAP_ZFS_FIXTURE_DIR');
putenv('DRIVEMAP_ZFS_FIXTURE_DIR=' . $fixtures . '/zfs_array');
try {
  [$zfs_array_code, $zfs_array_body] = run_api_action($root, 'zfs_info');
  assert_equal($zfs_array_code, 0, 'zfs_info array-device endpoint exits successfully');
  $zfs_array_info = json_decode($zfs_array_body, true);
  assert_true(is_array($zfs_array_info), 'zfs_info array-device endpoint returns JSON');
  assert_true(isset($zfs_array_info['zfs_disks']['1-1']), 'array-device zfs_info maps md1p1 pool to disk1 bay');
  assert_equal($zfs_array_info['zfs_disks']['1-1']['zpool_name'] ?? '', 'disk1', 'array-device zfs_info reports disk1 pool on bay 1-1');
  assert_true(!isset($zfs_array_info['zfs_disks']['/dev/mapper/md1p1']), 'array-device zfs_info does not expose md mapper key');
  assert_true(!isset($zfs_array_info['zfs_disks']['nvme1n1p1']), 'array-device zfs_info ignores unmapped nvme pool members');
  assert_true(!isset($zfs_array_info['zfs_disks']['nvme2n1p1']), 'array-device zfs_info ignores second unmapped nvme pool member');
  assert_true(empty($zfs_array_info['warnings']), 'array-device zfs_info suppresses warning for non-slot zfs devices');
} finally {
  if ($original_zfs_fixture_dir === false) {
    putenv('DRIVEMAP_ZFS_FIXTURE_DIR');
  } else {
    putenv('DRIVEMAP_ZFS_FIXTURE_DIR=' . $original_zfs_fixture_dir);
  }
}

require_once $root . '/php/zfs_info.php';
$malformed_alerts = verify_zfs_device_format([
  'tank' => "\ttank        ONLINE       0     0     0\n\t  mirror-0  ONLINE       0     0     0\n\t    bad-disk  ONLINE       0     0     0\n",
], 'tank', []);
assert_true(!empty($malformed_alerts), 'zfs device format warns for unresolved malformed members');
assert_true(strpos(implode('', $malformed_alerts), 'bad-disk') !== false, 'zfs device format warning names malformed member');

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
$smart_2_1 = find_slot($smart_map['rows'] ?? [], '2-1');
assert_true(is_array($smart_1_1), 'smart slot 1-1 exists');
assert_true(is_array($smart_1_2), 'smart slot 1-2 exists');
assert_true(is_array($smart_2_1), 'smart slot 2-1 exists');
assert_equal($smart_1_1['model-family'] ?? '', 'Seagate Exos X16', 'smart model-family from fixture');
assert_equal($smart_1_1['firm-ver'] ?? '', 'SC60', 'smart firmware from fixture');
assert_equal($smart_1_1['start-stop-count'] ?? '', '12', 'smart start-stop count');
assert_equal($smart_1_1['power-cycle-count'] ?? '', '3', 'smart power-cycle count');
assert_equal($smart_1_1['temp-c'] ?? '', '35 C', 'smart ata temperature');
assert_equal($smart_1_1['health'] ?? '', 'OK', 'smart health');
assert_equal($smart_1_1['power-on-time'] ?? '', '12345', 'smart power on time');
assert_equal($smart_1_2['temp-c'] ?? '', '30 C', 'smart non-ata temperature');
assert_equal($smart_2_1['power-mode'] ?? '', 'STANDBY', 'smart sas standby by command power mode');

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

// Scenario 8b: HL15 v1 X11 systems without a discrete HBA use the factory dalias order.
$ctx_hl15_x11 = create_context('ported-dmap-hl15-x11-no-hba');
$hl15_x11_server = [
  'Model' => '45Homelab HL-15 1.0',
  'Alias Style' => 'HOMELAB',
  'Chassis Size' => 'HL15',
  'Motherboard' => [
    'Product Name' => 'X11SPH-nCTPF',
  ],
  'HBA' => [],
  'OS NAME' => 'Unraid',
  'OS VERSION_ID' => '',
];
$hl15_x11_lspci = [
  '00:11.5 SATA controller: Intel Corporation C620 Series Chipset Family sSATA Controller [AHCI mode]',
  '00:17.0 SATA controller: Intel Corporation C620 Series Chipset Family SATA Controller [AHCI mode]',
  '19:00.0 Serial Attached SCSI controller: Broadcom / LSI SAS3008 PCI-Express Fusion-MPT SAS-3',
];
$hl15_x11_result = run_ported_dmap($root, $ctx_hl15_x11, $hl15_x11_server, [
  'DRIVEMAP_DMAP_LSPCI_JSON' => json_encode($hl15_x11_lspci),
]);
assert_equal($hl15_x11_result['code'] ?? 1, 0, 'hl15 x11 no-hba dmap exits successfully');
$hl15_x11_expected = [
  'alias 1-1 /dev/disk/by-path/pci-0000:00:17.0-ata-3',
  'alias 1-2 /dev/disk/by-path/pci-0000:00:17.0-ata-4',
  'alias 1-3 /dev/disk/by-path/pci-0000:00:17.0-ata-5',
  'alias 1-4 /dev/disk/by-path/pci-0000:00:17.0-ata-6',
  'alias 1-5 /dev/disk/by-path/pci-0000:00:17.0-ata-7',
  'alias 1-6 /dev/disk/by-path/pci-0000:00:17.0-ata-8',
  'alias 1-7 /dev/disk/by-path/pci-0000:00:17.0-ata-9',
  'alias 1-8 /dev/disk/by-path/pci-0000:00:17.0-ata-10',
  'alias 1-9 /dev/disk/by-path/pci-0000:19:00.0-sas-phy0-lun-0',
  'alias 1-10 /dev/disk/by-path/pci-0000:19:00.0-sas-phy1-lun-0',
  'alias 1-11 /dev/disk/by-path/pci-0000:19:00.0-sas-phy2-lun-0',
  'alias 1-12 /dev/disk/by-path/pci-0000:19:00.0-sas-phy3-lun-0',
  'alias 1-13 /dev/disk/by-path/pci-0000:19:00.0-sas-phy4-lun-0',
  'alias 1-14 /dev/disk/by-path/pci-0000:19:00.0-sas-phy5-lun-0',
  'alias 1-15 /dev/disk/by-path/pci-0000:19:00.0-sas-phy6-lun-0',
];
assert_equal($hl15_x11_result['aliases'] ?? null, $hl15_x11_expected, 'hl15 x11 no-hba dmap matches factory dalias order');

// Scenario 8b2: HL15 v1 X11 SATA aliases fall back to udev DEVPATH when ata by-path links are absent.
$ctx_hl15_x11_devpath = create_context('ported-dmap-hl15-x11-devpath-sata');
$hl15_x11_devpath_result = run_ported_dmap($root, $ctx_hl15_x11_devpath, $hl15_x11_server, [
  'DRIVEMAP_DMAP_LSPCI_JSON' => json_encode($hl15_x11_lspci),
  'DRIVEMAP_DMAP_LSBLK' => implode("\n", [
    'NAME="sdg" TYPE="disk" TRAN="sata" HCTL="3:0:0:0" MODEL="WDC WUH721414ALE6L4" SERIAL="9JH7KJXT" SIZE="14000519643136" ROTA="1"',
    'NAME="sdh" TYPE="disk" TRAN="sata" HCTL="4:0:0:0" MODEL="WDC WUH721414ALE604" SERIAL="9RG7N6VC" SIZE="14000519643136" ROTA="1"',
    'NAME="sdm" TYPE="disk" TRAN="sata" HCTL="10:0:0:0" MODEL="WDC WD80EFZZ-68BTXN0" SERIAL="WD-CA19LHJK" SIZE="8001563222016" ROTA="1"',
  ]),
  'DRIVEMAP_DMAP_UDEVADM_PROPS_JSON' => json_encode([
    'sdg' => [
      'DEVLINKS' => '/dev/disk/by-diskseq/27 /dev/disk/by-id/ata-WDC_WUH721414ALE6L4_9JH7KJXT /dev/disk/by-id/wwn-0x5000cca258d187f7',
      'DEVPATH' => '/devices/pci0000:00/0000:00:17.0/ata3/host3/target3:0:0/3:0:0:0/block/sdg',
      'ID_BUS' => 'ata',
    ],
    'sdh' => [
      'DEVLINKS' => '/dev/disk/by-diskseq/28 /dev/disk/by-id/ata-WDC_WUH721414ALE604_9RG7N6VC /dev/disk/by-id/wwn-0x5000cca264c37a81',
      'DEVPATH' => '/devices/pci0000:00/0000:00:17.0/ata4/host4/target4:0:0/4:0:0:0/block/sdh',
      'ID_BUS' => 'ata',
    ],
    'sdm' => [
      'DEVLINKS' => '/dev/disk/by-diskseq/33 /dev/disk/by-id/ata-WDC_WD80EFZZ-68BTXN0_WD-CA19LHJK /dev/disk/by-id/wwn-0x50014ee26a68e1eb',
      'DEVPATH' => '/devices/pci0000:00/0000:00:17.0/ata10/host10/target10:0:0/10:0:0:0/block/sdm',
      'ID_BUS' => 'ata',
    ],
  ]),
]);
assert_equal($hl15_x11_devpath_result['code'] ?? 1, 0, 'hl15 x11 devpath SATA dmap exits successfully');
$hl15_x11_devpath_aliases = $hl15_x11_devpath_result['aliases'] ?? [];
assert_equal($hl15_x11_devpath_aliases[0] ?? '', 'alias 1-1 /dev/disk/by-id/ata-WDC_WUH721414ALE6L4_9JH7KJXT', 'hl15 x11 maps slot 1-1 from SATA port 3');
assert_equal($hl15_x11_devpath_aliases[1] ?? '', 'alias 1-2 /dev/disk/by-id/ata-WDC_WUH721414ALE604_9RG7N6VC', 'hl15 x11 maps slot 1-2 from SATA port 4');
assert_equal($hl15_x11_devpath_aliases[6] ?? '', 'alias 1-7 /dev/disk/by-path/pci-0000:00:17.0-ata-9', 'hl15 x11 leaves slot 1-7 missing when SATA port 9 is absent');
assert_equal($hl15_x11_devpath_aliases[7] ?? '', 'alias 1-8 /dev/disk/by-id/ata-WDC_WD80EFZZ-68BTXN0_WD-CA19LHJK', 'hl15 x11 maps slot 1-8 from SATA port 10');

// Scenario 8b3: HL4 falls back to udev DEVPATH when ata by-path links are absent.
$ctx_hl4_devpath = create_context('ported-dmap-hl4-devpath-sata');
$hl4_server = [
  'Model' => '45Homelab HL-4',
  'Canvas Model' => 'HomeLab-HL4',
  'Alias Style' => 'HOMELAB',
  'Chassis Size' => 'HL4',
  'Motherboard' => [
    'Product Name' => 'B550I AORUS PRO AX',
  ],
  'HBA' => [],
  'OS NAME' => 'Unraid',
  'OS VERSION_ID' => '',
];
$hl4_result = run_ported_dmap($root, $ctx_hl4_devpath, $hl4_server, [
  'DRIVEMAP_DMAP_LSBLK' => implode("\n", [
    'NAME="sda" TYPE="disk" TRAN="sata" HCTL="0:0:0:0" MODEL="ST24000NT002-3N1101" SERIAL="ZYD54BXA" SIZE="24000277680128" ROTA="1"',
    'NAME="sdb" TYPE="disk" TRAN="sata" HCTL="1:0:0:0" MODEL="ST24000NT002-3N1101" SERIAL="ZYD6554S" SIZE="24000277680128" ROTA="1"',
    'NAME="sdd" TYPE="disk" TRAN="sata" HCTL="2:0:0:0" MODEL="ST24000NT002-3N1101" SERIAL="ZYD7FM9P" SIZE="24000277680128" ROTA="1"',
    'NAME="sde" TYPE="disk" TRAN="sata" HCTL="3:0:0:0" MODEL="ST24000NT002-3N1101" SERIAL="ZYD5X4DZ" SIZE="24000277680128" ROTA="1"',
  ]),
  'DRIVEMAP_DMAP_UDEVADM_PROPS_JSON' => json_encode([
    'sda' => [
      'DEVLINKS' => '/dev/disk/by-diskseq/19 /dev/disk/by-id/ata-ST24000NT002-3N1101_ZYD54BXA /dev/disk/by-id/wwn-0x5000c500ea2264be',
      'DEVPATH' => '/devices/pci0000:00/0000:00:02.1/0000:01:00.1/ata1/host0/target0:0:0/0:0:0:0/block/sda',
      'ID_BUS' => 'ata',
    ],
    'sdb' => [
      'DEVLINKS' => '/dev/disk/by-diskseq/20 /dev/disk/by-id/ata-ST24000NT002-3N1101_ZYD6554S /dev/disk/by-id/wwn-0x5000c500ea7a324c',
      'DEVPATH' => '/devices/pci0000:00/0000:00:02.1/0000:01:00.1/ata2/host1/target1:0:0/1:0:0:0/block/sdb',
      'ID_BUS' => 'ata',
    ],
    'sdd' => [
      'DEVLINKS' => '/dev/disk/by-diskseq/22 /dev/disk/by-id/ata-ST24000NT002-3N1101_ZYD7FM9P /dev/disk/by-id/wwn-0x5000c500ea78ab58',
      'DEVPATH' => '/devices/pci0000:00/0000:00:02.1/0000:01:00.1/ata3/host2/target2:0:0/2:0:0:0/block/sdd',
      'ID_BUS' => 'ata',
    ],
    'sde' => [
      'DEVLINKS' => '/dev/disk/by-diskseq/23 /dev/disk/by-id/ata-ST24000NT002-3N1101_ZYD5X4DZ /dev/disk/by-id/wwn-0x5000c500ea6bd21b',
      'DEVPATH' => '/devices/pci0000:00/0000:00:02.1/0000:01:00.1/ata4/host3/target3:0:0/3:0:0:0/block/sde',
      'ID_BUS' => 'ata',
    ],
  ]),
]);
assert_equal($hl4_result['code'] ?? 1, 0, 'hl4 devpath SATA dmap exits successfully');
assert_equal($hl4_result['aliases'] ?? [], [
  'alias 1-1 /dev/disk/by-id/ata-ST24000NT002-3N1101_ZYD5X4DZ',
  'alias 1-2 /dev/disk/by-id/ata-ST24000NT002-3N1101_ZYD7FM9P',
  'alias 1-3 /dev/disk/by-id/ata-ST24000NT002-3N1101_ZYD6554S',
  'alias 1-4 /dev/disk/by-id/ata-ST24000NT002-3N1101_ZYD54BXA',
], 'hl4 maps observed SATA ports in reverse DEVPATH order');

// Scenario 8b4: HL8 falls back to two udev DEVPATH groups when ata by-path links are absent.
$ctx_hl8_devpath = create_context('ported-dmap-hl8-devpath-sata');
$hl8_server = [
  'Model' => '45Homelab HL-8',
  'Canvas Model' => 'HomeLab-HL8',
  'Alias Style' => 'HOMELAB',
  'Chassis Size' => 'HL8',
  'Motherboard' => [
    'Product Name' => 'B550I AORUS PRO AX',
  ],
  'HBA' => [],
  'OS NAME' => 'Unraid',
  'OS VERSION_ID' => '',
];
$hl8_result = run_ported_dmap($root, $ctx_hl8_devpath, $hl8_server, [
  'DRIVEMAP_DMAP_LSBLK' => implode("\n", [
    'NAME="sda" TYPE="disk" TRAN="sata" HCTL="0:0:0:0" MODEL="ST24000NT002-3N1101" SERIAL="HL8A1" SIZE="24000277680128" ROTA="1"',
    'NAME="sdb" TYPE="disk" TRAN="sata" HCTL="1:0:0:0" MODEL="ST24000NT002-3N1101" SERIAL="HL8A2" SIZE="24000277680128" ROTA="1"',
    'NAME="sdc" TYPE="disk" TRAN="sata" HCTL="2:0:0:0" MODEL="ST24000NT002-3N1101" SERIAL="HL8A3" SIZE="24000277680128" ROTA="1"',
    'NAME="sdd" TYPE="disk" TRAN="sata" HCTL="3:0:0:0" MODEL="ST24000NT002-3N1101" SERIAL="HL8A4" SIZE="24000277680128" ROTA="1"',
    'NAME="sde" TYPE="disk" TRAN="sata" HCTL="4:0:0:0" MODEL="ST24000NT002-3N1101" SERIAL="HL8B1" SIZE="24000277680128" ROTA="1"',
    'NAME="sdf" TYPE="disk" TRAN="sata" HCTL="5:0:0:0" MODEL="ST24000NT002-3N1101" SERIAL="HL8B2" SIZE="24000277680128" ROTA="1"',
    'NAME="sdg" TYPE="disk" TRAN="sata" HCTL="6:0:0:0" MODEL="ST24000NT002-3N1101" SERIAL="HL8B3" SIZE="24000277680128" ROTA="1"',
    'NAME="sdh" TYPE="disk" TRAN="sata" HCTL="7:0:0:0" MODEL="ST24000NT002-3N1101" SERIAL="HL8B4" SIZE="24000277680128" ROTA="1"',
  ]),
  'DRIVEMAP_DMAP_UDEVADM_PROPS_JSON' => json_encode([
    'sda' => ['DEVLINKS' => '/dev/disk/by-id/ata-ST24000NT002-3N1101_HL8A1', 'DEVPATH' => '/devices/pci0000:00/0000:00:02.1/0000:01:00.1/ata1/host0/target0:0:0/0:0:0:0/block/sda', 'ID_BUS' => 'ata'],
    'sdb' => ['DEVLINKS' => '/dev/disk/by-id/ata-ST24000NT002-3N1101_HL8A2', 'DEVPATH' => '/devices/pci0000:00/0000:00:02.1/0000:01:00.1/ata2/host1/target1:0:0/1:0:0:0/block/sdb', 'ID_BUS' => 'ata'],
    'sdc' => ['DEVLINKS' => '/dev/disk/by-id/ata-ST24000NT002-3N1101_HL8A3', 'DEVPATH' => '/devices/pci0000:00/0000:00:02.1/0000:01:00.1/ata3/host2/target2:0:0/2:0:0:0/block/sdc', 'ID_BUS' => 'ata'],
    'sdd' => ['DEVLINKS' => '/dev/disk/by-id/ata-ST24000NT002-3N1101_HL8A4', 'DEVPATH' => '/devices/pci0000:00/0000:00:02.1/0000:01:00.1/ata4/host3/target3:0:0/3:0:0:0/block/sdd', 'ID_BUS' => 'ata'],
    'sde' => ['DEVLINKS' => '/dev/disk/by-id/ata-ST24000NT002-3N1101_HL8B1', 'DEVPATH' => '/devices/pci0000:00/0000:00:02.2/0000:02:00.1/ata5/host4/target4:0:0/4:0:0:0/block/sde', 'ID_BUS' => 'ata'],
    'sdf' => ['DEVLINKS' => '/dev/disk/by-id/ata-ST24000NT002-3N1101_HL8B2', 'DEVPATH' => '/devices/pci0000:00/0000:00:02.2/0000:02:00.1/ata6/host5/target5:0:0/5:0:0:0/block/sdf', 'ID_BUS' => 'ata'],
    'sdg' => ['DEVLINKS' => '/dev/disk/by-id/ata-ST24000NT002-3N1101_HL8B3', 'DEVPATH' => '/devices/pci0000:00/0000:00:02.2/0000:02:00.1/ata7/host6/target6:0:0/6:0:0:0/block/sdg', 'ID_BUS' => 'ata'],
    'sdh' => ['DEVLINKS' => '/dev/disk/by-id/ata-ST24000NT002-3N1101_HL8B4', 'DEVPATH' => '/devices/pci0000:00/0000:00:02.2/0000:02:00.1/ata8/host7/target7:0:0/7:0:0:0/block/sdh', 'ID_BUS' => 'ata'],
  ]),
]);
assert_equal($hl8_result['code'] ?? 1, 0, 'hl8 devpath SATA dmap exits successfully');
assert_equal($hl8_result['aliases'] ?? [], [
  'alias 1-1 /dev/disk/by-id/ata-ST24000NT002-3N1101_HL8A4',
  'alias 1-2 /dev/disk/by-id/ata-ST24000NT002-3N1101_HL8A3',
  'alias 1-3 /dev/disk/by-id/ata-ST24000NT002-3N1101_HL8A2',
  'alias 1-4 /dev/disk/by-id/ata-ST24000NT002-3N1101_HL8A1',
  'alias 2-1 /dev/disk/by-id/ata-ST24000NT002-3N1101_HL8B4',
  'alias 2-2 /dev/disk/by-id/ata-ST24000NT002-3N1101_HL8B3',
  'alias 2-3 /dev/disk/by-id/ata-ST24000NT002-3N1101_HL8B2',
  'alias 2-4 /dev/disk/by-id/ata-ST24000NT002-3N1101_HL8B1',
], 'hl8 maps two observed SATA groups in reverse DEVPATH order');

// Scenario 8b5: X4 has its own dynamic four-port SATA mapping and reuses the HL4 canvas.
$ctx_x4_devpath = create_context('ported-dmap-x4-devpath-sata');
$x4_server = [
  'Model' => 'Unraid >< 45Homelab X-4',
  'Canvas Model' => 'HomeLab-HL4',
  'Alias Style' => 'HOMELAB',
  'Chassis Size' => 'X4',
  'Motherboard' => [
    'Product Name' => 'X4 Prototype',
  ],
  'HBA' => [],
  'OS NAME' => 'Unraid',
  'OS VERSION_ID' => '',
];
$x4_result = run_ported_dmap($root, $ctx_x4_devpath, $x4_server, [
  'DRIVEMAP_DMAP_LSBLK' => implode("\n", [
    'NAME="sda" TYPE="disk" TRAN="sata" HCTL="5:0:0:0" MODEL="TOSHIBA MG10AFA22TE" SERIAL="Z360A01YFM8J" SIZE="22000969973760" ROTA="1"',
    'NAME="sdb" TYPE="disk" TRAN="sata" HCTL="6:0:0:0" MODEL="TOSHIBA MG10AFA22TE" SERIAL="Z360A00HFM8J" SIZE="22000969973760" ROTA="1"',
    'NAME="sdc" TYPE="disk" TRAN="sata" HCTL="7:0:0:0" MODEL="TOSHIBA MG10AFA22TE" SERIAL="Z360A00TFM8J" SIZE="22000969973760" ROTA="1"',
    'NAME="sdd" TYPE="disk" TRAN="sata" HCTL="8:0:0:0" MODEL="TOSHIBA MG10AFA22TE" SERIAL="Z360A021FM8J" SIZE="22000969973760" ROTA="1"',
  ]),
  'DRIVEMAP_DMAP_UDEVADM_PROPS_JSON' => json_encode([
    'sda' => [
      'DEVLINKS' => '/dev/disk/by-id/ata-TOSHIBA_MG10AFA22TE_Z360A01YFM8J /dev/disk/by-id/wwn-0x5000039bca000001',
      'DEVPATH' => '/devices/pci0000:80/0000:80:17.0/ata5/host5/target5:0:0/5:0:0:0/block/sda',
      'ID_BUS' => 'ata',
    ],
    'sdb' => [
      'DEVLINKS' => '/dev/disk/by-id/ata-TOSHIBA_MG10AFA22TE_Z360A00HFM8J /dev/disk/by-id/wwn-0x5000039bca000002',
      'DEVPATH' => '/devices/pci0000:80/0000:80:17.0/ata6/host6/target6:0:0/6:0:0:0/block/sdb',
      'ID_BUS' => 'ata',
    ],
    'sdc' => [
      'DEVLINKS' => '/dev/disk/by-id/ata-TOSHIBA_MG10AFA22TE_Z360A00TFM8J /dev/disk/by-id/wwn-0x5000039bca000003',
      'DEVPATH' => '/devices/pci0000:80/0000:80:17.0/ata7/host7/target7:0:0/7:0:0:0/block/sdc',
      'ID_BUS' => 'ata',
    ],
    'sdd' => [
      'DEVLINKS' => '/dev/disk/by-id/ata-TOSHIBA_MG10AFA22TE_Z360A021FM8J /dev/disk/by-id/wwn-0x5000039bca000004',
      'DEVPATH' => '/devices/pci0000:80/0000:80:17.0/ata8/host8/target8:0:0/8:0:0:0/block/sdd',
      'ID_BUS' => 'ata',
    ],
  ]),
]);
assert_equal($x4_result['code'] ?? 1, 0, 'x4 devpath SATA dmap exits successfully');
assert_equal($x4_result['aliases'] ?? [], [
  'alias 1-1 /dev/disk/by-id/ata-TOSHIBA_MG10AFA22TE_Z360A01YFM8J',
  'alias 1-2 /dev/disk/by-id/ata-TOSHIBA_MG10AFA22TE_Z360A00HFM8J',
  'alias 1-3 /dev/disk/by-id/ata-TOSHIBA_MG10AFA22TE_Z360A00TFM8J',
  'alias 1-4 /dev/disk/by-id/ata-TOSHIBA_MG10AFA22TE_Z360A021FM8J',
], 'x4 maps observed SATA ports in ascending DEVPATH order');

// Empty X4 bays still expose ATA ports. The four bay ports are 5-8 on B860I.
$ctx_x4_empty = create_context('ported-dmap-x4-empty');
$ata_port_dir = $ctx_x4_empty['tmp'] . '/ata-port';
ensure_dir($ata_port_dir);
for ($port = 1; $port <= 8; $port++) {
  $target = $ctx_x4_empty['tmp'] . "/pci0000:80/0000:80:17.0/ata$port/ata_port/ata$port";
  ensure_dir($target);
  symlink($target, "$ata_port_dir/ata$port");
}
$x4_empty_result = run_ported_dmap($root, $ctx_x4_empty, $x4_server, [
  'DRIVEMAP_DMAP_LSBLK' => 'NAME="nvme0n1" TYPE="disk" TRAN="nvme"',
  'DRIVEMAP_DMAP_ATA_PORT_DIR' => $ata_port_dir,
]);
assert_equal($x4_empty_result['code'] ?? 1, 0, 'x4 empty-bay dmap exits successfully');
assert_equal($x4_empty_result['aliases'] ?? [], [
  'alias 1-1 /dev/disk/by-path/pci-0000:80:17.0-ata-5',
  'alias 1-2 /dev/disk/by-path/pci-0000:80:17.0-ata-6',
  'alias 1-3 /dev/disk/by-path/pci-0000:80:17.0-ata-7',
  'alias 1-4 /dev/disk/by-path/pci-0000:80:17.0-ata-8',
], 'x4 maps empty bays from the highest four observed ATA ports');

// Scenario 8c: AV15 base aliasing also ignores Intel sSATA when choosing the SATA bus.
$ctx_av15_base_sata = create_context('ported-dmap-av15-base-sata-regex');
$av15_base_server = [
  'Model' => 'Storinator-AV15',
  'Alias Style' => 'AV15-BASE',
  'Chassis Size' => 'AV15',
  'Motherboard' => [
    'Product Name' => 'X11SPH-nCTPF',
  ],
  'HBA' => [],
  'OS NAME' => 'Unraid',
  'OS VERSION_ID' => '',
];
$av15_base_lspci = [
  '00:11.5 SATA controller: Intel Corporation C620 Series Chipset Family sSATA Controller [AHCI mode]',
  '00:17.0 SATA controller: Intel Corporation C620 Series Chipset Family SATA Controller [AHCI mode]',
  '19:00.0 Serial Attached SCSI controller: Broadcom / LSI SAS3008 PCI-Express Fusion-MPT SAS-3',
];
$av15_base_result = run_ported_dmap($root, $ctx_av15_base_sata, $av15_base_server, [
  'DRIVEMAP_DMAP_LSPCI_JSON' => json_encode($av15_base_lspci),
]);
assert_equal($av15_base_result['code'] ?? 1, 0, 'av15 base dmap exits successfully with sSATA present');
$av15_base_aliases = $av15_base_result['aliases'] ?? [];
assert_equal($av15_base_aliases[8] ?? '', 'alias 1-9 /dev/disk/by-path/pci-0000:00:17.0-ata-2', 'av15 base uses SATA bus instead of sSATA bus');
assert_true(!preg_grep('/pci-0000:00:11\.5-ata/', $av15_base_aliases), 'av15 base never selects the sSATA bus for any alias');

// Scenario 8d: X11 systems with an HBA 9400-16i use the X11-specific phy swap.
$ctx_hl15_x11_hba = create_context('ported-dmap-hl15-x11-hba-9400');
$hl15_x11_hba_server = [
  'Model' => '45Homelab HL-15 1.0',
  'Alias Style' => 'HOMELAB',
  'Chassis Size' => 'HL15',
  'Motherboard' => [
    'Product Name' => 'X11SPH-nCTPF',
  ],
  'HBA' => [[
    'Model' => 'HBA 9400-16i',
    'Bus Address' => '0000:b4:00.0',
    'Drive Connections' => 16,
  ]],
];
$hl15_x11_hba_result = run_ported_dmap($root, $ctx_hl15_x11_hba, $hl15_x11_hba_server, []);
assert_equal($hl15_x11_hba_result['code'] ?? 1, 0, 'hl15 x11 hba 9400 dmap exits successfully');
$hl15_x11_hba_aliases = $hl15_x11_hba_result['aliases'] ?? [];
assert_equal($hl15_x11_hba_aliases[7] ?? '', 'alias 1-8 /dev/disk/by-path/pci-0000:b4:00.0-sas-phy6-lun-0', 'hl15 x11 hba 9400 maps slot 1-8 to phy6');
assert_equal($hl15_x11_hba_aliases[14] ?? '', 'alias 1-15 /dev/disk/by-path/pci-0000:b4:00.0-sas-phy4-lun-0', 'hl15 x11 hba 9400 keeps slot 1-15 on phy4');
assert_true(!in_array('alias 1-8 /dev/disk/by-path/pci-0000:b4:00.0-sas-phy14-lun-0', $hl15_x11_hba_aliases, true), 'hl15 x11 hba 9400 does not map emitted slots to phy14');

// Scenario 8e: site-specific HBA phy overrides can support non-standard motherboard/HBA builds.
$ctx_hba_override = create_context('ported-dmap-hba-phy-override');
$override_config_dir = $ctx_hba_override['tmp'] . '/plugin-config';
ensure_dir($override_config_dir);
file_put_contents($override_config_dir . '/hba_phy_order_overrides.json', json_encode([
  'X11CUSTOM' => [
    'HBA 9400-16i' => [15, 14, 13, 12, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
  ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
$hba_override_server = [
  'Model' => '45Homelab HL-15 1.0',
  'Alias Style' => 'HOMELAB',
  'Chassis Size' => 'HL15',
  'Motherboard' => [
    'Product Name' => 'X11CUSTOM',
  ],
  'HBA' => [[
    'Model' => 'HBA 9400-16i',
    'Bus Address' => '0000:b4:00.0',
    'Drive Connections' => 16,
  ]],
];
$hba_override_result = run_ported_dmap($root, $ctx_hba_override, $hba_override_server, [
  'DRIVEMAP_PLUGIN_CONFIG_DIR' => $override_config_dir,
]);
assert_equal($hba_override_result['code'] ?? 1, 0, 'hba phy override dmap exits successfully');
$hba_override_aliases = $hba_override_result['aliases'] ?? [];
assert_equal($hba_override_aliases[0] ?? '', 'alias 1-1 /dev/disk/by-path/pci-0000:b4:00.0-sas-phy15-lun-0', 'hba phy override maps slot 1-1 from config');
assert_equal($hba_override_aliases[14] ?? '', 'alias 1-15 /dev/disk/by-path/pci-0000:b4:00.0-sas-phy1-lun-0', 'hba phy override maps slot 1-15 from config');

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
assert_equal($hl15_server['Canvas Model'] ?? '', 'HomeLab-HL15', 'hl15 fallback canvas model');
assert_equal($hl15_server['Alias Style'] ?? '', 'HOMELAB', 'hl15 fallback alias style');
assert_equal($hl15_server['Chassis Size'] ?? '', 'HL15', 'hl15 fallback chassis');
assert_equal($hl15_server['HBA'][0]['Model'] ?? '', 'HBA 9400-16i', 'hl15 fallback hba model');
assert_equal($hl15_server['HBA'][0]['Bus Address'] ?? '', '0000:02:00.0', 'hl15 fallback hba bus');
$hl15_map = load_json_file($ctx_hl15['out_dir'] . '/drivemap.json');
assert_true(is_array($hl15_map), 'hl15 fallback drivemap parses as JSON');
assert_equal(count($hl15_map['rows'] ?? []), 1, 'hl15 fallback row count');
assert_equal(count($hl15_map['rows'][0] ?? []), 15, 'hl15 fallback bay count');

// Scenario 10: X11 HL15 v1 detection works even when no HBA is reported.
$ctx_hl15_x11_info = create_context('hl15-x11-server-info-no-hba');
set_common_env($ctx_hl15_x11_info, $fixtures);
[$hl15_x11_info_code] = run_php_script($server_script, [
  'DRIVEMAP_PRODUCT_NAME' => 'X11SPH-nCTPF',
  'DRIVEMAP_BOARD_NAME' => 'X11SPH-nCTPF',
  'DRIVEMAP_BOARD_VENDOR' => 'Supermicro',
  'DRIVEMAP_LSPCI_VERBOSE' => '',
  'DRIVEMAP_OUTPUT_DIR' => $ctx_hl15_x11_info['out_dir'],
]);
assert_equal($hl15_x11_info_code, 0, 'hl15 x11 no-hba server_info exits successfully');
$hl15_x11_info = load_json_file($ctx_hl15_x11_info['out_dir'] . '/server_info.json');
assert_true(is_array($hl15_x11_info), 'hl15 x11 no-hba server_info parses as JSON');
assert_equal($hl15_x11_info['Model'] ?? '', '45Homelab HL-15 1.0', 'hl15 x11 no-hba model');
assert_equal($hl15_x11_info['Alias Style'] ?? '', 'HOMELAB', 'hl15 x11 no-hba alias style');
assert_equal($hl15_x11_info['Chassis Size'] ?? '', 'HL15', 'hl15 x11 no-hba chassis');
assert_equal($hl15_x11_info['HBA'] ?? null, [], 'hl15 x11 no-hba has empty hba list');

// Scenario 10b: HL4 server_info detection uses the B550I AORUS PRO AX motherboard.
$ctx_hl4_info = create_context('hl4-server-info');
set_common_env($ctx_hl4_info, $fixtures);
[$hl4_info_code] = run_php_script($server_script, [
  'DRIVEMAP_PRODUCT_NAME' => 'B550I AORUS PRO AX',
  'DRIVEMAP_BOARD_NAME' => 'B550I AORUS PRO AX',
  'DRIVEMAP_ATA_PORTS_JSON' => json_encode([
    '0000:01:00.1' => ['ata1', 'ata2', 'ata3', 'ata4'],
  ]),
  'DRIVEMAP_LSPCI_VERBOSE' => '',
  'DRIVEMAP_OUTPUT_DIR' => $ctx_hl4_info['out_dir'],
]);
assert_equal($hl4_info_code, 0, 'hl4 server_info exits successfully');
$hl4_info = load_json_file($ctx_hl4_info['out_dir'] . '/server_info.json');
assert_true(is_array($hl4_info), 'hl4 server_info parses as JSON');
assert_equal($hl4_info['Model'] ?? '', '45Homelab HL-4', 'hl4 server_info model');
assert_equal($hl4_info['Canvas Model'] ?? '', 'HomeLab-HL4', 'hl4 server_info canvas model');
assert_equal($hl4_info['Alias Style'] ?? '', 'HOMELAB', 'hl4 server_info alias style');
assert_equal($hl4_info['Chassis Size'] ?? '', 'HL4', 'hl4 server_info chassis');
assert_equal($hl4_info['HBA'] ?? null, [], 'hl4 server_info has empty hba list');

// Scenario 10c: HL8 server_info detection uses two SATA controller groups on the B550I AORUS PRO AX motherboard.
$ctx_hl8_info = create_context('hl8-server-info');
set_common_env($ctx_hl8_info, $fixtures);
[$hl8_info_code] = run_php_script($server_script, [
  'DRIVEMAP_PRODUCT_NAME' => 'B550I AORUS PRO AX',
  'DRIVEMAP_BOARD_NAME' => 'B550I AORUS PRO AX',
  'DRIVEMAP_ATA_PORTS_JSON' => json_encode([
    '0000:01:00.1' => ['ata1', 'ata2', 'ata3', 'ata4'],
    '0000:02:00.1' => ['ata5', 'ata6', 'ata7', 'ata8'],
  ]),
  'DRIVEMAP_LSPCI_VERBOSE' => '',
  'DRIVEMAP_OUTPUT_DIR' => $ctx_hl8_info['out_dir'],
]);
assert_equal($hl8_info_code, 0, 'hl8 server_info exits successfully');
$hl8_info = load_json_file($ctx_hl8_info['out_dir'] . '/server_info.json');
assert_true(is_array($hl8_info), 'hl8 server_info parses as JSON');
assert_equal($hl8_info['Model'] ?? '', '45Homelab HL-8', 'hl8 server_info model');
assert_equal($hl8_info['Canvas Model'] ?? '', 'HomeLab-HL8', 'hl8 server_info canvas model');
assert_equal($hl8_info['Alias Style'] ?? '', 'HOMELAB', 'hl8 server_info alias style');
assert_equal($hl8_info['Chassis Size'] ?? '', 'HL8', 'hl8 server_info chassis');
assert_equal($hl8_info['HBA'] ?? null, [], 'hl8 server_info has empty hba list');

// Scenario 10d: X4 server_info detection uses the B860I WiFi motherboard while reusing the HL4 canvas.
$ctx_x4_info = create_context('x4-server-info');
set_common_env($ctx_x4_info, $fixtures);
[$x4_info_code] = run_php_script($server_script, [
  'DRIVEMAP_PRODUCT_NAME' => 'Default string',
  'DRIVEMAP_BOARD_NAME' => 'B860I WiFi',
  'DRIVEMAP_LSPCI_VERBOSE' => '',
  'DRIVEMAP_OUTPUT_DIR' => $ctx_x4_info['out_dir'],
]);
assert_equal($x4_info_code, 0, 'x4 server_info exits successfully');
$x4_info = load_json_file($ctx_x4_info['out_dir'] . '/server_info.json');
assert_true(is_array($x4_info), 'x4 server_info parses as JSON');
assert_equal($x4_info['Model'] ?? '', 'Unraid >< 45Homelab X-4', 'x4 server_info model');
assert_equal($x4_info['Canvas Model'] ?? '', 'HomeLab-HL4', 'x4 server_info canvas model');
assert_equal($x4_info['Alias Style'] ?? '', 'HOMELAB', 'x4 server_info alias style');
assert_equal($x4_info['Chassis Size'] ?? '', 'X4', 'x4 server_info chassis');
assert_equal($x4_info['HBA'] ?? null, [], 'x4 server_info has empty hba list');

// Scenario 11: copied server_info gains a stable canvas model without changing the display model.
$ctx_server_copy = create_context('server-info-copy-canvas-model');
set_common_env($ctx_server_copy, $fixtures);
$server_copy_input = $ctx_server_copy['tmp'] . '/source_server_info.json';
file_put_contents($server_copy_input, json_encode([
  'Model' => 'Unraid >< 45Homelab X-15',
  'Alias Style' => 'HOMELAB',
  'Chassis Size' => 'HL15',
  'HBA' => [],
], JSON_PRETTY_PRINT) . "\n");
[$server_copy_code] = run_php_script($server_script, [
  'DRIVEMAP_SERVER_INFO_INPUT' => $server_copy_input,
  'DRIVEMAP_OUTPUT_DIR' => $ctx_server_copy['out_dir'],
  'DRIVEMAP_VENDOR_SERVER_IDENTIFIER' => '/bin/false',
]);
assert_equal($server_copy_code, 0, 'server_info copy with canvas model exits successfully');
$server_copy = load_json_file($ctx_server_copy['out_dir'] . '/server_info.json');
assert_equal($server_copy['Model'] ?? '', 'Unraid >< 45Homelab X-15', 'server_info copy preserves display model');
assert_equal($server_copy['Canvas Model'] ?? '', 'HomeLab-HL15', 'server_info copy derives canvas model');

if ($failures > 0) {
  fwrite(STDERR, "\n$failures test(s) failed.\n");
  exit(1);
}

fwrite(STDOUT, "All tests passed.\n");
