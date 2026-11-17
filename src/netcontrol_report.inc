<?php
/*
 * netcontrol: daily reports.
 *
 * One function gathers a day's figures; another renders them as a
 * standalone HTML file the admin can save, print or send on.
 *
 * Everything here reads what already exists - the usage tally, the portal
 * log, our own action log, and the kernel's own rules. Nothing is recorded
 * specially for reporting, so a report can be produced for any of the days
 * the usage history still holds.
 *
 * Figures about enforcement are read from pf and dnctl rather than from the
 * store, for the same reason the self test does: the store says what was
 * intended, and the kernel says what is actually in force.
 */

/* Which days can be reported on? Newest first. */
function nc_report_days() {
	$hist = @json_decode(@file_get_contents('/var/db/netcontrol_usage.json'), true);
	if (!is_array($hist)) {
		return array();
	}
	unset($hist['_last'], $hist['_who'], $hist['_split']);
	$days = array_keys($hist);
	rsort($days);
	return $days;
}

/*
 * A day's figures.
 *
 * $day is YYYY-MM-DD. Today is partial by definition - the day is not over -
 * and the report says so rather than presenting a half day as a whole one.
 */
function nc_report($day = null) {
	$day = $day ?: date('Y-m-d');
	$r = array('day' => $day, 'partial' => ($day === date('Y-m-d')),
	           'generated' => time());

	/* ---- devices, as they stand now ---- */
	$devs = nc_devices();
	$c = array('total' => count($devs), 'online' => 0, 'blocked' => 0,
	           'limited' => 0, 'random_mac' => 0, 'named' => 0, 'portal' => 0);
	foreach ($devs as $d) {
		if (!empty($d['online']))  { $c['online']++; }
		if (!empty($d['blocked'])) { $c['blocked']++; }
		if (!empty($d['limit']))   { $c['limited']++; }
		if (!empty($d['random']))  { $c['random_mac']++; }
		if (!empty($d['hostname']) || !empty($d['name'])) { $c['named']++; }
		if (!empty($d['cp_user'])) { $c['portal']++; }
	}
	$r['devices'] = $c;

	/* ---- what each device used that day ---- */
	$hist = @json_decode(@file_get_contents('/var/db/netcontrol_usage.json'), true);
	$rows = (is_array($hist) && isset($hist[$day])) ? $hist[$day] : array();
	arsort($rows);

	/*
	 * What the tick recorded about each device while it was still online.
	 * The day's heaviest consumer has often gone home by the time anyone
	 * reads the report, and a bare MAC is not something an admin can act
	 * on - so fall back to the name and account it had at the time.
	 */
	$who = (is_array($hist) && isset($hist['_who'])) ? $hist['_who'] : array();

	$name = $ipof = $user = array();
	foreach ($devs as $d) {
		if (empty($d['mac'])) {
			continue;
		}
		$name[$d['mac']] = $d['name'] ?: ($d['hostname'] ?: '');
		$ipof[$d['mac']] = $d['ip'] ?? '';
		$user[$d['mac']] = $d['cp_user'] ?? '';
	}
	$top = array();
	$total = 0;
	foreach ($rows as $mac => $bytes) {
		$total += $bytes;
		if (count($top) < 25) {
			$top[] = array(
				'mac'   => $mac,
				'ip'    => ($ipof[$mac] ?? '') ?: ($who[$mac]['ip']   ?? ''),
				'name'  => ($name[$mac] ?? '') ?: ($who[$mac]['name'] ?? ''),
				'user'  => ($user[$mac] ?? '') ?: ($who[$mac]['user'] ?? ''),
				'gone'  => !isset($name[$mac]),
				'bytes' => (int)$bytes,
			);
		}
	}
	/*
	 * The same day seen three ways: the whole 24 hours, working hours only,
	 * and the two halves of the day. They answer different questions - what
	 * the line carried, what the work cost, and when it happened - so the
	 * report gives all three rather than picking one.
	 */
	$sp = (is_array($hist) && isset($hist['_split'][$day]))
		? $hist['_split'][$day] : array();
	$w  = nc_work_hours();
	$r['usage'] = array(
		'total'   => $total,
		'devices' => count($rows),
		'top'     => $top,
		'work'    => array_sum($sp['work'] ?? array()),
		'off'     => array_sum($sp['off']  ?? array()),
		'am'      => array_sum($sp['am']   ?? array()),
		'pm'      => array_sum($sp['pm']   ?? array()),
		'hours'   => sprintf('%02d:00-%02d:00', $w['from'], $w['to']),
	);

	/* ---- the portal's own record of the day ---- */
	$acc = $fail = $kick = 0;
	$users = array();
	$hours = array_fill(0, 24, 0);
	foreach (nc_login_log(3000) as $e) {
		if (!$e['at'] || date('Y-m-d', $e['at']) !== $day) {
			continue;
		}
		$k = $e['kind'];
		if (stripos($k, 'ACCEPT') !== false) {
			$acc++;
			$hours[(int)date('G', $e['at'])]++;
		}
		if (stripos($k, 'FAILURE') !== false) {
			$fail++;
		}
		if (stripos($k, 'TERMINAT') !== false || stripos($k, 'CONCURRENT') !== false) {
			$kick++;
		}
		if ($e['user'] !== '') {
			if (!isset($users[$e['user']])) {
				$users[$e['user']] = array('n' => 0, 'macs' => array());
			}
			$users[$e['user']]['n']++;
			if ($e['mac']) {
				$users[$e['user']]['macs'][$e['mac']] = 1;
			}
		}
	}
	$ul = array();
	foreach ($users as $u => $v) {
		$ul[] = array('user' => $u, 'logins' => $v['n'], 'devices' => count($v['macs']));
	}
	usort($ul, function($a, $b) { return $b['logins'] <=> $a['logins']; });
	$r['logins'] = array('accepted' => $acc, 'failed' => $fail, 'kicked' => $kick,
	                     'users' => array_slice($ul, 0, 15), 'by_hour' => $hours);

	/* ---- what is actually in force, asked of the kernel ---- */
	$tbl = array_filter(preg_split('/\s+/',
		trim((string)shell_exec('/sbin/pfctl -t ' . NC_TABLE . ' -T show 2>/dev/null'))));
	$anchor = (string)shell_exec("/sbin/pfctl -a 'userrules/netcontrol' -sr 2>/dev/null");
	$r['enforcement'] = array(
		'blocked_in_table' => count($tbl),
		'shaping_rules'    => substr_count($anchor, 'dnpipe'),
		'dns_redirect'     => substr_count((string)shell_exec(
			"/sbin/pfctl -a 'natearly/ncdns' -s nat 2>/dev/null"), 'rdr'),
		'doh_blocked'      => substr_count((string)shell_exec(
			"/sbin/pfctl -a 'userrules/ncdns' -sr 2>/dev/null"), 'block'),
		'site_blocks'      => function_exists('nc_dns_active') ? nc_dns_active() : 0,
	);

	/* ---- the line ---- */
	$g = nc_global_limits();
	$r['line'] = array('down_limit' => $g['down'], 'up_limit' => $g['up'],
	                   'devices_down' => $g['devices_down'],
	                   'devices_up' => $g['devices_up']);

	/* ---- what the system did that day, from its own log ---- */
	$acts = array();
	foreach (@file(NC_LOG, FILE_IGNORE_NEW_LINES) ?: array() as $l) {
		if (strpos($l, $day) !== 0) {
			continue;
		}
		if (preg_match('/\s([A-Z][A-Z0-9-]{2,})\s/', $l, $m)) {
			$acts[$m[1]] = ($acts[$m[1]] ?? 0) + 1;
		}
	}
	arsort($acts);
	$r['actions'] = $acts;

	return $r;
}

/*
 * The last seven days, for the weekly file.
 *
 * Built from the same stored history as the daily report - nothing extra
 * is recorded for it - so a week can be produced for as far back as the
 * history reaches.
 */
function nc_report_week($end = null) {
	$end  = $end ?: date('Y-m-d');
	$hist = @json_decode(@file_get_contents('/var/db/netcontrol_usage.json'), true);
	$who  = (is_array($hist) && isset($hist['_who'])) ? $hist['_who'] : array();
	$split = (is_array($hist) && isset($hist['_split'])) ? $hist['_split'] : array();
	if (!is_array($hist)) {
		$hist = array();
	}
	unset($hist['_last'], $hist['_who'], $hist['_split']);

	/* name and address for whatever is still present */
	$name = $ipof = array();
	foreach (nc_devices() as $d) {
		if (!empty($d['mac'])) {
			$name[$d['mac']] = $d['name'] ?: ($d['hostname'] ?: '');
			$ipof[$d['mac']] = $d['ip'] ?? '';
		}
	}

	$days  = array();
	$byMac = array();
	$total = $work = $off = 0;
	for ($i = 6; $i >= 0; $i--) {
		$d = date('Y-m-d', strtotime($end . " -$i days"));
		$rows = $hist[$d] ?? array();
		$t = array_sum($rows);
		$wv = array_sum($split[$d]['work'] ?? array());
		$ov = array_sum($split[$d]['off']  ?? array());
		$days[] = array(
			'day'     => $d,
			'dow'     => (int)date('w', strtotime($d)),
			'total'   => $t,
			'work'    => $wv,
			'off'     => $ov,
			'devices' => count($rows),
			'workday' => in_array((int)date('w', strtotime($d)),
			                      nc_work_hours()['days'], true),
		);
		$total += $t;
		$work  += $wv;
		$off   += $ov;
		foreach ($rows as $mac => $b) {
			$byMac[$mac] = ($byMac[$mac] ?? 0) + $b;
		}
	}
	arsort($byMac);

	$top = array();
	foreach (array_slice($byMac, 0, 25, true) as $mac => $b) {
		$top[] = array(
			'mac'   => $mac,
			'ip'    => ($ipof[$mac] ?? '') ?: ($who[$mac]['ip']   ?? ''),
			'name'  => ($name[$mac] ?? '') ?: ($who[$mac]['name'] ?? ''),
			'user'  => $who[$mac]['user'] ?? '',
			'gone'  => !isset($name[$mac]),
			'bytes' => (int)$b,
		);
	}

	$wh = nc_work_hours();
	return array(
		'from'      => $days[0]['day'],
		'to'        => $end,
		'generated' => time(),
		'total'     => $total,
		'work'      => $work,
		'off'       => $off,
		'devices'   => count($byMac),
		'days'      => $days,
		'top'       => $top,
		'hours'     => sprintf('%02d:00-%02d:00', $wh['from'], $wh['to']),
	);
}

/* ----------------------------------------------------------- rendering */

function nc_rep_h($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function nc_rep_mb($b) {
	$b = (float)$b;
	if ($b >= 1073741824) { return number_format($b / 1073741824, 2) . ' GB'; }
	if ($b >= 1048576)    { return number_format($b / 1048576, 1) . ' MB'; }
	if ($b >= 1024)       { return number_format($b / 1024, 0) . ' KB'; }
	return (int)$b . ' B';
}

/*
 * The report as one self-contained HTML file.
 *
 * No external stylesheet, font or script: it has to survive being saved to
 * a laptop, emailed, or opened on a machine that cannot reach this firewall.
 * It prints cleanly too, which is why the colours are light and the layout
 * is plain blocks rather than a grid that reflows oddly on paper.
 */
function nc_report_html($r) {
	$h = 'nc_rep_h';
	$mb = 'nc_rep_mb';
	$day = $h($r['day']);
	$gen = date('Y-m-d H:i', $r['generated']);
	$d = $r['devices'];
	$u = $r['usage'];
	$l = $r['logins'];
	$e = $r['enforcement'];

	$peak = max(1, max($l['by_hour']));
	$bars = '';
	foreach ($l['by_hour'] as $hh => $n) {
		$pct = round($n / $peak * 100);
		$bars .= '<div class="hb"><i style="height:' . max(2, $pct) . '%"></i>'
		       . '<span>' . $hh . '</span></div>';
	}

	$rowsHtml = '';
	$rank = 0;
	foreach ($u['top'] as $x) {
		$rank++;
		$who = $x['name'] ?: ($x['user'] ?: ($x['ip'] ?: $x['mac']));
		if (!empty($x['gone'])) {
			$who .= ' (خرج من الشبكة)';
		}
		$sub = trim(($x['ip'] ?? '') . ' · ' . ($x['mac'] ?? ''), ' ·');
		if (!empty($x['user']) && $x['user'] !== $x['name']) {
			$sub .= ' · ' . $x['user'];
		}
		$pct = $u['total'] ? round($x['bytes'] / $u['total'] * 100, 1) : 0;
		$rowsHtml .= '<tr><td class="n">' . $rank . '</td>'
			. '<td><b>' . $h($who) . '</b><div class="m">' . $h($sub) . '</div></td>'
			. '<td class="r">' . $mb($x['bytes']) . '</td>'
			. '<td class="r">' . $pct . '%</td>'
			. '<td class="bar"><i style="width:' . min(100, $pct * 2) . '%"></i></td></tr>';
	}

	$userHtml = '';
	foreach ($l['users'] as $x) {
		$warn = $x['devices'] > 3 ? ' class="warn"' : '';
		$userHtml .= '<tr><td><b>' . $h($x['user']) . '</b></td>'
			. '<td class="r">' . $x['logins'] . '</td>'
			. '<td class="r"' . $warn . '>' . $x['devices'] . '</td></tr>';
	}

	$actHtml = '';
	foreach (array_slice($r['actions'], 0, 10, true) as $k => $n) {
		$actHtml .= '<tr><td>' . $h($k) . '</td><td class="r">' . $n . '</td></tr>';
	}

	$partial = !empty($r['partial'])
		? '<div class="note">التقرير ده عن يوم لسه ماخلصش — الأرقام بتاعة ' . $gen . '</div>'
		: '';

	$css = <<<'CSS'
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif;
  background:#f1f5f9;color:#0f172a;margin:0;padding:24px;direction:rtl;line-height:1.7}
.wrap{max-width:900px;margin:0 auto}
h1{font-size:22px;margin:0 0 4px}
.sub{color:#64748b;font-size:13px;margin-bottom:20px}
.note{background:#fef3c7;color:#b45309;border:1px solid #fcd34d;
  border-radius:9px;padding:10px 14px;font-size:13px;margin-bottom:18px}
.card{background:#fff;border:1px solid #e8edf3;border-radius:13px;
  padding:18px;margin-bottom:16px;box-shadow:0 1px 3px rgba(15,23,42,.05)}
.card h2{font-size:15px;margin:0 0 14px;padding-bottom:10px;
  border-bottom:1px solid #e8edf3}
.kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px}
.k{background:#f8fafc;border:1px solid #e8edf3;border-radius:10px;padding:13px}
.k .v{font-size:23px;font-weight:700;letter-spacing:-.5px;
  font-variant-numeric:tabular-nums}
.k .t{font-size:11.5px;color:#64748b;margin-top:3px}
.k.ok .v{color:#059669}.k.warn .v{color:#b45309}.k.bad .v{color:#dc2626}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:right;font-size:11.5px;color:#64748b;font-weight:600;
  padding:7px 8px;border-bottom:2px solid #e8edf3}
td{padding:8px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
td.r{text-align:left;font-variant-numeric:tabular-nums;white-space:nowrap}
td.n{color:#94a3b8;width:28px}
td.warn{color:#dc2626;font-weight:700}
.m{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:10.5px;
  color:#94a3b8;direction:ltr;text-align:right}
td.bar{width:110px}
td.bar i{display:block;height:6px;border-radius:99px;background:#2563eb}
.hours{display:flex;gap:3px;align-items:flex-end;height:90px;margin-top:6px}
.hb{flex:1;display:flex;flex-direction:column;justify-content:flex-end;
  align-items:center;height:100%}
.hb i{display:block;width:100%;background:#2563eb;border-radius:3px 3px 0 0}
.hb span{font-size:9px;color:#94a3b8;margin-top:3px}
.foot{text-align:center;color:#94a3b8;font-size:11.5px;margin-top:20px}
@media print{body{background:#fff;padding:0}.card{box-shadow:none;
  break-inside:avoid}}
CSS;

	$html = "<!DOCTYPE html>\n<html lang=\"ar\" dir=\"rtl\">\n<head>\n"
		. "<meta charset=\"utf-8\">\n"
		. "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
		. "<title>تقرير الشبكة — {$day}</title>\n<style>\n{$css}\n</style>\n"
		. "</head>\n<body>\n<div class=\"wrap\">\n"
		. "<h1>تقرير الشبكة</h1>\n"
		. "<div class=\"sub\">{$day} · اتعمل في {$gen}</div>\n"
		. $partial

		. "<div class=\"card\"><h2>الأجهزة</h2><div class=\"kpi\">"
		. "<div class=\"k\"><div class=\"v\">{$d['total']}</div><div class=\"t\">إجمالي معروف</div></div>"
		. "<div class=\"k ok\"><div class=\"v\">{$d['online']}</div><div class=\"t\">متصل دلوقتي</div></div>"
		. "<div class=\"k bad\"><div class=\"v\">{$d['blocked']}</div><div class=\"t\">موقوف</div></div>"
		. "<div class=\"k warn\"><div class=\"v\">{$d['limited']}</div><div class=\"t\">عليه حد سرعة</div></div>"
		. "<div class=\"k\"><div class=\"v\">{$d['portal']}</div><div class=\"t\">مسجّل دخول</div></div>"
		. "<div class=\"k warn\"><div class=\"v\">{$d['random_mac']}</div><div class=\"t\">MAC عشوائي</div></div>"
		. "</div></div>\n"

		. "<div class=\"card\"><h2>الاستهلاك</h2><div class=\"kpi\">"
		. "<div class=\"k\"><div class=\"v\">" . $mb($u['total']) . "</div><div class=\"t\">إجمالي اليوم</div></div>"
		. "<div class=\"k\"><div class=\"v\">{$u['devices']}</div><div class=\"t\">جهاز استهلك</div></div>"
		. "<div class=\"k\"><div class=\"v\">{$r['line']['down_limit']} / {$r['line']['up_limit']}</div>"
		. "<div class=\"t\">الحد العام نزول/رفع (ميجا)</div></div>"
		. "</div>\n<table style=\"margin-top:14px\"><tr><th></th><th>الجهاز</th>"
		. "<th class=\"r\">الاستهلاك</th><th class=\"r\">النسبة</th><th></th></tr>"
		. $rowsHtml . "</table></div>\n"

		. "<div class=\"card\"><h2>الدخول</h2><div class=\"kpi\">"
		. "<div class=\"k ok\"><div class=\"v\">{$l['accepted']}</div><div class=\"t\">دخول ناجح</div></div>"
		. "<div class=\"k warn\"><div class=\"v\">{$l['failed']}</div><div class=\"t\">محاولة فاشلة</div></div>"
		. "<div class=\"k " . ($l['kicked'] ? 'bad' : 'ok') . "\"><div class=\"v\">{$l['kicked']}</div>"
		. "<div class=\"t\">جلسة اتقطعت</div></div></div>"
		. "<div class=\"hours\">{$bars}</div>"
		. "<div class=\"sub\" style=\"margin:4px 0 14px\">الدخول على مدار الساعة</div>"
		. "<table><tr><th>المستخدم</th><th class=\"r\">دخول</th><th class=\"r\">أجهزة</th></tr>"
		. $userHtml . "</table></div>\n"

		. "<div class=\"card\"><h2>التنفيذ الفعلي</h2>"
		. "<div class=\"sub\" style=\"margin:-8px 0 12px\">مقروء من الفايروول نفسه، مش من الإعدادات</div>"
		. "<div class=\"kpi\">"
		. "<div class=\"k\"><div class=\"v\">{$e['blocked_in_table']}</div><div class=\"t\">عنوان محظور فعليًا</div></div>"
		. "<div class=\"k\"><div class=\"v\">{$e['shaping_rules']}</div><div class=\"t\">قاعدة تحديد سرعة</div></div>"
		. "<div class=\"k\"><div class=\"v\">{$e['site_blocks']}</div><div class=\"t\">موقع محظور</div></div>"
		. "<div class=\"k " . ($e['dns_redirect'] ? 'ok' : 'bad') . "\"><div class=\"v\">"
		. ($e['dns_redirect'] ? 'شغّال' : 'موقوف') . "</div><div class=\"t\">فرض الـDNS</div></div>"
		. "<div class=\"k " . ($e['doh_blocked'] ? 'ok' : 'bad') . "\"><div class=\"v\">"
		. ($e['doh_blocked'] ? 'شغّال' : 'موقوف') . "</div><div class=\"t\">حظر DoH</div></div>"
		. "</div></div>\n"

		. "<div class=\"card\"><h2>إجراءات النظام</h2>"
		. "<table><tr><th>الإجراء</th><th class=\"r\">مرات</th></tr>"
		. $actHtml . "</table></div>\n"

		. "<div class=\"foot\">netcontrol · pfSense</div>\n</div>\n</body>\n</html>";

	return $html;
}

/*
 * The week as one self-contained HTML file.
 *
 * Same rules as the daily one: no external stylesheet, font or script, so
 * it survives being saved, emailed, or opened on a machine that cannot
 * reach this firewall, and it prints cleanly.
 */
function nc_report_week_html($r) {
	$h  = 'nc_rep_h';
	$mb = 'nc_rep_mb';
	$gen = date('Y-m-d H:i', $r['generated']);
	$DOW = array('الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء',
	             'الخميس', 'الجمعة', 'السبت');

	$peak = 1;
	foreach ($r['days'] as $d) {
		$peak = max($peak, $d['total']);
	}

	$rowsHtml = '';
	foreach ($r['days'] as $d) {
		$pct = round($d['total'] / $peak * 100);
		$rowsHtml .= '<tr' . ($d['workday'] ? '' : ' class="off"') . '>'
			. '<td><b>' . $DOW[$d['dow']] . '</b><div class="m">' . $h($d['day']) . '</div></td>'
			. '<td class="r">' . $mb($d['total']) . '</td>'
			. '<td class="r">' . ($d['work'] ? $mb($d['work']) : '—') . '</td>'
			. '<td class="r">' . ($d['off'] ? $mb($d['off']) : '—') . '</td>'
			. '<td class="r">' . ($d['devices'] ?: '—') . '</td>'
			. '<td class="bar"><i style="width:' . max(1, $pct) . '%"></i></td></tr>';
	}

	$topHtml = '';
	$rank = 0;
	foreach ($r['top'] as $x) {
		$rank++;
		$who = $x['name'] ?: ($x['user'] ?: ($x['ip'] ?: $x['mac']));
		if (!empty($x['gone'])) {
			$who .= ' (خرج من الشبكة)';
		}
		$sub = trim(($x['ip'] ?? '') . ' · ' . ($x['mac'] ?? ''), ' ·');
		if (!empty($x['user']) && $x['user'] !== $x['name']) {
			$sub .= ' · ' . $x['user'];
		}
		$pct = $r['total'] ? round($x['bytes'] / $r['total'] * 100, 1) : 0;
		$topHtml .= '<tr><td class="n">' . $rank . '</td>'
			. '<td><b>' . $h($who) . '</b><div class="m">' . $h($sub) . '</div></td>'
			. '<td class="r">' . $mb($x['bytes']) . '</td>'
			. '<td class="r">' . $pct . '%</td>'
			. '<td class="bar"><i style="width:' . min(100, $pct * 2) . '%"></i></td></tr>';
	}

	$css = <<<'CSS'
*{box-sizing:border-box}
body{font-family:system-ui,-apple-system,"Segoe UI",Tahoma,Arial,sans-serif;
  background:#f1f5f9;color:#0f172a;margin:0;padding:24px;direction:rtl;line-height:1.7}
.wrap{max-width:900px;margin:0 auto}
h1{font-size:22px;margin:0 0 4px}
.sub{color:#64748b;font-size:13px;margin-bottom:20px}
.card{background:#fff;border:1px solid #e8edf3;border-radius:13px;
  padding:18px;margin-bottom:16px;box-shadow:0 1px 3px rgba(15,23,42,.05)}
.card h2{font-size:15px;margin:0 0 14px;padding-bottom:10px;
  border-bottom:1px solid #e8edf3}
.kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px}
.k{background:#f8fafc;border:1px solid #e8edf3;border-radius:10px;padding:13px}
.k .v{font-size:23px;font-weight:700;letter-spacing:-.5px;
  font-variant-numeric:tabular-nums}
.k .t{font-size:11.5px;color:#64748b;margin-top:3px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:right;font-size:11.5px;color:#64748b;font-weight:600;
  padding:7px 8px;border-bottom:2px solid #e8edf3}
td{padding:8px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
td.r{text-align:left;font-variant-numeric:tabular-nums;white-space:nowrap}
td.n{color:#94a3b8;width:28px}
tr.off{background:#fafbfc;color:#64748b}
.m{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:10.5px;
  color:#94a3b8;direction:ltr;text-align:right}
td.bar{width:110px}
td.bar i{display:block;height:6px;border-radius:99px;background:#2563eb}
.foot{text-align:center;color:#94a3b8;font-size:11.5px;margin-top:20px}
@media print{body{background:#fff;padding:0}.card{box-shadow:none;break-inside:avoid}}
CSS;

	return "<!DOCTYPE html>\n<html lang=\"ar\" dir=\"rtl\">\n<head>\n"
		. "<meta charset=\"utf-8\">\n"
		. "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">\n"
		. "<title>تقرير الأسبوع — {$h($r['from'])} إلى {$h($r['to'])}</title>\n"
		. "<style>\n{$css}\n</style>\n</head>\n<body>\n<div class=\"wrap\">\n"
		. "<h1>تقرير الأسبوع</h1>\n"
		. "<div class=\"sub\">" . $h($r['from']) . " إلى " . $h($r['to'])
		. " · اتعمل في {$gen} · ساعات العمل " . $h($r['hours']) . "</div>\n"

		. "<div class=\"card\"><h2>الإجمالي</h2><div class=\"kpi\">"
		. "<div class=\"k\"><div class=\"v\">" . $mb($r['total']) . "</div>"
		. "<div class=\"t\">الأسبوع كله</div></div>"
		. "<div class=\"k\"><div class=\"v\">" . $mb($r['work']) . "</div>"
		. "<div class=\"t\">في ساعات العمل</div></div>"
		. "<div class=\"k\"><div class=\"v\">" . $mb($r['off']) . "</div>"
		. "<div class=\"t\">بره ساعات العمل</div></div>"
		. "<div class=\"k\"><div class=\"v\">{$r['devices']}</div>"
		. "<div class=\"t\">جهاز استهلك</div></div>"
		. "</div></div>\n"

		. "<div class=\"card\"><h2>كل يوم</h2><table><tr><th>اليوم</th>"
		. "<th class=\"r\">الإجمالي</th><th class=\"r\">ساعات العمل</th>"
		. "<th class=\"r\">بره الساعات</th><th class=\"r\">أجهزة</th><th></th></tr>"
		. $rowsHtml . "</table></div>\n"

		. "<div class=\"card\"><h2>أعلى الأجهزة في الأسبوع</h2><table><tr><th></th>"
		. "<th>الجهاز</th><th class=\"r\">الاستهلاك</th><th class=\"r\">النسبة</th>"
		. "<th></th></tr>" . $topHtml . "</table></div>\n"

		. "<div class=\"foot\">netcontrol · pfSense</div>\n</div>\n</body>\n</html>";
}
