# Changelog

## 0.5.0

### Added
- Added basic solid-color and off controls for the 45HomeLab X4 fan lights through OpenRGB.
- Added Warm White, Yellow, Teal, Cyan, and Pink fan lighting presets.
- Added Rainbow Flow, Color Wave, and Spectrum Cycle effects.
- Added separate top and bottom fan colors, Outer Loop and Synchronized Rainbow effects, and a hub LED preview for the X4's 24-LED chain.
- Added a unified lighting editor with previews for all modes, per-LED color popovers, custom palette saving, and live tuning controls for streamed effects.
- Added 45D x Unraid, Comet, and Orange / Blue Pulse animated loops.
- Extended each outer loop arc to nine LEDs per fan so the patterns reach farther around both sides.
- Added selectable colors for streamed patterns, optional two-color rainbow palettes, and Comet tail length and variation controls.
- Added per-fan skipped LED and separate middle color controls for Outer Loop, 45D x Unraid Loop, and Comet Loop.
- Added X4 bay mapping from ATA ports when no SATA drives are installed.

### Changed
- Renamed the Unraid plugin to 45HomeLab and moved its settings under System Settings.
- Preserved drive-map overrides from existing 45d-drivemap installations during installation.
- Tuned the Orange preset to look less yellow on the X4 fans.
- Paced animated fan streams at 15 frames per second and blended adjacent frames for smoother motion.

### Fixed
- Target the X4 controller without OpenRGB's zone flag, which accepts commands but leaves the fan lights unchanged on this board.
- Let Unraid handle CSRF validation for the lighting form; its request handler removes the token before the page runs.
- Ensure faded LEDs reach fully off instead of remaining dimly lit.

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
