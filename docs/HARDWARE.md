# Hardware and Wi-Fi prerequisites

> Publication draft.

OpenAP detects interfaces by capability and hardware identity rather than by
assuming names such as `wlan0` or `eth0`.

The administrator is responsible for installing the correct operating-system
firmware and driver before using OpenAP. A visible interface is not sufficient:
the intended hotspot adapter must advertise AP mode through `iw`.

Useful read-only checks include:

```bash
ip -brief link
iw dev
iw list
rfkill list
```

For Repeater Mode, two usable Wi-Fi interfaces are required: one remains the
hotspot and the other associates with the upstream network. OpenAP must not
offer unsupported roles when the detected hardware lacks the required modes.

A public compatibility table will record device model, USB or PCI identifier,
chipset, driver, bands, operating system, kernel and tested OpenAP version.
