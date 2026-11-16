<?php
/*
 * netcontrol: who a device is, and what is wrong with it.
 *
 * Two jobs live here, and both only need names and records that the rest of
 * the package already has:
 *
 *   1. identity   - the log says "02:db:ef:8f:25:36 (192.168.1.143)", which
 *                   tells nobody anything. These functions attach the host
 *                   name and the portal user, and remember them per MAC so a
 *                   line about a phone that has left the network can still say
 *                   whose it was.
 *   2. diagnosis  - type a MAC, an IP, a host name or a person and get every
 *                   reason that device might be slow, blocked or missing, in
 *                   one list.
 *
 * Everything below is a pure function of the data it is handed. The pieces that
 * talk to pf, dummynet or the portal are gathered once, in nc_diag_context(),
 * so the logic can be tested away from a firewall.
 */

if (!function_exists('nc_norm_mac')) {
	function nc_norm_mac($mac) {
		$mac = strtolower(preg_replace('/[^0-9a-f]/i', '', (string)$mac));
		return strlen($mac) === 12 ? implode(':', str_split($mac, 2)) : '';
	}
}

/* ------------------------------------------------------------- identity */

/*
 * Remember the host name and the portal user of every MAC we can see.
 *
 * A value is never replaced by an empty one: a phone that stops announcing a
 * name, or a user who logs out, must not erase what we learned. The stamp only
 * moves when something changed or an hour has passed, so an idle network does
 * not rewrite the store every minute for nothing.
 */
function nc_ident_record(&$store, $names, $cp, $arp, $now = null) {
	$now   = $now ?: time();
	$id    = $store['ident'] ?? array();
	$dirty = false;

	$macs = array_unique(array_merge(
		array_keys($names),
		array_keys($cp['by_mac'] ?? array()),
		array_keys($arp)));

	foreach ($macs as $mac) {
		$host = $names[$mac]['host'] ?? '';
		$user = $cp['by_mac'][$mac]['username'] ?? '';
		$ip   = $arp[$mac]['ip'] ?? ($cp['by_mac'][$mac]['ip'] ?? '');
		$old  = $id[$mac] ?? array();

		$new = array(
			'host' => $host !== '' ? $host : ($old['host'] ?? ''),
			'user' => $user !== '' ? $user : ($old['user'] ?? ''),
			'ip'   => $ip   !== '' ? $ip   : ($old['ip']   ?? ''),
		);
		/* nothing to say about this one: do not store an empty shell */
		if ($new['host'] === '' && $new['user'] === '') {
			continue;
		}
		$changed = $new['host'] !== ($old['host'] ?? '') ||
		           $new['user'] !== ($old['user'] ?? '') ||
		           $new['ip']   !== ($old['ip']   ?? '');
		if ($changed || ($now - (int)($old['at'] ?? 0)) > 3600) {
			$new['at'] = $now;
			$id[$mac]  = $new;
			$dirty     = true;
		}
	}

	/* a hard ceiling, oldest out, in case the prune never gets to run */
	if (count($id) > 3000) {
		uasort($id, function ($a, $b) { return ($b['at'] ?? 0) <=> ($a['at'] ?? 0); });
		$id    = array_slice($id, 0, 3000, true);
		$dirty = true;
	}
	if ($dirty) {
		$store['ident'] = $id;
	}
	return $dirty;
}

/* Drop identities nobody has seen for $days. Returns how many went. */
function nc_ident_prune(&$store, $days = 90, $now = null) {
	$cut = ($now ?: time()) - $days * 86400;
	$n   = 0;
	foreach (($store['ident'] ?? array()) as $mac => $e) {
		if ((int)($e['at'] ?? 0) < $cut && !isset($store['devices'][$mac])) {
			unset($store['ident'][$mac]);
			$n++;
		}
	}
	return $n;
}

/*
 * A light map of every MAC we can name, built without sampling any rates.
 * Used by the log, which is read often and has no use for traffic figures.
 */
function nc_ident_map() {
	$store = nc_store_load();
	$arp   = nc_arp_table();
	$names = nc_lease_names();
	$cp    = nc_portal_sessions();
	$map   = array();
	foreach (array_unique(array_merge(array_keys($arp), array_keys($names),
	         array_keys($cp['by_mac']))) as $mac) {
		$map[$mac] = array(
			'mac'      => $mac,
			'ip'       => $arp[$mac]['ip'] ?? '',
			'hostname' => $names[$mac]['host'] ?? '',
			'cp_user'  => $cp['by_mac'][$mac]['username'] ?? '',
			'online'   => isset($arp[$mac]),
		);
	}
	return array($store, $map);
}

/*
 * Who is this MAC (or, failing a MAC, this IP)?
 *
 * A device that is on the network right now is believed first. Otherwise the
 * remembered identity answers, and 'live' says which of the two it was, so the
 * screen can say "last user" instead of "user" for someone who has left.
 */
function nc_ident_for($mac, $ip, $store, $live) {
	$mac = $mac !== '' ? nc_norm_mac($mac) : '';
	$id  = $store['ident'] ?? array();
	$out = array('mac' => $mac, 'ip' => $ip, 'name' => '', 'host' => '',
	             'user' => '', 'live' => false);

	if ($mac === '' && $ip !== '') {
		foreach ($live as $m => $d) {
			if (($d['ip'] ?? '') === $ip && !empty($d['online'])) { $mac = $m; break; }
		}
		if ($mac === '') {
			/* an address may have changed hands: take the newest owner we know */
			$best = -1;
			foreach ($id as $m => $e) {
				if (($e['ip'] ?? '') === $ip && (int)($e['at'] ?? 0) > $best) {
					$best = (int)($e['at'] ?? 0);
					$mac  = $m;
				}
			}
		}
	}
	if ($mac === '') {
		return $out;
	}

	$d = $live[$mac] ?? null;
	$e = $id[$mac] ?? array();
	$r = $store['devices'][$mac] ?? array();

	$out['mac']  = $mac;
	$out['ip']   = $out['ip'] !== '' ? $out['ip'] : (($d['ip'] ?? '') ?: ($e['ip'] ?? ''));
	$out['host'] = (($d['hostname'] ?? '') !== '') ? $d['hostname'] : ($e['host'] ?? '');
	$out['name'] = (($r['name'] ?? '') !== '') ? $r['name'] : $out['host'];
	if ($d && ($d['cp_user'] ?? '') !== '') {
		$out['user'] = $d['cp_user'];
		$out['live'] = true;
	} else {
		$out['user'] = $e['user'] ?? '';
	}
	return $out;
}

/*
 * One identity per log line, same order as the lines. A line that names no
 * device (DNS-ENFORCE, PRUNE...) gets null, and so does one we cannot name.
 */
function nc_log_enrich($lines, $store, $live) {
	$out = array();
	foreach ($lines as $l) {
		/* skip "date time  admin  admin-ip  " so the admin's own address is not read as a device */
		$msg = preg_replace('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\s+\S+\s+\S+\s\s+/', '', $l);
		$mac = '';
		$ip  = '';
		if (preg_match('/\b([0-9a-f]{2}(?::[0-9a-f]{2}){5})\b/i', $msg, $m)) {
			$mac = $m[1];
		} elseif (preg_match('/\b(\d{1,3}(?:\.\d{1,3}){3})\b/', $msg, $m)) {
			$ip = $m[1];
		}
		if ($mac === '' && $ip === '') {
			$out[] = null;
			continue;
		}
		$w = nc_ident_for($mac, $ip, $store, $live);
		$out[] = ($w['name'] === '' && $w['host'] === '' && $w['user'] === '') ? null : array(
			'mac' => $w['mac'], 'name' => $w['name'], 'host' => $w['host'],
			'user' => $w['user'], 'live' => $w['live'],
		);
	}
	return $out;
}

/* ------------------------------------------------------------ searching */

/* The list the search box completes from: every device we can name. */
function nc_diag_directory($devs, $store) {
	$out  = array();
	$seen = array();
	foreach ($devs as $d) {
		$seen[$d['mac']] = true;
		$e = ($store['ident'][$d['mac']] ?? array());
		$out[] = array(
			'mac'    => $d['mac'],
			'ip'     => $d['ip'] ?? '',
			'name'   => $d['name'] ?? '',
			'host'   => $d['hostname'] ?? '',
			'user'   => ($d['cp_user'] ?? '') !== '' ? $d['cp_user'] : ($e['user'] ?? ''),
			'vendor' => $d['vendor'] ?? '',
			'online' => !empty($d['online']),
		);
	}
	/* devices that left but that we still remember */
	foreach (($store['ident'] ?? array()) as $mac => $e) {
		if (isset($seen[$mac])) {
			continue;
		}
		$out[] = array(
			'mac'    => $mac,
			'ip'     => $e['ip'] ?? '',
			'name'   => $store['devices'][$mac]['name'] ?? ($e['host'] ?? ''),
			'host'   => $e['host'] ?? '',
			'user'   => $e['user'] ?? '',
			'vendor' => '',
			'online' => false,
		);
	}
	return $out;
}

/*
 * Find the devices a query could mean. A MAC (full or partial), an IP (or the
 * start of one), or any piece of a name, host name, user or vendor.
 */
function nc_diag_search($q, $directory) {
	$q = trim((string)$q);
	if ($q === '') {
		return array();
	}
	$ql  = mb_strtolower($q);
	$hex = strtolower(preg_replace('/[^0-9a-f]/i', '', $q));
	/* looks like part of a MAC: only hex digits and separators, and enough of them */
	$maclike = preg_match('/^[0-9a-f:.\-]+$/i', $q) && strlen($hex) >= 4 &&
	           !preg_match('/^\d{1,3}(\.\d{0,3}){0,3}$/', $q);
	$iplike  = (bool)preg_match('/^\d{1,3}(\.\d{0,3}){0,3}$/', $q);

	$hits = array();
	foreach ($directory as $r) {
		$score = 0;
		if ($iplike && $r['ip'] !== '') {
			if ($r['ip'] === $q)                  { $score = 100; }
			elseif (strpos($r['ip'], $q) === 0)   { $score = 60; }
		}
		if ($maclike && strpos(str_replace(':', '', $r['mac']), $hex) !== false) {
			$score = max($score, str_replace(':', '', $r['mac']) === $hex ? 100 : 70);
		}
		foreach (array('name', 'host', 'user', 'vendor') as $f) {
			$v = mb_strtolower((string)$r[$f]);
			if ($v === '') { continue; }
			if ($v === $ql)                      { $score = max($score, 95); }
			elseif (mb_strpos($v, $ql) === 0)    { $score = max($score, 80); }
			elseif (mb_strpos($v, $ql) !== false){ $score = max($score, 50); }
		}
		if ($score > 0) {
			$r['score'] = $score + (!empty($r['online']) ? 5 : 0);
			$hits[] = $r;
		}
	}
	usort($hits, function ($a, $b) { return $b['score'] <=> $a['score']; });
	return array_slice($hits, 0, 30);
}

/* ------------------------------------------------------------ diagnosis */

function nc_ago($secs) {
	$secs = max(0, (int)$secs);
	if ($secs < 90)     { return 'من أقل من دقيقة'; }
	if ($secs < 5400)   { return 'من ' . round($secs / 60) . ' دقيقة'; }
	if ($secs < 129600) { return 'من ' . round($secs / 3600) . ' ساعة'; }
	return 'من ' . round($secs / 86400) . ' يوم';
}

/*
 * Everything the report needs that has to be read from the firewall.
 * Kept in one place so nc_diagnose() stays a pure function.
 */
function nc_diag_context($devs) {
	$store = nc_store_load();
	$tbl   = array_flip(array_filter(preg_split('/\s+/',
		trim((string)shell_exec('/sbin/pfctl -t ' . NC_TABLE . ' -T show 2>/dev/null')))));
	$log   = array();
	if (file_exists(NC_LOG)) {
		$log = array_slice(@file(NC_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array(), -600);
	}
	/* who else holds each address right now, to catch two devices on one IP */
	$owners = array();
	foreach ($devs as $d) {
		if (!empty($d['online']) && ($d['ip'] ?? '') !== '') {
			$owners[$d['ip']][] = $d['mac'];
		}
	}
	/* today's bytes per MAC, to say whether this device is a heavy user */
	$today = array();
	$u = @json_decode(@file_get_contents('/var/db/netcontrol_usage.json'), true);
	if (is_array($u)) {
		$today = $u[date('Y-m-d')] ?? array();
	}
	$pipes = (string)shell_exec('/sbin/dnctl pipe list 2>/dev/null');

	return array(
		'now'      => time(),
		'store'    => $store,
		'pf'       => $tbl,
		'anchor'   => (string)shell_exec("/sbin/pfctl -a 'userrules/netcontrol' -sr 2>/dev/null"),
		'pipes'    => $pipes,
		'res'      => nc_dhcp_reservations(),
		'portal'   => nc_portal_active(),
		'global'   => nc_global_limits(),
		'dns'      => nc_dns_enforced(),
		'policy'   => nc_policy_get(),
		'log'      => $log,
		'logins'   => nc_login_log(400),
		'owners'   => $owners,
		'today'    => $today,
	);
}

function nc_finding(&$list, $sev, $title, $detail = '', $fix = '') {
	$list[] = array('sev' => $sev, 'title' => $title, 'detail' => $detail, 'fix' => $fix);
}

/*
 * The report for one device. $d is a record from nc_devices() (or the same
 * shape built from the directory for a device that has left).
 *
 * Every finding is something we can show evidence for: a field on the record,
 * a line in the log, a row in the pf table. Nothing here guesses at a cause it
 * cannot see; where the cause is a guess, the finding says so.
 */
function nc_diagnose($d, $ctx) {
	$now   = $ctx['now'];
	$store = $ctx['store'];
	$mac   = $d['mac'];
	$ip    = $d['ip'] ?? '';
	$rule  = $store['devices'][$mac] ?? array();
	$f     = array();

	$online  = !empty($d['online']);
	$blocked = !empty($d['blocked']);
	$type    = $d['type'] ?? '';
	$phone   = in_array($type, array('phone', 'mobile', 'apple'), true);

	/* the log lines that mention this device, newest first */
	$events = array();
	foreach (array_reverse($ctx['log']) as $l) {
		if (stripos($l, $mac) !== false) {
			$events[] = $l;
			if (count($events) >= 12) { break; }
		}
	}
	/* the portal's own record of this device */
	$logins = array();
	foreach ($ctx['logins'] as $e) {
		if (($e['mac'] ?? '') === $mac || ($ip !== '' && ($e['ip'] ?? '') === $ip)) {
			$logins[] = $e;
			if (count($logins) >= 10) { break; }
		}
	}

	/* ---- presence */
	if (!$online) {
		$seen = $d['last_seen'] ?? null;
		nc_finding($f, 'MED', 'الجهاز مش متصل بالشبكة دلوقتي',
			$seen ? 'آخر ظهور ' . nc_ago($now - $seen) . '.' : 'عمره ما اتشاف على الشبكة دي.',
			'لو المفروض إنه شغال: اتأكد إنه على الواي فاي الصح أو الكابل موصول، وإن الـ DHCP مش واقف.');
	}

	/* ---- blocked, why, and whether the block is real */
	if (!empty($d['protected']) && $blocked) {
		nc_finding($f, 'CRITICAL', 'جهاز محمي وموقوف في نفس الوقت',
			'الجهاز ده من البنية الأساسية ومايتوقفش من هنا.', 'شيل الحظر فورًا.');
	}
	if ($blocked) {
		/* the store records whether the policy or a person set the block */
		$why  = !empty($rule['by_policy'])
			? 'اتوقف أوتوماتيك بسياسة الأجهزة الجديدة'
			: 'اتوقف يدويًا من لوحة التحكم';
		$when = '';
		foreach ($events as $l) {
			/* "date time  admin  admin-ip  MSG": the first block line is the newest */
			if (!preg_match('/^(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\s+(\S+)\s+\S+\s\s+(?:POLICY )?BLOCK /', $l, $m)) {
				continue;
			}
			$when = $m[1];
			if (empty($rule['by_policy']) && $m[2] !== 'system') {
				$why .= ' بواسطة ' . $m[2];
			}
			break;
		}
		$exp = !empty($rule['expires']) ? ' وهيتفك ' . date('Y-m-d H:i', (int)$rule['expires']) . '.' : ' ومفيش وقت لفكه (دائم).';
		$sev = 'HIGH';
		nc_finding($f, $sev, 'الجهاز موقوف: مفيش نت عنده',
			$why . ($when ? ' بتاريخ ' . $when : '') . '.' . $exp,
			'من تبويب «الموقوفة» اضغط تشغيل. لو الجهاز لشخص معروف، سجّله دخول من البوابة بدل ما تفتحه يدوي.');
		if ($online && $ip !== '' && !isset($ctx['pf'][$ip])) {
			nc_finding($f, 'HIGH', 'الحظر مسجّل بس مش متنفّذ على الفايروول',
				'الجهاز موقوف في الإعدادات لكن عنوانه ' . $ip . ' مش في جدول الحظر، فالنت شغال عنده فعليًا.',
				'اعمل «تحديث» أو شغّل netcontrol_tick.php، ولو فضل كده شغّل netcontrol_audit.php.');
		}
		if ($online && ($d['rate'] ?? 0) > 0.05) {
			nc_finding($f, 'HIGH', 'جهاز موقوف ولسه بيمرر داتا',
				sprintf('بيسحب %.2f ميجا/ث رغم الحظر.', $d['rate']),
				'الحظر بيفلت: راجع إن الجهاز مش على IP تاني أو MAC عشوائي اتغير.');
		}
	}

	/* ---- the portal */
	if ($ctx['portal'] && ($d['cp_user'] ?? '') === '' && empty($d['protected'])) {
		nc_finding($f, 'MED', 'مش مسجّل دخول في الكابتيف بورتال',
			'البوابة شغالة والجهاز ده مالوش جلسة، فالنت ممكن يتوقف لحد ما يسجّل.',
			'خلّي المستخدم يفتح أي موقع ويدخل باليوزر بتاعه.');
	}
	if ($logins) {
		$bad = 0; $acc = null;
		foreach ($logins as $e) {
			if (stripos($e['kind'], 'ACCEPT') !== false && !$acc) { $acc = $e; }
			if (stripos($e['kind'], 'CONCURRENT') !== false || stripos($e['kind'], 'TERMINAT') !== false) { $bad++; }
		}
		if ($bad) {
			nc_finding($f, 'MED', 'الجلسة اتقفلت أكتر من مرة',
				"$bad مرة في آخر سجل الدخول: اتفصل بسبب دخول نفس اليوزر من جهاز تاني أو انتهاء الجلسة.",
				'لو نفس اليوزر على جهازين: حدد عدد الجلسات المسموحة في إعدادات البوابة.');
		}
		if ($acc) {
			nc_finding($f, 'INFO', 'آخر دخول ناجح',
				'اليوزر ' . $acc['user'] . ($acc['at'] ? ' ' . nc_ago($now - $acc['at']) : '') . '.');
		}
	}

	/* ---- identity trouble */
	if (!empty($d['random'])) {
		if (empty($d['fp'])) {
			nc_finding($f, 'MED', 'MAC عشوائي ومفيش بصمة نتابعه بيها',
				'الموبايل بيغيّر عنوانه كل شوية، فأي حظر أو حد ممكن يفلت ويظهر جهاز جديد في كل اتصال.',
				'اطلب إيقاف «Private/Random MAC» للشبكة دي في إعدادات الواي فاي، أو ثبّت حجز DHCP.');
		} else {
			nc_finding($f, 'INFO', 'MAC عشوائي بس متابَع',
				'الجهاز بيتتابع ببصمة اسمه فالقواعد بتتنقل معاه لو غيّر الـ MAC.');
		}
	}
	if (($d['hostname'] ?? '') === '') {
		nc_finding($f, 'LOW', 'الجهاز مابيبعتش اسم',
			'ده سبب إن اسم الكمبيوتر مش ظاهر: غالبًا موبايل بـ MAC عشوائي أو جهاز مش بيبلّغ DHCP.',
			'سمّيه يدوي من تبويب الأجهزة، أو سجّل دخول بيوزر علشان يظهر اسم الشخص.');
	}
	if (!empty($d['moved'])) {
		nc_finding($f, 'INFO', 'العنوان اتغير',
			'كان ' . ($d['prev_ip'] ?? '?') . ' وبقى ' . $ip . '، والقواعد اتنقلت معاه.');
	}
	if ($ip !== '' && count($ctx['owners'][$ip] ?? array()) > 1) {
		nc_finding($f, 'HIGH', 'عنوان IP متكرر',
			'أكتر من جهاز على ' . $ip . ': ' . implode('، ', $ctx['owners'][$ip]) . '. ده بيعمل قطع متقطع للاتنين.',
			'شيل الـ IP الثابت من واحد منهم أو اعمل حجز DHCP لكل جهاز.');
	}
	if (isset($ctx['res'][$mac]) && $ip !== '' && ($ctx['res'][$mac]['ip'] ?? '') !== '' &&
	    $ctx['res'][$mac]['ip'] !== $ip && $online) {
		nc_finding($f, 'MED', 'الجهاز مش على عنوان الحجز بتاعه',
			'محجوزله ' . $ctx['res'][$mac]['ip'] . ' وشغال على ' . $ip . ': غالبًا IP ثابت يدوي على الجهاز.',
			'خلّي الجهاز DHCP، أو غيّر الحجز للعنوان الفعلي.');
	}

	/* ---- speed */
	$lim = $rule['limit'] ?? null;
	if ($lim && !$blocked) {
		$pipe = NC_PIPE_BASE + (crc32($mac) % 500);
		$has_pipe = strpos($ctx['pipes'], sprintf('%05d:', $pipe)) !== false;
		$has_rule = $ip !== '' && strpos($ctx['anchor'], $ip) !== false;
		if ($online && (!$has_pipe || !$has_rule)) {
			nc_finding($f, 'HIGH', 'الحد متسجّل بس مش متنفّذ',
				'الجهاز عليه حد ' . $lim . ' ميجا لكن ' . (!$has_pipe ? 'مفيش pipe له' : 'مفيش قاعدة بتوجّه عليه') . ' في الفايروول.',
				'اعمل «تحديث» أو أعد تطبيق الحد.');
		} else {
			nc_finding($f, 'INFO', 'عليه حد سرعة خاص: ' . $lim . ' ميجا',
				'ده أقل من الحد العام، فهو سبب لو الجهاز بطيء.', 'غيّر الحد من تفاصيل الجهاز.');
		}
		if ($online && ($d['rate'] ?? 0) >= 0.9 * (float)$lim) {
			nc_finding($f, 'MED', 'بيستهلك أقصى حد مسموح له',
				sprintf('بيسحب %.1f من %s ميجا.', $d['rate'], $lim), 'ارفع الحد لو ده مقصود.');
		}
	} elseif (!$blocked) {
		$g = $ctx['global'];
		if (!empty($g['down']) && $g['down'] <= 2) {
			nc_finding($f, 'MED', 'الحد العام لكل جهاز صغير',
				'كل جهاز مالوش حد خاص واخد ' . $g['down'] . ' ميجا نزول بس.', 'ارفع الحد العام من فوق الصفحة.');
		} elseif (!empty($g['down'])) {
			nc_finding($f, 'INFO', 'تحت الحد العام',
				'مالوش حد خاص فبياخد الحد العام: ' . $g['down'] . ' ميجا نزول و' . ($g['up'] ?? '?') . ' رفع.');
		}
	}
	if ($online && !$blocked && ($d['rate'] ?? 0) > 10) {
		nc_finding($f, 'LOW', 'استهلاك عالي دلوقتي', sprintf('بيسحب %.1f ميجا/ث.', $d['rate']));
	}
	$bytes = (int)($ctx['today'][$mac] ?? 0);
	if ($bytes > 0 && count($ctx['today']) >= 4) {
		$all = array_values($ctx['today']); rsort($all);
		if ($bytes >= $all[0] && $bytes > 500 * 1048576) {
			nc_finding($f, 'LOW', 'أعلى مستهلك النهاردة', sprintf('%.1f جيجا من إجمالي الأجهزة.', $bytes / 1073741824),
				'لو الشبكة بطيئة عند غيره، ده أول مشتبه.');
		}
	}

	/* ---- rules that reach this device from outside */
	if (!empty($rule['group'])) {
		nc_finding($f, 'INFO', 'ضمن مجموعة: ' . $rule['group'],
			'أي قاعدة على المجموعة بتتطبق عليه.');
	}
	$pol = $ctx['policy'];
	if (!empty($pol['enabled']) && !isset($store['policy_seen'][$mac]) && !$blocked) {
		nc_finding($f, 'MED', 'سياسة الأجهزة الجديدة هتتطبق عليه',
			'السياسة شغالة (' . ($pol['action'] ?? 'block') . ') والجهاز لسه ماتصرفش معاه.',
			'سمّيه أو سجّله دخول، أو استثنِه قبل أول مرور للسياسة.');
	}
	if ($ctx['dns'] && $phone) {
		nc_finding($f, 'INFO', 'DNS الإجباري شغال',
			'أي تطبيق بيستخدم DoH أو «Private DNS» على الموبايل بيتحجب: لو التطبيقات «مفيش نت»، اقفل Private DNS على الجهاز.');
	}

	/* ---- verdict */
	$rank = array('CRITICAL' => 5, 'HIGH' => 4, 'MED' => 3, 'LOW' => 2, 'INFO' => 1, 'OK' => 0);
	usort($f, function ($a, $b) use ($rank) { return $rank[$b['sev']] <=> $rank[$a['sev']]; });
	$counts = array('CRITICAL' => 0, 'HIGH' => 0, 'MED' => 0, 'LOW' => 0, 'INFO' => 0);
	foreach ($f as $x) { $counts[$x['sev']]++; }
	$problems = $counts['CRITICAL'] + $counts['HIGH'] + $counts['MED'];
	if (!$problems) {
		array_unshift($f, array('sev' => 'OK', 'title' => 'مفيش مشاكل ظاهرة',
			'detail' => 'الجهاز مش موقوف ولا محدود بشكل غريب، ومفيش تعارض في العنوان.', 'fix' => ''));
	}
	$verdict = $counts['CRITICAL'] || $counts['HIGH'] ? 'bad' : ($counts['MED'] ? 'warn' : 'ok');

	return array(
		'verdict'  => $verdict,
		'counts'   => $counts,
		'findings' => $f,
		'identity' => array(
			'mac' => $mac, 'ip' => $ip, 'name' => $d['name'] ?? '', 'host' => $d['hostname'] ?? '',
			'user' => $d['cp_user'] ?? '', 'vendor' => $d['vendor'] ?? '', 'type' => $d['type_name'] ?? '',
			'online' => $online, 'group' => $rule['group'] ?? '',
			'reserved' => !empty($d['reserved']), 'random' => !empty($d['random']),
			'last_seen' => $d['last_seen'] ?? null,
		),
		'events'   => $events,
		'logins'   => $logins,
	);
}
