# Changelog

## 0.5.0

### Added
- Added basic solid-color and off controls for the 45HomeLab X4 fan lights through OpenRGB.
- Added Warm White, Yellow, Teal, Cyan, and Pink fan lighting presets.
- Added Rainbow Flow, Color Wave, and Spectrum Cycle effects.
- Added separate top and bottom fan colors, Outer Loop and Synchronized Wave effects, and a hub LED preview for the X4's 24-LED chain.
- Added a unified lighting editor with previews for all modes, per-LED color popovers, custom palette saving, and live tuning controls for streamed effects.
- Added Two-Color Loop, Comet Loop, and Two-Color Pulse animated effects.
- Extended each outer loop arc to nine LEDs per fan so the patterns reach farther around both sides.
- Added selectable colors for streamed patterns, optional two-color rainbow palettes, and Comet tail length and variation controls.
- Added per-fan skipped LED and separate middle color controls for Outer Loop, Two-Color Loop, and Comet Loop.
- Added adjustable unlit virtual steps at both fan crossings to smooth motion around the combined outer loop.
- Added a local-time night schedule that turns fan lights off and restores the saved daytime mode.
- Added a selectable fan-light toggle action through Unraid 7.4's power-button plugin hook.
- Debounced duplicate physical power-button events so one press leaves the lights in the selected state.
- Added X4 bay mapping from ATA ports when no SATA drives are installed.

### Changed
- Shared the two color pickers across separate fan colors and animated patterns, with labels and controls matched to each lighting mode.
- Replaced the skipped LED slider with balanced middle widths of 0, 2, 4, or 6 LEDs per fan; older odd settings round down, and the default is 4.
- Renamed the Unraid plugin to 45HomeLab and moved its settings under System Settings.
- Preserved drive-map overrides from existing 45d-drivemap installations during installation.
- Tuned the Orange preset to look less yellow on the X4 fans.
- Paced animated fan streams at 15 frames per second and blended adjacent frames for smoother motion.

### Fixed
- Run Unraid's cron updater through Bash, since its script lacks a valid shebang. Keep invalid night schedule input visible and show validation next to the schedule controls.
- Target the X4 controller without OpenRGB's zone flag, which accepts commands but leaves the fan lights unchanged on this board.
- Let Unraid handle CSRF validation for the lighting form; its request handler removes the token before the page runs.
- Ensure faded LEDs reach fully off instead of remaining dimly lit.
- Calibrate the X4 fan preview to the measured LED positions; LED 1 is at about 6:30 on the mounted fans. Pair top middle LEDs across the gap for even skipped counts.

## 0.4.0

### Added
- Added automatic detection and drive mapping for Unraid 45Homelab X-4 systems.
- Added SATA DEVPATH-based mapping for HL-4 and HL-8 systems when stable by-path links are unavailable.
- Added configurable HBA port and phy-order overrides for HL-15 systems.

### Fixed
- Improved HL-15/X11 SATA slot mapping when expected by-path links are missing.
- Improved diagnostics for lsblk-only SATA disks and systems without a by-path directory.

## 0.3.0

### Added
- Added support for HL-15 1.0 and 2.0 systems.

### Changed
- Rebuilt the shipped frontend from vendored source while preserving Unraid theme, drive-standby, and storage-context features.

### Fixed
- Prevented the Drive Map header from opening nested copies of the interface.
- Fixed errors when animations encounter non-slot devices.
- Restored the non-slot animation guard in the shipped frontend bundle.

## 0.2.0

### Added
- Added drive-standby values and visualisation.
- Added X-15 HomeLab model recognition and consistent canvas selection.

### Fixed
- Mapped raw ZFS pool devices to their physical drive bays.
- Refreshed drive-map lookup data before resolving ZFS devices.

## 0.1.5

### Added
- Added Unraid storage-context information to drive details.

### Fixed
- Improved HL-15 system detection.
- Reset disk details when selecting a different slot.

## 0.1.4

### Added
- Added the PHP drive-mapping implementation based on the upstream dmap behavior.
- Added simulation tools and a remote development test harness.

### Changed
- Moved Drive Map to the end of the Unraid Main page.
- Made plugin packages deterministic and embedded changelog notes in the PLG.

### Fixed
- Hardened plugin installation packaging and simulation refresh behavior.

## 0.1.3

### Fixed
- Updated PLG install scripts to use concrete runtime paths in CDATA.
- Preserved stable GitHub-hosted update/install URLs for plugin distribution.

## 0.1.2

### Added
- Added GitHub Actions release automation for tagged builds.
- Switched to thin `.plg` + versioned `.txz` release assets.
- Added templated `.plg` rendering for release URL + checksum injection.

## 0.1.1

### Added
- Published initial plugin release artifacts.
