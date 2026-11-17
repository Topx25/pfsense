# Handover — AiTech network / pfSense netcontrol

Written 17 Nov 2026, 17:10. Everything below was measured on the live
system, not assumed. Where something is unverified it says so.

---

## 1. How to reach things

| What | Address | Credentials |
|---|---|---|
| pfSense web UI | `https://192.168.1.1` | `admin` / `+Admin@123` |
| Linksys X3500 ("AiTech-MAX") | `http://192.168.1.100` | `admin` / `admin` |
| TP-Link RE505X ("MAX2") | **see §5 — it moved to `192.168.0.254`** | unknown after reset |
| ZTE H188A (internet modem) | `http://192.168.2.1` | not needed so far |

**SSH is closed on pfSense.** All firewall work goes through
`Diagnostics → Command Prompt`, driven by the helper script:

```
scratchpad/pf.py run "<shell command>"
scratchpad/pf.py put <local file> <remote path>
scratchpad/pf.py get <remote path> <local file>
```

It needs `PF_PASS` in the environment and, on Git Bash,
`MSYS_NO_PATHCONV=1` — without that, Git Bash rewrites `/usr/local/...`
into a Windows path and the upload silently lands in the wrong place.

```bash
export PF_PASS='+Admin@123'
export MSYS_NO_PATHCONV=1
```

Working on the firewall requires being on `192.168.1.x`. The laptop keeps
drifting onto the WiFi `WE-5G` (`192.168.2.x`), from which pfSense is
unreachable. If every command starts timing out, check `ipconfig` first —
that was the cause every time it happened.

---

## 2. Network layout

```
internet
   |
ZTE H188A  192.168.2.1        (modem, gateway)
   |
pfSense    192.168.2.4 (em2)  <- WAN side, all internet traffic exits here
           192.168.1.1 (em1)  <- LAN side, serves DHCP
   |
Linksys X3500  192.168.1.100  (used as a SWITCH - clients behind it get
   |                           addresses and internet from pfSense)
TP-Link RE505X                (access point; see §5)
```

The firewall runs on VMware (`00:0c:29:...` interfaces), so the physical
switch is upstream of the hypervisor and cannot be reached from the
firewall shell.

**Line:** 31 Mb down. Upload capacity is **not known** — this was asked
and never answered, and it matters for §4.

---

## 3. What the limits are now

As requested, **all automatic limiting is off**. A limit now exists only
because somebody set it in the control panel.

| Setting | Value |
|---|---|
| `NC_MOBILE_LIMIT` | `0` (off) |
| New-device policy | enabled, action `allow` (records, does not restrict) |
| Global ceiling | 21 Mb down / 2 Mb up |
| Devices limited automatically | **0** |
| Devices limited by hand | 2 |

Verified by running a tick afterwards: no limit came back, and the log
shows `POLICY ALLOW` where it used to show `MOBILE-LIMIT`.

The global ceiling was deliberately left in place. It is the cap that
stops one machine taking the whole line; it is not a per-device rule, and
nothing in the panel sets it per device. Remove it only if that is what
is actually wanted.

> **Correction worth carrying forward:** a limit set by hand at 14:10 on
> `192.168.1.225` was *not* preserved by the cleanup. The mobile rule had
> overwritten it seconds later and stamped it `by_mobile`, so the cleanup
> treated it as automatic and cleared it. Any hand-set limit made while
> `NC_MOBILE_LIMIT` was still on may have been lost the same way.

---

## 4. The one thing still unresolved

**Upload is shaped at 2 Mb per device and nobody has confirmed the line
can carry more.**

Earlier, every device's upload was collapsing into a single 2 Mb queue
because a filter rule with `gateway=STABLE_WEB` shaped traffic *after*
NAT, so all sources looked like `192.168.2.4`. That is fixed (commit
`04bef06`) — the pipes were removed from that one rule, upload drops went
from 2,115 and climbing to zero, and WAN upload rose from a hard 2.00 to
5.78 Mb/s.

What remains: with each device now holding its own queue, 39 devices at
2 Mb each could in theory ask for 78 Mb. **Find out the real upload speed
of the line and set the global `up` value to match.** Otherwise the
bottleneck simply moves from one queue to the line itself under load.

---

## 5. TP-Link RE505X ("MAX2") — currently down

**Diagnosed, not yet fixed.** It has reset itself to factory defaults.

Evidence:
- `192.168.1.20` does not answer: 100% packet loss from the firewall. The
  ARP entry that still shows is a `permanent` (static) entry, not a live
  one — do not be fooled by it.
- Its MAC `dc:62:79:af:0e:57` **is** on the wire, but now sending from
  **`192.168.0.254`** — a different subnet, which is a factory-default
  address for this model.
- It is broadcasting ARP for internet addresses, i.e. trying to route.

It is **not** blocked or limited by netcontrol (checked both).

**To recover it:** put a laptop on `192.168.0.x` (e.g. `192.168.0.50/24`),
browse to `http://192.168.0.254`, and set it back to the working config:

- static address `192.168.1.20`, mask `255.255.255.0`, gateway
  `192.168.1.1`
- **Access Point mode, not Repeater.** The September report traced
  repeated network-wide dropouts to this device running as a repeater and
  rewriting MAC addresses, which made ~12 devices appear to pfSense under
  one MAC and caused constant ARP conflicts.
- Fixed WiFi channels, not Auto. Auto on both this and the Linksys made
  them collide.
- Its own DHCP server **off**.

---

## 6. The DHCP trap — read before touching either device

The Linksys X3500 has re-enabled its own DHCP server **twice** on its own
(after a factory reset, and again after its settings page was saved from a
browser). Its default range is `192.168.1.1–192.168.1.50`, which
**contains pfSense's own address**. Two DHCP servers on one LAN, one of
them able to lease away `192.168.1.1`, is the most likely cause of the
repeated disconnections that started all this.

Always check after touching that device:

```bash
curl -s -u admin:admin http://192.168.1.100/ | grep -o "lan.dhcpEnb = '[^']*'"
```

`0` = off (correct) · `1` = server (rogue, fix immediately) · `2` = relay
(also wrong).

Two firmware quirks that cost real time:

1. **The Save button does not work.** On this build the form reaches
   `F.submit()` and *no request leaves the browser* — verified by watching
   the network. Settings must be posted to `/apply.cgi` directly. The
   working payload is in `tools/pending/fix_linksys.sh`.
2. **Setting `lan_dns0` flips it into DHCP relay mode.** Attempting to
   give the device a DNS server that way set `dhcpEnb=2`. If DNS is ever
   needed for it, find another field; zeroing `lan_dns0*` and
   `dhcpRelayServer*` is what restores `dhcpEnb=0`.

---

## 7. Protected addresses — never block or limit these

`NC_OVERRIDE_PROTECTED` in `/usr/local/pkg/netcontrol.conf.php`:

```
192.168.1.20, 192.168.1.100, 192.168.1.250, 192.168.1.101, 192.168.1.181
```

Plus, derived automatically from the running system: every interface
address, every gateway, and the first address of each managed subnet —
so `192.168.1.1`, `192.168.2.1` and `192.168.2.4` are covered without
being listed.

Protection is enforced in the API (block and limit both return HTTP 403)
and held by MAC as well as address, so it follows a device that moves.
This was verified by actually posting block and limit requests for the
Linksys and the gateways; all were refused.

---

## 8. Health check

```bash
php -f /usr/local/pkg/netcontrol_selftest.php | tail -3
php -f /usr/local/pkg/netcontrol_audit.php | tail -3
```

Last run: **all enforced checks passed, no issues found.** One standing
warning, "block table is empty", is correct and expected — addresses are
only enforced while the blocked device actually holds them.

---

## 9. Repo

`github.com/Topx25/pfsense` — everything is committed and pushed.

Recent commits, newest last:

- `555fa89` real usage for every device, grouped device list, language fixes
- `19306e4` stop phones escaping the mobile cap
- `9951e68` keep infrastructure visible after it goes silent
- `9d5624b` note the rogue-DHCP hazard
- `04bef06` shape uploads per device instead of once behind NAT
- `3940278` stage the two patches in `tools/pending/`

Both patches in `tools/pending/` have now been **applied**. They are kept
because both situations recur: the Linksys turns its DHCP back on, and the
automatic limits return if `NC_MOBILE_LIMIT` is ever set above zero again.

Server and repo checksums matched at the last deploy.

---

## 10. Open questions for the user

1. **What is the line's real upload speed?** Needed to set the global `up`
   value sensibly (§4).
2. **Should the global ceiling (21 Mb down) stay?** It is the only
   remaining automatic limit of any kind.
3. **Recover the TP-Link?** It needs physical access to a laptop on
   `192.168.0.x` (§5).
