<?php

// The X4 has two 12-LED fans in series on the ASRock ARGB header.
const HOMELAB_STREAM_LED_COUNT = 303;
const HOMELAB_STREAM_FAN_LEDS = 12;
// Clockwise around the pair: top left to top right, then bottom right to left.
const HOMELAB_PINWHEEL_ORDER = [2, 3, 4, 5, 6, 7, 8, 20, 21, 22, 23, 12, 13, 14];

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

function homelab_stream_leds($config, $elapsed = 0)
{
  if (($config['effect'] ?? null) === 'synchronized-rainbow') {
    $leds = [];
    $phase = (int) floor($elapsed * 256);
    for ($i = 0; $i < 24; $i++) {
      $leds[] = homelab_stream_color_wheel(($i % HOMELAB_STREAM_FAN_LEDS) * 128 - $phase);
    }
    return $leds;
  }
  if (in_array($config['effect'] ?? null, ['pinwheel-rainbow', 'center-rainbow'], true)) {
    $leds = array_fill(0, 24, '000000');
    $phase = (int) floor($elapsed * 256);
    foreach (HOMELAB_PINWHEEL_ORDER as $step => $index) {
      // Spread one complete rainbow around the combined outer perimeter.
      $hue = (int) round($step * 1536 / count(HOMELAB_PINWHEEL_ORDER));
      $leds[$index] = homelab_stream_color_wheel($hue - $phase);
    }
    return $leds;
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
