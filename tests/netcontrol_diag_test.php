<?php
/* Runs anywhere with PHP, no firewall needed:  php tests/netcontrol_diag_test.php */
define('NC_PIPE_BASE', 300);
require __DIR__ . '/../src/netcontrol_diag.inc';

$fail = 0;
function check($ok, $what) { global $fail; echo ($ok ? "  ok    " : "  FAIL  ") . $what . "\n"; if (!$ok) { $fail++; } }
function has($r, $needle) { return strpos(json_encode($r['findings'], JSON_UNESCAPED_UNICODE), $needle) !== false; }

$now = 1800000000;
$mac = 'aa:bb:cc:dd:ee:01';

/* ---- identity is remembered and never erased by an empty value */
$store = array('devices' => array());
$names = array($mac => array('host' => 'ALLAM-PC', 'descr' => ''));
$cp    = array('by_mac' => array($mac => array('username' => 'allam', 'ip' => '192.168.1.50')), 'by_ip' => array());
$arp   = array($mac => array('ip' => '192.168.1.50'));
check(nc_ident_record($store, $names, $cp, $arp, $now), 'first sighting is stored');
check($store['ident'][$mac]['host'] === 'ALLAM-PC' && $store['ident'][$mac]['user'] === 'allam', 'host and user kept');
check(!nc_ident_record($store, $names, $cp, $arp, $now + 60), 'an unchanged minute rewrites nothing');
nc_ident_record($store, array(), array('by_mac' => array(), 'by_ip' => array()), array(), $now + 120);
check($store['ident'][$mac]['user'] === 'allam', 'a logout does not erase the last user');

/* ---- lookup: live first, memory second, by IP last */
$live = array($mac => array('mac' => $mac, 'ip' => '192.168.1.50', 'hostname' => 'ALLAM-PC', 'cp_user' => 'allam', 'online' => true));
$w = nc_ident_for($mac, '', $store, $live);
check($w['user'] === 'allam' && $w['live'] === true && $w['host'] === 'ALLAM-PC', 'online device: live user');
$w = nc_ident_for($mac, '', $store, array());
check($w['user'] === 'allam' && $w['live'] === false, 'offline device: remembered user, marked not live');
$w = nc_ident_for('', '192.168.1.50', $store, array());
check($w['mac'] === $mac && $w['host'] === 'ALLAM-PC', 'found by IP from memory');
$store['devices'][$mac]['name'] = 'لابتوب علام';
check(nc_ident_for($mac, '', $store, array())['name'] === 'لابتوب علام', 'an admin-typed name wins over the host name');

/* ---- log lines */
$lines = array(
	"2026-11-16 10:15:00  system       -                POLICY BLOCK $mac (192.168.1.50) — mobile_unknown دائم",
	"2026-11-16 10:27:57  system       -                DNS-ENFORCE on (12 DoH endpoints blocked)",
	"2026-11-16 09:53:00  admin        192.168.1.5      IP-MOVED $mac: 192.168.1.240 -> 192.168.1.144",
);
$e = nc_log_enrich($lines, $store, array());
check($e[0]['host'] === 'ALLAM-PC' && $e[0]['user'] === 'allam', 'POLICY line names the device');
check($e[1] === null, 'a line about no device gets nothing');
check($e[2]['host'] === 'ALLAM-PC', "the admin's own IP is not read as a device");

/* ---- search */
$dir = array(
	array('mac' => $mac, 'ip' => '192.168.1.50', 'name' => 'لابتوب علام', 'host' => 'ALLAM-PC', 'user' => 'allam', 'vendor' => 'Dell', 'online' => true),
	array('mac' => 'aa:bb:cc:dd:ee:02', 'ip' => '192.168.1.51', 'name' => '', 'host' => 'HR-PC', 'user' => 'sara', 'vendor' => 'HP', 'online' => false),
);
check(nc_diag_search('allam', $dir)[0]['mac'] === $mac, 'search by user or host');
check(nc_diag_search('علام', $dir)[0]['mac'] === $mac, 'search by Arabic name');
check(nc_diag_search('192.168.1.51', $dir)[0]['mac'] === 'aa:bb:cc:dd:ee:02', 'search by exact IP');
check(count(nc_diag_search('192.168.1.5', $dir)) === 2, 'partial IP lists both');
check(nc_diag_search('AA:BB:CC:DD:EE:02', $dir)[0]['ip'] === '192.168.1.51', 'search by MAC, any case');
check(nc_diag_search('ddee01', $dir)[0]['mac'] === $mac, 'search by partial MAC without separators');
check(nc_diag_search('nobody', $dir) === array(), 'no match is empty');

/* ---- diagnosis */
function ctx($over = array()) {
	global $now, $store;
	return array_merge(array('now' => $now, 'store' => $store, 'pf' => array(), 'anchor' => '', 'pipes' => '',
		'res' => array(), 'portal' => false, 'global' => array('down' => 5, 'up' => 2), 'dns' => false,
		'policy' => array('enabled' => false), 'log' => array(), 'logins' => array(), 'owners' => array(),
		'today' => array()), $over);
}
function dev($over = array()) {
	global $mac, $now;
	return array_merge(array('mac' => $mac, 'ip' => '192.168.1.50', 'name' => 'x', 'hostname' => 'ALLAM-PC', 'cp_user' => 'allam',
		'vendor' => '', 'type' => 'pc', 'type_name' => 'كمبيوتر', 'online' => true, 'blocked' => false, 'rate' => 0.1,
		'last_seen' => $now, 'random' => false, 'fp' => '', 'reserved' => false, 'protected' => false,
		'moved' => false), $over);
}
function sevs($r) { return array_column($r['findings'], 'sev'); }

$r = nc_diagnose(dev(), ctx());
check($r['verdict'] === 'ok' && sevs($r)[0] === 'OK', 'a healthy device reports no problems');

$st2 = $store; $st2['devices'][$mac] = array('blocked' => true, 'by_policy' => true);
$blocklog = array("2026-11-16 10:12:01  system       -                POLICY BLOCK $mac (192.168.1.50) — mobile_unknown");
$r = nc_diagnose(dev(array('blocked' => true)), ctx(array('store' => $st2, 'log' => $blocklog)));
check($r['verdict'] === 'bad' && in_array('HIGH', sevs($r)), 'blocked device is flagged');
check(has($r, 'بسياسة'), 'says the policy blocked it');
check(has($r, '2026-11-16 10:12:01'), 'says when');
check(has($r, 'مش متنفّذ'), 'online and not in the pf table: not enforced');
$r = nc_diagnose(dev(array('blocked' => true)), ctx(array('store' => $st2, 'pf' => array('192.168.1.50' => 0))));
check(!has($r, 'مش متنفّذ'), 'in the pf table: no enforcement warning');

$r = nc_diagnose(dev(array('online' => false, 'last_seen' => $now - 7200)), ctx());
check(in_array('MED', sevs($r)) && has($r, 'ساعة'), 'offline device says how long');

$r = nc_diagnose(dev(array('cp_user' => '')), ctx(array('portal' => true)));
check(has($r, 'كابتيف'), 'portal on, no session: flagged');
$r = nc_diagnose(dev(array('cp_user' => '')), ctx(array('portal' => false)));
check(!has($r, 'كابتيف'), 'portal off: not flagged');

$r = nc_diagnose(dev(array('random' => true, 'hostname' => '')), ctx());
check(in_array('MED', sevs($r)), 'random MAC without a fingerprint: flagged');
$r = nc_diagnose(dev(array('random' => true, 'fp' => 'iphone-x')), ctx());
check(!in_array('MED', sevs($r)), 'random MAC with a fingerprint: informational only');

$r = nc_diagnose(dev(), ctx(array('owners' => array('192.168.1.50' => array($mac, 'aa:bb:cc:dd:ee:09')))));
check(in_array('HIGH', sevs($r)), 'two devices on one IP: flagged');

$st3 = $store; $st3['devices'][$mac] = array('limit' => 1);
$r = nc_diagnose(dev(), ctx(array('store' => $st3)));
check(in_array('HIGH', sevs($r)), 'a limit with no pipe and no rule is flagged');
$pipe = 300 + (crc32($mac) % 500);
$r = nc_diagnose(dev(), ctx(array('store' => $st3, 'pipes' => sprintf("%05d: 1.000 Mbit/s\n", $pipe), 'anchor' => 'pass in from 192.168.1.50')));
check(!in_array('HIGH', sevs($r)), 'a limit with pipe and rule is only informational');

$r = nc_diagnose(dev(), ctx(array('global' => array('down' => 1, 'up' => 1))));
check(in_array('MED', sevs($r)), 'a 1 Mb global limit is called out');

echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit($fail ? 1 : 0);
