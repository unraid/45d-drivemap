<?php
require_once __DIR__ . '/rgb_stream.php';

// The X4 fan lights are wired to the ASRock board's addressable RGB header.
// Keep this intentionally specific: OpenRGB can also control unrelated devices.
const HOMELAB_RGB_DEVICE = 'ASRock B860I WiFi';
const HOMELAB_RGB_ZONE = 'Addressable Header 1';
const HOMELAB_RGB_PRESETS = [
  'off' => ['label' => 'Off', 'color' => null],
  'white' => ['label' => 'White', 'color' => 'FFFFFF'],
  'warm-white' => ['label' => 'Warm White', 'color' => 'FFD8A8'],
  'red' => ['label' => 'Red', 'color' => 'FF0000'],
  'orange' => ['label' => 'Orange', 'color' => 'FF4500'],
  'yellow' => ['label' => 'Yellow', 'color' => 'FFFF00'],
  'green' => ['label' => 'Green', 'color' => '00FF00'],
  'teal' => ['label' => 'Teal', 'color' => '00BFA5'],
  'cyan' => ['label' => 'Cyan', 'color' => '00FFFF'],
  'blue' => ['label' => 'Blue', 'color' => '0000FF'],
  'purple' => ['label' => 'Purple', 'color' => '8000FF'],
  'pink' => ['label' => 'Pink', 'color' => 'FF69B4'],
];
const HOMELAB_RGB_EFFECTS = [
  'rainbow-flow' => ['label' => 'Rainbow Flow', 'mode' => 'Rainbow'],
  'color-wave' => ['label' => 'Color Wave', 'mode' => 'Wave'],
  'spectrum-cycle' => ['label' => 'Spectrum Cycle', 'mode' => 'Spectrum Cycle'],
];

function homelab_rgb_label($preset)
{
  return HOMELAB_RGB_PRESETS[$preset]['label'] ?? HOMELAB_RGB_EFFECTS[$preset]['label'] ?? null;
}

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
    'error' => $exit_code === 0 ? null : ($exit_code === 124 ? 'OpenRGB timed out.' : 'OpenRGB failed. Check its installation and device access.'),
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
    $device_found = preg_match("/^  Zones: '" . preg_quote(HOMELAB_RGB_ZONE, '/') . "'\\r?$/m", $device) &&
      preg_match('/^  Modes:.*\bOff\b.*\bStatic\b.*\bWave\b.*\bRainbow\b/m', $device);
    break;
  }
  if (!$device_found) {
    return ['ok' => false, 'output' => $result['output'], 'error' => 'X4 fan lighting controller was not found.'];
  }
  return ['ok' => true, 'output' => $result['output'], 'error' => null];
}

function homelab_rgb_set($preset, $binary = null)
{
  if (!is_string($preset) || homelab_rgb_label($preset) === null) {
    return ['ok' => false, 'error' => 'Choose a listed lighting option.'];
  }
  $stopped = homelab_stream_stop();
  if (!$stopped['ok']) {
    return $stopped;
  }
  $detected = homelab_rgb_detect($binary);
  if (!$detected['ok']) {
    return $detected;
  }
  // This board exposes one zone. OpenRGB accepts --zone 0 but does not apply
  // colors on the X4 when it is present, so target the verified device only.
  $args = ['--device', HOMELAB_RGB_DEVICE];
  if (isset(HOMELAB_RGB_EFFECTS[$preset])) {
    $args = array_merge($args, ['--mode', HOMELAB_RGB_EFFECTS[$preset]['mode']]);
  } else {
    $color = HOMELAB_RGB_PRESETS[$preset]['color'];
    $args = array_merge($args, ['--mode', $color === null ? 'Off' : 'Static']);
    if ($color !== null) {
      $args = array_merge($args, ['--color', $color]);
    }
  }
  return homelab_openrgb_run($args, $binary);
}

function homelab_rgb_set_separate($top, $bottom)
{
  if (!is_string($top) || !is_string($bottom) ||
      !isset(HOMELAB_RGB_PRESETS[$top], HOMELAB_RGB_PRESETS[$bottom])) {
    return ['ok' => false, 'error' => 'Choose listed colors for both fans.'];
  }
  $top_color = HOMELAB_RGB_PRESETS[$top]['color'] ?: '000000';
  $bottom_color = HOMELAB_RGB_PRESETS[$bottom]['color'] ?: '000000';
  return homelab_stream_start(['top' => $top_color, 'bottom' => $bottom_color]);
}

function homelab_rgb_set_stream_effect($effect, $input = [])
{
  if (!is_string($effect) || !isset(HOMELAB_STREAM_EFFECTS[$effect])) {
    return ['ok' => false, 'error' => 'Choose a listed lighting option.'];
  }
  try {
    $tuning = homelab_stream_tuning(array_merge($input, ['effect' => $effect]));
  } catch (InvalidArgumentException $error) {
    return ['ok' => false, 'error' => $error->getMessage()];
  }
  return homelab_stream_start(array_merge(['effect' => $effect], $tuning));
}

function homelab_rgb_set_pinwheel($input = [])
{
  return homelab_rgb_set_stream_effect('pinwheel-rainbow', $input);
}

function homelab_rgb_set_synchronized_rainbow($input = [])
{
  return homelab_rgb_set_stream_effect('synchronized-rainbow', $input);
}

function homelab_rgb_custom_palette_path()
{
  return getenv('HOMELAB_RGB_CUSTOM_PATH') ?: '/boot/config/plugins/45homelab/rgb-custom.json';
}

function homelab_rgb_custom_palette()
{
  $colors = json_decode((string) @file_get_contents(homelab_rgb_custom_palette_path()), true);
  try {
    return homelab_stream_validate_led_colors($colors);
  } catch (InvalidArgumentException $ignored) {
    return array_fill(0, 24, '000000');
  }
}

function homelab_rgb_set_custom($json, $input = [])
{
  if (!is_string($json) || strlen($json) > 512) {
    return ['ok' => false, 'error' => 'Choose valid LED colors.'];
  }
  try {
    $colors = homelab_stream_validate_led_colors(json_decode($json, true));
    $tuning = homelab_stream_tuning($input);
  } catch (InvalidArgumentException $error) {
    return ['ok' => false, 'error' => $error->getMessage()];
  }
  $result = homelab_stream_start(array_merge(['effect' => 'custom-leds', 'leds' => $colors], $tuning));
  if (!$result['ok']) {
    return $result;
  }
  $path = homelab_rgb_custom_palette_path();
  $dir = dirname($path);
  if ((!is_dir($dir) && !mkdir($dir, 0700, true)) ||
      file_put_contents($path . '.tmp', json_encode($colors)) === false ||
      !rename($path . '.tmp', $path)) {
    @unlink($path . '.tmp');
    return ['ok' => false, 'error' => 'Fan colors applied, but could not save the custom palette.'];
  }
  return $result;
}

function homelab_rgb_stream_selection()
{
  $config = json_decode((string) @file_get_contents(homelab_stream_paths()['config']), true);
  if (!is_array($config)) {
    return ['top' => 'white', 'bottom' => 'blue'];
  }
  $colors = [];
  foreach (['top', 'bottom'] as $fan) {
    $colors[$fan] = $fan === 'top' ? 'white' : 'blue';
    foreach (HOMELAB_RGB_PRESETS as $name => $preset) {
      if (($preset['color'] ?: '000000') === ($config[$fan] ?? null)) {
        $colors[$fan] = $name;
        break;
      }
    }
  }
  return $colors;
}
