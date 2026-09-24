This directory contains vendored 45drives-disks frontend assets.

- `45drives-disks/` holds a prebuilt Cockpit module release snapshot (v2.5.4-2).
- `45drives-disks/index.html` is patched to shim Cockpit APIs and route process
  calls to `/plugins/45homelab/php/api.php` on Unraid.
- `drivemap.js` and `drivemap.css` remain as a lightweight fallback renderer.
