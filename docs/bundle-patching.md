# Bundle patching

The vendored frontend at `vendor/45drives/cockpit-hardware/45drives-disks` depends on private `@45drives` packages, so local rebuilds can fail when `npm.pkg.github.com` auth is unavailable. In that case, keep the source file changes in sync with the shipped bundle at `assets/45d/45drives-disks/assets/index.bbceb330.js`.

Current upstream source/bundle pair:

- Source: `vendor/45drives/cockpit-hardware/45drives-disks/src/components/DiskSection.vue`
- Bundle: `assets/45d/45drives-disks/assets/index.bbceb330.js`
- Added storage computed blocks: `storageRoleLabel`, `hasStorageDetails`

Manual patch workflow:

1. Edit the source Vue file first.
2. Locate the matching compiled component in the shipped bundle by searching for `storageRoleLabel`, `hasStorageDetails`, or the surrounding `Disk Information` strings.
3. Patch the matching compiled block in place. For the storage-context change, the key replacements were:
   - add the storage computed declarations
   - update `updateDiskObj` so it clears `diskObj` before `Object.assign`
   - keep the rendered storage section in sync with the Vue template
4. Verify the bundle still parses:

```sh
node --check assets/45d/45drives-disks/assets/index.bbceb330.js
```

Useful commands:

```sh
rg -n "storageRoleLabel|hasStorageDetails|Disk Information" assets/45d/45drives-disks/assets/index.bbceb330.js
sed -n '5855,5910p' assets/45d/45drives-disks/assets/index.bbceb330.js
```
