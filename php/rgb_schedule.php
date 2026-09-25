<?php
require_once __DIR__ . '/rgb_control.php';

function homelab_schedule_paths()
{
  $boot = rtrim(getenv('HOMELAB_RGB_CONFIG_DIR') ?: '/boot/config/plugins/45homelab', '/');
  $runtime = homelab_stream_runtime_dir();
  return [
    'schedule' => "$boot/rgb-schedule.json",
    'day' => "$boot/rgb-day.json",
    'last_on' => "$boot/rgb-last-on.json",
    'cron' => "$boot/rgb.cron",
    'state' => "$runtime/rgb-schedule-state.json",
    'power' => "$runtime/rgb-power-state.json",
    'lock' => "$runtime/rgb-schedule.lock",
  ];
}

function homelab_schedule_defaults()
{
  return ['enabled' => false, 'start' => '', 'end' => ''];
}

function homelab_schedule_validate($input)
{
  if (!is_array($input) || !in_array($input['enabled'] ?? null, ['0', '1', 0, 1, false, true], true)) {
    throw new InvalidArgumentException('Choose whether night mode is enabled.');
  }
  $enabled = in_array($input['enabled'], ['1', 1, true], true);
  $start = $input['start'] ?? '';
  $end = $input['end'] ?? '';
  foreach ([$start, $end] as $time) {
    if (!is_string($time) || ($time !== '' && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D', $time))) {
      throw new InvalidArgumentException('Choose valid local start and end times.');
    }
  }
  if ($enabled && ($start === '' || $end === '' || $start === $end)) {
    throw new InvalidArgumentException('Choose different start and end times for night mode.');
  }
  return ['enabled' => $enabled, 'start' => $start, 'end' => $end];
}

function homelab_schedule_load()
{
  $saved = json_decode((string) @file_get_contents(homelab_schedule_paths()['schedule']), true);
  try {
    return is_array($saved) ? homelab_schedule_validate($saved) : homelab_schedule_defaults();
  } catch (InvalidArgumentException $ignored) {
    return homelab_schedule_defaults();
  }
}

function homelab_schedule_is_night($schedule, $timestamp = null)
{
  if (!$schedule['enabled']) {
    return false;
  }
  $now = date('H:i', $timestamp ?? time());
  if ($schedule['start'] < $schedule['end']) {
    return $now >= $schedule['start'] && $now < $schedule['end'];
  }
  return $now >= $schedule['start'] || $now < $schedule['end'];
}

function homelab_schedule_write($path, $value)
{
  $directory = dirname($path);
  if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
    return false;
  }
  $temporary = $path . '.' . getmypid() . '.tmp';
  $bytes = json_encode($value, JSON_UNESCAPED_SLASHES);
  if ($bytes === false || file_put_contents($temporary, $bytes . "\n", LOCK_EX) === false ||
      !rename($temporary, $path)) {
    @unlink($temporary);
    return false;
  }
  return true;
}

function homelab_schedule_day_selection()
{
  $selection = json_decode((string) @file_get_contents(homelab_schedule_paths()['day']), true);
  return is_array($selection) ? $selection : null;
}

function homelab_schedule_capture_active_stream()
{
  if (homelab_stream_pid() === null) {
    return false;
  }
  $config = json_decode((string) @file_get_contents(homelab_stream_paths()['config']), true);
  if (!is_array($config)) {
    return false;
  }
  try {
    homelab_stream_packets(homelab_stream_leds($config));
  } catch (Throwable $ignored) {
    return false;
  }
  return homelab_schedule_write(homelab_schedule_paths()['day'], ['kind' => 'stream', 'config' => $config]);
}

function homelab_schedule_record_day($action, $input)
{
  if ($action === 'global') {
    $preset = $input['color'] ?? null;
    if (!is_string($preset) || homelab_rgb_label($preset) === null) {
      return false;
    }
    $selection = ['kind' => 'global', 'preset' => $preset];
  } else {
    $config = json_decode((string) @file_get_contents(homelab_stream_paths()['config']), true);
    if (!is_array($config)) {
      return false;
    }
    try {
      homelab_stream_packets(homelab_stream_leds($config));
    } catch (Throwable $ignored) {
      return false;
    }
    $selection = ['kind' => 'stream', 'config' => $config];
  }
  $paths = homelab_schedule_paths();
  if (!homelab_schedule_write($paths['day'], $selection)) {
    return false;
  }
  if ($selection !== ['kind' => 'global', 'preset' => 'off'] &&
      !homelab_schedule_write($paths['last_on'], $selection)) {
    return false;
  }
  return homelab_schedule_write($paths['power'], [
    'off' => $selection === ['kind' => 'global', 'preset' => 'off'],
  ]);
}

function homelab_schedule_apply_day($selection)
{
  if (($selection['kind'] ?? null) === 'global' &&
      is_string($selection['preset'] ?? null) && homelab_rgb_label($selection['preset']) !== null) {
    return homelab_rgb_set($selection['preset']);
  }
  if (($selection['kind'] ?? null) === 'stream' && is_array($selection['config'] ?? null)) {
    try {
      homelab_stream_packets(homelab_stream_leds($selection['config']));
    } catch (Throwable $error) {
      return ['ok' => false, 'error' => 'Saved daytime lighting is invalid.'];
    }
    return homelab_stream_start($selection['config']);
  }
  return ['ok' => false, 'error' => 'Apply daytime lighting before enabling night mode.'];
}

function homelab_schedule_tick($force = false, $timestamp = null, $apply = null)
{
  $paths = homelab_schedule_paths();
  if (!is_dir(dirname($paths['lock'])) && !mkdir(dirname($paths['lock']), 0700, true)) {
    return ['ok' => false, 'error' => 'Could not create lighting runtime directory.'];
  }
  $lock = fopen($paths['lock'], 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    return ['ok' => false, 'error' => 'Lighting schedule is already updating.'];
  }
  try {
    $schedule = homelab_schedule_load();
    $state = json_decode((string) @file_get_contents($paths['state']), true);
    $phase = homelab_schedule_is_night($schedule, $timestamp) ? 'night' : 'day';
    if (!$schedule['enabled'] && (!is_array($state) || ($state['phase'] ?? null) !== 'night')) {
      return ['ok' => true, 'error' => null];
    }
    if (!$force && ($state['phase'] ?? null) === $phase) {
      return ['ok' => true, 'error' => null];
    }
    $selection = $phase === 'day' ? homelab_schedule_day_selection() : null;
    $result = $apply ? $apply($phase, $selection) :
      ($phase === 'night' ? homelab_rgb_set('off') : homelab_schedule_apply_day($selection ?? []));
    if (!$result['ok']) {
      return $result;
    }
    if (!homelab_schedule_write($paths['state'], ['phase' => $phase, 'applied_at' => time()]) ||
        !homelab_schedule_write($paths['power'], ['off' => $phase === 'night' ||
          $selection === ['kind' => 'global', 'preset' => 'off']])) {
      return ['ok' => false, 'error' => 'Lighting changed, but schedule state was not saved.'];
    }
    return ['ok' => true, 'error' => null];
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

function homelab_power_toggle($apply = null, $timestamp = null)
{
  $paths = homelab_schedule_paths();
  if (!is_dir(dirname($paths['lock'])) && !mkdir(dirname($paths['lock']), 0700, true)) {
    return ['ok' => false, 'error' => 'Could not create lighting runtime directory.'];
  }
  $lock = fopen($paths['lock'], 'c');
  if (!$lock || !flock($lock, LOCK_EX)) {
    return ['ok' => false, 'error' => 'Could not lock fan lighting.'];
  }
  try {
    $state = json_decode((string) @file_get_contents($paths['power']), true);
    $now = $timestamp ?? hrtime(true) / 1000000000;
    // Some boards dispatch one physical press twice. Keep this inside the
    // lock so a second handler waiting for OpenRGB cannot undo the first.
    $last_press = $state['button_at'] ?? null;
    if (is_numeric($last_press) && $now >= $last_press && $now - $last_press < 1) {
      return ['ok' => true, 'error' => null];
    }
    $schedule_state = json_decode((string) @file_get_contents($paths['state']), true);
    $off = ($state['off'] ?? (($schedule_state['phase'] ?? null) === 'night')) === true;
    if ($off) {
      $selection = json_decode((string) @file_get_contents($paths['last_on']), true);
      if (!is_array($selection)) {
        $selection = homelab_schedule_day_selection();
      }
      if (!is_array($selection) || $selection === ['kind' => 'global', 'preset' => 'off']) {
        $selection = ['kind' => 'global', 'preset' => 'white'];
      }
      $result = $apply ? $apply('on', $selection) : homelab_schedule_apply_day($selection);
    } else {
      if (homelab_stream_pid() !== null) {
        $config = json_decode((string) @file_get_contents(homelab_stream_paths()['config']), true);
        if (is_array($config)) {
          homelab_schedule_write($paths['last_on'], ['kind' => 'stream', 'config' => $config]);
        }
      }
      $result = $apply ? $apply('off', null) : homelab_rgb_set('off');
    }
    if (!$result['ok']) {
      return $result;
    }
    if (!homelab_schedule_write($paths['power'], ['off' => !$off, 'button_at' => $timestamp ?? hrtime(true) / 1000000000])) {
      return ['ok' => false, 'error' => 'Fan lights changed, but button state was not saved.'];
    }
    return ['ok' => true, 'error' => null];
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

function homelab_schedule_sync_cron()
{
  $paths = homelab_schedule_paths();
  if (homelab_schedule_load()['enabled']) {
    $entry = "* * * * * /usr/bin/php /usr/local/emhttp/plugins/45homelab/scripts/45d-rgb-schedule.php >/dev/null 2>&1\n";
    if (file_put_contents($paths['cron'], $entry, LOCK_EX) === false) {
      return ['ok' => false, 'error' => 'Could not install the night schedule.'];
    }
  } else {
    @unlink($paths['cron']);
  }
  $binary = getenv('HOMELAB_UPDATE_CRON_BIN') ?: '/usr/local/sbin/update_cron';
  if (!is_executable($binary)) {
    return ['ok' => false, 'error' => 'Unraid cron updater was not found.'];
  }
  $pipes = [];
  $process = proc_open([$binary], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
  if (!is_resource($process)) {
    return ['ok' => false, 'error' => 'Could not update the night schedule.'];
  }
  fclose($pipes[1]);
  fclose($pipes[2]);
  return proc_close($process) === 0
    ? ['ok' => true, 'error' => null]
    : ['ok' => false, 'error' => 'Could not update the night schedule.'];
}

function homelab_schedule_save($input)
{
  try {
    $schedule = homelab_schedule_validate($input);
  } catch (InvalidArgumentException $error) {
    return ['ok' => false, 'error' => $error->getMessage()];
  }
  $paths = homelab_schedule_paths();
  if ($schedule['enabled'] && !is_array(homelab_schedule_day_selection()) &&
      !homelab_schedule_capture_active_stream()) {
    return ['ok' => false, 'error' => 'Apply daytime lighting before enabling night mode.'];
  }
  $previous = @file_get_contents($paths['schedule']);
  if (!homelab_schedule_write($paths['schedule'], $schedule)) {
    return ['ok' => false, 'error' => 'Could not save night schedule.'];
  }
  $cron = homelab_schedule_sync_cron();
  if (!$cron['ok']) {
    if ($previous === false) {
      @unlink($paths['schedule']);
    } else {
      file_put_contents($paths['schedule'], $previous);
    }
    homelab_schedule_sync_cron();
    return $cron;
  }
  return homelab_schedule_tick(true);
}
