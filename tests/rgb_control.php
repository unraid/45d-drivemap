<?php
require_once dirname(__DIR__) . '/php/rgb_control.php';

$dir = sys_get_temp_dir() . '/45homelab-rgb-' . getmypid();
mkdir($dir, 0700);
$binary = $dir . '/openrgb';
$log = $dir . '/args';
$fixture = $dir . '/devices';
file_put_contents($fixture, "0: ASRock B860I WiFi\n  Modes: [Off] Static Breathing\n  Zones: 'Addressable Header 1'\n");
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
$before = file_get_contents($log);
check(!homelab_rgb_set('red;touch /tmp/bad', $binary)['ok'], 'rejects unknown preset');
check(!homelab_rgb_set(['red'], $binary)['ok'], 'rejects array input');
check(file_get_contents($log) === $before, 'rejects unknown preset before command execution');
check(homelab_rgb_set('blue', $binary)['ok'], 'applies listed preset');
check(strpos(file_get_contents($log), "--device\nASRock B860I WiFi\n--zone\n0\n--mode\nStatic\n--color\n0000FF\n") !== false, 'targets X4 zone with solid blue');
check(homelab_rgb_set('off', $binary)['ok'], 'turns lighting off');
check(strpos(file_get_contents($log), "--mode\nOff\n") !== false, 'uses Off mode');
file_put_contents($fixture, "0: Other controller\n  Modes: [Off] Static\n  Zones: 'Addressable Header 1'\n");
check(!homelab_rgb_detect($binary)['ok'], 'rejects unrelated controller');
check(!homelab_rgb_detect($dir . '/missing')['ok'], 'reports missing runtime');

unlink($binary);
unlink($log);
unlink($fixture);
rmdir($dir);
echo "RGB control tests passed\n";
