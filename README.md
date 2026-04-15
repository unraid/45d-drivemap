# 45D Drive Map (Unraid Plugin)

Embed the 45Drives disk map UI directly in Unraid's **Main** page and generate
map/server metadata on the Unraid host.

## Stable Plugin Links

- Install / update URL (stable latest):
  - `https://github.com/unraid/45d-drivemap/releases/latest/download/45d-drivemap.plg`
- Release history (version-specific assets):
  - `https://github.com/unraid/45d-drivemap/releases`

## What This Plugin Does

- Adds a **Drive Map** section to the top of Unraid Main (`Main:0`).
- Serves the 45Drives disk-map frontend from plugin assets.
- Generates and caches:
  - `drivemap.json`
  - `server_info.json`
  - runtime logs
  in `/var/local/45d/`.
- Supports SMART-derived fields and ZFS info endpoints used by the UI.

## Getting Started

1. In Unraid, open **Plugins**.
2. Choose **Install Plugin**.
3. Paste the stable URL:
   - `https://github.com/unraid/45d-drivemap/releases/latest/download/45d-drivemap.plg`
4. Install, then open **Main** and scroll to **Drive Map** (top section).
5. Click **Refresh** in the Drive Map toolbar to force regeneration if needed.

## Uninstall Behavior

Removing the plugin cleans up:

- `/usr/local/emhttp/plugins/45d-drivemap`
- `/var/local/45d`
- cached package files under `/boot/config/plugins/45d-drivemap`

## Development

- Release process details: `RELEASING.md`
- Changelog management: `CHANGELOG.md` + `knope.toml`
- Test suite:
  - `php tests/run.php`
  - `php tests/remote_smoke.php` (non-45d / no-python smoke)
- Remote dev harness (SSH + rsync/scp):
  - `dev/remote-test-harness.sh --host root@<unraid-ip>`
  - details in `dev/README.md`
