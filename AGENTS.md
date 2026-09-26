# AGENTS.md — guide for AI coding agents working in this repo

This file is the contract for any AI agent (Claude Code, Codex, Cursor, etc.)
making changes here. **Read the "Frontend: source ≠ what ships" section before
touching any UI/animation code.** That divergence is the single most common way
work gets silently lost in this repo — it has already cost us at least one fix
(see the cautionary tale at the end).

---

## What this repo is

An Unraid plugin that embeds the 45Drives disk-map UI in Unraid's **Main** page
and generates map/server metadata on the host. It is **not** the upstream
45Drives app — it *vendors* a snapshot of that app and patches it for Unraid.

| Area | Path | Language |
|------|------|----------|
| Host-side data generation & API | `php/`, `scripts/45d-*` | PHP / bash |
| Unraid page wiring | `*.page`, `plugin.cfg` | PHP/HTML |
| **Frontend source** | `vendor/45drives/cockpit-hardware/45drives-disks/src/` | Vue 3 |
| **Frontend shipped bundle** | `assets/45d/45drives-disks/` | built output (committed) |
| Release packaging | `.github/workflows/release.yml`, `scripts/build-plugin-txz` | bash |
| Tests | `tests/run.php` | PHP |

---

## ⚠️ Frontend: source ≠ what ships (READ THIS)

The frontend exists in **two places that do not update each other automatically**:

```
  vendor/.../src/  ──(npm run build)──>  dist/  ──(MANUAL sync)──>  assets/45d/45drives-disks/
   Vue source                          git-IGNORED                  COMMITTED — this is what ships
   (edit here)                         (throwaway)                  (release packages this as-is)
```

Three facts that trip up every agent:

1. **Editing source changes nothing on its own.** A change to a `.vue` file or
   `zfsAnimation.js` does **not** reach users until the bundle is rebuilt **and
   the rebuilt output is committed** into `assets/45d/45drives-disks/`.

2. **`dist/` is git-ignored.** Running `npm run build` produces `dist/`, which is
   never committed. If you build, verify locally, and commit only what `git status`
   shows, you ship **nothing** — `dist/` is invisible to git.

3. **The release pipeline never builds.** `release.yml` → `build-plugin-txz`
   copies the committed `assets/` directory verbatim into the `.txz`. No `npm`,
   no `vite`, no source. **Whatever is committed in `assets/` is exactly what
   users get.** Source is, for shipping purposes, documentation.

### The two failure modes

- **Edit source only → fix never ships.** Source and the committed bundle
  diverge; the running product is stale. (You "fixed" it, QA sees the old build.)
- **Hand-edit the minified `assets/.../index.*.js` only → fix is fragile.** It
  ships now, but it lives nowhere in source, so the *next* from-source rebuild
  silently reverts it. This is real minified-JS surgery and easy to get wrong.

**The only correct path is to keep both in sync: edit source, rebuild, re-commit
the bundle, and verify the change is present in the committed `assets/` file.**

---

## Rebuilding & shipping a frontend change (the correct procedure)

```bash
cd vendor/45drives/cockpit-hardware/45drives-disks

# 1. Build from source. Deps are vendored (no private registry / token needed) —
#    @45drives/cockpit-helpers and cockpit-css are aliased in vite.config.js to
#    local reconstructions under src/lib/. Plain public npm install works.
npm install
npm run build          # -> dist/  (git-ignored, new content-hashed filenames)

# 2. Sync the build output into the COMMITTED bundle.
#    cd back to repo root first.
cd -
cp -a vendor/45drives/cockpit-hardware/45drives-disks/dist/assets/. \
      assets/45d/45drives-disks/assets/
#    (also copy any new top-level files dist/ produced: favicon, img/, etc.)
```

### index.html is hand-maintained — do NOT blindly copy `dist/index.html`

`assets/45d/45drives-disks/index.html` contains an **inline Unraid Cockpit shim**
(an inline `<script>` block that sets `apiBase: "/plugins/45homelab/php/api.php"`
and stands in for Cockpit's loader). A raw `vite` build's `index.html` does **not**
have this shim. So you must:

- Update the `<script src="./assets/index.<hash>.js">` and the CSS `<link>` in the
  **committed** `index.html` to point at the **new content hashes** the build
  produced (each build changes the hashes, e.g. `index.bbceb330.js` →
  `index.e6b9148c.js`).
- **Preserve** the inline shim `<script>` block — do not overwrite it with the
  build's version.

### Verify before you commit (do not skip this)

```bash
# Confirm your change is actually IN the committed, shipped bundle —
# not just in source or in the ignored dist/.
grep -c "<a unique token from your change>" assets/45d/45drives-disks/assets/index.*.js

# Confirm index.html references the hashes that actually exist on disk.
ls assets/45d/45drives-disks/assets/index.*.js
grep -oE 'index\.[a-z0-9]+\.(js|css)' assets/45d/45drives-disks/index.html
```

Tailwind/Vite versions differ from the original hand-maintained bundle, so a fresh
build reformats output and changes hashes — that diff noise is expected. The
behavioral change is what matters; **real-device visual QA is required** before
shipping a regenerated bundle, since formatting/runtime can shift.

---

## Other things worth knowing

- **Vendored, not upstream.** `vendor/.../` is a patched snapshot of 45Drives'
  app (base ~v2.5.x). Upstream later rewrote its architecture
  (`@45drives/houston-common-*`, Yarn workspace). A version bump is a re-port, not
  a merge — do not naively pull a newer upstream over this snapshot.
- **Unraid-specific patches live on top of the 45D base:** drive-standby orange
  overlays across the `P5*.vue` canvases, light/dark theme matching, Unraid
  storage context. Preserve these when touching the frontend.
- **PHP/host side** (`php/`, `scripts/45d-*`) ships directly — no build step.
  Edit and test with `php tests/run.php`.
- **Release** is tag-driven (`v*`) via `release.yml`; details in `RELEASING.md`.

---

## Cautionary tale: how we lost Simon's animation fix

A real incident that motivated this file. Simon fixed the `zfsAnimation()` guard
for non-slot / storage-group-peer devices by **hand-patching the minified
committed bundle**. Later, that hand-patch was *deleted* from the bundle on the
assumption that a "rebuild from source" PR would re-supply it. The source half
landed; **the regenerated-bundle half never merged to `main`.** Net result: the
fix lived in `vendor/.../src/components/zfsAnimation.js` but was **absent from the
shipped `assets/` bundle** — so the released product silently lost it, even though
git history and source both "had" the fix.

The lesson encoded above: **source and the committed bundle must move together in
the same change, and you must verify the token is present in the committed
`assets/` file before considering the work done.**
