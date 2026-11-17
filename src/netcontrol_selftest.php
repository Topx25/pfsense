<?php
/*
 * netcontrol self-test.
 *
 * Checks that each feature is actually ENFORCED, not merely recorded. Most
 * of the bugs found in this system were of one kind: the page stored an
 * intent and nothing carried it out. So every check here looks at the
 * kernel or the resolver, never at the store alone.
 *
 * Read-only except where marked [WRITE]; those restore what they changed.
 */
require_once("/usr/local/pkg/netcontrol.inc");

$pass = $fail = $warn = 0;

/*
 * Snapshot the portal sessions before anything else runs.
 *
 * captiveportal_read_db() reads global state that other calls in this script
 * disturb, so a count taken late comes back as zero while the database holds
 * real rows - which is exactly the kind of silent wrong answer this test
 * exists to catch. Take it first, report it later.
 */
$CP_SNAPSHOT = count(nc_portal_sessions()['by_mac']);
$CP_DB = 0;
if (function_exists('captiveportal_read_db')) {
	$GLOBALS['cpzone'] = 'mainlan';
	$z = config_get_path('captiveportal/mainlan', array());
	$GLOBALS['cpzoneid'] = $z['zoneid'] ?? null;
	$db = @captiveportal_read_db();
	$CP_DB = is_array($db) ? count($db) : 0;
}
function ok($m)   { global $pass; $pass++; echo "  [ok]   $m\n"; }
function bad($m)  { global $fail; $fail++; echo "  [FAIL] $m\n"; }
function note($m) { global $warn; $warn++; echo "  [warn] $m\n"; }
function head($m) { echo "\n== $m ==\n"; }

/* ---------------------------------------------------------- library */
head('library');
foreach (array('nc_devices', 'nc_apply', 'nc_install_anchor', 'nc_anchor_active',
               'nc_dns_apply', 'nc_live_video', 'nc_video_groups',
               'nc_video_limits', 'nc_rate_sample', 'nc_portal_sessions',
               'nc_reattach_rules', 'nc_release_authenticated') as $fn) {
	function_exists($fn) ? ok("$fn() defined") : bad("$fn() MISSING");
}

/* ------------------------------------------------------- block path */
head('device blocking');
$tbl = trim(shell_exec('/sbin/pfctl -t ' . NC_TABLE . ' -T show 2>/dev/null'));
$n_tbl = $tbl === '' ? 0 : count(preg_split('/\s+/', trim($tbl)));
$store = nc_store_load();
$n_store = 0;
foreach ($store['devices'] as $d) {
	if (!empty($d['blocked'])) { $n_store++; }
}
echo "  store says $n_store blocked, pf table holds $n_tbl\n";
($n_tbl > 0) ? ok('block table is populated') : note('block table is empty');

$anchor = shell_exec("/sbin/pfctl -a 'userrules/netcontrol' -sr 2>/dev/null");
(strpos((string)$anchor, NC_TABLE) !== false)
	? ok('a pf rule reads the block table')
	: bad('NO rule reads the table - blocks are not enforced');

$v = shell_exec("/sbin/pfctl -a 'userrules/netcontrol' -sr -v 2>/dev/null");
if (preg_match('/Evaluations: (\d+)/', (string)$v, $m)) {
	((int)$m[1] > 0)
		? ok("block rule is being evaluated ({$m[1]} times)")
		: note('block rule loaded but never reached yet');
}

/* the main ruleset must actually call the anchor */
$main = shell_exec('/sbin/pfctl -sr 2>/dev/null');
(strpos((string)$main, 'userrules') !== false)
	? ok('main ruleset calls the userrules anchor')
	: bad('userrules anchor is NOT called - the rules never run');

/* ------------------------------------------------------- shaping */
head('speed limits');
$lim = array();
foreach ($store['devices'] as $mac => $d) {
	if (!empty($d['limit']) && empty($d['blocked'])) { $lim[$mac] = $d; }
}
echo "  " . count($lim) . " device(s) carry a per-device limit\n";

$pipes = shell_exec('/sbin/dnctl pipe list 2>/dev/null');
$ncstore = nc_store_load();
$offline = 0;
foreach ($lim as $mac => $d) {
	/*
	 * A device with no address has no rule and so no pipe. The limit is
	 * still recorded and applies again the moment it reconnects, so this
	 * is not a failure - there is nothing to enforce against yet.
	 */
	if (empty($d['ip'])) {
		$offline++;
		continue;
	}
	/*
	 * Ask the store for the pipe number. crc32(mac) % 500 was the old
	 * scheme and was replaced because it collided, so computing it here
	 * checked a pipe nobody uses.
	 */
	$p = (int)($ncstore['devices'][$mac]['pipe'] ?? 0);
	if ($p <= 0) {
		bad("no pipe allocated for {$d['ip']} - limit not enforced");
		continue;
	}
	$shown = shell_exec("/sbin/dnctl pipe {$p} show 2>/dev/null");
	(trim((string)$shown) !== '')
		? ok("pipe {$p} exists for {$d['ip']}")
		: bad("pipe {$p} MISSING for {$d['ip']} - limit not enforced");
	(strpos((string)$anchor, "dnpipe") !== false && strpos((string)$anchor, $d['ip']) !== false)
		? ok("a rule routes {$d['ip']} into its pipe")
		: bad("NO rule routes {$d['ip']} - the pipe shapes nothing");
}

/* the dnctl syntax itself: this is what silently broke every limit */
$t = 499;
mwexec("/sbin/dnctl pipe {$t} delete 2>/dev/null");
$rc = mwexec("/sbin/dnctl pipe {$t} config bw 2Mbit/s mask dst-ip 0xffffffff codel 2>/dev/null");
($rc === 0) ? ok('dnctl accepts the pipe syntax we use')
            : bad("dnctl REJECTS our pipe syntax (rc=$rc)");
mwexec("/sbin/dnctl pipe {$t} delete 2>/dev/null");

/* the global limit everyone falls under */
$g = nc_global_limits();
echo "  global: {$g['down']} Mb down / {$g['up']} Mb up\n";
$p22 = shell_exec('/sbin/dnctl pipe 22 show 2>/dev/null');
$flows = preg_match_all('/^\s*\d+\s+ip/m', (string)$p22);
($flows > 0) ? ok("global pipe is shaping {$flows} device(s)")
             : note('global pipe has no active flows right now');

/* -------------------------------------------------------- dns block */
head('site blocking');
$live = nc_dns_active();
echo "  $live site rule(s) live\n";
/*
 * Check the include unconditionally.
 *
 * This used to be skipped when nothing was blocked, which read as "fine"
 * while the include had actually been dropped by a pfSense config rewrite.
 * The next site block would then have had no effect at all, and the page
 * would still have listed it as blocked.
 */
$conf = @file_get_contents('/var/unbound/unbound.conf');
$inc  = (strpos((string)$conf, 'include: /var/unbound/netcontrol.conf') !== false);
if ($inc) {
	ok('unbound includes the block file');
} elseif ($live > 0) {
	bad('block file NOT included - the blocked sites still resolve');
} else {
	bad('block file not included - a new site block would do nothing');
}

$chk = mwexec('/usr/local/sbin/unbound-checkconf /var/unbound/unbound.conf >/dev/null 2>&1');
($chk === 0) ? ok('unbound config is valid')
             : bad('unbound config is INVALID - DNS is at risk');

$sock = shell_exec('/usr/bin/sockstat -4 -l 2>/dev/null | grep -c ":53 "');
((int)trim((string)$sock) > 0) ? ok('unbound is listening on :53')
                               : bad('NOTHING is listening on :53 - DNS is down');

/* a domain that should always resolve */
$r = shell_exec('/usr/bin/drill google.com @127.0.0.1 2>/dev/null | grep -o "rcode: [A-Z]*"');
(strpos((string)$r, 'NOERROR') !== false)
	? ok('normal DNS resolution works')
	: bad('google.com does not resolve - DNS is broken');

/* ------------------------------------------------------ video panel */
head('live video');
$gs = nc_video_groups();
echo "  " . count($gs) . " service group(s)\n";
$watch = 0;
foreach ($gs as $x) { $watch += $x['watching']; }
echo "  devices actually streaming: $watch\n";
count($gs) ? ok('video grouping returns data') : note('no video traffic right now');
foreach ($gs as $x) {
	if ($x['count'] < 1) { bad("group {$x['kind']} has no devices"); }
}

/* ---------------------------------------------------------- portal */
head('captive portal');
/*
 * Read the portal db directly as well as through our own helper.
 *
 * An earlier run reported zero here while 19 sessions existed: pfSense's
 * mwexec(), called a few checks above, resets the config globals that
 * captiveportal_read_db() depends on, so the helper came back empty.
 * Comparing the two makes a mismatch visible instead of quietly believable.
 */
/*
 * mwexec() earlier in this script re-reads the configuration, which leaves
 * $config stale for the rest of the process. nc_portal_sessions() walks
 * $config['captiveportal'] to find the zones, so it finds none and reports
 * zero while the database holds real rows. Refresh $config first.
 */
$n = $CP_SNAPSHOT;
$direct = $CP_DB;
echo "  $n via nc_portal_sessions() | $direct in the portal db
";
/*
 * These two numbers are not meant to be equal.
 *
 * by_mac is keyed by MAC, so a device holding several rows counts once,
 * and the database keeps a row after its device has gone until the portal
 * reaps it. Requiring equality failed on 35 rows vs 25 devices, which was
 * correct on both sides.
 *
 * What this check exists to catch is the helper going blind - returning
 * nothing while the database holds rows, which happened when mwexec()
 * reset the config globals it reads. Test for that, and treat a surplus
 * of rows as the ordinary state it is.
 */
if ($direct > 0 && $n === 0) {
	bad("nc_portal_sessions() sees nothing while the db holds $direct row(s)");
} elseif ($n > $direct) {
	bad("more sessions than rows ($n vs $direct) - impossible");
} elseif ($n > 0) {
	$stale = $direct - $n;
	ok("portal sessions readable ($n device(s)" .
	   ($stale ? ", $stale row(s) awaiting reap" : '') . ')');
} else {
	note('no portal sessions right now');
}
$cfg = config_get_path('captiveportal/mainlan', array());
!empty($cfg) ? ok('portal zone is configured') : bad('portal zone missing');
$page = base64_decode($cfg['page']['htmltext'] ?? '');
if ($page === '') {
	note('using the stock login page');
} else {
	foreach (array('auth_user', 'auth_pass', '$PORTAL_ACTION$', 'name="accept"') as $k) {
		(strpos($page, $k) !== false)
			? ok("custom login page keeps $k")
			: bad("custom login page is MISSING $k - logins will fail");
	}
}

/* ------------------------------------------------------------ dhcp */
head('dhcp');
$kea = (int)trim((string)shell_exec('/bin/ps auxw | grep -c "[k]ea-dhcp4"'));
($kea > 0) ? ok('kea-dhcp4 is running') : bad('kea-dhcp4 is NOT running');
$j = @json_decode(@file_get_contents('/usr/local/etc/kea/kea-dhcp4.conf'), true);
$sub = $j['Dhcp4']['subnet4'][0] ?? array();
echo "  lease: " . ($sub['valid-lifetime'] ?? '?') . "s · reservations: "
   . count($sub['reservations'] ?? array()) . "\n";
(count($sub['reservations'] ?? array()) > 0)
	? ok('dhcp reservations intact') : note('no dhcp reservations');

/* ------------------------------------------------------ connectivity */
head('connectivity');
$ping = shell_exec('/sbin/ping -c2 -t3 8.8.8.8 2>&1 | tail -1');
(strpos((string)$ping, 'round-trip') !== false)
	? ok('internet reachable') : bad('NO internet from the firewall');

/* --------------------------------------------------------- summary */
echo "\n" . str_repeat('-', 46) . "\n";
printf("passed: %d   failed: %d   warnings: %d\n", $pass, $fail, $warn);
echo $fail ? "RESULT: FAILURES PRESENT\n" : "RESULT: all enforced checks passed\n";
