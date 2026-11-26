<?php
/*
 * netcontrol_api.php  —  JSON endpoint for the Network Control page.
 *
 * Every state-changing call requires:
 *   - the page-services-netcontrol privilege (enforced by guiconfig.inc)
 *   - a valid pfSense CSRF token
 *   - a device identified by MAC, not by IP
 *
 * Protected infrastructure can never be blocked, whatever the client sends.
 */

require_once("guiconfig.inc");
require_once("/usr/local/pkg/netcontrol.inc");
require_once("/usr/local/pkg/netcontrol_report.inc");

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function nc_out($data, $code = 200) {
	if (!empty($data['ok']) && isset($GLOBALS['nc_change_status'])) {
		$data['change_status'] = $GLOBALS['nc_change_status'];
	}
	http_response_code($code);
	echo json_encode($data, JSON_UNESCAPED_UNICODE);
	exit;
}

$action = $_REQUEST['action'] ?? '';

/* ---------------------------------------------------------------- reads */

if ($action === 'state') {
	$sample = isset($_GET['sample']) ? max(1, min(5, (int)$_GET['sample'])) : 2;
	$store  = nc_store_load();

	/*
	 * The table shows a live connection count per device. That number comes
	 * from the pf state table, which nc_live_devices() already walks, so
	 * merge it in here rather than making the page fetch two endpoints and
	 * stitch them together in the browser.
	 */
	$devs = nc_devices();
	$blocked = array_filter($devs, function($d) { return !empty($d['blocked']); });
	$table = array();
	$table_ok = false;
	if ($blocked && nc_anchor_active()) {
		$lines = array();
		exec('/sbin/pfctl -t ' . NC_TABLE . ' -T show 2>/dev/null', $lines, $rc);
		$table_ok = ($rc === 0);
		$table = array_flip(array_map('trim', $lines));
	}
	$live = array();
	foreach (nc_live_devices() as $l) {
		if (!empty($l['mac'])) {
			$live[$l['mac']] = $l;
		}
	}
	foreach ($devs as &$d) {
		$l = $live[$d['mac']] ?? null;
		$d['conns']   = $l['conns']   ?? 0;
		$d['session'] = $l['session'] ?? null;
		$d['hidden_offline'] = empty($d['online']) && empty($d['protected']) &&
			!empty($store['offline_dismissed'][$d['mac']]);
		if (!empty($d['blocked'])) {
			$d['block_applied'] = $table_ok && !empty($d['online']) && !empty($d['ip'])
				? isset($table[$d['ip']]) : null;
		}
	}
	unset($d);

	nc_out(array(
		'ok'       => true,
		'devices'  => $devs,
		'rates'    => nc_rates($sample),
		'system'   => nc_system_stats(),
		'sites'    => array_values($store['sites']   ?? array()),
		'video'    => array_values($store['video']   ?? array()),
		'schedule' => array_values($store['schedule'] ?? array()),
		'groups'   => $store['groups'] ?? array(),
		'attempts' => array_slice($store['attempts'] ?? array(), 0, 50),
		'global'   => nc_global_limits(),
		'now'      => time(),
	));
}

if ($action === 'traffic') {
	$view = $_GET['view'] ?? 'devices';
	nc_out(array(
		'ok'       => true,
		'view'     => $view,
		'devices'  => ($view === 'devices') ? nc_live_devices() : array(),
		'services' => ($view === 'services') ? nc_top_services(25) : array(),
	));
}

if ($action === 'livevideo') {
	nc_out(array(
		'ok'      => true,
		'groups'  => nc_video_groups(),
		'streams' => nc_live_video(),
		'now'     => time(),
	));
}

if ($action === 'history') {
	nc_out(array(
		'ok'      => true,
		'logins'  => nc_login_log(250),
		'users'   => nc_login_summary(600),
		'usage'   => nc_usage_history(7),
		'now'     => time(),
	));
}

if ($action === 'report') {
	$days = nc_report_days();
	$day  = (string)($_GET['day'] ?? '');
	/* only a day we actually hold figures for, or today */
	if ($day === '' || (!in_array($day, $days, true) && $day !== date('Y-m-d'))) {
		$day = date('Y-m-d');
	}
	nc_out(array('ok' => true, 'report' => nc_report($day), 'days' => $days));
}

/*
 * The same report as a file to save.
 *
 * Served as a download rather than JSON the page assembles, so what the
 * admin keeps is exactly what the firewall reported, and it opens on a
 * machine that cannot reach this box.
 */
if ($action === 'reporthtml') {
	$days = nc_report_days();
	$day  = (string)($_GET['day'] ?? '');
	if ($day === '' || (!in_array($day, $days, true) && $day !== date('Y-m-d'))) {
		$day = date('Y-m-d');
	}
	$html = nc_report_html(nc_report($day));
	header_remove('Content-Type');
	header('Content-Type: text/html; charset=utf-8');
	header('Content-Disposition: attachment; filename="network-report-' . $day . '.html"');
	header('X-Content-Type-Options: nosniff');
	echo $html;
	exit;
}

if ($action === 'weekreport') {
	nc_out(array('ok' => true, 'week' => nc_report_week()));
}

/* The week as a file to save. */
if ($action === 'weekhtml') {
	$r = nc_report_week();
	$html = nc_report_week_html($r);
	header_remove('Content-Type');
	header('Content-Type: text/html; charset=utf-8');
	header('Content-Disposition: attachment; filename="network-week-'
		. $r['from'] . '_' . $r['to'] . '.html"');
	header('X-Content-Type-Options: nosniff');
	echo $html;
	exit;
}

if ($action === 'policy') {
	$cp = nc_portal_sessions();
	nc_out(array(
		'ok'            => true,
		'policy'        => nc_policy_get(),
		'preview'       => nc_policy_run(true),
		'history'       => nc_policy_history(30),
		'portal_active' => nc_portal_active(),
		'portal_users'  => count($cp['by_mac']) ?: count($cp['by_ip']),
	));
}

if ($action === 'stale') {
	$days = isset($_GET['days']) ? max(1, min(365, (int)$_GET['days'])) : 30;
	nc_out(array('ok' => true, 'items' => nc_stale($days)));
}

if ($action === 'log') {
	$lines = array();
	if (file_exists(NC_LOG)) {
		$raw = @file(NC_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$lines = array_slice(array_reverse($raw ?: array()), 0, 100);
	}
	/* 'who' runs parallel to 'lines': the host name and portal user of the device each one is about */
	list($st, $live) = nc_ident_map();
	nc_out(array('ok' => true, 'lines' => $lines, 'who' => nc_log_enrich($lines, $st, $live)));
}

/* every device we can name, for the diagnose box to complete from */
if ($action === 'directory') {
	nc_out(array('ok' => true, 'items' => nc_diag_directory(nc_devices(), nc_store_load())));
}

/* a full report for one device: ?q= a MAC, an IP, a host name or a person */
if ($action === 'diagnose') {
	$q    = trim((string)($_GET['q'] ?? ''));
	$devs = nc_devices();
	$st   = nc_store_load();
	$dir  = nc_diag_directory($devs, $st);
	$hits = nc_diag_search($q, $dir);
	if (!$hits) {
		nc_out(array('ok' => true, 'found' => 0, 'query' => $q));
	}
	/* one clear best match is reported; otherwise let the person choose */
	$one = count($hits) === 1 || $hits[0]['score'] >= 95 &&
	       (!isset($hits[1]) || $hits[1]['score'] < 95);
	if (!$one) {
		nc_out(array('ok' => true, 'found' => count($hits), 'choose' => $hits, 'query' => $q));
	}
	$by = array();
	foreach ($devs as $d) { $by[$d['mac']] = $d; }
	$h = $hits[0];
	/* a device that has left is not in nc_devices(): build the same shape from what we remember */
	$d = $by[$h['mac']] ?? array(
		'mac' => $h['mac'], 'ip' => $h['ip'], 'name' => $h['name'], 'hostname' => $h['host'],
		'cp_user' => '', 'vendor' => '', 'type' => '', 'type_name' => '', 'online' => false,
		'blocked' => !empty($st['devices'][$h['mac']]['blocked']), 'rate' => 0,
		'last_seen' => $st['ident'][$h['mac']]['at'] ?? ($st['devices'][$h['mac']]['last_seen'] ?? null),
		'random' => nc_mac_is_random($h['mac']), 'fp' => $st['devices'][$h['mac']]['fp'] ?? '',
		'reserved' => false, 'protected' => false, 'moved' => false,
	);
	nc_out(array('ok' => true, 'found' => 1, 'report' => nc_diagnose($d, nc_diag_context($devs))));
}

/* ------------------------------------------------------- writes (CSRF) */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	nc_out(array('ok' => false, 'error' => 'POST required'), 405);
}
/* everything from here on is JSON; never let a notice corrupt it */
@ini_set('display_errors', '0');
/*
 * csrf_check() does not merely return false on a stale token: pfSense renders
 * its own HTML "CSRF check failed" page and exits. That HTML reaches a caller
 * expecting JSON and kills JSON.parse() with "Unexpected token '<'" - which is
 * exactly what a page left open past the token lifetime used to show.
 *
 * Buffering the call lets us swallow that page and answer in JSON instead, so
 * the client can tell the user to refresh rather than print a parse error.
 */
$nc_token_ok = false;
if (isset($_POST['__csrf_magic'])) {
	ob_start();
	$nc_token_ok = (bool)@csrf_check($_POST['__csrf_magic'], false);
	ob_end_clean();
}
if (!$nc_token_ok) {
	nc_out(array(
		'ok'      => false,
		'expired' => true,
		'error'   => 'انتهت صلاحية الجلسة — اعمل تحديث للصفحة (F5) وحاول تاني',
	), 403);
}

/* Resolve a MAC the client sent to a device we actually know about. */
function nc_require_device($mac) {
	$mac = nc_norm_mac($mac);
	if (!preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $mac)) {
		nc_out(array('ok' => false, 'error' => 'MAC غير صالح'), 400);
	}
	foreach (nc_devices() as $d) {
		if ($d['mac'] === $mac) {
			return $d;
		}
	}
	nc_out(array('ok' => false, 'error' => 'الجهاز غير معروف'), 404);
}

/* Translate a duration choice into an absolute expiry. */
function nc_expiry($dur) {
	switch ($dur) {
		case '60':   return time() + 3600;
		case '180':  return time() + 10800;
		case 'eod':  return strtotime('today 23:59:59');
		default:     return null;   // permanent
	}
}

function nc_api_lock_store() {
	$lock = nc_store_lock();
	if (!$lock) {
		nc_out(array('ok' => false, 'error' => 'فيه تعديل تاني شغّال. جرّب تاني.'), 503);
	}
	register_shutdown_function('nc_store_unlock', $lock);
}

function nc_api_check_save($saved) {
	if (!$saved) {
		nc_out(array('ok' => false, 'change_status' => 'save_failed',
			'error' => 'تعذّر حفظ التغيير.'), 500);
	}
	$GLOBALS['nc_change_status'] = 'saved';
}

function nc_api_check_apply($applied) {
	if (!$applied) {
		nc_out(array('ok' => false, 'change_status' => 'pending',
			'error' => 'التغيير اتحفظ لكن تعذّر تطبيقه على الفايروول.'), 502);
	}
	$GLOBALS['nc_change_status'] = 'applied';
}

function nc_api_upload_limit($down) {
	if (!$down || !isset($_POST['up_mb'])) {
		return null;
	}
	$up = $_POST['up_mb'];
	if (!is_string($up) || !preg_match('/^\d+(?:\.\d{1,3})?$/D', $up) ||
		(float)$up < 0.1 || (float)$up > 50) {
		nc_out(array('ok' => false, 'error' => 'حد الرفع لازم يكون بين 0.1 و50 Mb/s'), 400);
	}
	return (float)$up;
}

function nc_limit_wan_peers($states, $ip) {
	$peers = array();
	$client = preg_quote($ip, '/');
	$wan = preg_quote(NC_WAN_IF, '/');
	foreach ($states as $line) {
		if (preg_match('/^' . $wan . '\s+(?:tcp|udp)\s+\S+\s+\(' . $client .
			':\d+\)\s*->\s*(\d+(?:\.\d+){3}):\d+\b/', $line, $m) &&
			filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
			$peers[$m[1]] = true;
		}
	}
	return array_keys($peers);
}

function nc_limit_reset_wan($device) {
	$ip = $device['ip'];
	if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || empty($device['online'])) {
		return false;
	}
	$states = array();
	exec('/sbin/pfctl -ss 2>/dev/null', $states, $rc);
	if ($rc !== 0) {
		return false;
	}
	nc_arp_table($holders);
	if (($holders[$ip] ?? null) !== $device['mac'] || nc_is_protected($device['mac'], $ip)) {
		return false;
	}
	$ok = true;
	foreach (nc_limit_wan_peers($states, $ip) as $peer) {
		if (mwexec('/sbin/pfctl -k ' . escapeshellarg($ip) . ' -k ' . escapeshellarg($peer) . ' 2>/dev/null') !== 0) {
			$ok = false;
		}
	}
	return $ok;
}

if ($action !== 'speedtest') {
	nc_api_lock_store();
	$store = nc_store_load();
}
$dur   = $_POST['duration'] ?? 'perm';
$exp   = nc_expiry($dur);
$durTxt = $exp ? ' (مؤقت حتى ' . date('H:i', $exp) . ')' : '';

switch ($action) {

case 'cleanoffline': {
	$requested = $_POST['macs'] ?? array();
	if (!is_array($requested)) {
		nc_out(array('ok' => false, 'error' => 'Invalid device list'), 400);
	}
	$requested = array_fill_keys(array_filter($requested, 'is_string'), true);
	$removed = 0;
	foreach (nc_devices() as $device) {
		$mac = $device['mac'];
		if (isset($requested[$mac]) && empty($device['online']) && empty($device['protected']) &&
			empty($device['ip_mac_conflict']) &&
			empty($store['offline_dismissed'][$mac])) {
			$store['offline_dismissed'][$mac] = true;
			$removed++;
		}
	}
	if ($removed) {
		nc_api_check_save(nc_store_save($store));
		nc_log("CLEAN-OFFLINE {$removed}");
	}
	nc_out(array('ok' => true, 'removed' => $removed));
}

case 'block':
case 'unblock': {
	$d = nc_require_device($_POST['mac'] ?? '');
	if ($action === 'block' && $d['protected']) {
		nc_out(array('ok' => false,
			'error' => 'الجهاز ده من البنية الأساسية ومايتوقفش من هنا'), 403);
	}
	$mac = $d['mac'];
	$store['devices'][$mac] = array_merge($store['devices'][$mac] ?? array(), array(
		'ip'      => $d['ip'],
		'name'    => $d['name'],
		'blocked' => ($action === 'block'),
		'expires' => ($action === 'block') ? $exp : null,
		/*
		 * An admin acting by hand outranks the policy: clear the flag so a
		 * later portal login cannot quietly undo this block. Unblocking
		 * clears it too, so the device starts clean.
		 */
		'by_policy' => false,
		/* learn the fingerprint so the block survives a MAC rotation */
		'fp' => ($action === 'block' && $d['random'])
			? (function($h) {
				$f = nc_fingerprint($h);
				return ($f !== '' && !nc_fp_is_generic($f)) ? $f : '';
			})($d['hostname'] ?? '')
			: ($store['devices'][$mac]['fp'] ?? ''),
		/*
		 * For a device with no fingerprint to follow, the portal account is
		 * the only identifier that survives a MAC rotation - the person has
		 * to type it to get online. Taken from the portal's own session
		 * table, never from the request. Cleared on unblock.
		 */
		'cp_user' => ($action === 'block') ? ($d['cp_user'] ?? '') : '',
		'last_seen' => time(),
	));
	nc_api_check_save(nc_store_save($store));
	$n = nc_apply();
	nc_api_check_apply($n !== false);
	nc_log(sprintf("%s %s (%s) بـ%s%s",
		$action === 'block' ? 'BLOCK' : 'UNBLOCK',
		$mac, $d['ip'], $d['random'] ? 'IP' : 'MAC', $durTxt));
	nc_out(array('ok' => true, 'blocked_count' => $n));
}

case 'limit': {
	$d   = nc_require_device($_POST['mac'] ?? '');
	/*
	 * Infrastructure must not be throttled either. Only 'block' checked
	 * this before, so the firewall's own interface or a gateway could be
	 * capped to 1 Mb - nearly as damaging as blocking it, and much easier
	 * to miss.
	 */
	if (!empty($d['protected'])) {
		nc_out(array('ok' => false,
			'error' => 'الجهاز ده من البنية الأساسية ومايتحددش من هنا'), 403);
	}
	$mb  = (int)($_POST['mb'] ?? 0);
	if ($mb < 0 || $mb > 50) {
		nc_out(array('ok' => false, 'error' => 'الحد لازم يكون بين 0 و 50'), 400);
	}
	$mac = $d['mac'];
	$store['devices'][$mac] = array_merge($store['devices'][$mac] ?? array(), array(
		'ip'        => $d['ip'],
		'name'      => $d['name'],
		'limit'     => $mb ?: null,
		'limit_up'  => nc_api_upload_limit($mb),
		'expires'   => $mb ? $exp : null,
		'last_seen' => time(),
	));
	nc_api_check_save(nc_store_save($store));
	nc_api_check_apply(nc_apply() !== false);
	nc_log(sprintf("LIMIT %s (%s) = %s%s", $mac, $d['ip'],
		$mb ? $mb . 'Mb down / ' . nc_upload_limit($store['devices'][$mac]) . 'Mb up' : 'بلا حد', $durTxt));
	$result = array('ok' => true);
	if ($mb && ($_POST['restart_traffic'] ?? '') === '1' && !nc_limit_reset_wan($d)) {
		$result['warning'] = 'الحد اتطبق، لكن تعذّر إغلاق اتصالات الإنترنت المفتوحة.';
		nc_log("LIMIT-RESTART-ERROR {$mac}");
	}
	nc_out($result);
}

case 'bulk': {
	$macs = $_POST['macs'] ?? array();
	$op   = $_POST['op'] ?? '';
	if (!is_array($macs) || !count($macs)) {
		nc_out(array('ok' => false, 'error' => 'مفيش أجهزة محددة'), 400);
	}
	if (count($macs) > 60) {
		nc_out(array('ok' => false, 'error' => 'أكتر من 60 جهاز في المرة'), 400);
	}
	if (!in_array($op, array('block', 'unblock', 'lim2', 'lim', 'unl'), true)) {
		nc_out(array('ok' => false, 'error' => 'عملية غير معروفة'), 400);
	}
	/* a chosen speed for 'lim' (the group-limit button) */
	$bmb = max(1, min(50, (int)($_POST['mb'] ?? 0)));
	if ($op === 'lim' && (int)($_POST['mb'] ?? 0) <= 0) {
		nc_out(array('ok' => false, 'error' => 'السرعة ناقصة'), 400);
	}
	$up = in_array($op, array('lim', 'lim2'), true) ? nc_api_upload_limit($bmb) : null;
	/* one scan, not one per device */
	$bydev = array();
	foreach (nc_devices() as $x) {
		$bydev[$x['mac']] = $x;
	}
	$done = 0; $skipped = array();
	foreach ($macs as $m) {
		$mac = nc_norm_mac($m);
		$dev = $bydev[$mac] ?? null;
		if (!$dev) { continue; }
		/*
		 * Skip infrastructure for anything that restricts it, not just
		 * for a block: a limit on a gateway strands everyone behind it.
		 * The ops are named lim2/lim on the wire - testing for 'limit'
		 * here never matched, so a bulk limit used to reach them.
		 */
		if (in_array($op, array('block', 'lim2', 'lim'), true) && !empty($dev['protected'])) {
			$skipped[] = $dev['ip'] ?: $dev['mac'];
			continue;
		}
		$cur = $store['devices'][$mac] ?? array();
		$cur['ip'] = $dev['ip'];
		$cur['name'] = $dev['name'];
		$cur['last_seen'] = time();
		if ($op === 'block')   { $cur['blocked'] = true;  $cur['expires'] = $exp; }
		if ($op === 'unblock') {
			$cur['blocked'] = false;
			$cur['expires'] = null;
			/*
			 * Clear the policy flag too. Without this the device is seen as
			 * new again on the next tick and the policy re-blocks it, so the
			 * admin's unblock would silently undo itself.
			 */
			$cur['by_policy'] = false;
			$seen = $store['policy_seen'] ?? array();
			$seen[$mac] = time();
			$store['policy_seen'] = $seen;
		}
		if ($op === 'lim2')    { $cur['limit'] = 2;       $cur['expires'] = $exp; }
		if ($op === 'lim')     { $cur['limit'] = $bmb;    $cur['expires'] = $exp; }
		if ($op === 'unl')     { $cur['limit'] = null;    $cur['expires'] = $exp; }
		if (in_array($op, array('lim', 'lim2', 'unl'), true)) {
			$cur['limit_up'] = $up;
		}
		$store['devices'][$mac] = $cur;
		$done++;
	}
	nc_api_check_save(nc_store_save($store));
	nc_api_check_apply(nc_apply() !== false);
	nc_log(sprintf("BULK %s على %d جهاز%s%s", $op, $done, $durTxt,
		$skipped ? ' (اتخطى محميين: ' . implode(',', $skipped) . ')' : ''));
	nc_out(array('ok' => true, 'done' => $done, 'skipped' => $skipped));
}

case 'group': {
	$macs = $_POST['macs'] ?? array();
	$g    = preg_replace('/[^a-z0-9_-]/i', '', $_POST['group'] ?? '');
	if (!$g || !is_array($macs)) {
		nc_out(array('ok' => false, 'error' => 'بيانات ناقصة'), 400);
	}
	foreach ($macs as $m) {
		$mac = nc_norm_mac($m);
		$store['devices'][$mac] = array_merge($store['devices'][$mac] ?? array(),
			array('group' => $g, 'last_seen' => time()));
	}
	nc_api_check_save(nc_store_save($store));
	nc_log(sprintf("GROUP %d جهاز -> %s", count($macs), $g));
	nc_out(array('ok' => true));
}

case 'rename': {
	$d    = nc_require_device($_POST['mac'] ?? '');
	$name = trim(mb_substr($_POST['name'] ?? '', 0, 48));
	$store['devices'][$d['mac']] = array_merge($store['devices'][$d['mac']] ?? array(),
		array('name' => $name, 'ip' => $d['ip'], 'last_seen' => time()));
	nc_api_check_save(nc_store_save($store));
	nc_log(sprintf("RENAME %s -> %s", $d['mac'], $name));
	nc_out(array('ok' => true));
}

case 'clean': {
	$macs = $_POST['macs'] ?? array();
	if (!is_array($macs) || !count($macs)) {
		nc_out(array('ok' => false, 'error' => 'مفيش عناصر محددة'), 400);
	}
	global $config;
	$removed = 0;
	$skipped = array();
	$prot = array_flip(nc_protected_ips());
	$maps = &$config['dhcpd']['lan']['staticmap'];

	foreach ($macs as $m) {
		$mac = nc_norm_mac($m);

		/*
		 * Check the shape before using it. This path writes config.xml, so
		 * a value that is not a MAC has no business reaching it - and the
		 * only other guard, nc_require_device(), is deliberately not used
		 * here because a stale entry has no live device to match.
		 */
		if (!preg_match('/^[0-9a-f]{2}(:[0-9a-f]{2}){5}$/', $mac)) {
			$skipped[] = $m;
			continue;
		}

		/*
		 * Refuse protected devices even when asked directly. nc_stale()
		 * already keeps them off the list, but the list is not the only way
		 * to reach this: deleting the gateway's or the switch's reservation
		 * moves it to a new address at its next renewal.
		 */
		$ip = $store['devices'][$mac]['ip'] ?? '';
		if ($ip === '' && is_array($maps)) {
			foreach ($maps as $row) {
				if (nc_norm_mac($row['mac'] ?? '') === $mac) {
					$ip = $row['ipaddr'] ?? '';
					break;
				}
			}
		}
		if ($ip !== '' && isset($prot[$ip])) {
			$skipped[] = $ip;
			continue;
		}

		if (is_array($maps)) {
			foreach ($maps as $i => $row) {
				if (nc_norm_mac($row['mac'] ?? '') === $mac) {
					unset($maps[$i]);
					$removed++;
				}
			}
		}
		unset($store['devices'][$mac]);
	}
	if (is_array($maps)) {
		$maps = array_values($maps);
	}
	nc_api_check_save(nc_store_save($store));
	if ($removed) {
		write_config("netcontrol: مسح {$removed} حجز DHCP غير مستخدم");
		services_dhcpd_configure();
	}
	nc_log(sprintf("CLEAN اتمسح %d حجز و %d قاعدة", $removed, count($macs)));
	nc_out(array(
		'ok'      => true,
		'removed' => $removed,
		'skipped' => $skipped,
		'note'    => $skipped
			? 'اتخطى ' . count($skipped) . ' عنصر محمي أو غير صالح'
			: '',
	));
}

case 'savepolicy': {
	$pol = array(
		'enabled'  => !empty($_POST['enabled']),
		'action'   => in_array($_POST['pact'] ?? '', array('block','limit','allow'))
		              ? $_POST['pact'] : 'block',
		'limit_mb' => max(1, min(50, (int)($_POST['limit_mb'] ?? 1))),
		'duration' => in_array($_POST['pdur'] ?? '', array('perm','60','180','eod'))
		              ? $_POST['pdur'] : 'perm',
		'targets'  => array_values(array_filter(
		                 (array)($_POST['targets'] ?? array('mobile')),
		                 function($x) {
		                     return in_array($x, array('all','mobile','android','ios',
		                                               'windows','mac','linux','iot'));
		                 })),
		'respect_portal' => !empty($_POST['resp_cp']),
		'schedule' => array(
			'enabled' => !empty($_POST['sch_on']),
			'from'    => preg_replace('/[^0-9:]/', '', $_POST['sch_from'] ?? '08:00'),
			'to'      => preg_replace('/[^0-9:]/', '', $_POST['sch_to']   ?? '17:00'),
			'days'    => in_array($_POST['sch_days'] ?? '', array('all','work','weekend'))
			             ? $_POST['sch_days'] : 'all',
		),
	);
	if (!count($pol['targets'])) {
		$pol['targets'] = array('mobile');
	}
	nc_api_check_save(nc_policy_set($pol));
	nc_log(sprintf('POLICY-SAVE %s %s targets=%s%s',
		$pol['enabled'] ? 'ON' : 'OFF', $pol['action'],
		implode(',', $pol['targets']),
		$pol['schedule']['enabled']
			? ' ' . $pol['schedule']['from'] . '-' . $pol['schedule']['to'] : ''));
	nc_out(array('ok' => true, 'policy' => $pol,
	             'preview' => nc_policy_run(true)));
}

case 'policyrun': {
	try {
		$acted = nc_policy_run(false);
	} catch (RuntimeException $e) {
		nc_log($e->getMessage());
		nc_out(array('ok' => false, 'error' => 'تعذّر تنفيذ سياسة الأجهزة.'), 500);
	}
	nc_out(array('ok' => true, 'acted' => $acted));
}

case 'policyforget': {
	$mac = nc_norm_mac($_POST['mac'] ?? '');
	if (!$mac) {
		nc_out(array('ok' => false, 'error' => 'MAC ناقص'), 400);
	}
	unset($store['policy_seen'][$mac]);
	/* Forget the device and clear its rule in the same write. */
	$has_rule = isset($store['devices'][$mac]);
	if ($has_rule) {
		$store['devices'][$mac]['blocked'] = false;
		$store['devices'][$mac]['limit']   = null;
		unset($store['devices'][$mac]['limit_up']);
		$store['devices'][$mac]['expires'] = null;
	}
	nc_api_check_save(nc_store_save($store));
	if ($has_rule) {
		nc_api_check_apply(nc_apply() !== false);
	}
	nc_log("POLICY-FORGET {$mac}");
	nc_out(array('ok' => true));
}

case 'blockservice': {
	/*
	 * Block a whole service by name. One click here writes every domain the
	 * service is known by, which is what makes the live list actionable —
	 * blocking "facebook" has to cover fbcdn.net and messenger.com too.
	 */
	$svc = preg_replace('/[^a-z0-9._-]/i', '', $_POST['service'] ?? '');
	if (!$svc) {
		nc_out(array('ok' => false, 'error' => 'اسم الخدمة ناقص'), 400);
	}
	$sets = array(
		'youtube'   => array('youtube.com','googlevideo.com','ytimg.com','youtu.be'),
		'facebook'  => array('facebook.com','fbcdn.net','fbsbx.com','messenger.com'),
		'instagram' => array('instagram.com','cdninstagram.com'),
		'tiktok'    => array('tiktok.com','tiktokcdn.com','tiktokv.com'),
		'netflix'   => array('netflix.com','nflxvideo.net'),
		'twitter'   => array('twitter.com','x.com','twimg.com'),
		'snapchat'  => array('snapchat.com','sc-cdn.net'),
		'telegram'  => array('telegram.org','t.me'),
		'chatgpt'   => array('openai.com','chatgpt.com'),
		'steam'     => array('steampowered.com','steamcontent.com'),
		'whatsapp'  => array('whatsapp.net','whatsapp.com'),
	);
	/*
	 * The live view names a flow after the host it saw, which for a CDN is
	 * not the service's own name: TikTok arrives as byteoversea.net and
	 * Facebook as fbcdn.net. Map those back, or one click would block a
	 * single CDN domain and leave the service working.
	 */
	$alias = array(
		'byteoversea.net' => 'tiktok',  'byteoversea'   => 'tiktok',
		'tiktokcdn.com'   => 'tiktok',  'tiktokcdn'     => 'tiktok',
		'muscdn.com'      => 'tiktok',  'ibytedtos.com' => 'tiktok',
		'googlevideo.com' => 'youtube', 'googlevideo'   => 'youtube',
		'ytimg.com'       => 'youtube', 'youtu.be'      => 'youtube',
		'fbcdn.net'       => 'facebook','fbsbx.com'     => 'facebook',
		'cdninstagram.com'=> 'instagram','cdninstagram' => 'instagram',
		'nflxvideo.net'   => 'netflix', 'nflxso.net'    => 'netflix',
		'ttvnw.net'       => 'twitch',  'jtvnw.net'     => 'twitch',
		'whatsapp.net'    => 'whatsapp','twimg.com'     => 'twitter',
	);
	$key  = strtolower($svc);
	$svc  = $alias[$key] ?? $key;
	$doms = $sets[$svc] ?? array($svc);

	/* what is already blocked, so a second click does not duplicate it */
	$have = array();
	foreach (($store['sites'] ?? array()) as $x) {
		$have[strtolower($x['domain'] ?? '')] = true;
	}

	$added = 0;
	foreach ($doms as $d) {
		if (isset($have[strtolower($d)])) {
			continue;
		}
		$added++;
		$store['sites'][] = array(
			'domain'  => $d,
			'enabled' => true,
			'scope'   => $_POST['scope'] ?? 'all',
			'macs'    => $_POST['macs'] ?? array(),
			'service' => $svc,
			'expires' => $exp,
			'added'   => time(),
		);
	}
	nc_api_check_save(nc_store_save($store));
	nc_api_check_apply(nc_dns_apply() >= 0);
	nc_log(sprintf("SERVICE-BLOCK %s (%d نطاق جديد من %d)%s",
		$svc, $added, count($doms), $durTxt));
	nc_out(array(
		'ok'      => true,
		'service' => $svc,
		'domains' => $doms,
		'added'   => $added,
		'note'    => $added ? '' : 'الخدمة دي كانت محظورة بالفعل',
	));
}

case 'site': {
	$op  = $_POST['op'] ?? '';
	$dom = strtolower(trim($_POST['domain'] ?? ''));
	$dom = preg_replace('#^https?://#', '', $dom);
	$dom = preg_replace('#/.*$#', '', $dom);
	if ($op === 'add') {
		if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $dom)) {
			nc_out(array('ok' => false, 'error' => 'اسم النطاق غير صالح'), 400);
		}
		$store['sites'][] = array(
			'domain'  => $dom,
			'enabled' => true,
			'scope'   => $_POST['scope'] ?? 'all',
			'group'   => $_POST['group'] ?? null,
			'macs'    => $_POST['macs'] ?? array(),
			'expires' => $exp,
			'added'   => time(),
		);
		nc_log("SITE-ADD {$dom}{$durTxt}");
	} elseif ($op === 'toggle' || $op === 'delete') {
		$i = (int)($_POST['index'] ?? -1);
		if (!isset($store['sites'][$i])) {
			nc_out(array('ok' => false, 'error' => 'القاعدة غير موجودة'), 404);
		}
		if ($op === 'delete') {
			$d   = $store['sites'][$i]['domain'];
			$svc = $store['sites'][$i]['service'] ?? '';

			/*
			 * A service block writes several domains at once, so removing
			 * one row leaves the rest in place: unblocking "tiktok" would
			 * drop tiktok.com while tiktokcdn.com stayed blocked, and the
			 * service came back half-working with no sign why. Remove the
			 * whole set when the row belongs to a service.
			 */
			if ($svc !== '') {
				$kept = array();
				$gone = 0;
				foreach ($store['sites'] as $row) {
					if (($row['service'] ?? '') === $svc) {
						$gone++;
						continue;
					}
					$kept[] = $row;
				}
				$store['sites'] = $kept;
				nc_log(sprintf('SITE-DEL %s (%d نطاق للخدمة)', $svc, $gone));
				nc_api_check_save(nc_store_save($store));
				nc_api_check_apply(nc_dns_apply() >= 0);
				nc_out(array('ok' => true, 'removed' => $gone, 'service' => $svc));
			}

			array_splice($store['sites'], $i, 1);
			nc_log("SITE-DEL {$d}");
		} else {
			$store['sites'][$i]['enabled'] = !$store['sites'][$i]['enabled'];
			nc_log("SITE-TOGGLE {$store['sites'][$i]['domain']} = " .
				($store['sites'][$i]['enabled'] ? 'on' : 'off'));
		}
	}
	nc_api_check_save(nc_store_save($store));
	nc_api_check_apply(nc_dns_apply() >= 0);
	nc_out(array('ok' => true, 'sites' => array_values($store['sites'])));
}

case 'video': {
	$key  = preg_replace('/[^a-z]/', '', $_POST['key'] ?? '');
	$mode = $_POST['mode'] ?? 'off';
	if (!in_array($mode, array('off', 'limit', 'block'))) {
		nc_out(array('ok' => false, 'error' => 'وضع غير معروف'), 400);
	}
	/* the speed is part of the rule, so the tick can re-apply it later */
	$mb = (int)($_POST['mb'] ?? 1);
	if ($mb < 1 || $mb > 50) {
		$mb = 1;
	}

	$found = false;
	foreach ($store['video'] as &$v) {
		if (($v['key'] ?? '') === $key) {
			$v['mode']    = $mode;
			$v['mb']      = $mb;
			$v['macs']    = $_POST['macs'] ?? array();
			$v['expires'] = $exp;
			$found = true;
			break;
		}
	}
	unset($v);
	if (!$found) {
		$store['video'][] = array('key' => $key, 'mode' => $mode, 'mb' => $mb,
			'macs' => $_POST['macs'] ?? array(), 'expires' => $exp);
	}

	/*
	 * "limit" caps the devices that are streaming this service right now.
	 *
	 * The service itself cannot be throttled: its addresses change
	 * constantly, and the DNS layer that blocks a domain can only answer
	 * yes or no. Capping the device is what the firewall can actually
	 * enforce, so that is what this does - and the reply says how many
	 * devices were affected, because capping the device slows everything
	 * that device does, not only the video.
	 */
	$touched = array();
	if ($mode === 'limit') {
		$only = $_POST['macs'] ?? array();      /* empty = whoever is watching */
		foreach (nc_live_video() as $vv) {
			if ($vv['kind'] !== $key || empty($vv['mac'])) {
				continue;
			}
			/* a page sitting open is not worth slowing someone down for */
			if (($vv['how'] ?? '') === 'page') {
				continue;
			}
			if ($only && !in_array($vv['mac'], $only, true)) {
				continue;
			}
			if (!empty($vv['blocked'])) {
				continue;                       /* already cut off */
			}
			$mac = $vv['mac'];
			if (isset($touched[$mac])) {
				continue;
			}
			$cur = $store['devices'][$mac] ?? array();
			/* never raise a tighter limit the admin already set by hand */
			if (!empty($cur['limit']) && (int)$cur['limit'] <= $mb) {
				$touched[$mac] = 'kept';
				continue;
			}
			$cur['ip']        = $vv['ip'];
			$cur['name']      = $cur['name'] ?? '';
			$cur['limit']     = $mb;
			unset($cur['limit_up']);
			$cur['expires']   = $exp;
			$cur['by_video']  = $key;
			$cur['last_seen'] = time();
			$store['devices'][$mac] = $cur;
			$touched[$mac] = 'set';
		}
	} elseif ($mode === 'off') {
		/* release only the caps this feature applied, not the admin's own */
		foreach ($store['devices'] as $mac => &$cur) {
			if (($cur['by_video'] ?? '') === $key) {
				$cur['limit']    = null;
				unset($cur['limit_up']);
				$cur['expires']  = null;
				unset($cur['by_video']);
				$touched[$mac]   = 'cleared';
			}
		}
		unset($cur);
	}

	nc_api_check_save(nc_store_save($store));
	nc_api_check_apply(nc_apply() !== false);
	nc_api_check_apply(nc_dns_apply() >= 0);

	$set = count(array_filter($touched, function($x) { return $x === 'set'; }));
	$clr = count(array_filter($touched, function($x) { return $x === 'cleared'; }));
	nc_log(sprintf("VIDEO %s = %s%s%s", $key, $mode, $durTxt,
		$set ? " (حدّ {$set} جهاز)" : ($clr ? " (رفع الحد عن {$clr})" : '')));
	nc_out(array(
		'ok'      => true,
		'mode'    => $mode,
		'devices' => count($touched),
		'limited' => $set,
		'cleared' => $clr,
		'note'    => ($mode === 'limit' && !$set)
			? 'مفيش حد بيتفرّج على الخدمة دي دلوقتي — الحد هيتطبق لما حد يبدأ'
			: '',
	));
}

case 'schedule': {
	$op = $_POST['op'] ?? '';
	if ($op === 'toggle') {
		$i = (int)($_POST['index'] ?? -1);
		if (!isset($store['schedule'][$i])) {
			nc_out(array('ok' => false, 'error' => 'القاعدة غير موجودة'), 404);
		}
		$store['schedule'][$i]['enabled'] = !$store['schedule'][$i]['enabled'];
		nc_log("SCHED-TOGGLE #{$i} = " . ($store['schedule'][$i]['enabled'] ? 'on' : 'off'));
	} elseif ($op === 'delete') {
		$i = (int)($_POST['index'] ?? -1);
		array_splice($store['schedule'], $i, 1);
		nc_log("SCHED-DEL #{$i}");
	} elseif ($op === 'add') {
		$store['schedule'][] = array(
			'enabled' => true,
			'from'    => preg_replace('/[^0-9:]/', '', $_POST['from'] ?? '09:00'),
			'to'      => preg_replace('/[^0-9:]/', '', $_POST['to'] ?? '17:00'),
			'days'    => $_POST['days'] ?? 'كل يوم',
			'what'    => mb_substr($_POST['what'] ?? '', 0, 120),
			'scope'   => $_POST['scope'] ?? 'all',
		);
		nc_log("SCHED-ADD " . ($_POST['from'] ?? '') . '-' . ($_POST['to'] ?? ''));
	}
	nc_api_check_save(nc_store_save($store));
	nc_out(array('ok' => true, 'schedule' => array_values($store['schedule'])));
}

case 'global': {
	/*
	 * The network-wide per-device ceiling. Both pipes are masked per address,
	 * so this is "each device may use up to N", not "all devices share N".
	 */
	$dn = (int)($_POST['down'] ?? 0);
	$up = (int)($_POST['up'] ?? 0);
	if ($dn < 1 || $dn > 100 || $up < 1 || $up > 100) {
		nc_out(array('ok' => false, 'error' => 'القيم لازم تكون بين 1 و 100 ميجا'), 400);
	}

	global $config;
	$changed = 0;
	foreach ($config['dnshaper']['queue'] as $i => $q) {
		if (($q['name'] ?? '') === 'PerDevice_Down_5M') {
			$config['dnshaper']['queue'][$i]['bandwidth']['item'][0]['bw'] = (string)$dn;
			$config['dnshaper']['queue'][$i]['bandwidth']['item'][0]['bwscale'] = 'Mb';
			$changed++;
		}
		if (($q['name'] ?? '') === 'NoLimit_Up') {
			$config['dnshaper']['queue'][$i]['bandwidth']['item'][0]['bw'] = (string)$up;
			$config['dnshaper']['queue'][$i]['bandwidth']['item'][0]['bwscale'] = 'Mb';
			$changed++;
		}
	}
	if ($changed) {
		write_config("netcontrol: الحد العام {$dn}/{$up} ميجا لكل جهاز");
	}
	/* apply to the running kernel too, so it takes effect without a reload */
	mwexec("/sbin/dnctl pipe 22 config bw {$dn}Mbit/s mask dst-ip 0xffffffff 2>/dev/null");
	mwexec("/sbin/dnctl pipe 23 config bw {$up}Mbit/s mask src-ip 0xffffffff 2>/dev/null");

	nc_log("GLOBAL الحد العام = {$dn} نزول / {$up} رفع ميجا لكل جهاز");
	nc_out(array('ok' => true, 'down' => $dn, 'up' => $up));
}

case 'speedtest': {
	/*
	 * Measure the line, then say honestly whether the figure is the line's
	 * capacity or merely what was left over while it was already busy.
	 */
	$before = nc_rates(1);
	$raw = shell_exec('/usr/local/bin/curl -s -o /dev/null -w "%{speed_download}" ' .
		'--max-time 25 https://speed.cloudflare.com/__down?bytes=26214400 2>/dev/null');
	$bps  = (float)trim((string)$raw);
	$mbps = round($bps * 8 / 1000000, 1);
	$busy = $before['wan_in'];

	nc_api_lock_store();
	$store = nc_store_load();
	$store['source']['down'] = max($store['source']['down'] ?? 0, $mbps + $busy);
	$store['source']['at']   = time();
	nc_api_check_save(nc_store_save($store));

	nc_log(sprintf("SPEEDTEST %.1f Mbps (الخط كان مشغول بـ %.1f)", $mbps, $busy));
	nc_out(array('ok' => true, 'measured' => $mbps, 'busy' => $busy,
		'capacity' => $store['source']['down'],
		'reliable' => ($busy < 3)));
}

default:
	nc_out(array('ok' => false, 'error' => 'إجراء غير معروف'), 400);
}
?>
