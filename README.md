# netcontrol — pfSense Full Control Plugin

A device and bandwidth control panel for pfSense: see who is on the network,
what they are using it for, and act on it from one page.

Built and run against **pfSense 2.8.1-RELEASE** (FreeBSD 15, PHP 8.3, Kea
DHCP) on a network of ~70 devices behind a captive portal.

---

## What it does

**Devices** — every device with its name, address, MAC, live speed, total
usage and state. Sort any column, drag to resize, expand a row for the full
detail. Block or throttle a device in one click.

**Live traffic** — the services each device is actually talking to right now,
grouped by service, with the real server hostnames.

**Blocking** — by MAC, enforced in `pf`. A device that changes its IP keeps
its rule; a device with a randomized MAC is followed by its DHCP hostname
where that name is specific enough to be safe.

**Speed limits** — per device, enforced with dummynet pipes, plus a global
per-device limit for everyone else.

**Site blocking** — by domain, enforced in the resolver, with whole-service
presets (YouTube, TikTok, Facebook…) that cover every domain a service uses.

**New-device policy** — decide in advance what happens when an unknown
device joins: allow, limit or block, optionally only for phones, optionally
on a schedule, and optionally exempting anyone who signed in to the captive
portal.

**History** — who logged in and when, which devices each account used, and
how much each device consumed per day for the last two weeks.

**DNS enforcement** — forces every DNS query through the firewall and blocks
the public DNS-over-HTTPS endpoints, so site blocking cannot be sidestepped
by pointing a laptop at 8.8.8.8.

**Arabic and English** — a switch in the header, remembered per browser.
The page is authored in Arabic and translated at render time, so a phrase
with no translation stays Arabic rather than leaving a gap. Layout flips to
LTR in English; numeric columns keep their alignment. Device names, hostnames,
MACs, addresses and the log are never rewritten — they are data, not
interface. The dictionary is `docs/translations.json`.

---

## Requirements

- pfSense 2.7 or newer (developed on 2.8.1)
- Shell or **Diagnostics → Command Prompt** access
- The **Limiters** feature available (it ships with pfSense; no package needed)

Optional, and only for the features that use them:

| Feature | Needs |
|---|---|
| Login history, portal-aware policy | Captive Portal enabled on the LAN |
| Site blocking, DNS enforcement | DNS Resolver (unbound) — the default |
| Device names | DHCP server on the LAN |

No packages to install. Everything is plain PHP against pfSense's own
libraries.

---

## Install

> دليل التركيب بالعربي: **[docs/INSTALL.ar.md](docs/INSTALL.ar.md)**

```sh
git clone https://github.com/Topx25/pfsense.git
cd pfsense
sh install.sh
```

The installer backs up anything it replaces to `/root/netcontrol-backup-*`,
copies the files, registers the cron job, installs the `pf` rules, and runs
the self test. Re-running it upgrades in place and keeps your settings.

To remove:

```sh
sh install.sh remove
```

Then open **Services → Network Control**.

---

## Configuration

Interfaces, the LAN subnet and the captive portal zone are read from the
running configuration, so a standard pfSense needs no configuration at all.

Everything you may want to change lives in one file,
`/usr/local/pkg/netcontrol.conf.php`:

```php
/* Never block or throttle these. The firewall's own LAN address is
   always protected and need not be listed. */
define('NC_OVERRIDE_PROTECTED', '192.168.1.20,192.168.1.250');

/* Only if the automatic detection is wrong for your setup. */
// define('NC_OVERRIDE_LAN_IF', 'em1');
// define('NC_OVERRIDE_WAN_IF', 'em2');
// define('NC_OVERRIDE_CP_ZONE', 'guest');
```

**Set `NC_OVERRIDE_PROTECTED` before you use the blocking features.** The
plugin protects the firewall itself automatically, but it cannot know which
of your addresses are switches, access points, hypervisors or servers.

### More than one WAN

The WAN is detected from the **default route**, not from the interface
labelled "wan" — on a multi-WAN box the labelled WAN is often a standby with
no address, and parsing it would show an idle network. If you use a gateway
group and want to pin one uplink, set `NC_OVERRIDE_WAN_IF`.

### More than one LAN

**This release handles one LAN.** The plugin reads the interface pfSense
calls `lan` and everything — the state parser, the shaping rules, the block
rules — is written against that one interface.

On a box with several internal networks it will work correctly for the `lan`
interface and simply not see the others. Devices on `opt1`, a VLAN or a guest
network will not appear.

If your main network is not the one called `lan`, point the plugin at the
right one with `NC_OVERRIDE_LAN_IF` and `NC_OVERRIDE_LAN_IP`.

Full multi-interface support is the main thing this project still needs; see
[docs/ROADMAP.md](docs/ROADMAP.md).

---

## How enforcement works

Worth understanding, because it explains both the limits and the failure
modes.

**Blocking** writes the addresses into the `pf` table `nc_blocked` and loads
two rules into the `userrules/netcontrol` anchor. That anchor is used because
pfSense evaluates it *before* the LAN pass rules, which are `pass quick` —
a block placed after them would never be reached.

**Speed limits** configure a dummynet pipe per device and a pass rule that
routes that device's traffic into it. A pipe with no rule pointing at it
shapes nothing, which is a genuinely easy mistake to make.

**Site blocking** writes `local-zone … refuse` lines into
`/var/unbound/netcontrol.conf` and includes that file from inside unbound's
`server:` block. It is done in DNS rather than in `pf` because a domain has
no stable address: `youtube.com` answers from hundreds of IPs that change
constantly.

Every write to the resolver is validated with `unbound-checkconf` **before**
it is loaded. An invalid file would stop unbound and take the network's DNS
with it.

`nc_apply()` re-asserts all of this on every run, so a filter reload or a
pfSense configuration change cannot silently drop the rules.

---

## What it cannot do

Stated plainly, because the opposite is often assumed:

- **Full URLs.** HTTPS encrypts the path. The firewall sees the hostname
  from the TLS handshake and the DNS lookup, never `/watch?v=…`. Getting the
  full URL needs a TLS-intercepting proxy with a certificate installed on
  every client.
- **Throttling one service.** A service's addresses move constantly, and the
  DNS layer that blocks a domain can only answer yes or no. "Limit YouTube"
  caps the *devices* that are streaming it, which slows everything those
  devices do. The UI says so where you choose it.
- **Following a randomized MAC reliably.** Modern phones invent a new MAC
  per network. The plugin follows them by DHCP hostname where that name is
  specific, and marks the rest as weak in the UI. The real fix is asking
  people to turn the feature off for your network.

---

## Verifying an install

```sh
php -f /usr/local/sbin/netcontrol_selftest.php
```

32 checks. It deliberately never trusts the stored state: it asks `pf`,
`dnctl` and unbound whether each rule is actually loaded and being
evaluated, because the failure mode in this kind of tool is a UI that
reports a block while the traffic flows.

For a deeper check that compares the store against the kernel item by item:

```sh
php -f /usr/local/sbin/netcontrol_audit.php
```

It reports every blocked device missing from the `pf` table, every limit
without a pipe or without a rule feeding that pipe, site blocks the resolver
does not have, protected addresses that ended up blocked, and whether the
tick has actually run recently.

---

## Layout

```
src/netcontrol.inc              the library: all detection and enforcement
src/netcontrol_api.php          JSON API, CSRF-checked
src/services_netcontrol.php     the page
src/netcontrol_tick.php         cron: runs every minute
src/netcontrol_selftest.php     verification
src/netcontrol.conf.php.example per-site overrides
src/netcontrol.xml              menu registration
src/netcontrol.priv.inc         privilege definition
```

State lives in `/var/db/netcontrol*.json`. Actions are logged to
`/var/log/netctl.log`.

---

## Licence

MIT. See [LICENSE](LICENSE).
