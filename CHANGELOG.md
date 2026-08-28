# Changelog

All notable changes to OpenAP are documented in this file.

The project follows semantic versioning. Until a versioned GitHub release is
published, entries describe validated pre-release candidates from `main`.

## [0.8.0] - 2026-08-28

### Added

- Added simultaneous dual-band 2.4 GHz and 5 GHz hotspots with a shared
  gateway and bridge, independent channel and width settings, and persistent
  `ap_24ghz`, `ap_5ghz` and `uplink` radio roles.
- Added capability- and hardware-identity-based Wi-Fi inventory, including
  late detection, hot-plug, removal, replacement and compatible-radio
  fallback without relying on fixed Linux interface names.
- Added transactional Wi-Fi role swaps and live radio-state updates across AP
  Configuration, Repeater Mode and Network Topology.
- Added selectable Ethernet Routed NAT and Bridge modes, dynamic hotspot
  gateway/subnet configuration, Wi-Fi QR codes and responsive per-user page
  widgets.
- Added read-only APT/dpkg preflight checks that stop before installation when
  the host has missing indexes, incomplete configuration, broken dependencies
  or pending upgrades.

### Changed

- Reworked Repeater, Routed NAT and Ethernet Bridge transitions around
  persistent `openap0`/`br0` topology, ordered service readiness and
  transactional rollback.
- Made interface discovery and hotspot startup portable across USB, PCIe and
  SDIO radios on supported Debian-family systems and virtualized environments
  with hardware passthrough.
- Limited package installation to OpenAP dependencies with `apt-get install
  --no-upgrade`; the installer no longer updates, repairs or upgrades the host
  operating system automatically.
- Preserved and restored pre-existing hostapd, dnsmasq, dnscrypt-proxy and
  lighttpd configuration and service state across installation and removal.
- Refreshed the responsive light/dark interface, service health, DHCP/DNS,
  Protected DNS, live traffic, reboot flow and Network Topology feedback.

### Fixed

- Fixed cold-boot, delayed-radio and post-role-swap interface persistence by
  matching configured radios by permanent MAC address and capabilities.
- Fixed single- and dual-band transitions among Repeater Mode, Ethernet
  Bridge and Routed NAT, including IPv4/gateway readiness, route ownership,
  stale bridge cleanup, hotspot recovery and host DNS continuity.
- Fixed DHCP/DNS synchronization, dnscrypt-proxy startup, hostapd/dnsmasq
  ordering and mode-aware firewall/service status.
- Fixed a watchdog race by stopping its timer before the worker, clearing
  expected failed state and restarting it only after Repeater validation.
- Fixed stale Dashboard, modal, selector, topology and widget state after
  asynchronous network changes.

### Validation

- Validated on Debian 13 x86-64, Ubuntu 26.04 x86-64 in Incus with Wi-Fi
  passthrough, and Raspberry Pi OS/Debian ARM64 on physical Raspberry Pi 3B+.
- Exercised real USB and PCIe Wi-Fi adapters, single- and dual-band hotspots,
  Repeater Mode, Routed NAT, Ethernet Bridge, gateway/subnet changes, repeated
  mode transitions, reboots and persistence.
- Verified hostapd, dnsmasq, dnscrypt-proxy, nftables, routes, host DNS and
  transactional restoration of pre-existing service configuration and state.

### Known limitations

- OpenAP does not install hardware-specific Wi-Fi firmware or drivers.
- Repeater Mode requires two suitable radios, and WPA3-only uplinks are not
  supported.
- Raspberry Pi 4 and 5 are expected to be compatible but still need physical
  validation; other Debian-like systems remain experimental.
- The full multi-radio rollback path still needs deliberate fault-injection
  validation.

## [0.2.5.1] - 2026-08-07

### Fixed

- Fixed uninstall detection to use the installed OpenAP marker and entrypoint
  instead of requiring the intentionally excluded webroot `VERSION` file.
- Limited uninstall cleanup to OpenAP-owned files and services, preserving
  unrelated webroot content, shared services, packages, firmware and drivers.
- Added a single updating progress bar and a final removed-items summary to
  applied uninstall operations.

## [0.2.5] - 2026-08-06

### Added

- Added an interactive maintenance menu for existing installations, with
  adapter role switching, uninstall and safe exit actions.
- Added a dry-run-first uninstaller that removes only OpenAP-owned web files,
  configuration, helpers and systemd units while retaining shared services,
  packages, firmware and drivers.
- Added CLI and web workflows for swapping the configured access-point and
  Wi-Fi uplink adapters without reinstalling OpenAP.
- Added progress, confirmation and result states for interface role changes in
  AP Configuration.

### Changed

- Preserved the selected AP/uplink roles consistently when switching between
  AP Ethernet and WiFi Repeater Mode.
- Improved service settling and interface cleanup during runtime mode changes.
- Extended the installer to deploy the interface-role helper and its narrowly
  scoped sudo authorization.

### Fixed

- Fixed the final Network Topology state after choosing AP Ethernet from the
  repeater uplink recovery flow. The UI now clears stale interrupted nodes,
  links and health badges before completing the Ethernet transition.
- Fixed stale interface state during wireless role swaps and subsequent mode
  restoration.

### Validation

- Validated maintenance-menu, adapter-role round trips and the web role-switch
  workflow on Ubuntu 26.04 with Realtek RTL8821CU USB and RTL8822CE PCIe radios.
- Validated clean Debian 13 startup with persistent Ethernet, Debian Realtek
  firmware and both Wi-Fi radios available.
- Validated the repeater failure, recovery modal and AP Ethernet fallback on a
  Debian 13 ARM64 Raspberry Pi test system.

### Known limitations

- Uninstall requires a full applied removal-and-reinstall validation before it
  is considered production-ready.
- OpenAP does not install Wi-Fi firmware or hardware-specific drivers.
- WPA3-only uplinks and automatic full configuration rollback are not yet
  supported.

## [0.2.0] - 2026-08-05

### Added

- Introduced the first public OpenAP pre-release candidate with AP-over-
  Ethernet, two-radio WiFi Repeater Mode, DHCP/DNS, nftables forwarding and a
  responsive administration interface.
- Added the universal Debian-family installer, hardware-capability detection,
  release metadata and read-only continuous-integration checks.
- Added public installation, hardware, security and contribution guidance.
