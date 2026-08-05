# WiFi Repeater Mode

> Publication draft.

Repeater Mode is a post-install operating mode. The initial installer always
uses the validated AP-over-Ethernet flow.

Repeater Mode requires two suitable Wi-Fi interfaces:

1. an AP-capable interface that continues serving the OpenAP hotspot;
2. a managed-mode interface that connects to the upstream Wi-Fi network.

The dashboard scans for upstream networks, stores approved uplink profiles and
keeps hotspot addressing stable while the uplink changes. Saved credentials
must not be returned to the browser.

WPA3-only uplinks are not supported by the first release candidate. Networks
advertising WPA2/WPA3 transition mode may be used through their WPA2 path when
the adapter and upstream configuration permit it.

Mode changes must preserve dashboard reachability and must be validated on the
exact release candidate before publication.
