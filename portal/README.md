# Captive portal login page

`login.html` replaces the stock pfSense portal page. Upload it under
**Services → Captive Portal → your zone → Custom captive portal page**.

It keeps every field pfSense requires — `auth_user`, `auth_pass`,
`auth_voucher`, the terms checkbox and the four `$PORTAL_*$` placeholders.
Changing or removing any of them breaks logins for everyone, so check them
before uploading a modified copy:

```sh
grep -c 'auth_user\|auth_pass\|PORTAL_ACTION\|name="accept"' login.html
```

## The warning modal

Devices with a private (randomized) MAC are shown a modal before they log
in, explaining how to turn the feature off and how to name the device.

They are the devices that cannot be tracked: a phone that invents a new MAC
per connection appears as a new device each time, which on a portal limited
to one session per account means users are disconnected repeatedly. On the
network this was built for, nine of the ten most-disconnected users had
randomized MACs, and one phone had presented 53 different addresses.

A web page cannot read a client's MAC, so the modal decides from the user
agent and errs towards silence: only phones and tablets running a version
that randomises by default are asked, and the choice is remembered per
browser. The terms text is inlined rather than templated, because pfSense
injects it into its own markup and offers no placeholder — keep it in step
with **Services → Captive Portal → Terms and Conditions**.
