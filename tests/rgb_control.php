<?php
require_once dirname(__DIR__) . '/php/rgb_control.php';

$dir = sys_get_temp_dir() . '/45homelab-rgb-' . getmypid();
mkdir($dir, 0700);
$binary = $dir . '/openrgb';
$log = $dir . '/args';
$fixture = $dir . '/devices';
file_put_contents($fixture, "0: ASRock B860I WiFi\n  Modes: [Off] Static Wave Rainbow Direct\n  Zones: 'Addressable Header 1'\n");
file_put_contents($binary, "#!/bin/sh\nprintf '%s\\n' \"\$@\" >> " . escapeshellarg($log) . "\nif [ \"\$2\" = '--list-detailed' ]; then cat " . escapeshellarg($fixture) . "; fi\n");
chmod($binary, 0700);

function check($value, $message)
{
  if (!$value) {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
  }
}

check(homelab_rgb_detect($binary)['ok'], 'detects X4 addressable header');
$leds = homelab_stream_leds(['top' => 'FFFFFF', 'bottom' => '0000FF']);
check(count($leds) === 24 && count(array_unique(array_slice($leds, 0, 12))) === 1 &&
  $leds[0] === 'FFFFFF' && $leds[11] === 'FFFFFF' && $leds[12] === '0000FF' && $leds[23] === '0000FF',
  'first 12 LEDs are top fan and next 12 are bottom fan');
$packets = homelab_stream_packets($leds);
check(count($packets) === 16 && array_reduce($packets, fn($ok, $packet) => $ok && strlen($packet) === 65, true),
  'sends sixteen 65-byte HID reports');
check(substr($packets[0], 0, 9) === "\x00\x10\x00\xff\xe3\x00\x00\x2f\x01" &&
  substr($packets[0], 9, 36) === str_repeat("\xff\xff\xff", 12) &&
  substr($packets[0], 45, 18) === str_repeat("\x00\x00\xff", 6) &&
  substr($packets[1], 5, 18) === str_repeat("\x00\x00\xff", 6),
  'encodes both fans in SignalRGB-style stream packets');
$rainbow = homelab_stream_leds(['effect' => 'pinwheel-rainbow'], 0);
check($rainbow[0] === '000000' && $rainbow[11] === '000000' &&
  $rainbow[12] !== '000000' && $rainbow[23] !== '000000',
  'pinwheel blanks inward hub LEDs and lights outward arcs');
check(count(array_filter($rainbow, fn($color) => $color !== '000000')) === 14,
  'pinwheel lights seven outward LEDs per fan');
check($rainbow[8] !== $rainbow[20] && $rainbow[14] !== $rainbow[2] &&
  count(array_unique(array_map(fn($index) => $rainbow[$index], HOMELAB_PINWHEEL_ORDER))) === 14,
  'pinwheel spreads all rainbow hues across one perimeter');
check($rainbow === homelab_stream_leds(['effect' => 'pinwheel-rainbow'], 6),
  'pinwheel repeats after one full rotation');
$aligned = homelab_stream_leds(['effect' => 'synchronized-rainbow'], 0);
check(count($aligned) === 24 && array_slice($aligned, 0, 12) === array_slice($aligned, 12, 12),
  'synchronized rainbow uses same phase at matching positions on both fans');
check($aligned === homelab_stream_leds(['effect' => 'synchronized-rainbow'], 6),
  'synchronized rainbow repeats after one full rotation');
check(!homelab_rgb_set_separate('bad', 'blue')['ok'], 'rejects unknown separate preset');
file_put_contents($fixture, "0: ASRock B860I WiFi\n  Modes: [Off] Static Wave Rainbow Direct\n  Zones: 'Addressable Header 1' 'Other Header'\n");
check(!homelab_rgb_detect($binary)['ok'], 'rejects controller with other zones');
file_put_contents($fixture, "0: ASRock B860I WiFi\n  Modes: [Off] Static Wave Rainbow Direct\n  Zones: 'Addressable Header 1'\n");
$before = file_get_contents($log);
check(!homelab_rgb_set('red;touch /tmp/bad', $binary)['ok'], 'rejects unknown preset');
check(!homelab_rgb_set(['red'], $binary)['ok'], 'rejects array input');
check(file_get_contents($log) === $before, 'rejects unknown preset before command execution');
check(homelab_rgb_set('blue', $binary)['ok'], 'applies listed preset');
check(homelab_rgb_set('blue', $binary)['error'] === null, 'successful command has no error');
check(strpos(file_get_contents($log), "--device\nASRock B860I WiFi\n--mode\nStatic\n--color\n0000FF\n") !== false, 'targets X4 controller with static blue');
check(homelab_rgb_set('warm-white', $binary)['ok'], 'applies warm white preset');
check(strpos(file_get_contents($log), "--mode\nStatic\n--color\nFFD8A8\n") !== false, 'sends warm white RGB value');
check(homelab_rgb_set('off', $binary)['ok'], 'turns lighting off');
check(strpos(file_get_contents($log), "--mode\nOff\n") !== false, 'uses Off mode');
check(homelab_rgb_set('rainbow-flow', $binary)['ok'], 'applies animated effect');
check(strpos(file_get_contents($log), "--mode\nRainbow\n") !== false, 'uses Rainbow mode');
// Unraid's request handler validates and removes csrf_token before the page runs.
putenv('HOMELAB_OPENRGB_BIN=' . $binary);
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['color' => 'orange'];
$var = ['csrf_token' => 'already-validated'];
ob_start();
include dirname(__DIR__) . '/HomeLab.page';
$page = ob_get_clean();
check(strpos($page, 'Orange applied to fan lights.') !== false, 'page accepts validated POST without token field');
check(strpos($page, 'Rainbow Flow') !== false, 'page lists animated effects');
check(strpos($page, 'Apply separate colors') !== false && strpos($page, 'Synchronized Rainbow') !== false &&
  strpos($page, 'Apply previewed pattern') !== false,
  'page offers separate colors and animated preview');
unset($_SERVER['REQUEST_METHOD'], $_POST, $var);
putenv('HOMELAB_OPENRGB_BIN');
file_put_contents($fixture, "0: Other controller\n  Modes: [Off] Static\n  Zones: 'Addressable Header 1'\n");
check(!homelab_rgb_detect($binary)['ok'], 'rejects unrelated controller');
check(!homelab_rgb_detect($dir . '/missing')['ok'], 'reports missing runtime');

unlink($binary);
unlink($log);
unlink($fixture);
rmdir($dir);
echo "RGB control tests passed\n";
