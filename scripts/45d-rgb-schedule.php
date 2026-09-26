<?php
require_once dirname(__DIR__) . '/php/rgb_schedule.php';

if ($argc > 2 || ($argc === 2 && $argv[1] !== '--install')) {
  fwrite(STDERR, "Usage: php 45d-rgb-schedule.php [--install]\n");
  exit(2);
}
if ($argc === 2) {
  $result = homelab_schedule_sync_cron();
  if (!$result['ok']) {
    fwrite(STDERR, $result['error'] . "\n");
    openlog('45homelab', LOG_PID, LOG_USER);
    syslog(LOG_ERR, 'Night schedule installation failed: ' . $result['error']);
    closelog();
    exit(1);
  }
}
$result = homelab_schedule_tick();
if (!$result['ok']) {
  fwrite(STDERR, $result['error'] . "\n");
  openlog('45homelab', LOG_PID, LOG_USER);
  syslog(LOG_ERR, 'Night schedule update failed: ' . $result['error']);
  closelog();
  exit(1);
}
