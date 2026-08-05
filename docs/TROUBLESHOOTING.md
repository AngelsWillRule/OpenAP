# Troubleshooting OpenAP

> Publication draft.

Start with read-only checks:

```bash
ip -brief link
ip -brief address
ip route
iw dev
systemctl --failed
systemctl status lighttpd hostapd dnsmasq openap-firewall --no-pager
```

Before sharing diagnostic output, remove:

- hotspot and uplink SSIDs;
- BSSIDs and MAC addresses;
- public IP addresses and hostnames;
- usernames and home-directory paths;
- passwords, pre-shared keys, cookies, tokens and private keys.

Do not attach `/etc/openap`, hostapd or wpa_supplicant configuration files
without reviewing them for credentials. A sanitized support-bundle workflow is
planned but is not yet a release feature.

Security vulnerabilities must not be reported through a public issue. The
private reporting channel will be documented in `SECURITY.md` before release.
