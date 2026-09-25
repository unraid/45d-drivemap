<?php
require_once dirname(__DIR__) . '/php/rgb_control.php';

$dir = sys_get_temp_dir() . '/45homelab-rgb-' . getmypid();
mkdir($dir, 0700);
putenv('HOMELAB_RGB_CONFIG_DIR=' . $dir);
putenv('HOMELAB_RGB_RUNTIME_DIR=' . $dir);
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
$rainbow = homelab_stream_leds(['effect' => 'pinwheel-rainbow', 'palette_mode' => 'rainbow'], 0);
check(homelab_pinwheel_order(4) === HOMELAB_PINWHEEL_ORDER,
  'default middle width preserves balanced X4 perimeter');
check(homelab_stream_tuning(['skipped_leds' => 5])['skipped_leds'] === 4 &&
  homelab_stream_tuning(['skipped_leds' => 3])['skipped_leds'] === 2,
  'old odd middle widths migrate down to balanced widths');
check(array_values(array_diff(range(0, 11), homelab_pinwheel_order(4))) === [0, 1, 10, 11] &&
  array_values(array_diff(range(12, 23), homelab_pinwheel_order(4))) === [16, 17, 18, 19],
  'four skipped LEDs form balanced center pairs on both mounted fans');
check($rainbow[0] === '000000' && $rainbow[11] === '000000' &&
  $rainbow[12] !== '000000' && $rainbow[23] !== '000000',
  'pinwheel blanks inward hub LEDs and lights outward arcs');
check(count(array_filter($rainbow, fn($color) => $color !== '000000')) === 16 &&
  $rainbow[2] !== '000000' && $rainbow[9] !== '000000' &&
  $rainbow[20] !== '000000' && $rainbow[15] !== '000000' &&
  $rainbow[0] === '000000' && $rainbow[10] === '000000' && $rainbow[11] === '000000' &&
  $rainbow[16] === '000000' && $rainbow[17] === '000000' && $rainbow[18] === '000000' && $rainbow[19] === '000000',
  'pinwheel lights eight outer LEDs per fan with balanced middle arcs');
check($rainbow[8] !== $rainbow[20] && $rainbow[14] !== $rainbow[2] &&
  count(array_unique(array_map(fn($index) => $rainbow[$index], HOMELAB_PINWHEEL_ORDER))) === 16,
  'pinwheel spreads all rainbow hues across one perimeter');
check($rainbow === homelab_stream_leds(['effect' => 'pinwheel-rainbow', 'palette_mode' => 'rainbow'], 6),
  'pinwheel repeats after one full rotation');
$without_gap = homelab_stream_leds(['effect' => 'pinwheel-rainbow', 'palette_mode' => 'rainbow', 'virtual_gap_steps' => 0], 0);
check($rainbow[HOMELAB_PINWHEEL_ORDER[7]] !== $without_gap[HOMELAB_PINWHEEL_ORDER[7]] &&
  $rainbow[HOMELAB_PINWHEEL_ORDER[8]] === $without_gap[HOMELAB_PINWHEEL_ORDER[8]],
  'virtual gap changes spacing at fan crossings while keeping the opposite fan centered');
foreach (['pinwheel-rainbow', 'brand-loop', 'comet-loop'] as $effect) {
  foreach ([0, 2, 4, 6] as $skipped) {
    $order = homelab_pinwheel_order($skipped);
    $frame = homelab_stream_leds(['effect' => $effect, 'skipped_leds' => $skipped], 0);
    check(count($order) === 24 - 2 * $skipped && count(array_unique($order)) === count($order),
      "$effect has valid perimeter for $skipped skipped LEDs");
    $dark = array_diff(range(0, 23), $order);
    check(count($dark) === 2 * $skipped &&
      count(array_filter($dark, fn($index) => $frame[$index] === '000000')) === count($dark),
      "$effect blanks skipped LEDs on both fans");
    if ($effect !== 'comet-loop') {
      check(count(array_filter($frame, fn($color) => $color !== '000000')) === count($order),
        "$effect lights remaining perimeter LEDs");
    }
    $middle = homelab_stream_leds(['effect' => $effect, 'skipped_leds' => $skipped,
      'middle_enabled' => '1', 'middle_color' => '#00FF00', 'brightness_pct' => 50], 0);
    check(count(array_filter($dark, fn($index) => $middle[$index] === '008000')) === count($dark),
      "$effect lights only skipped LEDs with separate scaled middle color");
  }
}
$aligned = homelab_stream_leds(['effect' => 'synchronized-rainbow'], 0);
check(count($aligned) === 24 && array_slice($aligned, 0, 12) === array_slice($aligned, 12, 12),
  'synchronized rainbow uses same phase at matching positions on both fans');
check($aligned === homelab_stream_leds(['effect' => 'synchronized-rainbow'], 6),
  'synchronized rainbow repeats after one full rotation');
$brand = homelab_stream_leds(['effect' => 'brand-loop'], 0);
check($brand[HOMELAB_PINWHEEL_ORDER[0]] === 'FF4500' &&
  $brand[HOMELAB_PINWHEEL_ORDER[8]] === '0000FF' &&
  count(array_filter($brand, fn($color) => $color !== '000000')) === 16,
  'two-color loop places default orange and blue on the outer arc');
check($brand === homelab_stream_leds(['effect' => 'brand-loop'], 6) &&
  $brand !== homelab_stream_leds(['effect' => 'brand-loop'], 1),
  'branded loop moves and repeats');
$custom_brand = homelab_stream_leds(['effect' => 'brand-loop',
  'color_primary' => '#00FF00', 'color_secondary' => '#800080'], 0);
check($custom_brand[HOMELAB_PINWHEEL_ORDER[0]] === '00FF00' &&
  $custom_brand[HOMELAB_PINWHEEL_ORDER[8]] === '800080',
  'branded loop uses chosen colors');
$comet = homelab_stream_leds(['effect' => 'comet-loop'], 0);
check($comet[HOMELAB_PINWHEEL_ORDER[0]] === HOMELAB_BRAND_ORANGE &&
  $comet[HOMELAB_PINWHEEL_ORDER[1]] !== '000000' &&
  $comet[HOMELAB_PINWHEEL_ORDER[6]] === '000000',
  'comet has orange head and short fading blue tail');
check(!in_array(HOMELAB_BRAND_ORANGE, homelab_stream_leds(['effect' => 'comet-loop'], 8 / 18 * 6), true) &&
  homelab_stream_leds(['effect' => 'comet-loop'], 3)[HOMELAB_PINWHEEL_ORDER[8]] === HOMELAB_BRAND_ORANGE &&
  homelab_stream_leds(['effect' => 'comet-loop', 'virtual_gap_steps' => 0], 3)[HOMELAB_PINWHEEL_ORDER[8]] === HOMELAB_BRAND_ORANGE,
  'comet traverses an unlit virtual step before crossing to the next fan');
check($comet === homelab_stream_leds(['effect' => 'comet-loop'], 0) &&
  $comet[HOMELAB_PINWHEEL_ORDER[2]] !==
    homelab_stream_leds(['effect' => 'comet-loop', 'tail_variation' => 0], 0)[HOMELAB_PINWHEEL_ORDER[2]],
  'comet tail variation is repeatable for a frame and changes tail brightness');
check(homelab_stream_leds(['effect' => 'comet-loop', 'tail_variation' => 0], 0) ===
  homelab_stream_leds(['effect' => 'comet-loop', 'tail_variation' => 0], 6),
  'comet completes a loop when variation is disabled');
check(homelab_stream_leds(['effect' => 'comet-loop', 'color_primary' => '#FF0000'], 0)[HOMELAB_PINWHEEL_ORDER[0]] === 'FF0000',
  'comet uses chosen head color');
$pulse = homelab_stream_leds(['effect' => 'orange-blue-pulse'], 0);
check($pulse[0] === 'FF4500' && $pulse[12] === '000040' &&
  homelab_stream_leds(['effect' => 'orange-blue-pulse'], 3)[12] === '0000FF',
  'orange and blue hubs pulse in opposite phases');
check($pulse === homelab_stream_leds(['effect' => 'orange-blue-pulse'], 6),
  'dual pulse repeats');
check(homelab_stream_leds(['effect' => 'orange-blue-pulse',
  'color_primary' => '#00FF00', 'color_secondary' => '#FF00FF'], 0)[0] === '00FF00',
  'pulse uses chosen top color');
check(homelab_stream_tuning([]) === HOMELAB_STREAM_TUNING_DEFAULTS,
  'streamed effects have stable defaults');
foreach (HOMELAB_STREAM_EFFECTS as $effect => $label) {
  $defaults = homelab_stream_tuning(['effect' => $effect]);
  check($defaults['color_primary'] === HOMELAB_BRAND_ORANGE &&
    $defaults['color_secondary'] === HOMELAB_BRAND_BLUE &&
    $defaults['palette_mode'] === 'rainbow', "$label starts with the 45D / Unraid colors and keeps rainbow available");
}
check(homelab_stream_leds(['effect' => 'synchronized-rainbow', 'brightness_pct' => 50])[0] === '800000',
  'brightness scales the streamed RGB frame');
$shifted = homelab_stream_leds(['effect' => 'synchronized-rainbow', 'bottom_phase_steps' => 3]);
check($shifted[0] !== $shifted[12] && $shifted[12] === $shifted[3],
  'bottom alignment changes only its phase');
check(homelab_stream_leds(['effect' => 'synchronized-rainbow', 'direction' => 'clockwise'], 1) !==
  homelab_stream_leds(['effect' => 'synchronized-rainbow', 'direction' => 'counterclockwise'], 1),
  'direction changes the animation');
check(homelab_stream_leds(['effect' => 'synchronized-rainbow', 'palette_mode' => 'rainbow', 'rainbow_cycles' => 2])[1] !==
  homelab_stream_leds(['effect' => 'synchronized-rainbow', 'palette_mode' => 'rainbow'])[1],
  'rainbow repeats change color spacing');
$two_color = homelab_stream_leds(['effect' => 'pinwheel-rainbow', 'palette_mode' => 'two-color',
  'color_primary' => '#FF4500', 'color_secondary' => '#0000FF'], 0);
check($two_color[HOMELAB_PINWHEEL_ORDER[0]] === 'FF4500' &&
  $two_color[HOMELAB_PINWHEEL_ORDER[8]] === '0000FF',
  'rainbow patterns support chosen two-color palettes');
$split = array_merge(array_fill(0, 12, 'FF4500'), array_fill(0, 12, '0000FF'));
check(homelab_stream_leds(['effect' => 'custom-leds', 'leds' => $split]) === $split,
  'custom LED frame supports orange and blue fan split');
$one_led = array_fill(0, 24, '000000');
$one_led[17] = '12AB34';
check(homelab_stream_leds(['effect' => 'custom-leds', 'leds' => $one_led])[17] === '12AB34' &&
  count(array_filter($one_led, fn($color) => $color !== '000000')) === 1,
  'custom LED frame supports a single painted LED');
check(homelab_stream_blend_frame(array_fill(0, 24, '000000'), array_fill(0, 24, 'FFFFFF'), 50)[0] === '808080',
  'fade blends adjacent streamed frames');
check(homelab_stream_blend_frame(array_fill(0, 24, '000000'), $split, 0) === $split,
  'zero fade preserves target colors');
check(homelab_stream_blend_frame(array_fill(0, 24, '010101'), array_fill(0, 24, '000000'), 80)[0] === '000000',
  'fade lets dim LEDs turn fully off');
foreach ([['period_seconds' => '0'], ['brightness_pct' => ['100']],
  ['bottom_phase_steps' => '7'], ['direction' => 'sideways'], ['fade_pct' => '81'],
  ['tail_leds' => '2'], ['tail_variation' => '101'], ['skipped_leds' => '-1'],
  ['skipped_leds' => '7'], ['virtual_gap_steps' => '-1'], ['virtual_gap_steps' => '4'],
  ['middle_enabled' => 'yes'],
  ['middle_color' => '#12345Z'], ['palette_mode' => 'unknown'],
  ['color_primary' => '#12345Z'], ['color_secondary' => ['#0000FF']]] as $bad_tuning) {
  check(!homelab_rgb_set_stream_effect('synchronized-rainbow', $bad_tuning)['ok'],
    'rejects invalid tuning before opening controller');
}
check(!homelab_rgb_set_custom(json_encode(array_fill(0, 23, 'FFFFFF')))['ok'],
  'rejects custom frames without exactly 24 LEDs');
check(!homelab_rgb_set_stream_effect('unknown-loop')['ok'],
  'rejects unlisted streamed effects');
check(!homelab_rgb_set_custom(json_encode(array_merge(array_fill(0, 23, 'FFFFFF'), ['invalid'])))['ok'],
  'rejects invalid custom LED colors');
check(!homelab_rgb_set_separate('bad', 'blue')['ok'], 'rejects unknown separate preset');
check(homelab_rgb_separate_colors('#aBcDeF', 'off') === ['top' => 'ABCDEF', 'bottom' => '000000'] &&
  homelab_rgb_separate_colors('white', '#123456') === ['top' => 'FFFFFF', 'bottom' => '123456'],
  'separate fans accept custom colors and existing presets');
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
check(strpos($page, 'Orange applied.') !== false, 'page accepts validated POST without token field');
check(strpos($page, 'Rainbow Flow') !== false, 'page lists animated effects');
check(strpos($page, 'Lighting mode') !== false && strpos($page, 'Separate fan colors') !== false &&
  strpos($page, 'Synchronized Wave') !== false && strpos($page, 'bottom_phase_steps') !== false &&
  strpos($page, 'Two-Color Loop') !== false && strpos($page, 'Comet Loop') !== false &&
  strpos($page, 'Two-Color Pulse') !== false && strpos($page, 'name="color_primary"') !== false &&
  strpos($page, 'name="color_secondary"') !== false && strpos($page, 'name="tail_variation"') !== false &&
  strpos($page, 'Split orange / blue') !== false && strpos($page, 'Edit LED') !== false &&
  strpos($page, 'data-led-popover') !== false &&
  strpos($page, 'Night schedule') !== false && strpos($page, 'name="schedule_action"') !== false &&
  strpos($page, 'name="fade_pct"') !== false && strpos($page, 'Middle width:') !== false &&
  strpos($page, 'name="skipped_leds" min="0" max="6" step="2"') !== false &&
  strpos($page, 'data-color-heading') !== false && strpos($page, 'data-separate-help') !== false &&
  strpos($page, 'data-tuning-controls') !== false && strpos($page, 'name="top_color"') === false &&
  strpos($page, 'name="virtual_gap_steps"') !== false &&
  strpos($page, 'name="middle_enabled"') !== false && strpos($page, 'name="middle_color"') !== false,
  'page offers tuned animations and custom LED painting');
unset($_SERVER['REQUEST_METHOD'], $_POST, $var);
putenv('HOMELAB_OPENRGB_BIN');
file_put_contents($fixture, "0: Other controller\n  Modes: [Off] Static\n  Zones: 'Addressable Header 1'\n");
check(!homelab_rgb_detect($binary)['ok'], 'rejects unrelated controller');
check(!homelab_rgb_detect($dir . '/missing')['ok'], 'reports missing runtime');

unlink($binary);
unlink($log);
unlink($fixture);
@unlink($dir . '/rgb-day.json');
@unlink($dir . '/rgb-last-on.json');
@unlink($dir . '/rgb-power-state.json');
rmdir($dir);
putenv('HOMELAB_RGB_CONFIG_DIR');
putenv('HOMELAB_RGB_RUNTIME_DIR');
echo "RGB control tests passed\n";
