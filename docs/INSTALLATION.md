# Installing OpenAP

> Publication draft: validate every command from the final release archive
> before publishing this guide.

## Supported release targets

The first release candidate is intended for:

| Platform | Intended release status |
| --- | --- |
| Debian 13 x86-64 | Tested |
| Ubuntu 26.04 x86-64 | Tested |
| Current Raspberry Pi OS 64-bit on Raspberry Pi 3B+ | Tested |
| Raspberry Pi 4 and 5 | Expected compatible; physical reports wanted |
| Other Debian-like systems | Experimental |

These labels become release claims only after the exact candidate checksum
passes the clean validation matrix.

## Prerequisites

OpenAP does not install Wi-Fi firmware or hardware drivers. Before running the
installer, the operating system must expose:

- a working Ethernet uplink;
- at least one Wi-Fi interface that supports AP mode;
- a valid two-letter regulatory country code.

Verify that the expected Ethernet and Wi-Fi interfaces are visible before
downloading OpenAP:

```bash
ip -brief link
iw dev
```

If `iw` is missing, install the distribution package that provides it before
continuing. If the intended Wi-Fi interface does not appear, install and verify
its firmware or driver first; OpenAP cannot do this for you.

## Download the current pre-release from GitHub

On a clean Debian-based system, install Git and clone OpenAP:

```bash
sudo apt update
sudo apt install -y git
git clone https://github.com/AngelsWillRule/OpenAP.git
cd OpenAP
```

These commands download the current `main` branch. It is a moving pre-release
target and must not be treated as a supported or reproducible release. Use it
only on a test system. Fixed release archives and their SHA-256 checksums will
replace this procedure when the first release is published.

All commands below must be run from the cloned `OpenAP` directory.

## Detect the host

Use the read-only detector to inspect the host:

```bash
openap-installer/bin/openap-detect
openap-installer/bin/openap-detect --json
```

## Dry run

The installer performs a dry run by default and makes no changes:

```bash
openap-installer/bin/openap-install
```

## Interactive installation

```bash
sudo openap-installer/bin/openap-install --apply
```

The validated installation flow creates an AP over an Ethernet uplink. The
installer lets the administrator select the AP-capable Wi-Fi interface and
asks for the regulatory country, hotspot credentials and web administrator
credentials.

Do not use unattended installation for a public deployment until the static
default-credential behavior has been replaced by explicit or generated secure
credentials.

## After installation

Verify the hotspot, DHCP, DNS, Internet forwarding and dashboard access before
changing operating mode. WiFi Repeater Mode is selected later from the web UI;
it is not an initial installer profile.
