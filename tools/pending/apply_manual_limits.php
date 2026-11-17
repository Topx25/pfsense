<?php
/*
 * Hand control of speed limits back to the operator.
 *
 * Asked for: no automatic per-device limits at all; every limit is to be
 * set by hand from the control panel.
 *
 * Three things apply limits without anyone asking, and each is turned off
 * in the way that releases what it previously did:
 *
 *   1. NC_MOBILE_LIMIT - caps every phone. Setting it to 0 makes
 *      nc_mobile_limits() release exactly the devices it limited (they
 *      carry by_mobile) and leave every hand-set limit alone.
 *
 *   2. The new-device policy - acts on each device that joins. Only its
 *      "limit" action is disabled here; if the policy is set to block or
 *      log, that stays as it is, because blocking is not a speed limit
 *      and was not part of the request.
 *
 *   3. Limits already in the store that were applied by one of those two
 *      (by_mobile / by_policy). Those are cleared. A limit set by hand,
 *      or by a video rule the operator created, is left untouched.
 *
 * The global per-device pipe (10 Mb) is NOT removed: it is the ceiling
 * that stops one machine taking the whole line, not a per-device rule,
 * and nothing in the panel sets it per device.
 *
 * Run without arguments to see what would change. Pass --apply to write.
 */

require_once("/etc/inc/config.inc");
require_once("/usr/local/pkg/netcontrol.inc");

$apply = in_array('--apply', $argv, true);
$conf  = '/usr/local/pkg/netcontrol.conf.php';

echo "=== 1. the standing phone cap ===\n";
$cur = defined('NC_MOBILE_LIMIT') ? (int)constant('NC_MOBILE_LIMIT') : 0;
printf("  NC_MOBILE_LIMIT is %d%s\n", $cur,
	$cur > 0 ? ' Mb  -> will be set to 0 (off)' : ' (already off)');

echo "\n=== 2. the new-device policy ===\n";
$pol = nc_policy_get();
printf("  enabled=%s  action=%s  limit=%s Mb\n",
	!empty($pol['enabled']) ? 'yes' : 'no',
	$pol['action'] ?? '-', $pol['limit_mb'] ?? '-');
$policy_limits = (!empty($pol['enabled']) && ($pol['action'] ?? '') === 'limit');
echo $policy_limits
	? "  -> it applies limits; its action will be switched to 'allow' (watch only)\n"
	: "  -> it does not apply limits; left exactly as it is\n";

echo "\n=== 3. limits already in place ===\n";
$store = nc_store_load();
$byMobile = $byPolicy = $byVideo = $byHand = array();
foreach ($store['devices'] as $mac => $r) {
	if (empty($r['limit'])) {
		continue;
	}
	if (!empty($r['by_mobile']))      { $byMobile[$mac] = $r; }
	elseif (!empty($r['by_video']))   { $byVideo[$mac]  = $r; }
	elseif (!empty($r['by_policy']))  { $byPolicy[$mac] = $r; }
	else                              { $byHand[$mac]   = $r; }
}
printf("  applied by the phone cap : %d  -> cleared\n", count($byMobile));
printf("  applied by the policy    : %d  -> cleared\n", count($byPolicy));
printf("  from a video rule you set: %d  -> kept\n", count($byVideo));
printf("  set by hand              : %d  -> kept\n", count($byHand));

$g = nc_global_limits();
printf("\n  global ceiling stays at %s Mb down / %s Mb up\n", $g['down'], $g['up']);

if (!$apply) {
	echo "\n(dry run - pass --apply to make these changes)\n";
	exit(0);
}

echo "\n=== applying ===\n";

/* ---- 1. the phone cap ------------------------------------------- */
if (is_writable($conf)) {
	@copy($conf, '/root/netcontrol.conf.php.bak-' . date('Hi'));
	$txt = (string)@file_get_contents($conf);
	$new = preg_replace("/define\(\s*'NC_MOBILE_LIMIT'\s*,\s*\d+\s*\)/",
		"define('NC_MOBILE_LIMIT', 0)", $txt, 1, $n);
	if ($n > 0 && $new !== null) {
		@file_put_contents($conf, $new);
		echo "  NC_MOBILE_LIMIT set to 0\n";
	} else {
		echo "  NC_MOBILE_LIMIT not found in the config - check it by hand\n";
	}
} else {
	echo "  cannot write $conf\n";
}

/* ---- 2. the policy ---------------------------------------------- */
if ($policy_limits) {
	$pol['action'] = 'allow';
	nc_policy_set($pol);
	echo "  new-device policy action changed from 'limit' to 'allow'\n";
} else {
	echo "  new-device policy left as it is\n";
}

/* ---- 3. clear the limits those two applied ---------------------- */
$lock = nc_store_lock(10);
if (!$lock) {
	echo "  could not lock the store - nothing cleared, run again\n";
	exit(1);
}
$store = nc_store_load();
$cleared = 0;
foreach ($store['devices'] as $mac => $r) {
	if (empty($r['limit'])) {
		continue;
	}
	if (empty($r['by_mobile']) && empty($r['by_policy'])) {
		continue;                 /* by hand, or a video rule: keep */
	}
	$store['devices'][$mac]['limit'] = null;
	unset($store['devices'][$mac]['by_mobile']);
	unset($store['devices'][$mac]['by_policy']);
	$cleared++;
}
if ($cleared) {
	nc_store_save($store);
}
nc_store_unlock($lock);
printf("  cleared %d automatic limit(s)\n", $cleared);

nc_log(sprintf('MANUAL-LIMITS الحدود التلقائية اتقفلت — %d حد اتشال، الباقي يدوي', $cleared));

$n = nc_apply();
printf("  rules reapplied (%d blocked address(es))\n", $n);

echo "\n=== result ===\n";
$store = nc_store_load();
$left = 0;
foreach ($store['devices'] as $r) {
	if (!empty($r['limit'])) {
		$left++;
	}
}
printf("  devices still carrying a limit: %d (all set by hand or by a video rule)\n", $left);
echo "  every new limit from here on is set from the control panel.\n";
