# Patches waiting to run

Two changes prepared while the firewall was unreachable. Both report what
they would do when run with no arguments, and only change anything when
given `--apply`. Run the dry pass first and read it.

## 1. Hand limits back to the operator

`apply_manual_limits.php` — run **on the firewall**.

Turns off every rule that applies a speed limit on its own, so that from
then on a limit exists only because someone set it in the control panel.

What it switches off:

- `NC_MOBILE_LIMIT` → `0`. This is the standing cap on every phone
  (currently 2 Mb). Setting it to zero makes the tick release exactly the
  devices that rule had limited — they carry a `by_mobile` marker — and
  leave everything else alone.
- The new-device policy, **only if** its action is `limit`. It becomes
  `allow`, which still records each new device without restricting it. If
  the policy is set to block, that is left as it is: blocking is not a
  speed limit and was not part of the request.
- Limits already in the store that came from either of those two.

What it keeps:

- Limits set by hand from the panel.
- Limits from a video rule, since those are rules the operator created.
- The global per-device ceiling (10 Mb down / 2 Mb up). That is the cap
  that stops one machine taking the whole line, not a per-device rule, and
  nothing in the panel sets it per device.

```
php -f tools/pending/apply_manual_limits.php             # report
php -f tools/pending/apply_manual_limits.php -- --apply  # do it
```

The `--` matters: without it PHP reads `--apply` as its own option.

## 2. Give the X3500 a route for itself

`fix_linksys.sh` — run from a machine on `192.168.1.x`.

The X3500 (`192.168.1.100`, named AiTech-MAX) is used as a switch: the
devices behind it get addresses and internet from pfSense through its LAN
ports, which works. The device itself had no gateway and no DNS — both
`0.0.0.0` — and its WAN was set to ADSL, which is not connected. Watching
the wire confirmed it never sent a single packet toward the internet, only
local multicast and NetBIOS.

Sets, on the LAN side only:

- gateway `192.168.1.1`
- DNS `192.168.1.1`
- DHCP server off, re-asserted

The WAN side is left alone on purpose. Nothing is plugged into it, and
changing WAN mode on a device being used as a switch risks it starting to
NAT or to hand out addresses again.

The DHCP server is set off again because it has already come back on once
since being disabled — a factory reset, or the settings page being saved
from a browser, turns it on. Two DHCP servers on one LAN, one of them
offering `192.168.1.1`, is what caused the original outage.

```
./tools/pending/fix_linksys.sh            # report
./tools/pending/fix_linksys.sh --apply    # do it
```

Note: the firmware's own Save button does not post on this build. The form
reaches `F.submit()` and no request leaves the browser — verified by
watching the network while clicking it. The script posts to `apply.cgi`
directly, exactly as the page would.

## After both

```
php -f /usr/local/pkg/netcontrol_selftest.php | tail -3
php -f /usr/local/pkg/netcontrol_audit.php | tail -3
```
