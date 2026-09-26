# Releasing

This repo ships a thin `45homelab.plg` that downloads a versioned plugin
tarball from GitHub Releases and extracts it on Unraid.

The GitHub repository remains `unraid/45d-drivemap` until it is renamed.
Release assets use the new `45homelab` name. Existing installs must be removed
and replaced manually because the Unraid plugin ID changed.

`scripts/render-plg` renders `<CHANGES>` from `CHANGELOG.md`.

## Changelog flow (KNope)

1. Create a change file:
   - `knope document-change`
2. Prepare a release locally:
   - `knope release --dry-run`
3. Run the real release workflow locally (commits changelog + tags + pushes):
   - `knope release`

KNope configuration lives in `knope.toml` and writes release notes to
`CHANGELOG.md`.

## Automatic release assets

1. Push a tag in the format `vX.Y.Z`.
2. GitHub Actions workflow `.github/workflows/release.yml` will:
   - build `packages/45homelab-X.Y.Z.txz`
   - render `45homelab.plg` with matching checksum and release URLs
   - publish both assets to that release

Because `plugin_url` points to:

`https://github.com/<owner>/<repo>/releases/latest/download/45homelab.plg`

the install URL remains stable while payloads stay versioned.

## Local build helpers

- Build full plugin package:

`scripts/build-plugin-txz 0.5.0`

- Render release plg from template:

`scripts/render-plg 0.5.0 <sha256> unraid/45d-drivemap`
