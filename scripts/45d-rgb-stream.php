<?php

require_once dirname(__DIR__) . '/php/rgb_stream.php';

if (count($argv) !== 4 || !preg_match('#^/dev/hidraw[0-9]+$#D', $argv[1])) {
  fwrite(STDERR, "Usage: php 45d-rgb-stream.php /dev/hidrawN config.json pidfile\n");
  exit(2);
}
[$script, $device, $config_path, $pid_path] = $argv;
$lock = fopen(dirname($pid_path) . '/rgb-stream.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
  fwrite(STDERR, "Another fan stream is running\n");
  exit(2);
}
if (homelab_stream_controller() !== $device) {
  fwrite(STDERR, "X4 RGB controller mismatch\n");
  exit(2);
}
$config = json_decode((string) @file_get_contents($config_path), true);
if (!is_array($config)) {
  fwrite(STDERR, "Invalid fan settings\n");
  exit(2);
}
try {
  homelab_stream_packets(homelab_stream_leds($config));
} catch (Throwable $error) {
  fwrite(STDERR, "Invalid fan settings: {$error->getMessage()}\n");
  exit(2);
}
$fd = @fopen($device, 'r+b');
if (!$fd) {
  fwrite(STDERR, "Could not open X4 RGB controller\n");
  exit(2);
}
stream_set_blocking($fd, false);
$running = true;
pcntl_async_signals(true);
pcntl_signal(SIGTERM, function () use (&$running) { $running = false; });
pcntl_signal(SIGINT, function () use (&$running) { $running = false; });
$pid = getmypid();
file_put_contents($pid_path, "$pid\n");
register_shutdown_function(function () use ($pid_path, $pid) {
  if ((int) @file_get_contents($pid_path) === $pid) {
    @unlink($pid_path);
  }
});
$started = microtime(true);
while ($running) {
  $leds = homelab_stream_leds($config, microtime(true) - $started);
  foreach (homelab_stream_packets($leds) as $packet) {
    if (fwrite($fd, $packet) !== 65) {
      fwrite(STDERR, "X4 RGB write failed\n");
      exit(1);
    }
    usleep(1000);
  }
  while (($response = fread($fd, 64)) !== false && $response !== '') {}
  usleep(180000);
}
fclose($fd);
