# What is missing

An honest list of the gaps, in the order they matter. Written from running
this on one production network, so the priorities reflect what actually bit.

---

## 1. Multiple interfaces — the main gap

**Now:** one LAN, read from the interface pfSense calls `lan`.

Hardcoded to that single interface:

| Place | What it does |
|---|---|
| `nc_live_traffic()` | parses states on `NC_LAN_IF` only |
| `nc_install_anchor()` | writes `on {$lan}` into every block and shaping rule |
| `nc_dns_enforce()` | redirect and DoH block are `on {$lan}` |
| `nc_cfg('lan_subnet')` | one prefix, used to tell a client from a NATed address |

**Needed:** take a list of interfaces instead of one. Concretely:

- `nc_cfg('lan_ifs')` returning every internal interface, defaulting to all
  of them that have an address and are not the WAN
- the state parser accepting any of them, and tagging each device with the
  interface it was seen on
- one rule set per interface in the anchor
- an interface column and filter in the UI

The store is already MAC-keyed, so it needs no change — two devices on
different VLANs are already distinct records.

**Watch out for:** overlapping subnets. Two VLANs both using 192.168.1.0/24
would collide in every IP-keyed lookup. Key those by `interface + ip`.

---

## 2. Several firewalls

**Now:** state is local files on one box. Two firewalls know nothing of each
other, and a device that roams between them is two separate records.

**Needed, in rough order of effort:**

- export/import of `/var/db/netcontrol.json` so rules can be copied
- a read-only aggregate view that polls several boxes' APIs
- genuine sync (one primary, others follow) — which means conflict rules,
  and is a much larger job than it first looks

For a CARP pair, syncing `/var/db/netcontrol*.json` with the existing
`pfsync`/XMLRPC mechanism is the smaller and more reliable path.

---

## 3. Known weaknesses in what already exists

**Randomized MACs.** Followed by DHCP hostname, but only when the name is
specific — `galaxy-a14` is a model, not a handset, so it is deliberately
ignored. Coverage on the test network was 6 of 21 blocked devices. There is
no better signal available without client cooperation.

**DoH blocklist is static.** Twelve well-known endpoints, listed in
`nc_doh_hosts()`. New providers and any resolver on a CDN address are not
covered. A real fix would detect the DoH content-type, which needs packet
inspection.

**Usage history has one-minute granularity** and keeps 14 days. Anything
finer or longer needs a real time-series store rather than a JSON file.

**The captive portal log has no year.** `nc_login_log()` infers it and steps
back when a date lands in the future — correct except in the first seconds
of a new year.

**No tests beyond the self test.** The self test checks a live system; there
are no unit tests, because almost every function reads real `pf`/`dnctl`
output. Parser-level tests against captured fixtures would be worth having
— `nc_live_traffic()` and `nc_login_log()` especially, both of which had
parsing bugs that silently returned nothing.

---

## 4. Smaller things

- **IPv6.** Entirely unhandled. The parsers match IPv4 only.
- **Per-user limits.** Limits attach to devices; a person with three devices
  gets three times the allowance.
- **Scheduled site blocks.** The schedule applies to the new-device policy,
  not to site blocks.
- **Backup/restore from the UI.** The state files are backed up only by
  whatever backs up `/var/db`.
- **Localisation.** The UI is Arabic only, with strings inline. Extracting
  them is mechanical but has not been done.

---

## Contributing

Two things worth knowing before changing enforcement code:

**Verify in the kernel, not in the store.** Every serious bug found while
building this was the same shape: the UI recorded an intention and nothing
carried it out — a pf table nothing read, a dummynet pipe no rule pointed
at, a resolver file unbound never included. If you add an enforcement path,
add a self-test check that asks the kernel whether it is loaded *and being
evaluated*.

**Validate before loading.** A bad resolver config stops unbound and takes
DNS down for everyone; a bad NAT rule can take routing with it. Both paths
here check with `unbound-checkconf` / `pfctl -n` first, and keep a backup.
