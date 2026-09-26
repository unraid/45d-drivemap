<?php
require_once '/usr/local/emhttp/plugins/45homelab/php/rgb_stream.php';
if (is_file('/etc/acpi/powerbutton.d/README') && homelab_stream_controller() !== null) {
  echo mk_option($cur, '45homelab-lights', _('Toggle fan lights'));
}
