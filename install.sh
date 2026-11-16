#!/bin/sh
#
# netcontrol installer for pfSense.
#
# Copies the files into place, registers the menu entry and the cron job,
# and installs the pf rules that do the enforcing. Safe to re-run: it
# backs up anything it replaces and never edits config.xml by hand.
#
# Usage:  sh install.sh          install or upgrade
#         sh install.sh remove   take it back out
#
set -e

PKG=/usr/local/pkg
WWW=/usr/local/www
SBIN=/usr/local/sbin
PRIV=/etc/inc/priv
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP=/root/netcontrol-backup-$STAMP

say()  { echo "  $*"; }
fail() { echo "ERROR: $*" >&2; exit 1; }

# ---------------------------------------------------------------- checks
[ "$(id -u)" = "0" ] || fail "run this as root"
[ -f /etc/version ] || fail "this does not look like pfSense"
say "pfSense $(cat /etc/version 2>/dev/null)"

for f in src/netcontrol.inc src/netcontrol_api.php src/services_netcontrol.php; do
	[ -f "$f" ] || fail "missing $f - run this from the repository root"
done

# ---------------------------------------------------------------- remove
if [ "$1" = "remove" ]; then
	say "removing netcontrol"
	crontab -l 2>/dev/null | grep -v netcontrol_tick | crontab - || true
	pfctl -a 'userrules/netcontrol' -F rules 2>/dev/null || true
	pfctl -a 'userrules/ncdns'      -F rules 2>/dev/null || true
	pfctl -a 'natearly/ncdns'       -F nat   2>/dev/null || true
	pfctl -t nc_blocked -T flush 2>/dev/null || true
	rm -f "$PKG/netcontrol.inc" "$PKG/netcontrol.xml" \
	      "$WWW/netcontrol_api.php" "$WWW/services_netcontrol.php" \
	      "$SBIN/netcontrol_tick.php" "$SBIN/netcontrol_selftest.php" \
	      "$PRIV/netcontrol.priv.inc" /var/unbound/netcontrol.conf
	# the resolver include has to go too, or unbound will not start
	if grep -q 'netcontrol.conf' /var/unbound/unbound.conf 2>/dev/null; then
		sed -i.bak '/netcontrol\.conf/d' /var/unbound/unbound.conf
		/usr/local/sbin/pfSsh.php playback svc restart unbound >/dev/null 2>&1 || true
	fi
	say "removed. The stored rules in /var/db/netcontrol.json were kept."
	exit 0
fi

# ---------------------------------------------------------------- backup
mkdir -p "$BACKUP"
for f in "$PKG/netcontrol.inc" "$WWW/netcontrol_api.php" \
         "$WWW/services_netcontrol.php" "$PKG/netcontrol.conf.php"; do
	[ -f "$f" ] && cp "$f" "$BACKUP/" 2>/dev/null || true
done
cp /cf/conf/config.xml "$BACKUP/config.xml" 2>/dev/null || true
say "backup: $BACKUP"

# ---------------------------------------------------------------- install
install -m 0644 src/netcontrol.inc           "$PKG/netcontrol.inc"
install -m 0644 src/netcontrol_api.php       "$WWW/netcontrol_api.php"
install -m 0644 src/services_netcontrol.php  "$WWW/services_netcontrol.php"
install -m 0755 src/netcontrol_tick.php      "$SBIN/netcontrol_tick.php"
install -m 0755 src/netcontrol_selftest.php  "$SBIN/netcontrol_selftest.php"
[ -f src/netcontrol.xml ]      && install -m 0644 src/netcontrol.xml "$PKG/netcontrol.xml"
[ -f src/netcontrol.priv.inc ] && install -m 0644 src/netcontrol.priv.inc "$PRIV/netcontrol.priv.inc"

# the override file is never overwritten: it holds this site's own settings
if [ ! -f "$PKG/netcontrol.conf.php" ]; then
	install -m 0644 src/netcontrol.conf.php.example "$PKG/netcontrol.conf.php"
	say "created $PKG/netcontrol.conf.php - review the protected addresses"
else
	say "kept the existing netcontrol.conf.php"
fi

php -l "$PKG/netcontrol.inc" >/dev/null || fail "netcontrol.inc has a syntax error"
php -l "$WWW/netcontrol_api.php" >/dev/null || fail "netcontrol_api.php has a syntax error"
say "files installed"

# ---------------------------------------------------------------- cron
if ! crontab -l 2>/dev/null | grep -q netcontrol_tick; then
	( crontab -l 2>/dev/null; \
	  echo "* * * * * /usr/local/bin/php -f $SBIN/netcontrol_tick.php >/dev/null 2>&1" \
	) | crontab -
	say "cron job added (runs every minute)"
else
	say "cron job already present"
fi

# ---------------------------------------------------------------- rules
php -r '
require_once("/usr/local/pkg/netcontrol.inc");
printf("  LAN: %s (%s)  WAN: %s\n", NC_LAN_IF, NC_LAN_IP, NC_WAN_IF);
printf("  protected: %s\n", implode(", ", nc_protected_ips()));
$z = nc_cfg("cp_zone");
printf("  captive portal zone: %s\n", $z ?: "(none configured)");
nc_install_anchor();
nc_apply();
echo "  pf rules installed\n";
' || fail "could not install the pf rules"

# ---------------------------------------------------------------- verify
echo
say "running the self test"
php -f "$SBIN/netcontrol_selftest.php" | tail -4

echo
say "Open  Services > Network Control  in the pfSense menu."
say "To undo:  sh install.sh remove"
