<?php
/*
 * Set the WAN MTU to what the line actually carries, and clamp TCP MSS.
 *
 * The interface is configured for 1500 but the path only passes 1492 -
 * measured directly, twice, in separate sessions: a 1464-byte payload
 * (MTU 1492) gets through and 1465 (MTU 1493) does not. That 8-byte gap
 * is PPPoE overhead in the modem upstream.
 *
 * Why it breaks QUIC and not ordinary HTTPS: TCP endpoints negotiate MSS
 * during the handshake, so segments usually end up small enough by
 * accident. QUIC sends fixed ~1200-1350 byte UDP datagrams with DF set
 * and does not negotiate, so anything over the real path is dropped in
 * silence - which is exactly the ERR_QUIC_PROTOCOL_ERROR being seen on
 * Google services, which use QUIC most heavily.
 *
 * Two changes, both on the WAN interface only:
 *   mtu = 1492   so the firewall itself stops emitting oversized frames
 *   mss = 1452   (1492 - 20 IP - 20 TCP) so TCP sessions through it are
 *                clamped rather than relying on a lucky negotiation
 *
 * The LAN side is left at 1500 on purpose: it is a local segment that
 * genuinely carries 1500, and lowering it would cost throughput inside
 * the network for no reason.
 *
 * Applying this restarts the interface. Expect a few seconds of outage.
 *
 * Run with no arguments to see what would change; pass --apply to write.
 */

require_once("/etc/inc/config.inc");
require_once("/etc/inc/util.inc");
require_once("/etc/inc/interfaces.inc");
require_once("/etc/inc/filter.inc");

$apply = in_array('--apply', $argv, true);
global $config;

$MTU = 1492;
$MSS = 1452;

/* Which interface actually carries the default route? The one labelled
   'wan' on this box has no address; traffic leaves by opt2. Ask the
   routing table rather than trusting the label. */
/* the default route prints as "default" on some builds and "0.0.0.0" on
   others; the interface is the last field in both */
$gw = trim((string)shell_exec(
	"/usr/bin/netstat -rn -f inet 2>/dev/null | " .
	"/usr/bin/awk '$1 == \"default\" || $1 == \"0.0.0.0\" {print $4; exit}'"));
printf("default route leaves by: %s\n", $gw !== '' ? $gw : '(could not determine)');

$target = null;
foreach (($config['interfaces'] ?? array()) as $k => $i) {
	if (($i['if'] ?? '') === $gw) {
		$target = $k;
		break;
	}
}
if ($target === null) {
	echo "could not match that interface to a pfSense interface - stopping\n";
	exit(1);
}
printf("that is pfSense interface '%s'\n\n", $target);

$cur_mtu = $config['interfaces'][$target]['mtu'] ?? '';
$cur_mss = $config['interfaces'][$target]['mss'] ?? '';
printf("current: mtu=%s  mss=%s\n", $cur_mtu !== '' ? $cur_mtu : '(default 1500)',
	$cur_mss !== '' ? $cur_mss : '(none)');
printf("wanted : mtu=%d  mss=%d\n", $MTU, $MSS);

/* Confirm the measurement here rather than trusting the plan. */
echo "\nverifying the path before changing anything:\n";
$ok1464 = (trim((string)shell_exec("/sbin/ping -D -s 1464 -c1 -W2 8.8.8.8 >/dev/null 2>&1 && echo y")) === 'y');
$ok1465 = (trim((string)shell_exec("/sbin/ping -D -s 1465 -c1 -W2 8.8.8.8 >/dev/null 2>&1 && echo y")) === 'y');
printf("  payload 1464 (MTU 1492): %s\n", $ok1464 ? 'OK' : 'FAIL');
printf("  payload 1465 (MTU 1493): %s\n", $ok1465 ? 'OK' : 'FAIL');

if (!$ok1464 || $ok1465) {
	echo "\nthe path does not match the 1492 measurement any more.\n";
	echo "not changing anything - re-measure before acting.\n";
	exit(1);
}
echo "  => confirmed: the line carries 1492, not 1500\n";

if (!$apply) {
	echo "\n(dry run - pass --apply to make the change; the interface will restart)\n";
	exit(0);
}

/* keep a copy that can be put back by hand */
$bak = '/root/config.xml.mtu-bak-' . date('Hi');
@copy('/conf/config.xml', $bak);
printf("\nbacked up /conf/config.xml to %s\n", $bak);

$config['interfaces'][$target]['mtu'] = (string)$MTU;
$config['interfaces'][$target]['mss'] = (string)$MSS;
write_config("netcontrol: WAN MTU 1492 / MSS 1452 - the line does not carry 1500");
echo "config written\n";

interface_configure($target, true);
echo "interface reconfigured\n";
filter_configure();
echo "filter reloaded\n";

sleep(6);

echo "\n=== after ===\n";
echo trim((string)shell_exec("/sbin/ifconfig " . escapeshellarg($gw) . " | grep mtu")) . "\n";
$still = (trim((string)shell_exec("/sbin/ping -c2 -W2 8.8.8.8 >/dev/null 2>&1 && echo y")) === 'y');
printf("internet still reachable: %s\n", $still ? 'yes' : 'NO - check immediately');
