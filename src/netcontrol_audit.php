<?php
/*
 * Project audit: look for the failure mode this system keeps producing -
 * something recorded in the store that nothing actually enforces.
 */
require_once("/usr/local/pkg/netcontrol.inc");

$issues = array();
function issue($sev, $msg) { global $issues; $issues[] = array($sev, $msg); }

echo "=== store vs kernel ===\n";
$store = nc_store_load();

/* 1. every blocked device should be in the pf table */
$tbl = array_filter(preg_split('/\s+/',
	trim((string)shell_exec('/sbin/pfctl -t ' . NC_TABLE . ' -T show 2>/dev/null'))));
$tbl = array_flip($tbl);
/*
 * A blocked device that is offline is deliberately NOT in the table: its
 * address goes back to the DHCP pool, and holding it would block whoever
 * is handed it next. Only a device that is present and unenforced is a
 * fault.
 */
$arp  = nc_arp_table();
$miss = 0; $withheld = 0;
foreach ($store['devices'] as $mac => $d) {
	if (empty($d['blocked'])) continue;
	$ip = $d['ip'] ?? '';
	if (!$ip) continue;
	if (isset($tbl[$ip])) continue;
	if (isset($arp[$mac])) { $miss++; }      /* present but not enforced */
	else { $withheld++; }                    /* absent - correct */
}
if ($withheld) {
	printf("  %d block(s) withheld while the device is offline (correct)
", $withheld);
}
printf("  blocked in store: %d · in pf table: %d · missing: %d\n",
	count(array_filter($store['devices'], function($d){ return !empty($d['blocked']); })),
	count($tbl), $miss);
if ($miss) issue('HIGH', "$miss blocked device(s) are not in the pf table");

/* 2. every limited device needs a pipe AND a rule */
$anchor = (string)shell_exec("/sbin/pfctl -a 'userrules/netcontrol' -sr 2>/dev/null");
$nopipe = $norule = $offline = 0;
foreach ($store['devices'] as $mac => $d) {
	if (empty($d['limit']) || !empty($d['blocked'])) continue;
	/*
	 * No address means no rule and therefore no pipe. The limit still
	 * stands and applies again on reconnect, so this is not a fault.
	 */
	if (empty($d['ip'])) { $offline++; continue; }
	/* the number the allocator assigned, not the retired crc32 scheme */
	$p = (int)($d['pipe'] ?? 0);
	if ($p <= 0 ||
	    trim((string)shell_exec("/sbin/dnctl pipe $p show 2>/dev/null")) === '') {
		$nopipe++;
	}
	if (strpos($anchor, $d['ip']) === false) $norule++;
}
printf("  limited devices without a pipe: %d · without a rule: %d · offline (skipped): %d\n",
	$nopipe, $norule, $offline);
if ($nopipe) issue('HIGH', "$nopipe limit(s) have no dummynet pipe");
if ($norule) issue('HIGH', "$norule limit(s) have a pipe but no rule feeding it");

/* 3. site blocks vs the resolver */
$live = nc_dns_active();
$zones = (int)trim((string)shell_exec(
	"grep -c local-zone /var/unbound/netcontrol.conf 2>/dev/null"));
$inc = function_exists('nc_dns_included') ? nc_dns_included() : false;
printf("  site blocks: %d live · %d zones written · include %s\n",
	$live, $zones, $inc ? 'present' : 'MISSING');
if ($live != $zones) issue('HIGH', "store says $live site blocks, resolver has $zones");
if (!$inc) issue('HIGH', 'the resolver does not include our block file');

/* 4. DNS enforcement */
$nat = (int)trim((string)shell_exec("/sbin/pfctl -a 'natearly/ncdns' -s nat 2>/dev/null | wc -l"));
$doh = (int)trim((string)shell_exec("/sbin/pfctl -a 'userrules/ncdns' -sr 2>/dev/null | wc -l"));
printf("  dns redirect rules: %d · doh block rules: %d\n", $nat, $doh);
if ($nat < 1) issue('MED', 'the DNS redirect is not loaded');
if ($doh < 1) issue('MED', 'the DoH block is not loaded');

/* 5. the cron job that drives all of the above */
/*
 * pfSense keeps cron in config.xml and writes /etc/crontab from it; the
 * root user crontab is not used. Checking `crontab -l` reported the job
 * missing while it was running fine - so check both, and prove it by
 * looking for recent automatic entries in our own log.
 */
$inxml = 0;
foreach ((array)config_get_path('cron/item', array()) as $c) {
	if (strpos($c['command'] ?? '', 'netcontrol_tick') !== false) $inxml++;
}
$insys = (int)trim((string)shell_exec('grep -c netcontrol_tick /etc/crontab 2>/dev/null'));
printf("  cron: %d in config.xml · %d in /etc/crontab
", $inxml, $insys);
if (!$inxml && !$insys) {
	issue('HIGH', 'the cron job is missing - nothing self-heals');
} elseif (!$inxml) {
	issue('MED', 'cron is in /etc/crontab only - a config restore will drop it');
}

/*
 * Did it actually run recently?
 *
 * Not from the log: the tick only writes a line when it changes something,
 * so a quiet half hour read as "has not run for 31 minutes" while it was
 * firing every minute. The rate snapshot is rewritten on every pass, so
 * its timestamp is the honest signal.
 */
$stamp = '/var/db/netcontrol_rate.json';
if (is_readable($stamp)) {
	$age = (time() - filemtime($stamp)) / 60;
	printf("  last tick: %.0f minute(s) ago
", $age);
	if ($age > 5) {
		issue('HIGH', sprintf('the tick has not run for %.0f minutes', $age));
	}
} else {
	issue('MED', 'no tick timestamp found - has it ever run?');
}

echo "\n=== data integrity ===\n";
/* usage history must be MAC-keyed, or totals mix devices */
$u = @json_decode(@file_get_contents('/var/db/netcontrol_usage.json'), true);
unset($u['_last']);
$day = date('Y-m-d');
$k = $u[$day] ?? array();
$first = $k ? array_key_first($k) : '';
$ismac = (bool)preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/i', $first);
printf("  usage rows: %d · keyed by %s\n", count($k), $ismac ? 'MAC' : 'IP');
if ($k && !$ismac) issue('HIGH', 'usage history is IP-keyed - totals mix devices');

/* protected addresses must never be in the block table */
$bad = 0;
foreach (nc_protected_ips() as $ip) {
	if (isset($tbl[$ip])) { $bad++; printf("  PROTECTED %s IS BLOCKED\n", $ip); }
}
if ($bad) issue('CRITICAL', "$bad protected address(es) are in the block table");
else echo "  protected addresses: none blocked\n";

/* a blocked device still moving data means enforcement is failing */
$leak = 0;
foreach (nc_devices() as $d) {
	if (!empty($d['blocked']) && ($d['rate'] ?? 0) > 0.05) $leak++;
}
printf("  blocked devices still passing traffic: %d\n", $leak);
if ($leak) issue('HIGH', "$leak blocked device(s) are still moving data");

echo "\n=== disk ===\n";
foreach (array('/var/db/netcontrol.json', '/var/db/netcontrol_usage.json',
               '/var/db/netcontrol_dns.json', '/var/log/netctl.log') as $f) {
	printf("  %-34s %s\n", $f,
		is_readable($f) ? number_format(filesize($f)) . ' bytes' : 'missing');
}
echo trim((string)shell_exec("/bin/df -h / /var 2>/dev/null | tail -2")) . "\n";

echo "\n" . str_repeat('-', 52) . "\n";
if (!$issues) {
	echo "No issues found.\n";
} else {
	foreach ($issues as $i) printf("%-9s %s\n", $i[0], $i[1]);
}
