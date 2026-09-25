<?php
if (is_file('/etc/acpi/powerbutton.d/README')) {
  echo mk_option($cur, '45homelab-lights', _('Toggle fan lights'));
}
