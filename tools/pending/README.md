# Manual limit policy utility

The utility reports changes by default. Run the dry pass before using `--apply`.

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

## Verification

```
php -f /usr/local/pkg/netcontrol_selftest.php | tail -3
php -f /usr/local/pkg/netcontrol_audit.php | tail -3
```
