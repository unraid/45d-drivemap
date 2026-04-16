<?php
// ZFS collector used by api.php?action=zfs_info.
// Supports fixture overrides so tests can run on hosts without ZFS binaries.
function zfs_fixture_dir()
{
  $dir = getenv('DRIVEMAP_ZFS_FIXTURE_DIR');
  return $dir ? rtrim($dir, '/') : '';
}

function zfs_force_enabled()
{
  return getenv('DRIVEMAP_ZFS_FORCE') === '1';
}

function read_fixture($name)
{
  $dir = zfs_fixture_dir();
  if ($dir === '') {
    return null;
  }
  $path = $dir . '/' . $name;
  if (is_file($path)) {
    return (string)@file_get_contents($path);
  }
  return null;
}

function zfs_load_json($path)
{
  if (!is_file($path)) {
    return null;
  }
  $raw = @file_get_contents($path);
  if ($raw === false) {
    return null;
  }
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : null;
}

function zfs_drivemap_lookup()
{
  $lookup = [];
  $map_path = getenv('DRIVEMAP_OUTPUT_FILE');
  if (!$map_path) {
    $base_dir = getenv('DRIVEMAP_OUTPUT_DIR') ?: '/var/local/45d';
    $map_path = rtrim($base_dir, '/') . '/drivemap.json';
  }

  $map = zfs_load_json($map_path);
  if (!is_array($map) || !isset($map['rows']) || !is_array($map['rows'])) {
    return $lookup;
  }

  foreach ($map['rows'] as $row) {
    if (!is_array($row)) {
      continue;
    }
    foreach ($row as $slot) {
      if (!is_array($slot)) {
        continue;
      }
      $bay_id = $slot['bay-id'] ?? '';
      if (!is_string($bay_id) || $bay_id === '') {
        continue;
      }

      foreach (['dev', 'dev-by-path'] as $field) {
        $value = $slot[$field] ?? '';
        if (!is_string($value) || $value === '') {
          continue;
        }
        $lookup[$value] = $bay_id;
        $lookup[basename($value)] = $bay_id;
      }
    }
  }

  return $lookup;
}

function zfs_strip_partition_suffix($name)
{
  if (!is_string($name) || $name === '') {
    return '';
  }

  if (preg_match('/^(.*)-part[0-9]+$/', $name, $match)) {
    return $match[1];
  }
  if (preg_match('/^(nvme[0-9]+n[0-9]+)p[0-9]+$/', $name, $match)) {
    return $match[1];
  }
  if (preg_match('/^(sd[a-z]+)[0-9]+$/', $name, $match)) {
    return $match[1];
  }

  return $name;
}

function canonical_zfs_disk_name($name, $lookup = [])
{
  if (!is_string($name) || $name === '') {
    return $name;
  }
  if (preg_match('/^\d+-\d+$/', $name)) {
    return $name;
  }

  $candidates = [];
  $candidates[] = $name;
  $candidates[] = basename($name);

  foreach ($candidates as $candidate) {
    $stripped = zfs_strip_partition_suffix($candidate);
    if ($stripped !== $candidate) {
      $candidates[] = $stripped;
    }
    if (strpos($candidate, '/dev/') === 0) {
      $without_dev = substr($candidate, 5);
      if ($without_dev !== '') {
        $candidates[] = $without_dev;
        $stripped = zfs_strip_partition_suffix($without_dev);
        if ($stripped !== $without_dev) {
          $candidates[] = $stripped;
        }
      }
    } else {
      $candidates[] = '/dev/' . $candidate;
      $stripped = zfs_strip_partition_suffix($candidate);
      if ($stripped !== $candidate) {
        $candidates[] = '/dev/' . $stripped;
      }
    }
  }

  foreach (array_values(array_unique($candidates)) as $candidate) {
    if (isset($lookup[$candidate])) {
      return $lookup[$candidate];
    }
  }

  return $name;
}

function command_output($command, $fixture_name = '')
{
  // Fixtures take precedence over command execution when provided.
  if ($fixture_name !== '') {
    $fixture = read_fixture($fixture_name);
    if ($fixture !== null) {
      return $fixture;
    }
  }
  $output = shell_exec($command);
  return $output === null ? '' : $output;
}

function zfs_installed()
{
  if (zfs_force_enabled()) {
    return true;
  }
  $path = trim((string)command_output('command -v zfs 2>/dev/null'));
  return $path !== '';
}

function get_zfs_list()
{
  $output = command_output('zfs list -H', 'zfs_list.txt');
  if ($output === '') {
    return [];
  }
  $zpools = [];
  $lines = preg_split('/\r?\n/', trim($output));
  foreach ($lines as $line) {
    if ($line === '') {
      continue;
    }
    $parts = explode("\t", $line);
    if (!isset($parts[0])) {
      continue;
    }
    $name = $parts[0];
    if ($name === '' || strpos($name, '/') !== false || strpos($name, '@') !== false) {
      continue;
    }
    $zpools[] = [
      'name' => $name,
      'used' => $parts[1] ?? '-',
      'avail' => $parts[2] ?? '-',
      'refer' => $parts[3] ?? '-',
      'mountpoint' => $parts[4] ?? '-',
    ];
  }
  return $zpools;
}

function get_zpool_list()
{
  // zpool list supplies pool-level raw fields; merge in selected zfs list
  // values so the frontend receives both views in one payload.
  $output = command_output('zpool list -H', 'zpool_list.txt');
  if ($output === '') {
    return [];
  }
  $zpools = [];
  $lines = preg_split('/\r?\n/', trim($output));
  foreach ($lines as $line) {
    if ($line === '') {
      continue;
    }
    $parts = explode("\t", $line);
    if (!isset($parts[0])) {
      continue;
    }
    $zpools[] = [
      'name' => $parts[0],
      'raw_size' => $parts[1] ?? '-',
      'raw_alloc' => $parts[2] ?? '-',
      'raw_free' => $parts[3] ?? '-',
      'ckpoint' => $parts[4] ?? '-',
      'expandsz' => $parts[5] ?? '-',
      'frag' => $parts[6] ?? '-',
      'cap' => $parts[7] ?? '-',
      'dedup' => $parts[8] ?? '-',
      'health' => $parts[9] ?? '-',
      'altroot' => $parts[10] ?? '-',
    ];
  }

  $zfs_list = get_zfs_list();
  foreach ($zpools as &$pool) {
    foreach ($zfs_list as $entry) {
      if ($entry['name'] === $pool['name']) {
        $pool['used'] = $entry['used'];
        $pool['avail'] = $entry['avail'];
        $pool['refer'] = $entry['refer'];
        $pool['mountpoint'] = $entry['mountpoint'];
      }
    }
    if (!isset($pool['used'])) {
      $pool['used'] = '-';
      $pool['avail'] = '-';
      $pool['refer'] = '-';
      $pool['mountpoint'] = '-';
    }
  }
  unset($pool);

  return $zpools;
}

function zpool_status_output($pool_name, $path_flag)
{
  $fixture = $path_flag ? 'zpool_status_path_' . $pool_name . '.txt' : 'zpool_status_' . $pool_name . '.txt';
  $command = $path_flag ? ('zpool status -P ' . escapeshellarg($pool_name)) : ('zpool status ' . escapeshellarg($pool_name));
  return command_output($command, $fixture);
}

function zpool_iostat_output($pool_name, $path_flag)
{
  $fixture = $path_flag ? 'zpool_iostat_path_' . $pool_name . '.txt' : 'zpool_iostat_' . $pool_name . '.txt';
  $command = $path_flag ? ('zpool iostat -vP ' . escapeshellarg($pool_name)) : ('zpool iostat -v ' . escapeshellarg($pool_name));
  return command_output($command, $fixture);
}

function collect_vdev_lines($output, $pattern)
{
  $matches = [];
  preg_match_all($pattern, $output, $matches);
  if (!isset($matches[0])) {
    return '';
  }
  return trim(implode("\n", $matches[0]));
}

function zpool_status($pool_name)
{
  $output = zpool_status_output($pool_name, false);
  if ($output === '') {
    return [$pool_name => '', 'state' => 'UNKNOWN'];
  }

  $pattern = '/^\t{1}(\S+).*$\n(?:^\t{1} +.*$\n)+|^\t{1}(\S+).*$\n(?:^\t{1} +.*$\n)+/m';
  $combined = collect_vdev_lines($output, $pattern);

  $state = 'UNKNOWN';
  if (preg_match('/^.*state:\s+(\S+)/m', $output, $match)) {
    $state = $match[1];
  }

  return [
    $pool_name => $combined,
    'state' => $state,
  ];
}

function zpool_iostat($pool_name)
{
  $output = zpool_iostat_output($pool_name, false);
  if ($output === '') {
    return [$pool_name => ''];
  }
  $pattern = '/^(\S+).*$\n(?:^ +.*$\n)+|^(\S+).*$\n(?:^ +.*$\n)+/m';
  $combined = collect_vdev_lines($output, $pattern);
  return [$pool_name => $combined];
}

function zpool_status_parse($status_obj, $key, $pool_name, $disk_lookup = [])
{
  // Parse default and -P (absolute path) variants to keep display names while
  // still validating alias-backed path conformity.
  if (!isset($status_obj[$key])) {
    return [[], [], []];
  }

  $status_pattern = '/^\t{1}(\S+).*$\n(?:^\t{1} +.*$\n)+|^\t{1}(\S+).*$\n(?:^\t{1} +.*$\n)+/m';
  $status_path_obj = [$key => collect_vdev_lines(zpool_status_output($pool_name, true), $status_pattern)];
  $status_default = $status_obj[$key];
  $status_path = $status_path_obj[$key] ?? '';

  $default_lines = preg_split('/\r?\n/', trim($status_default));
  $path_lines = preg_split('/\r?\n/', trim($status_path));

  $vdevs = [];
  $disks = [];
  $counts = [];
  $disk_count = 0;
  $initial_disk = true;

  $line_count = count($default_lines);
  for ($i = 0; $i < $line_count; $i++) {
    $default_line = $default_lines[$i];
    $path_line = $path_lines[$i] ?? '';

    $re_vdev_default = preg_match('/^\t\s{2}(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+).*/', $default_line, $vdev_default_match);
    $re_vdev_path = preg_match('/^\t\s{2}(\/dev\/\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+).*/', $path_line, $vdev_path_match);
    $re_disk_path = preg_match('/^\t\s{4}(\/dev\/\S+)(?:-part[0-9])?\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+).*/', $path_line, $disk_path_match);
    $re_disk_default = preg_match('/^\t\s{4}(\S+)(?:-part[0-9])?\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+).*/', $default_line, $disk_default_match);

    if ($re_vdev_default) {
      $vdevs[] = [
        'tag' => $key,
        'name' => $vdev_default_match[1],
        'state' => $vdev_default_match[2],
        'read_errors' => $vdev_default_match[3],
        'write_errors' => $vdev_default_match[4],
        'checksum_errors' => $vdev_default_match[5],
      ];

      if ($re_vdev_path) {
        $disks[] = [
          'tag' => $key,
          'name' => $vdev_default_match[1],
          'state' => $vdev_default_match[2],
          'read_errors' => $vdev_default_match[3],
          'write_errors' => $vdev_default_match[4],
          'checksum_errors' => $vdev_default_match[5],
        ];
        if (!$initial_disk) {
          $counts[] = $disk_count;
          $disk_count = 1;
        } else {
          $disk_count += 1;
          $initial_disk = false;
        }
      } elseif (!$initial_disk) {
        $counts[] = $disk_count;
        $disk_count = 0;
      }
    }

    if ($re_disk_path && $re_disk_default) {
      $initial_disk = false;
      $disks[] = [
        'tag' => $key,
        'name' => $disk_default_match[1],
        'state' => $disk_default_match[2],
        'read_errors' => $vdev_default_match[3] ?? $disk_default_match[3],
        'write_errors' => $vdev_default_match[4] ?? $disk_default_match[4],
        'checksum_errors' => $vdev_default_match[5] ?? $disk_default_match[5],
      ];
      $disk_count += 1;
    }
  }

  $exception_match = '/^(\d+-\d+)(?:-part[0-9])/';
  foreach ($disks as &$disk) {
    if (preg_match($exception_match, $disk['name'], $match)) {
      $disk['name'] = $match[1];
      continue;
    }
    $disk['name'] = canonical_zfs_disk_name($disk['name'], $disk_lookup);
  }
  unset($disk);

  $counts[] = $disk_count;
  return [$vdevs, $disks, $counts];
}

function verify_zfs_device_format($status_obj, $pool_name, $disk_lookup = [])
{
  // Frontend drive-to-bay mapping relies on "card-drive" aliases (e.g. 1-1).
  // Emit warnings when pool members do not follow that convention.
  $alert = [];
  if (!isset($status_obj[$pool_name])) {
    return $alert;
  }

  $default_pattern = '/^\t    (\d+-\d+)(?:-part[0-9])?\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+).*/m';
  $unsupported_pattern = '/^\t    (\S+)(?:-part[0-9])?\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+).*/m';

  preg_match_all($default_pattern, $status_obj[$pool_name], $default_matches, PREG_SET_ORDER);
  preg_match_all($unsupported_pattern, $status_obj[$pool_name], $unsupported_matches, PREG_SET_ORDER);

  $default_disks = [];
  foreach ($default_matches as $match) {
    $default_disks[] = $match[1];
  }

  $unsupported_disks = [];
  foreach ($unsupported_matches as $match) {
    $unsupported_disks[] = $match[1];
  }

  if (count($unsupported_disks) > count($default_disks)) {
    $filtered = array_values(array_diff($unsupported_disks, $default_disks));
    $filtered = array_values(array_filter($filtered, function ($name) {
      return !preg_match('/^(\d+-\d+)(?:-part[0-9])/', $name);
    }));
    $filtered = array_values(array_filter($filtered, function ($name) use ($disk_lookup) {
      return !preg_match('/^\d+-\d+$/', canonical_zfs_disk_name($name, $disk_lookup));
    }));

    if (!$filtered) {
      return $alert;
    }

    $alert[] = "ZFS status displayed by this module for zpool '$pool_name' may be incomplete.\n\n";
    $alert[] = "This module can only display zfs status information for devices that are created using a device alias.\n\n";
    $alert[] = "This can be done using the 45Drives cockpit-zfs-manager package:\nhttps://github.com/45Drives/cockpit-zfs-manager/releases/\n\n";
    if ($filtered) {
      $alert[] = "The following zfs devices do not conform:\n";
      foreach ($filtered as $disk) {
        $alert[] = "\t  $disk\n";
      }
    }
    $alert[] = "\n";
  }

  return $alert;
}

function zpool_iostat_parse($iostat_obj, $key, $pool_name, $disk_lookup = [])
{
  if (!isset($iostat_obj[$key])) {
    return [[], [], []];
  }

  $iostat_pattern = '/^(\S+).*$\n(?:^ +.*$\n)+|^(\S+).*$\n(?:^ +.*$\n)+/m';
  $iostat_path_obj = [$key => collect_vdev_lines(zpool_iostat_output($pool_name, true), $iostat_pattern)];
  $iostat_default = $iostat_obj[$key];
  $iostat_path = $iostat_path_obj[$key] ?? '';

  $default_lines = preg_split('/\r?\n/', trim($iostat_default));
  $path_lines = preg_split('/\r?\n/', trim($iostat_path));

  $vdevs = [];
  $disks = [];
  $counts = [];
  $disk_count = 0;
  $initial_disk = true;

  $line_count = count($default_lines);
  for ($i = 0; $i < $line_count; $i++) {
    $default_line = $default_lines[$i];
    $path_line = $path_lines[$i] ?? '';

    $re_vdev_default = preg_match('/^  (\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+).*/', $default_line, $vdev_default_match);
    $re_vdev_path = preg_match('/^  (\/dev\/\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+).*/', $path_line, $vdev_path_match);
    $re_disk_path = preg_match('/^    (\/dev\/\S+)(?:-part[0-9])?\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+).*/', $path_line, $disk_path_match);
    $re_disk_default = preg_match('/^    (\S+)(?:-part[0-9])?\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+).*/', $default_line, $disk_default_match);

    if ($re_vdev_default) {
      $vdevs[] = [
        'tag' => $key,
        'raid_level' => $vdev_default_match[1],
        'alloc' => $vdev_default_match[2],
        'free' => $vdev_default_match[3],
        'read_ops' => $vdev_default_match[4],
        'write_ops' => $vdev_default_match[5],
        'read_bw' => $vdev_default_match[6],
        'write_bw' => $vdev_default_match[7],
      ];
      if ($re_vdev_path) {
        $disks[] = [
          'tag' => $key,
          'name' => $vdev_default_match[1],
          'alloc' => $vdev_default_match[2],
          'free' => $vdev_default_match[3],
          'read_ops' => $vdev_default_match[4],
          'write_ops' => $vdev_default_match[5],
          'read_bw' => $vdev_default_match[6],
          'write_bw' => $vdev_default_match[7],
        ];
        $vdevs[count($vdevs) - 1]['raid_level'] = 'Disk';
        if (!$initial_disk) {
          $counts[] = $disk_count;
          $disk_count = 1;
        } else {
          $disk_count += 1;
          $initial_disk = false;
        }
      } elseif (!$initial_disk) {
        $counts[] = $disk_count;
        $disk_count = 0;
      }
    }

    if ($re_disk_path && $re_disk_default) {
      $initial_disk = false;
      $disks[] = [
        'tag' => $key,
        'name' => $disk_default_match[1],
        'alloc' => $disk_default_match[2],
        'free' => $disk_default_match[3],
        'read_ops' => $disk_default_match[4],
        'write_ops' => $disk_default_match[5],
        'read_bw' => $disk_default_match[6],
        'write_bw' => $disk_default_match[7],
      ];
      $disk_count += 1;
    }
  }

  $exception_match = '/^(\d+-\d+)(?:-part[0-9])/';
  foreach ($disks as &$disk) {
    if (preg_match($exception_match, $disk['name'], $match)) {
      $disk['name'] = $match[1];
      continue;
    }
    $disk['name'] = canonical_zfs_disk_name($disk['name'], $disk_lookup);
  }
  unset($disk);

  $counts[] = $disk_count;
  return [$vdevs, $disks, $counts];
}

function generate_zfs_info()
{
  // Keep response shape stable regardless of ZFS availability.
  $json_zfs = [
    'zfs_installed' => false,
  ];

  if (!zfs_installed()) {
    return $json_zfs;
  }

  $json_zfs['zfs_installed'] = true;
  $json_zfs['zpools'] = get_zpool_list();
  $json_zfs['warnings'] = [];
  $disk_lookup = zfs_drivemap_lookup();

  foreach ($json_zfs['zpools'] as &$pool) {
    $status_output = zpool_status($pool['name']);
    $iostat_output = zpool_iostat($pool['name']);
    $pool['state'] = $status_output['state'] ?? 'UNKNOWN';
    $pool['vdevs'] = [];

    $alerts = verify_zfs_device_format($status_output, $pool['name'], $disk_lookup);
    if ($alerts) {
      $json_zfs['warnings'] = array_merge($json_zfs['warnings'], $alerts);
    }

    foreach ($status_output as $key => $value) {
      if (!isset($iostat_output[$key])) {
        continue;
      }
      [$status_vdevs, $status_disks, $status_counts] = zpool_status_parse($status_output, $key, $pool['name'], $disk_lookup);
      [$iostat_vdevs, $iostat_disks, $iostat_counts] = zpool_iostat_parse($iostat_output, $key, $pool['name'], $disk_lookup);

      if (!$status_disks || !$iostat_disks || !$status_counts || !$iostat_counts) {
        continue;
      }

      $disk_index = 0;
      $vdev_count = count($status_vdevs);
      for ($i = 0; $i < $vdev_count; $i++) {
        $status_vdevs[$i]['raid_level'] = $iostat_vdevs[$i]['raid_level'] ?? $status_vdevs[$i]['raid_level'];
        $status_vdevs[$i]['alloc'] = $iostat_vdevs[$i]['alloc'] ?? '-';
        $status_vdevs[$i]['free'] = $iostat_vdevs[$i]['free'] ?? '-';
        $status_vdevs[$i]['read_ops'] = $iostat_vdevs[$i]['read_ops'] ?? '-';
        $status_vdevs[$i]['write_ops'] = $iostat_vdevs[$i]['write_ops'] ?? '-';
        $status_vdevs[$i]['read_bw'] = $iostat_vdevs[$i]['read_bw'] ?? '-';
        $status_vdevs[$i]['write_bw'] = $iostat_vdevs[$i]['write_bw'] ?? '-';
        $status_vdevs[$i]['disks'] = [];

        $limit = $status_counts[$i] ?? 0;
        for ($j = $disk_index; $j < $disk_index + $limit; $j++) {
          $status_disks[$j]['alloc'] = $iostat_disks[$j]['alloc'] ?? '-';
          $status_disks[$j]['free'] = $iostat_disks[$j]['free'] ?? '-';
          $status_disks[$j]['read_ops'] = $iostat_disks[$j]['read_ops'] ?? '-';
          $status_disks[$j]['write_ops'] = $iostat_disks[$j]['write_ops'] ?? '-';
          $status_disks[$j]['read_bw'] = $iostat_disks[$j]['read_bw'] ?? '-';
          $status_disks[$j]['write_bw'] = $iostat_disks[$j]['write_bw'] ?? '-';
          $status_disks[$j]['vdev_idx'] = count($pool['vdevs']);
          $status_vdevs[$i]['disks'][] = $status_disks[$j];
        }

        $pool['vdevs'][] = $status_vdevs[$i];
        $disk_index += $limit;
      }
    }
  }
  unset($pool);

  $disk_entries = [];
  foreach ($json_zfs['zpools'] as $pool_index => $pool) {
    if (!isset($pool['vdevs'])) {
      continue;
    }
    foreach ($pool['vdevs'] as $vdev) {
      foreach ($vdev['disks'] as $disk) {
        $disk_entries[$disk['name']] = [
          'zpool_name' => $pool['name'],
          'zpool_used' => $pool['used'] ?? '-',
          'zpool_avail' => $pool['avail'] ?? '-',
          'zpool_mountpoint' => $pool['mountpoint'] ?? '-',
          'zpool_state' => $pool['state'] ?? 'UNKNOWN',
          'zpool_idx' => $pool_index,
          'vdev_raid_level' => $vdev['raid_level'] ?? '-',
          'vdev_alloc' => $vdev['alloc'] ?? '-',
          'vdev_free' => $vdev['free'] ?? '-',
          'vdev_read_ops' => $vdev['read_ops'] ?? '-',
          'vdev_write_ops' => $vdev['write_ops'] ?? '-',
          'vdev_read_bw' => $vdev['read_bw'] ?? '-',
          'vdev_write_bw' => $vdev['write_bw'] ?? '-',
          'name' => $disk['name'],
          'alloc' => $disk['alloc'] ?? '-',
          'free' => $disk['free'] ?? '-',
          'read_ops' => $disk['read_ops'] ?? '-',
          'write_ops' => $disk['write_ops'] ?? '-',
          'read_bw' => $disk['read_bw'] ?? '-',
          'write_bw' => $disk['write_bw'] ?? '-',
          'vdev_idx' => $disk['vdev_idx'] ?? 0,
          'state' => $disk['state'] ?? 'UNKNOWN',
          'read_errors' => $disk['read_errors'] ?? '0',
          'write_errors' => $disk['write_errors'] ?? '0',
          'checksum_errors' => $disk['checksum_errors'] ?? '0',
          'tag' => $disk['tag'] ?? $pool['name'],
        ];
      }
    }
  }
  $json_zfs['zfs_disks'] = $disk_entries;

  return $json_zfs;
}
