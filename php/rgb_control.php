<?php

// The X4 fan lights are wired to the ASRock board's addressable RGB header.
// Keep this intentionally specific: OpenRGB can also control unrelated devices.
const HOMELAB_RGB_DEVICE = 'ASRock B860I WiFi';
const HOMELAB_RGB_ZONE = 'Addressable Header 1';
const HOMELAB_RGB_PRESETS = [
  'off' => ['label' => 'Off', 'color' => null],
  'white' => ['label' => 'White', 'color' => 'FFFFFF'],
  'red' => ['label' => 'Red', 'color' => 'FF0000'],
  'orange' => ['label' => 'Orange', 'color' => 'FF7800'],
  'green' => ['label' => 'Green', 'color' => '00FF00'],
  'blue' => ['label' => 'Blue', 'color' => '0000FF'],
  'purple' => ['label' => 'Purple', 'color' => '8000FF'],
];

function homelab_openrgb_binary()
{
  return getenv('HOMELAB_OPENRGB_BIN') ?: '/usr/bin/openrgb';
}

function homelab_openrgb_run($args, $binary = null)
{
  $binary = $binary ?: homelab_openrgb_binary();
  if (!is_file($binary) || !is_executable($binary)) {
    return ['ok' => false, 'output' => '', 'error' => 'Install the OpenRGB Unraid plugin to control fan colors.'];
  }

  $command = is_executable('/usr/bin/timeout') ? ['/usr/bin/timeout', '10s'] : [];
  $command = array_merge($command, [$binary, '--noautoconnect'], $args);
  $pipes = [];
  $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($process)) {
    return ['ok' => false, 'output' => '', 'error' => 'Could not start OpenRGB.'];
  }
  $stdout = stream_get_contents($pipes[1]);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[1]);
  fclose($pipes[2]);
  $exit_code = proc_close($process);
  $output = trim($stdout . "\n" . $stderr);
  return [
    'ok' => $exit_code === 0,
    'output' => $output,
    'error' => $exit_code === 124 ? 'OpenRGB timed out.' : 'OpenRGB failed. Check its installation and device access.',
  ];
}

function homelab_rgb_detect($binary = null)
{
  $result = homelab_openrgb_run(['--list-detailed'], $binary);
  if (!$result['ok']) {
    return $result;
  }
  $device_found = false;
  foreach (preg_split('/(?=^\d+: )/m', $result['output']) as $device) {
    if (!preg_match('/^\d+: ASRock B860I WiFi\r?$/m', $device)) {
      continue;
    }
    $device_found = preg_match("/^  Zones:.*'" . preg_quote(HOMELAB_RGB_ZONE, '/') . "'/m", $device) &&
      preg_match('/^  Modes:.*\bOff\b.*\bStatic\b/m', $device);
    break;
  }
  if (!$device_found) {
    return ['ok' => false, 'output' => $result['output'], 'error' => 'X4 fan lighting controller was not found.'];
  }
  return ['ok' => true, 'output' => $result['output'], 'error' => null];
}

function homelab_rgb_set($preset, $binary = null)
{
  if (!is_string($preset) || !isset(HOMELAB_RGB_PRESETS[$preset])) {
    return ['ok' => false, 'error' => 'Choose a listed color.'];
  }
  $detected = homelab_rgb_detect($binary);
  if (!$detected['ok']) {
    return $detected;
  }
  $args = ['--device', HOMELAB_RGB_DEVICE, '--zone', '0'];
  $color = HOMELAB_RGB_PRESETS[$preset]['color'];
  if ($color === null) {
    $args = array_merge($args, ['--mode', 'Off']);
  } else {
    $args = array_merge($args, ['--mode', 'Static', '--color', $color]);
  }
  return homelab_openrgb_run($args, $binary);
}
