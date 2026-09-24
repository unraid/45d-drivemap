# Dev Harnesses (Not Packaged)

Everything in `dev/` is development-only and is not included in the plugin
`.txz` payload.

## Live Deploy (Direct to Unraid Plugin Dir)

Use `dev/live-deploy.sh` to copy local files directly into the live plugin
directory on a remote Unraid host:

- default target: `/usr/local/emhttp/plugins/45homelab`
- default paths: `DriveMap.page`, `plugin.cfg`, `php/`, `scripts/`
- optional extras: `assets/`, dev pages, or additional repo-relative paths

### Usage

```bash
dev/live-deploy.sh --host root@192.168.1.201
dev/live-deploy.sh --host 192.168.1.201 --include-assets
dev/live-deploy.sh --host root@192.168.1.201 --include-dev-pages
dev/live-deploy.sh --host root@192.168.1.201 --extra-path DriveMapDevToolsSimulator.page
```

### Options

- `--method auto|rsync|scp` transfer mode (default `auto`)
- `--host` accepts either `user@host` or bare `host`/IP (bare defaults to `root@`)
- `--plugin-dir <path>` override remote plugin directory
- `--include-assets` include `assets/` in deploy
- `--include-dev-pages` include `DriveMapDevTools.page` and `DriveMapDevToolsSimulator.page`
- `--extra-path <path>` add repo-relative path(s) to deploy
- `--dry-run` print deploy plan without changing remote files
- `--no-verify` skip remote post-deploy checks

## Remote Non-45d Harness

Use `dev/remote-test-harness.sh` to copy this repo to a remote host over SSH
and run tests there.

Default behavior runs a pure-PHP smoke suite (`tests/remote_smoke.php`) so it
works on non-45Drives systems that do not have Python installed.

You can also run the smoke suite against a custom alias fixture to simulate
non-45d layouts.

### Usage

```bash
dev/remote-test-harness.sh --host root@192.168.1.201
dev/remote-test-harness.sh --host 192.168.1.201
dev/remote-test-harness.sh --host root@192.168.1.201 --simulate-fixture tests/fixtures/vdev_id_h16_q30.conf
```

### Options

- `--method auto|rsync|scp` transfer mode (default `auto`)
- `--host` accepts either `user@host` or bare `host`/IP (bare defaults to `root@`)
- `--remote-dir /tmp/...` fixed remote workspace path
- `--full` run `tests/run.php` when Python is available remotely (falls back to
  smoke suite when Python is missing)
- `--simulate-fixture <path>` run smoke tests with a custom alias fixture
  (absolute path or repo-relative path)
- `--keep-remote` keep remote workspace after success

## Dev Tools Page (Live Simulation Mode)

To simulate a 45d layout directly on a non-45d Unraid host via the web UI:

1. Copy the dev-only pages:

```bash
scp DriveMapDevTools.page DriveMapDevToolsSimulator.page root@192.168.1.201:/usr/local/emhttp/plugins/45homelab/
```

2. Open **Tools -> 45HomeLab Drive Map Dev -> Simulator** in Unraid.
3. Pick a profile (for example `H16 Q30`).
4. Choose an occupancy source:
   - `Use detected by-path devices only` (real occupancy cap)
   - `Repeat detected by-path devices...` (fills more bays on non-45d hosts)
5. Choose disk data mode:
   - `Use generator output only` (real data only)
   - `Overlay healthy/mixed/failing synthetic disk metadata`
6. Click **Apply Simulation** and reload **Main** to inspect the Drive Map.
7. Use **Clear Simulation** to restore backups/remove simulated state.

By default this page is not included in stable plugin packages.
Set `INCLUDE_DEV_TOOLS_PAGE=1` when running `scripts/build-plugin-txz` to include it in a dev package.
