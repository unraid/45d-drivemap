<?php

// The X4 has two 12-LED fans in series on the ASRock ARGB header.
const HOMELAB_STREAM_LED_COUNT = 303;
const HOMELAB_STREAM_FAN_LEDS = 12;
// Clockwise around the pair: top left to top right, then bottom right to left.
const HOMELAB_PINWHEEL_ORDER = [2, 3, 4, 5, 6, 7, 8, 9, 20, 21, 22, 23, 12, 13, 14, 15];
const HOMELAB_TOP_SKIP_PRIORITY = [11, 0, 10, 1, 9, 2];
const HOMELAB_BOTTOM_SKIP_PRIORITY = [17, 18, 16, 19, 15, 20];
const HOMELAB_STREAM_EFFECTS = [
  'pinwheel-rainbow' => 'Outer Loop',
  'synchronized-rainbow' => 'Synchronized Wave',
  'brand-loop' => 'Two-Color Loop',
  'comet-loop' => 'Comet Loop',
  'orange-blue-pulse' => 'Two-Color Pulse',
];
const HOMELAB_STREAM_TUNING_DEFAULTS = [
  'period_seconds' => 6,
  'direction' => 'clockwise',
  'hue_degrees' => 0,
  'brightness_pct' => 100,
  'bottom_phase_steps' => 0,
  'rainbow_cycles' => 1,
  'fade_pct' => 35,
  'palette_mode' => 'rainbow',
  'color_primary' => 'FF4500',
  'color_secondary' => '0000FF',
  'tail_leds' => 6,
  'tail_variation' => 50,
  'skipped_leds' => 4,
  'virtual_gap_steps' => 1,
  'middle_enabled' => false,
  'middle_color' => 'FFFFFF',
];

function homelab_pinwheel_order($skipped_leds)
{
  $skipped = array_flip(array_merge(
    array_slice(HOMELAB_TOP_SKIP_PRIORITY, 0, $skipped_leds),
    array_slice(HOMELAB_BOTTOM_SKIP_PRIORITY, 0, $skipped_leds)
  ));
  $perimeter = array_merge(range(0, 11), range(18, 23), range(12, 17));
  return array_values(array_filter($perimeter, fn($index) => !isset($skipped[$index])));
}

function homelab_stream_color_defaults($effect)
{
  return $effect === 'comet-loop'
    ? ['color_primary' => 'FFFFFF', 'color_secondary' => '00AFFF']
    : ['color_primary' => 'FF4500', 'color_secondary' => '0000FF'];
}

function homelab_stream_tuning($input)
{
  if (!is_array($input)) {
    throw new InvalidArgumentException('Invalid lighting settings.');
  }
  $settings = array_merge(HOMELAB_STREAM_TUNING_DEFAULTS,
    homelab_stream_color_defaults($input['effect'] ?? null));
  foreach (['period_seconds' => [2, 20], 'hue_degrees' => [0, 359],
    'brightness_pct' => [10, 100], 'bottom_phase_steps' => [-6, 6],
    'rainbow_cycles' => [1, 3], 'fade_pct' => [0, 80],
    'tail_leds' => [3, 9], 'tail_variation' => [0, 100],
    'skipped_leds' => [0, 6], 'virtual_gap_steps' => [0, 3]] as $key => [$min, $max]) {
    if (!array_key_exists($key, $input)) {
      continue;
    }
    $value = $input[$key];
    if ((!is_int($value) && (!is_string($value) || !preg_match('/^-?[0-9]+$/D', $value))) ||
        (int) $value < $min || (int) $value > $max) {
      throw new InvalidArgumentException('Choose valid lighting settings.');
    }
    // Older saved settings allowed odd widths, which put one extra LED on one side.
    $settings[$key] = $key === 'skipped_leds' ? (int) $value - (int) $value % 2 : (int) $value;
  }
  if (array_key_exists('direction', $input)) {
    if (!in_array($input['direction'], ['clockwise', 'counterclockwise'], true)) {
      throw new InvalidArgumentException('Choose valid lighting settings.');
    }
    $settings['direction'] = $input['direction'];
  }
  if (array_key_exists('palette_mode', $input)) {
    if (!in_array($input['palette_mode'], ['rainbow', 'two-color'], true)) {
      throw new InvalidArgumentException('Choose a valid color palette.');
    }
    $settings['palette_mode'] = $input['palette_mode'];
  }
  if (array_key_exists('middle_enabled', $input)) {
    if (!in_array($input['middle_enabled'], [true, false, '1', '0', 1, 0], true)) {
      throw new InvalidArgumentException('Choose valid middle LED settings.');
    }
    $settings['middle_enabled'] = in_array($input['middle_enabled'], [true, '1', 1], true);
  }
  foreach (['color_primary', 'color_secondary', 'middle_color'] as $key) {
    if (!array_key_exists($key, $input)) {
      continue;
    }
    $color = $input[$key];
    if (!is_string($color) || !preg_match('/^#?[0-9A-Fa-f]{6}$/D', $color)) {
      throw new InvalidArgumentException('Choose valid pattern colors.');
    }
    $settings[$key] = strtoupper(ltrim($color, '#'));
  }
  return $settings;
}

function homelab_stream_runtime_dir()
{
  return getenv('HOMELAB_RGB_RUNTIME_DIR') ?: '/var/local/45d';
}

function homelab_stream_paths()
{
  $dir = homelab_stream_runtime_dir();
  return ['config' => "$dir/rgb-stream.json", 'pid' => "$dir/rgb-stream.pid", 'log' => "$dir/rgb-stream.log"];
}

function homelab_stream_controller()
{
  foreach (glob('/sys/class/hidraw/hidraw*/device/uevent') ?: [] as $uevent) {
    $details = @file_get_contents($uevent);
    if ($details !== false && preg_match('/^HID_ID=0003:000026CE:000001A2$/m', $details)) {
      $device = '/dev/' . basename(dirname(dirname($uevent)));
      if (is_readable($device) && is_writable($device)) {
        return $device;
      }
    }
  }
  return null;
}

function homelab_stream_packets($leds)
{
  if (count($leds) !== 24) {
    throw new InvalidArgumentException('Expected 24 fan LEDs.');
  }
  $bytes = '';
  foreach ($leds as $color) {
    if (!is_string($color) || !preg_match('/^[0-9A-Fa-f]{6}$/D', $color)) {
      throw new InvalidArgumentException('Invalid LED color.');
    }
    $bytes .= hex2bin($color);
  }
  $bytes = str_pad($bytes, HOMELAB_STREAM_LED_COUNT * 3, "\0");
  $packets = [str_pad("\x00\x10\x00\xff\xe3\x00\x00\x2f\x01" . substr($bytes, 0, 54), 65, "\0")];
  for ($i = 0; $i < 15; $i++) {
    $packets[] = str_pad("\x00\x10\x00\xff\xe4" . substr($bytes, 54 + $i * 57, 57), 65, "\0");
  }
  return $packets;
}

function homelab_stream_color_wheel($hue)
{
  $hue = (($hue % 1536) + 1536) % 1536;
  $segment = intdiv($hue, 256);
  $step = $hue % 256;
  $channels = [[255, $step, 0], [255 - $step, 255, 0], [0, 255, $step],
    [0, 255 - $step, 255], [$step, 0, 255], [255, 0, 255 - $step]][$segment];
  return sprintf('%02X%02X%02X', ...$channels);
}

function homelab_stream_brightness($color, $percent)
{
  if ($percent === 100) {
    return $color;
  }
  $channels = str_split($color, 2);
  return sprintf('%02X%02X%02X', ...array_map(
    fn($channel) => (int) round(hexdec($channel) * $percent / 100), $channels
  ));
}

function homelab_stream_mix($first, $second, $ratio)
{
  $channels = [];
  for ($channel = 0; $channel < 3; $channel++) {
    $offset = $channel * 2;
    $start = hexdec(substr($first, $offset, 2));
    $end = hexdec(substr($second, $offset, 2));
    $channels[] = (int) round($start * (1 - $ratio) + $end * $ratio);
  }
  return sprintf('%02X%02X%02X', ...$channels);
}

function homelab_stream_level($color, $level)
{
  return homelab_stream_mix('000000', $color, max(0, min(1, $level)));
}

function homelab_stream_middle_leds($leds, $order, $tuning)
{
  if (!$tuning['middle_enabled']) {
    return $leds;
  }
  $middle_color = homelab_stream_brightness($tuning['middle_color'], $tuning['brightness_pct']);
  foreach (array_diff(range(0, 23), $order) as $index) {
    $leds[$index] = $middle_color;
  }
  return $leds;
}

function homelab_stream_palette_color($hue, $tuning)
{
  if ($tuning['palette_mode'] === 'two-color') {
    $ratio = 0.5 + 0.5 * cos(2 * M_PI * $hue / 1536);
    return homelab_stream_mix($tuning['color_secondary'], $tuning['color_primary'], $ratio);
  }
  return homelab_stream_color_wheel($hue);
}

function homelab_stream_validate_led_colors($colors)
{
  if (!is_array($colors) || array_keys($colors) !== range(0, 23)) {
    throw new InvalidArgumentException('Choose a color for each of the 24 LEDs.');
  }
  foreach ($colors as $color) {
    if (!is_string($color) || !preg_match('/^[0-9A-Fa-f]{6}$/D', $color)) {
      throw new InvalidArgumentException('Choose valid LED colors.');
    }
  }
  return array_map('strtoupper', $colors);
}

function homelab_stream_blend_frame($previous, $target, $fade_pct)
{
  if ($fade_pct === 0) {
    return $target;
  }
  $blended = [];
  foreach ($target as $index => $color) {
    $old = $previous[$index];
    $channels = [];
    for ($channel = 0; $channel < 3; $channel++) {
      $offset = $channel * 2;
      $old_value = hexdec(substr($old, $offset, 2));
      $new_value = hexdec(substr($color, $offset, 2));
      $blended_value = (int) round(($old_value * $fade_pct + $new_value * (100 - $fade_pct)) / 100);
      if ($blended_value === $old_value && $old_value !== $new_value) {
        $blended_value += $new_value > $old_value ? 1 : -1;
      }
      $channels[] = $blended_value;
    }
    $blended[] = sprintf('%02X%02X%02X', ...$channels);
  }
  return $blended;
}

function homelab_stream_leds($config, $elapsed = 0)
{
  $tuning = homelab_stream_tuning($config);
  if (($config['effect'] ?? null) === 'custom-leds') {
    return array_map(
      fn($color) => homelab_stream_brightness($color, $tuning['brightness_pct']),
      homelab_stream_validate_led_colors($config['leds'] ?? null)
    );
  }
  $cycles = $tuning['rainbow_cycles'];
  $phase = (int) floor($elapsed * 1536 * $cycles / $tuning['period_seconds']);
  if ($tuning['direction'] === 'counterclockwise') {
    $phase = -$phase;
  }
  $hue_offset = (int) round($tuning['hue_degrees'] * 1536 / 360);
  $bottom_offset = $tuning['bottom_phase_steps'] * 128 * $cycles;
  $effect = $config['effect'] ?? null;
  if ($effect === 'orange-blue-pulse') {
    $leds = [];
    $direction = $tuning['direction'] === 'counterclockwise' ? -1 : 1;
    for ($i = 0; $i < 24; $i++) {
      $bottom = $i >= HOMELAB_STREAM_FAN_LEDS;
      $offset = $bottom ? 0.5 + $tuning['bottom_phase_steps'] / 12 : 0;
      $angle = 2 * M_PI * ($direction * $elapsed * $cycles / $tuning['period_seconds'] + $offset);
      $level = 0.25 + 0.75 * (0.5 + 0.5 * cos($angle));
      $color = $bottom ? $tuning['color_secondary'] : $tuning['color_primary'];
      $leds[] = homelab_stream_brightness(homelab_stream_level($color, $level), $tuning['brightness_pct']);
    }
    return $leds;
  }
  if (in_array($effect, ['brand-loop', 'comet-loop'], true)) {
    $leds = array_fill(0, 24, '000000');
    $order = homelab_pinwheel_order($tuning['skipped_leds']);
    $top_count = intdiv(count($order), 2);
    $count = count($order) + 2 * $tuning['virtual_gap_steps'];
    $direction = $tuning['direction'] === 'counterclockwise' ? -1 : 1;
    foreach ($order as $step => $index) {
      $alignment = $index >= HOMELAB_STREAM_FAN_LEDS ? $tuning['bottom_phase_steps'] : 0;
      $slot = $step + ($step >= $top_count ? $tuning['virtual_gap_steps'] : 0);
      $position = ($slot + $alignment) * $cycles -
        $direction * $elapsed * $count * $cycles / $tuning['period_seconds'];
      if ($effect === 'brand-loop') {
        $ratio = 0.5 + 0.5 * cos(2 * M_PI * $position / $count);
        $color = homelab_stream_mix($tuning['color_secondary'], $tuning['color_primary'], $ratio);
      } else {
        $distance = fmod(fmod($position, $count) + $count, $count);
        $level = max(0, 1 - $distance / $tuning['tail_leds']);
        if ($distance >= 1 && $level > 0) {
          $tick = (int) floor($elapsed * 5);
          $variation = (($index * 73 + $tick * 151 + 37) % 101) / 100;
          $level *= 1 - $tuning['tail_variation'] / 100 * (0.65 * (1 - $variation));
        }
        $head = max(0, 1 - $distance);
        $color = homelab_stream_level(homelab_stream_mix(
          $tuning['color_secondary'], $tuning['color_primary'], $head), $level);
      }
      $leds[$index] = homelab_stream_brightness($color, $tuning['brightness_pct']);
    }
    return homelab_stream_middle_leds($leds, $order, $tuning);
  }
  if (($config['effect'] ?? null) === 'synchronized-rainbow') {
    $leds = [];
    for ($i = 0; $i < 24; $i++) {
      $hue = ($i % HOMELAB_STREAM_FAN_LEDS) * 128 * $cycles + $hue_offset - $phase;
      if ($i >= HOMELAB_STREAM_FAN_LEDS) {
        $hue += $bottom_offset;
      }
      $leds[] = homelab_stream_brightness(homelab_stream_palette_color($hue, $tuning), $tuning['brightness_pct']);
    }
    return $leds;
  }
  if (in_array($config['effect'] ?? null, ['pinwheel-rainbow', 'center-rainbow'], true)) {
    $leds = array_fill(0, 24, '000000');
    $order = homelab_pinwheel_order($tuning['skipped_leds']);
    $top_count = intdiv(count($order), 2);
    $count = count($order) + 2 * $tuning['virtual_gap_steps'];
    foreach ($order as $step => $index) {
      // Spread one complete rainbow around the combined outer perimeter.
      $slot = $step + ($step >= $top_count ? $tuning['virtual_gap_steps'] : 0);
      $hue = (int) round($slot * 1536 * $cycles / $count);
      $hue += $hue_offset - $phase;
      if ($index >= HOMELAB_STREAM_FAN_LEDS) {
        $hue += $bottom_offset;
      }
      $leds[$index] = homelab_stream_brightness(homelab_stream_palette_color($hue, $tuning), $tuning['brightness_pct']);
    }
    return homelab_stream_middle_leds($leds, $order, $tuning);
  }
  if (!isset($config['top'], $config['bottom'])) {
    throw new InvalidArgumentException('Missing fan colors.');
  }
  return array_merge(array_fill(0, 12, $config['top']), array_fill(0, 12, $config['bottom']));
}

function homelab_stream_pid()
{
  $path = homelab_stream_paths()['pid'];
  $pid = (int) @file_get_contents($path);
  if ($pid < 2 || !is_dir("/proc/$pid")) {
    return null;
  }
  $cmdline = @file_get_contents("/proc/$pid/cmdline");
  return $cmdline !== false && strpos($cmdline, '45d-rgb-stream.php') !== false ? $pid : null;
}

function homelab_stream_stop()
{
  $pid = homelab_stream_pid();
  if ($pid !== null) {
    posix_kill($pid, SIGTERM);
    for ($i = 0; $i < 20 && homelab_stream_pid() !== null; $i++) {
      usleep(50000);
    }
    if (homelab_stream_pid() !== null) {
      return ['ok' => false, 'error' => 'Could not stop the separate fan stream.'];
    }
  }
  @unlink(homelab_stream_paths()['pid']);
  return ['ok' => true, 'error' => null];
}

function homelab_stream_start($config)
{
  $device = homelab_stream_controller();
  if ($device === null) {
    return ['ok' => false, 'error' => 'X4 RGB HID controller was not found.'];
  }
  $stopped = homelab_stream_stop();
  if (!$stopped['ok']) {
    return $stopped;
  }
  $paths = homelab_stream_paths();
  if (!is_dir(homelab_stream_runtime_dir()) && !mkdir(homelab_stream_runtime_dir(), 0700, true)) {
    return ['ok' => false, 'error' => 'Could not create RGB runtime directory.'];
  }
  $tmp = $paths['config'] . '.' . getmypid() . '.tmp';
  if (file_put_contents($tmp, json_encode($config)) === false || !rename($tmp, $paths['config'])) {
    @unlink($tmp);
    return ['ok' => false, 'error' => 'Could not save fan settings.'];
  }
  $worker = dirname(__DIR__) . '/scripts/45d-rgb-stream.php';
  $command = 'nohup /usr/bin/php ' . escapeshellarg($worker) . ' ' . escapeshellarg($device) .
    ' ' . escapeshellarg($paths['config']) . ' ' . escapeshellarg($paths['pid']) .
    ' > ' . escapeshellarg($paths['log']) . ' 2>&1 < /dev/null &';
  $pipes = [];
  $process = proc_open(['/bin/sh', '-c', $command], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($process)) {
    return ['ok' => false, 'error' => 'Could not start fan stream.'];
  }
  fclose($pipes[1]);
  fclose($pipes[2]);
  proc_close($process);
  for ($i = 0; $i < 30; $i++) {
    if (homelab_stream_pid() !== null) {
      return ['ok' => true, 'error' => null];
    }
    usleep(50000);
  }
  return ['ok' => false, 'error' => 'Fan stream did not start. ' . trim((string) @file_get_contents($paths['log']))];
}
