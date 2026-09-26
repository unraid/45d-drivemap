Drive Map tests

Run the generator + API parity checks:

```bash
php tests/run.php
```

Run the X4 RGB controller command and preset checks:

```bash
php tests/rgb_control.php
```

Run the non-45d smoke suite (no Python required):

```bash
php tests/remote_smoke.php
```

Run smoke with a custom alias fixture simulation:

```bash
php tests/remote_smoke.php --simulate-fixture tests/fixtures/vdev_id_h16_q30.conf
```

The test harness uses fixtures under `tests/fixtures` and overrides generator/API
paths through environment variables, so it is safe to run on non-Unraid hosts.

Coverage includes:
- baseline map + API + ZFS fixture behavior
- SMART-derived field population via fixture JSON blobs
- row-order parity checks against vendored upstream `lsdev` templates
- server_info inference from alias layouts (for example `H16/Q30`)
- fixture parity checks against vendored upstream `dmap` outputs
- direct ported `dmap` parity checks vs vendored upstream for covered cases
- alias-line structural validation (format, uniqueness, contiguous numbering)
- deterministic output checks + unsupported-style failure checks
- upstream template extraction helper: `tests/vendor_template.py`
- upstream dmap case helper: `tests/vendor_dmap_case.py`
- non-45d remote smoke harness entrypoint: `tests/remote_smoke.php`
- custom fixture simulation mode for non-45d harness validation

Additional fixture files:
- `tests/fixtures/vdev_id_h16_q30.conf`
- `tests/fixtures/vdev_id_h16_s45.conf`
- `tests/fixtures/vdev_id_s45_full.conf`
- `tests/fixtures/dmap_f8_x1.conf`
- `tests/fixtures/dmap_c8.conf`
