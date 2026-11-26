#!/usr/local/bin/php -f
<?php
/*
 * netcontrol_tick.php  —  runs every minute from cron.
 *
 *   1. follows DHCP changes: if a known MAC now holds a different IP, the
 *      block and limit rules move with it (this is what stops a rule from
 *      hitting whoever inherited the old address)
 *   2. expires temporary rules whose deadline has passed
 *   3. re-applies the resulting rule set to pf and dummynet
 *
 * Everything it does is logged to /var/log/netctl.log as "system".
 */

require_once("/usr/local/pkg/netcontrol.inc");

$lock = '/tmp/netcontrol_tick.lock';
$fh = fopen($lock, 'c');
if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
	exit(0);   // a previous run is still going
}

try {
	nc_tick();
	/* catch devices that joined since the last run */
	$storeLock = nc_store_lock(10);
	if ($storeLock) {
		try {
			nc_policy_run(false);
		} finally {
			nc_store_unlock($storeLock);
		}
	}
} catch (Throwable $e) {
	nc_log('TICK-ERROR ' . $e->getMessage());
}

flock($fh, LOCK_UN);
fclose($fh);
?>
