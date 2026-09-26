<?php
require_once dirname(__DIR__) . '/php/rgb_schedule.php';

function schedule_check($value, $message)
{
  if (!$value) {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
  }
}

$directory = sys_get_temp_dir() . '/45homelab-schedule-' . getmypid();
mkdir($directory, 0700);
putenv('HOMELAB_RGB_CONFIG_DIR=' . $directory);
putenv('HOMELAB_RGB_RUNTIME_DIR=' . $directory);
$sysfs = $directory . '/sys';
$devices = $directory . '/dev';
mkdir($sysfs . '/hidraw0/device', 0700, true);
mkdir($devices, 0700);
file_put_contents($sysfs . '/hidraw0/device/uevent', "HID_ID=0003:000026CE:000001A2\n");
file_put_contents($devices . '/hidraw0', '');
putenv('HOMELAB_RGB_HIDRAW_SYSFS=' . $sysfs);
putenv('HOMELAB_RGB_HIDRAW_DEV=' . $devices);
$cron_updater = $directory . '/update-cron';
file_put_contents($cron_updater, "#/bin/bash\nexit 0\n");
chmod($cron_updater, 0700);
putenv('HOMELAB_UPDATE_CRON_BIN=' . $cron_updater);
$paths = homelab_schedule_paths();

schedule_check(homelab_schedule_load() === homelab_schedule_defaults(),
  'schedule starts disabled without choosing hours');
foreach ([
  ['enabled' => '1', 'start' => '', 'end' => '07:00'],
  ['enabled' => '1', 'start' => '22:00', 'end' => '22:00'],
  ['enabled' => '1', 'start' => '25:00', 'end' => '07:00'],
  ['enabled' => 'yes', 'start' => '22:00', 'end' => '07:00'],
] as $invalid) {
  schedule_check(!homelab_schedule_save($invalid)['ok'], 'invalid schedule is rejected');
}

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['schedule_action' => 'save', 'enabled' => '1', 'start' => '22:00', 'end' => '22:00'];
ob_start();
include dirname(__DIR__) . '/HomeLab.page';
$page = ob_get_clean();
unset($_SERVER['REQUEST_METHOD'], $_POST);
$night_card = substr($page, strpos($page, '<section class="homelab-night-card"'));
schedule_check(strpos($night_card, 'role="alert"') !== false &&
  strpos($night_card, 'Choose different start and end times') !== false &&
  strpos($night_card, 'value="1" selected>On') !== false &&
  substr_count($night_card, 'value="22:00"') === 2 &&
  strpos($night_card, 'action="#homelab-night-heading"') !== false,
  'invalid schedule shows error beside times and preserves input');

$schedule = homelab_schedule_validate(['enabled' => '1', 'start' => '22:00', 'end' => '07:00']);
date_default_timezone_set('America/New_York');
schedule_check(!homelab_schedule_is_night($schedule, strtotime('2026-09-24 21:59')) &&
  homelab_schedule_is_night($schedule, strtotime('2026-09-24 22:00')) &&
  homelab_schedule_is_night($schedule, strtotime('2026-09-25 06:59')) &&
  !homelab_schedule_is_night($schedule, strtotime('2026-09-25 07:00')),
  'overnight boundaries use server local time');
$daytime = homelab_schedule_validate(['enabled' => '1', 'start' => '09:00', 'end' => '17:00']);
schedule_check(homelab_schedule_is_night($daytime, strtotime('2026-09-24 10:00')) &&
  !homelab_schedule_is_night($daytime, strtotime('2026-09-24 18:00')),
  'same-day time window is handled');

schedule_check(homelab_schedule_record_day('global', ['color' => 'blue']),
  'global daytime color is saved');
schedule_check(homelab_schedule_day_selection() === ['kind' => 'global', 'preset' => 'blue'],
  'saved daytime color restores exactly');
schedule_check(homelab_schedule_write($paths['schedule'], $schedule), 'schedule persists');
schedule_check(homelab_schedule_sync_cron()['ok'] &&
  strpos(file_get_contents($paths['cron']), '45d-rgb-schedule.php') !== false,
  'enabled schedule installs Unraid cron entry with its malformed shebang');

$applied = [];
$apply = function ($phase, $selection) use (&$applied) {
  $applied[] = [$phase, $selection];
  return ['ok' => true, 'error' => null];
};
schedule_check(homelab_schedule_tick(false, strtotime('2026-09-24 22:00'), $apply)['ok'] &&
  count($applied) === 1 && $applied[0][0] === 'night', 'night transition turns lighting off');
schedule_check(homelab_schedule_tick(false, strtotime('2026-09-24 23:00'), $apply)['ok'] &&
  count($applied) === 1, 'cron tick does not repeat an applied night action');
schedule_check(homelab_schedule_tick(false, strtotime('2026-09-25 07:00'), $apply)['ok'] &&
  count($applied) === 2 && $applied[1] === ['day', ['kind' => 'global', 'preset' => 'blue']],
  'morning transition restores saved daytime color');

$button = [];
$press = function ($action, $selection) use (&$button) {
  $button[] = [$action, $selection];
  return ['ok' => true, 'error' => null];
};
schedule_check(homelab_power_toggle($press, 100)['ok'] && $button[0] === ['off', null],
  'power button turns current lighting off');
schedule_check(homelab_power_toggle($press, 100.5)['ok'] && count($button) === 1 &&
  json_decode(file_get_contents($paths['power']), true)['off'],
  'duplicate button event does not turn lighting back on');
schedule_check(homelab_power_toggle($press, 101)['ok'] &&
  $button[1] === ['on', ['kind' => 'global', 'preset' => 'blue']],
  'button press after one second restores last daytime lighting');
schedule_check(homelab_schedule_tick(false, strtotime('2026-09-25 22:00'), $apply)['ok'],
  'night schedule can turn lights off after a power button toggle');
schedule_check(homelab_power_toggle($press, 102)['ok'] &&
  $button[2] === ['on', ['kind' => 'global', 'preset' => 'blue']],
  'power button can temporarily restore lights during night mode');
schedule_check(homelab_schedule_tick(false, strtotime('2026-09-25 23:00'), $apply)['ok'] &&
  count($applied) === 3,
  'night cron does not erase a power button override before the next transition');

schedule_check(homelab_schedule_write($paths['schedule'], ['enabled' => false, 'start' => '22:00', 'end' => '07:00']),
  'disabled schedule persists');
schedule_check(homelab_schedule_sync_cron()['ok'] && !file_exists($paths['cron']),
  'disabled schedule removes Unraid cron entry');

schedule_check(homelab_schedule_write($paths['schedule'], $schedule), 'schedule remains configured');
schedule_check(homelab_schedule_sync_cron()['ok'] && file_exists($paths['cron']),
  'controller allows the schedule');
unlink($devices . '/hidraw0');
$applied_before = count($applied);
$button_before = count($button);
schedule_check(homelab_schedule_sync_cron()['ok'] && !file_exists($paths['cron']) &&
  homelab_schedule_tick(true, strtotime('2026-09-25 22:00'), $apply)['ok'] &&
  homelab_power_toggle($press, 104)['ok'] &&
  count($applied) === $applied_before && count($button) === $button_before &&
  !homelab_schedule_save(['enabled' => '1', 'start' => '22:00', 'end' => '07:00'])['ok'],
  'missing controller removes cron and skips lighting actions');

foreach (glob($directory . '/*') as $path) {
  if (is_file($path)) unlink($path);
}
unlink($sysfs . '/hidraw0/device/uevent');
rmdir($sysfs . '/hidraw0/device');
rmdir($sysfs . '/hidraw0');
rmdir($sysfs);
rmdir($devices);
rmdir($directory);
putenv('HOMELAB_RGB_CONFIG_DIR');
putenv('HOMELAB_RGB_RUNTIME_DIR');
putenv('HOMELAB_UPDATE_CRON_BIN');
putenv('HOMELAB_RGB_HIDRAW_SYSFS');
putenv('HOMELAB_RGB_HIDRAW_DEV');
echo "RGB schedule tests passed\n";
