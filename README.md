# OpenAP

OpenAP is a web-managed Wi-Fi access point and two-radio repeater for supported
Debian-based systems. It provides one focused interface for hotspot, DHCP/DNS,
uplink, firewall and system status while keeping privileged network changes in
explicit root-owned helpers.

> OpenAP `0.2.0` is in pre-release preparation. Do not treat the current
> publication branch as a supported release or install it on a production
> router. Release downloads and checksum-linked installation instructions will
> appear here only after the clean validation matrix passes.

## What OpenAP does

- creates a Wi-Fi hotspot over an Ethernet uplink;
- detects interfaces by capability and hardware identity instead of assuming
  names such as `wlan0` or `eth0`;
- provides DHCP, DNS policy and nftables-based forwarding for hotspot clients;
- switches to WiFi Repeater Mode after installation when a second suitable
  Wi-Fi interface is available;
- manages saved Wi-Fi uplinks without returning stored passphrases to the
  browser;
- exposes AP configuration, DHCP/DNS, clients, logs and service health through
  a responsive light/dark web interface.

The initial installer intentionally offers only the validated AP-over-Ethernet
flow. Repeater Mode is selected later from the dashboard.

## Intended first-release platforms

| Platform | Intended status |
| --- | --- |
| Debian 13 x86-64 | Tested |
| Ubuntu 26.04 x86-64 | Tested |
| Current Raspberry Pi OS 64-bit on Raspberry Pi 3B+ | Tested |
| Raspberry Pi 4 and 5 | Expected compatible; physical reports wanted |
| Other Debian-like systems | Experimental |

These labels become release claims only when the exact `0.2.0` candidate and
checksum pass the clean-platform matrix.

## Hardware prerequisites

OpenAP does not install Wi-Fi firmware or hardware drivers. Before installation
the operating system must expose:

- a working Ethernet uplink;
- at least one Wi-Fi interface supporting AP mode;
- a valid regulatory country.

Repeater Mode requires a second Wi-Fi interface capable of managed/client mode.
See [Hardware and Wi-Fi prerequisites](docs/HARDWARE.md).

## Installer preview

The detector and installer live in `openap-installer/bin`.

Read-only detection:

```bash
openap-installer/bin/openap-detect
openap-installer/bin/openap-detect --json
```

Dry run, which makes no changes:

```bash
openap-installer/bin/openap-install
```

Interactive installation from a verified release archive:

```bash
sudo openap-installer/bin/openap-install --apply
```

Do not use unattended `--yes` installation for a public deployment until the
pre-release static credential behavior has been replaced and revalidated.

## Security model

The Lighttpd/PHP dashboard runs without unrestricted root access. Network
changes are delegated to narrowly named helpers under `/usr/local/sbin`, with
corresponding sudoers rules generated for detected interfaces and supported
operations. Repository sources, installer scripts and project documentation
are excluded from the installed webroot.

OpenAP does not enable WAN access to the dashboard by default. Administrators
remain responsible for host firewall policy, physical security and applying
operating-system security updates.

Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Known pre-release limitations

- installation, reinstall and mode switching still require the complete
  release-candidate validation matrix;
- unattended installation currently has insecure static defaults and must not
  be used for public deployments;
- WPA3-only Wi-Fi uplinks are not supported;
- automatic configuration rollback is planned but not yet implemented;
- Raspberry Pi 4/5 compatibility has not yet been physically validated;
- OpenAP installs no Wi-Fi firmware or driver.

## Documentation

- [Documentation index](docs/README.md)
- [Installation](docs/INSTALLATION.md)
- [Hardware prerequisites](docs/HARDWARE.md)
- [WiFi Repeater Mode](docs/REPEATER_MODE.md)
- [Troubleshooting](docs/TROUBLESHOOTING.md)
- [Feature roadmap](docs/OPENAP_FEATURE_ROADMAP.md)

## Relationship to RaspAP

OpenAP is derived from [RaspAP](https://github.com/RaspAP/raspap-webgui) and
retains the GNU GPL version 3 license, original copyright notices and upstream
authorship information. OpenAP is an independent project and is not endorsed
by or affiliated with RaspAP unless explicitly stated otherwise.

See [NOTICE.md](NOTICE.md) for provenance and
[THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md) for the ongoing dependency
license audit.

## Contributing

OpenAP is not yet accepting production support requests. Code, documentation
and hardware-validation contributions will be welcome after the initial
private audit. See [CONTRIBUTING.md](CONTRIBUTING.md) for the intended workflow.

## License

OpenAP is distributed under the [GNU General Public License version 3](LICENSE).
