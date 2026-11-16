<?php
/*
 * netcontrol.inc  —  core library for the Network Control page
 *
 * Identity model
 * --------------
 * A device is identified by its MAC, never by its IP. Rules are stored
 * against the MAC and re-applied to whatever IP that MAC currently holds,
 * so a DHCP change never orphans a rule or hits the wrong device.
 *
 * A MAC whose second hex digit is 2, 6, A or E is locally administered —
 * a randomised "private" address. Those rotate, so they are pinned by IP
 * plus a DHCP reservation instead, and flagged in the UI as the weaker case.
 *
 * Nothing here writes config.xml directly; callers go through the normal
 * pfSense config functions so backups and the config history stay correct.
 */

require_once("config.inc");
require_once("util.inc");
require_once("interfaces.inc");

define('NC_STORE',    '/var/db/netcontrol.json');
define('NC_LOG',      '/var/log/netctl.log');
define('NC_PIPE_BASE', 300);      // per-device pipes live at 300+
define('NC_TABLE',    'nc_blocked');
/*
 * The interfaces and addresses are read from the running configuration, not
 * fixed here: em1/em2 and 192.168.1.x are one installation's layout, and
 * hardcoding them made the plugin silently wrong anywhere else - it would
 * parse the wrong interface and filter out every real client.
 *
 * Override any of these in /usr/local/pkg/netcontrol.conf.php if the
 * automatic detection picks the wrong interface.
 */
@include_once('/usr/local/pkg/netcontrol.conf.php');

function nc_cfg($key, $default = null) {
	static $cache = array();
	if (array_key_exists($key, $cache)) {
		return $cache[$key];
	}
	$const = 'NC_OVERRIDE_' . strtoupper($key);
	if (defined($const)) {
		return $cache[$key] = constant($const);
	}

	switch ($key) {
	case 'lan_if':
		$v = get_real_interface('lan');
		break;
	case 'wan_if':
		/*
		 * The interface carrying the default route, not the one labelled
		 * "wan". On a box with several uplinks the labelled WAN may be
		 * down or a standby: this firewall's "wan" (em3) has no address
		 * at all while the traffic leaves through opt2 (em2). Parsing the
		 * wrong interface means reading zero client traffic, which looks
		 * like an idle network rather than a misconfiguration.
		 */
		$v = '';
		$rt = shell_exec('/usr/bin/netstat -rn -f inet 2>/dev/null');
		foreach (explode("
", (string)$rt) as $line) {
			if (preg_match('/^(?:0\.0\.0\.0|default)\s+\S+\s+\S+\s+(\S+)/',
			               $line, $m)) {
				$v = $m[1];
				break;
			}
		}
		if ($v === '') {
			$v = get_real_interface('wan');   /* single-uplink fallback */
		}
		break;
	case 'lan_ip':
		$v = get_interface_ip('lan');
		break;
	case 'lan_subnet':
		/* the network prefix, e.g. "192.168.1." - used to tell a local
		   client from a post-NAT address */
		$ip = get_interface_ip('lan');
		$bits = (int)get_interface_subnet('lan');
		$v = '';
		if ($ip && $bits >= 24) {
			$v = implode('.', array_slice(explode('.', $ip), 0, 3)) . '.';
		} elseif ($ip && $bits >= 16) {
			$v = implode('.', array_slice(explode('.', $ip), 0, 2)) . '.';
		}
		break;
	case 'lan_cidr':
		$ip = get_interface_ip('lan');
		$bits = (int)get_interface_subnet('lan');
		$v = ($ip && $bits) ? gen_subnet($ip, $bits) . '/' . $bits : '';
		break;
	case 'cp_zone':
		/* the first configured captive portal zone, if any */
		$v = '';
		foreach ((array)config_get_path('captiveportal', array()) as $z => $c) {
			$v = $z;
			break;
		}
		break;
	default:
		$v = $default;
	}
	return $cache[$key] = ($v !== '' && $v !== null) ? $v : $default;
}

/* kept as constants for the call sites that already use them */
define('NC_LAN_IF',   nc_cfg('lan_if',  'em1'));
define('NC_LAN_IP',   nc_cfg('lan_ip',  '192.168.1.1'));
define('NC_WAN_IF',   nc_cfg('wan_if',  'em2'));

/* Devices that must never be blockable, whatever the UI sends. */
function nc_protected_ips() {
	/*
	 * The firewall itself is always protected. Anything else an installation
	 * wants to keep untouchable - a switch, an access point, a hypervisor -
	 * goes in netcontrol.conf.php as NC_OVERRIDE_PROTECTED, because those
	 * addresses differ on every network.
	 */
	$list = array();
	$self = nc_cfg('lan_ip');
	if ($self) {
		$list[] = $self;
	}
	if (defined('NC_OVERRIDE_PROTECTED')) {
		foreach (explode(',', constant('NC_OVERRIDE_PROTECTED')) as $ip) {
			$ip = trim($ip);
			if ($ip !== '') {
				$list[] = $ip;
			}
		}
	}
	return array_values(array_unique($list));
}

/* ------------------------------------------------------------------ store */

function nc_store_load() {
	$d = array('devices' => array(), 'sites' => array(), 'video' => array(),
	           'schedule' => array(), 'groups' => array(), 'attempts' => array());
	if (file_exists(NC_STORE)) {
		$j = json_decode(@file_get_contents(NC_STORE), true);
		if (is_array($j)) {
			$d = array_merge($d, $j);
		}
	}
	return $d;
}

function nc_store_save($d) {
	$tmp = NC_STORE . '.tmp';
	if (file_put_contents($tmp, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
		return false;
	}
	@chmod($tmp, 0600);
	return rename($tmp, NC_STORE);
}

function nc_log($msg) {
	$user = $_SESSION['Username'] ?? 'system';
	$ip   = $_SERVER['REMOTE_ADDR'] ?? '-';
	$line = sprintf("%s  %-12s %-15s  %s\n", date('Y-m-d H:i:s'), $user, $ip, $msg);
	@file_put_contents(NC_LOG, $line, FILE_APPEND | LOCK_EX);
	/* keep the log from growing without bound */
	if (@filesize(NC_LOG) > 2000000) {
		@rename(NC_LOG, NC_LOG . '.0');
	}
}

/* ------------------------------------------------------------- identity */

function nc_mac_is_random($mac) {
	$mac = strtolower(trim($mac));
	if (!preg_match('/^[0-9a-f]{2}:/', $mac)) {
		return false;
	}
	$second = $mac[1];
	return in_array($second, array('2', '6', 'a', 'e'));
}

function nc_norm_mac($mac) {
	return strtolower(trim($mac));
}

/* ------------------------------------------------------------- discovery */

/* Current ARP table: mac => ip, plus expiry/permanent info. */
function nc_arp_table() {
	$out = array();
	$raw = shell_exec('/usr/sbin/arp -an 2>/dev/null');
	foreach (explode("\n", (string)$raw) as $line) {
		if (!preg_match('/\((\d+\.\d+\.\d+\.\d+)\) at ([0-9a-f:]{17})/i', $line, $m)) {
			continue;
		}
		$ip  = $m[1];
		$mac = nc_norm_mac($m[2]);
		$out[$mac] = array(
			'ip'        => $ip,
			'permanent' => (strpos($line, 'permanent') !== false),
		);
	}
	return $out;
}

/*
 * ---------------------------------------------------------------- identity
 *
 * Vendor lookup from the OUI (first three bytes of the MAC). pfSense ships no
 * OUI database, so this is a hand-picked table of the prefixes that actually
 * appear on this network plus the common consumer vendors. It is used for two
 * things: showing who made the device, and guessing what kind of device it is
 * when the hostname does not say.
 */
function nc_oui_table() {
	return array(
		/* seen on this network */
		'00:0c:29' => array('VMware',    'vm'),
		'00:50:56' => array('VMware',    'vm'),
		'00:1c:14' => array('VMware',    'vm'),
		'38:63:bb' => array('HP',        'server'),
		'3c:d9:2b' => array('HP',        'server'),
		'00:00:c0' => array('Western Digital', 'nas'),
		'00:90:a9' => array('Western Digital', 'nas'),
		'dc:62:79' => array('TP-Link',   'ap'),
		'c8:b3:73' => array('Cisco/Linksys', 'router'),
		'80:7c:62' => array('Hikvision', 'camera'),
		'bc:ad:28' => array('Hikvision', 'camera'),
		'4c:bd:8f' => array('Dahua',     'camera'),
		'e8:9c:25' => array('Liteon',    'pc'),
		'74:56:3c' => array('Dell',      'pc'),
		'04:42:1a' => array('Dell',      'pc'),
		'10:7c:61' => array('Dell',      'pc'),
		'bc:c7:46' => array('Foxconn',   'pc'),
		'00:28:f8' => array('Intel',     'pc'),
		'34:f3:9a' => array('Intel',     'pc'),
		'4c:1d:96' => array('Intel',     'pc'),
		'b8:8a:60' => array('Intel',     'pc'),
		'3c:a9:f4' => array('Intel',     'pc'),
		'70:c9:4e' => array('Liteon',    'pc'),
		'c4:3d:1a' => array('Intel',     'pc'),
		'28:d0:43' => array('Samsung',   'phone'),
		'e4:a4:71' => array('Intel',     'pc'),
		'48:a4:72' => array('Intel',     'pc'),
		'fc:3f:db' => array('HP',        'pc'),
		'00:28:f8' => array('Intel',     'pc'),
		/* common consumer vendors */
		'd8:a2:5e' => array('Apple',     'apple'),
		'f0:18:98' => array('Apple',     'apple'),
		'a4:83:e7' => array('Apple',     'apple'),
		'3c:15:c2' => array('Apple',     'apple'),
		'ac:bc:32' => array('Apple',     'apple'),
		'd0:81:7a' => array('Apple',     'apple'),
		'f4:f5:d8' => array('Google',    'phone'),
		'd8:6c:63' => array('Google',    'phone'),
		'd0:04:01' => array('Xiaomi',    'phone'),
		'64:cc:2e' => array('Xiaomi',    'phone'),
		'd4:38:9c' => array('Samsung',   'phone'),
		'5c:97:f3' => array('Apple',     'apple'),
		'b4:2e:99' => array('Giga-Byte', 'pc'),
		'1c:69:7a' => array('Elitegroup','pc'),
		'00:1a:11' => array('Google',    'phone'),
		'd4:6e:0e' => array('TP-Link',   'ap'),
		'50:c7:bf' => array('TP-Link',   'ap'),
		'b0:be:76' => array('TP-Link',   'ap'),
		'00:1d:0f' => array('TP-Link',   'ap'),
		'08:00:27' => array('VirtualBox','vm'),
		'52:54:00' => array('QEMU/KVM',  'vm'),
		'00:15:5d' => array('Hyper-V',   'vm'),
		'b8:27:eb' => array('Raspberry Pi', 'iot'),
		'dc:a6:32' => array('Raspberry Pi', 'iot'),
		'e4:5f:01' => array('Raspberry Pi', 'iot'),
	);
}

/* Vendor name for a MAC, or '' when the prefix is unknown. */
function nc_vendor($mac) {
	if (nc_mac_is_random($mac)) {
		return '';           // a randomised MAC carries no real vendor
	}
	$p = strtolower(substr($mac, 0, 8));
	$t = nc_oui_table();
	return isset($t[$p]) ? $t[$p][0] : '';
}

/*
 * What kind of device is this? The hostname is the strongest signal when it
 * says something recognisable; the OUI is the fallback. Returns an array of
 * (type key, label in Arabic).
 */
function nc_device_type($mac, $hostname, $descr) {
	$h = strtolower($hostname . ' ' . $descr);

	$by_name = array(
		'camera|cam-|ipcam|dvr|nvr|hikvision|dahua' => array('camera', 'كاميرا'),
		'nas|storage|synology|qnap|truenas'         => array('nas',    'تخزين'),
		'ilo|idrac|ipmi|bmc'                        => array('mgmt',   'إدارة سيرفر'),
		'vmware|esxi|proxmox|hyper-?v|vm-|vcenter'  => array('vm',     'مُضيف افتراضي'),
		'server|srv-|dc01|ad-'                      => array('server', 'سيرفر'),
		'router|gateway|pfsense|mikrotik'           => array('router', 'راوتر'),
		'\\bap\\b|access-?point|re505|repeater|wifi' => array('ap',    'أكسس بوينت'),
		'switch|sw-'                                => array('switch', 'سويتش'),
		'printer|print-|hp-?lj|epson|canon'         => array('printer','طابعة'),
		'iphone|ipad|-ios|macbook|imac|-mac\\b'      => array('apple',  'جهاز Apple'),
		'android|galaxy|redmi|xiaomi|huawei|oppo'   => array('phone',  'موبايل'),
		'ws\\d|pc-|desktop|laptop|lap-'              => array('pc',     'كمبيوتر'),
		'raspberry|rpi|esp|tasmota|shelly'          => array('iot',    'جهاز IoT'),
	);
	foreach ($by_name as $re => $t) {
		if (preg_match('/' . $re . '/i', $h)) {
			return $t;
		}
	}

	if (nc_mac_is_random($mac)) {
		/* iOS and modern Android randomise; almost always a phone or laptop */
		return array('mobile', 'موبايل/لابتوب');
	}

	$p = strtolower(substr($mac, 0, 8));
	$t = nc_oui_table();
	if (isset($t[$p])) {
		$labels = array(
			'vm' => 'جهاز افتراضي', 'server' => 'سيرفر', 'nas' => 'تخزين',
			'ap' => 'أكسس بوينت', 'router' => 'راوتر', 'camera' => 'كاميرا',
			'pc' => 'كمبيوتر', 'phone' => 'موبايل', 'apple' => 'جهاز Apple',
			'iot' => 'جهاز IoT', 'printer' => 'طابعة',
		);
		$k = $t[$p][1];
		return array($k, $labels[$k] ?? 'غير معروف');
	}
	return array('unknown', 'غير معروف');
}

/* Hostnames and descriptions that DHCP already knows: mac => array(host, descr) */
function nc_lease_names() {
	$out = array();
	if (!function_exists('system_get_dhcpleases')) {
		return $out;
	}
	$all = system_get_dhcpleases();
	foreach (($all['lease'] ?? array()) as $l) {
		if (empty($l['mac'])) {
			continue;
		}
		$mac = nc_norm_mac($l['mac']);
		/* leases carry the FQDN form, e.g. "it-pc." or "ws3.home.arpa" */
		$h = trim($l['hostname'] ?? '');
		$h = rtrim($h, '.');
		if (($dot = strpos($h, '.')) !== false) {
			$h = substr($h, 0, $dot);
		}
		$d = trim($l['descr'] ?? '');
		if ($h || $d) {
			$out[$mac] = array('host' => $h, 'descr' => $d);
		}
	}
	return $out;
}

/* DHCP static reservations from config: mac => array(ip, descr) */
function nc_dhcp_reservations() {
	global $config;
	$out = array();
	$maps = $config['dhcpd']['lan']['staticmap'] ?? array();
	foreach ($maps as $m) {
		if (empty($m['mac'])) {
			continue;
		}
		$out[nc_norm_mac($m['mac'])] = array(
			'ip'    => $m['ipaddr'] ?? '',
			'descr' => $m['descr'] ?? '',
		);
	}
	return $out;
}

/*
 * Per-device byte counters straight from the kernel's dummynet buckets.
 *
 * `dnctl pipe show` needs each pipe named explicitly — there is no flag that
 * dumps every pipe with its buckets. Bucket rows look like:
 *
 *   BKT Prot ___Source IP/port____ ____Dest. IP/port____ Tot_pkt/bytes Pkt/Byte Drp
 *   128 ip           0.0.0.0/0       192.168.1.208/0     15055  4745001  0  0  0
 *
 * A download pipe masks on dst-ip, so the device is the DESTINATION column;
 * an upload pipe masks on src-ip and the device is the SOURCE column. Take
 * whichever column is not the 0.0.0.0 wildcard.
 */
function nc_pipe_usage() {
	$out   = array();
	$pipes = array();

	/* Which pipes exist? `dnctl pipe list` — bare `dnctl list` prints usage. */
	$list = shell_exec('/sbin/dnctl pipe list 2>/dev/null');
	foreach (explode("\n", (string)$list) as $line) {
		if (preg_match('/^(\d{5}):/', $line, $m)) {
			$pipes[] = (int)$m[1];
		}
	}
	$pipes = array_unique($pipes);

	foreach ($pipes as $p) {
		$raw = shell_exec('/sbin/dnctl pipe ' . (int)$p . ' show 2>/dev/null');
		foreach (explode("\n", (string)$raw) as $line) {
			if (!preg_match('/^\s*\d+\s+ip\s+(\S+)\s+(\S+)\s+(\d+)\s+(\d+)/', $line, $m)) {
				continue;
			}
			$src = explode('/', $m[1])[0];
			$dst = explode('/', $m[2])[0];
			/* the masked side is the real device; the other is the wildcard */
			$ip = ($dst !== '0.0.0.0') ? $dst : $src;
			if ($ip === '0.0.0.0' || !filter_var($ip, FILTER_VALIDATE_IP)) {
				continue;
			}
			/*
			 * Only LAN devices. Post-NAT traffic collapses onto pfSense's own
			 * WAN address, which would otherwise top the list every time and
			 * look like one enormous client.
			 */
			if (strpos($ip, nc_cfg('lan_subnet', '192.168.1.')) !== 0) {
				continue;
			}
			if (!isset($out[$ip])) {
				$out[$ip] = array('pkts' => 0, 'bytes' => 0);
			}
			$out[$ip]['pkts']  += (int)$m[3];
			$out[$ip]['bytes'] += (int)$m[4];
		}
	}
	return $out;
}

/*
 * The device list the page renders. Merges live ARP, DHCP reservations and
 * our own stored rules, keyed on MAC.
 */
function nc_devices() {
	$store = nc_store_load();
	$arp   = nc_arp_table();
	$res   = nc_dhcp_reservations();
	$usage = nc_pipe_usage();
	$rates = nc_rate_sample($usage);
	$names = nc_lease_names();
	$cp    = nc_portal_sessions();
	$prot  = nc_protected_ips();

	$macs = array_unique(array_merge(
		array_keys($arp), array_keys($res), array_keys($store['devices']),
		array_keys($names)));

	$list = array();
	foreach ($macs as $mac) {
		$rule    = $store['devices'][$mac] ?? array();
		$live    = isset($arp[$mac]);
		$ip      = $arp[$mac]['ip'] ?? ($res[$mac]['ip'] ?? ($rule['ip'] ?? ''));
		$known_ip = $rule['ip'] ?? '';

		/* The IP moved under this MAC — the rules follow the MAC. */
		$moved = ($known_ip && $ip && $known_ip !== $ip);

		/*
		 * Name, in order of trust: a name the operator typed here, the
		 * DHCP reservation's description, the hostname the device announced,
		 * then the lease description. Only if all of those are empty do we
		 * fall back to the address.
		 */
		$host  = $names[$mac]['host']  ?? '';
		$ldesc = $names[$mac]['descr'] ?? '';
		$name  = $rule['name'] ?? '';
		if ($name === '') { $name = $res[$mac]['descr'] ?? ''; }
		if ($name === '') { $name = $host; }
		if ($name === '') { $name = $ldesc; }

		list($tkey, $tlabel) = nc_device_type($mac, $host, $ldesc . ' ' . ($res[$mac]['descr'] ?? ''));

		/* a portal login is tracked by MAC where we have it, else by IP */
		$sess = $cp['by_mac'][$mac] ?? ($ip ? ($cp['by_ip'][$ip] ?? null) : null);

		$list[] = array(
			'mac'       => $mac,
			'ip'        => $ip,
			'name'      => $name,
			'rate'      => $ip ? ($rates[$ip] ?? 0.0) : 0.0,
			/*
			 * A randomized MAC is only a real risk when we have no
			 * fingerprint to follow it by; with one, nc_reattach_rules()
			 * carries the block onto the new MAC.
			 */
			'fp'        => $store['devices'][$mac]['fp'] ?? '',
			'cp_user'   => $sess['username'] ?? '',
			'cp_since'  => $sess['since']    ?? null,
			'hostname'  => $host,
			'vendor'    => nc_vendor($mac),
			'type'      => $tkey,
			'type_name' => $tlabel,
			'group'     => $rule['group'] ?? '',
			'limit'     => isset($rule['limit']) ? (int)$rule['limit'] : null,
			'blocked'   => !empty($rule['blocked']),
			'expires'   => $rule['expires'] ?? null,
			'random'    => nc_mac_is_random($mac),
			'reserved'  => isset($res[$mac]),
			'online'    => $live,
			'protected' => in_array($ip, $prot),
			'bytes'     => $usage[$ip]['bytes'] ?? 0,
			'moved'     => $moved,
			'prev_ip'   => $moved ? $known_ip : null,
			'last_seen' => $rule['last_seen'] ?? ($live ? time() : null),
		);
	}

	usort($list, function($a, $b) { return $b['bytes'] <=> $a['bytes']; });
	return $list;
}

/* ------------------------------------------------------------ WAN / rates */

/* Interface byte counters, for computing a rate between two samples. */
function nc_if_counters($if) {
	$raw = shell_exec("/usr/bin/netstat -ibn -I " . escapeshellarg($if) . " 2>/dev/null");
	foreach (explode("\n", (string)$raw) as $line) {
		$f = preg_split('/\s+/', trim($line));
		if (count($f) >= 11 && $f[0] === $if && strpos($line, '<Link') !== false) {
			return array('rx' => (float)$f[7], 'tx' => (float)$f[10], 't' => microtime(true));
		}
	}
	/* fall back to the first data row for this interface */
	foreach (explode("\n", (string)$raw) as $line) {
		$f = preg_split('/\s+/', trim($line));
		if (count($f) >= 11 && $f[0] === $if) {
			return array('rx' => (float)$f[7], 'tx' => (float)$f[10], 't' => microtime(true));
		}
	}
	return null;
}

/* Sample both interfaces over $secs and return Mbit/s in each direction. */
function nc_rates($secs = 2) {
	$a_wan = nc_if_counters(NC_WAN_IF);
	$a_lan = nc_if_counters(NC_LAN_IF);
	usleep((int)($secs * 1000000));
	$b_wan = nc_if_counters(NC_WAN_IF);
	$b_lan = nc_if_counters(NC_LAN_IF);

	$calc = function($a, $b) {
		if (!$a || !$b) {
			return array(0.0, 0.0);
		}
		$dt = max(0.2, $b['t'] - $a['t']);
		return array(
			round(max(0, $b['rx'] - $a['rx']) * 8 / $dt / 1000000, 2),
			round(max(0, $b['tx'] - $a['tx']) * 8 / $dt / 1000000, 2),
		);
	};
	list($wan_in,  $wan_out) = $calc($a_wan, $b_wan);
	list($lan_in,  $lan_out) = $calc($a_lan, $b_lan);

	$store = nc_store_load();
	return array(
		'wan_in'   => $wan_in,    // arriving from the internet
		'wan_out'  => $wan_out,   // leaving to the internet
		'lan_in'   => $lan_in,    // arriving from local devices
		'lan_out'  => $lan_out,   // sent to local devices
		'src_down' => $store['source']['down'] ?? 31.0,
		'src_up'   => $store['source']['up']   ?? 5.0,
		'src_at'   => $store['source']['at']   ?? null,
	);
}

/*
 * The network-wide per-device ceiling, read from the shaper config rather
 * than from the kernel, so the page shows what will survive a reboot.
 */
function nc_global_limits() {
	global $config;
	$out = array('down' => null, 'up' => null, 'devices_down' => 0, 'devices_up' => 0);
	foreach (($config['dnshaper']['queue'] ?? array()) as $q) {
		$bw = $q['bandwidth']['item'][0] ?? array();
		$mb = (float)($bw['bw'] ?? 0);
		if (($bw['bwscale'] ?? '') === 'Kb') { $mb = $mb / 1000; }
		if (($q['name'] ?? '') === 'PerDevice_Down_5M') { $out['down'] = $mb; }
		if (($q['name'] ?? '') === 'NoLimit_Up')        { $out['up']   = $mb; }
	}
	$c22 = shell_exec("/sbin/dnctl pipe 22 show 2>/dev/null | grep -cE '^ *[0-9]+ +ip'");
	$c23 = shell_exec("/sbin/dnctl pipe 23 show 2>/dev/null | grep -cE '^ *[0-9]+ +ip'");
	$out['devices_down'] = (int)trim((string)$c22);
	$out['devices_up']   = (int)trim((string)$c23);
	return $out;
}




/* --------------------------------------------- live video streams */

/*
 * Which hosts a flow belongs to a video service, and which service.
 *
 * Matching is on the hostname the resolver saw, because a CDN address alone
 * says nothing: googlevideo.com is YouTube's video path while googleapis.com
 * is not, and both sit on Google ranges. A flow we cannot name is left out
 * rather than guessed at, so the panel never invents a stream.
 */
function nc_video_sig($host, $service) {
	$h = strtolower((string)$host);
	$v = strtolower((string)$service);

	/*
	 * Two kinds of match, because they mean different things.
	 *
	 * A "stream" host is the video path itself: googlevideo.com carries the
	 * bytes of whatever is playing. A "page" host is the site around it:
	 * youtube.com is the page, and it keeps a small connection open whether
	 * or not anything is playing.
	 *
	 * Reporting only streams misses someone who has just pressed play;
	 * reporting only pages calls an idle tab a stream. So both are returned
	 * and the caller is told which it got.
	 */
	$stream = array(
		'youtube'  => array('googlevideo.com', 'youtube-nocookie',
		                    'yt3.ggpht.com', 'ytimg.com'),
		'tiktok'   => array('tiktokcdn', 'byteoversea', 'muscdn.com',
		                    'ibytedtos.com', 'tiktokv.com', 'ibyteimg'),
		'facebook' => array('fbcdn.net', 'video.fb', 'fbsbx.com'),
		'netflix'  => array('nflxvideo.net', 'nflxso.net'),
		'instagram'=> array('cdninstagram'),
		'twitch'   => array('ttvnw.net', 'jtvnw.net'),
		'whatsapp' => array('whatsapp.net'),
	);
	$page = array(
		'youtube'  => array('youtube.com', 'youtu.be'),
		'tiktok'   => array('tiktok.com'),
		'facebook' => array('facebook.com', 'fb.watch'),
		'netflix'  => array('netflix.com'),
		'instagram'=> array('instagram.com'),
		'twitch'   => array('twitch.tv'),
		'whatsapp' => array('whatsapp.com'),
	);

	if ($h !== '') {
		foreach ($stream as $name => $pats) {
			foreach ($pats as $pat) {
				if (strpos($h, $pat) !== false) {
					return array($name, 'stream');
				}
			}
		}
		foreach ($page as $name => $pats) {
			foreach ($pats as $pat) {
				if (strpos($h, $pat) !== false) {
					return array($name, 'page');
				}
			}
		}
	}

	/*
	 * No hostname. The resolver cache only holds names somebody looked up
	 * recently, so a long-running stream can outlive its DNS entry - which
	 * is how tens of megabytes of Facebook video stayed invisible here. Fall
	 * back to the service label that nc_service_of() already worked out.
	 */
	$known = array('youtube','tiktok','netflix','twitch','instagram',
	               'facebook','whatsapp');
	if (in_array($v, $known, true)) {
		return array($v, 'guess');
	}
	return array('', '');
}

/*
 * The video streams open right now, newest first, with who is watching.
 *
 * Only flows above a floor are reported: a few kilobytes to youtube.com is a
 * page visit or a thumbnail, not someone watching, and listing those would
 * bury the handful of streams that are actually eating the line.
 */
function nc_live_video($min_bytes = 131072) {
	$out = array();
	foreach (nc_live_devices() as $d) {
		foreach (($d['services'] ?? array()) as $sv) {
			$bytes = (int)($sv['bytes'] ?? 0);

			list($kind, $how) = nc_video_sig($sv['host'] ?? '',
			                                 $sv['service'] ?? '');
			if ($kind === '') {
				continue;
			}

			/*
			 * The floor depends on what we matched. A stream host carrying
			 * real bytes is worth showing early, while a page host needs no
			 * floor at all: having youtube.com open IS the fact worth
			 * reporting, even at a few kilobytes. A guess from the service
			 * label is the weakest signal, so it has to be large to count.
			 */
			$floor = ($how === 'page')  ? 0
			       : (($how === 'guess') ? 1048576 : $min_bytes);
			if ($bytes < $floor) {
				continue;
			}

			$out[] = array(
				'kind'    => $kind,
				'how'     => $how,
				'host'    => $sv['host'] ?? '',
				'ip'      => $d['ip'],
				'mac'     => $d['mac'],
				'name'    => $d['name'] ?: ($d['hostname'] ?: $d['ip']),
				'cp_user' => $d['cp_user'] ?? '',
				'bytes'   => $bytes,
				'conns'   => (int)($sv['conns'] ?? 0),
				'age'     => (int)($sv['age'] ?? 0),
				'blocked' => !empty($d['blocked']),
				'limit'   => $d['limit'] ?? null,
			);
		}
	}
	/* someone actually watching outranks someone with a tab open */
	$rank = array('stream' => 0, 'guess' => 1, 'page' => 2);
	usort($out, function($a, $b) use ($rank) {
		$ra = $rank[$a['how']] ?? 3;
		$rb = $rank[$b['how']] ?? 3;
		if ($ra !== $rb) {
			return $ra <=> $rb;
		}
		return $b['bytes'] <=> $a['bytes'];
	});
	return $out;
}

/* ----------------------------------------- video, grouped by service */

/*
 * The flat stream list answers "what is open", but not "who is watching
 * what", which is the question an admin acts on. Group it: service, then
 * the devices inside it, then that device's own hosts.
 *
 * On URLs, so the page does not promise what it cannot deliver: the full
 * address (youtube.com/watch?v=...) is inside the encrypted request and no
 * firewall sees it without terminating TLS on every client. What IS visible
 * is the hostname, from the SNI field of the handshake and from the DNS
 * lookup that preceded it. So the panel shows real hostnames and says so,
 * rather than implying it knows which video is playing.
 */
function nc_video_groups() {
	$streams = nc_live_video();
	$svc = array();

	foreach ($streams as $v) {
		$k = $v['kind'];
		if (!isset($svc[$k])) {
			$svc[$k] = array(
				'kind'    => $k,
				'bytes'   => 0,
				'conns'   => 0,
				'watching'=> 0,          /* devices actually streaming */
				'devices' => array(),
			);
		}
		$svc[$k]['bytes'] += $v['bytes'];
		$svc[$k]['conns'] += $v['conns'];

		$m = $v['mac'] ?: $v['ip'];
		if (!isset($svc[$k]['devices'][$m])) {
			$svc[$k]['devices'][$m] = array(
				'mac'     => $v['mac'],
				'ip'      => $v['ip'],
				'name'    => $v['name'],
				'cp_user' => $v['cp_user'],
				'blocked' => $v['blocked'],
				'limit'   => $v['limit'],
				'bytes'   => 0,
				'conns'   => 0,
				'how'     => 'page',
				'hosts'   => array(),
			);
		}
		$d = &$svc[$k]['devices'][$m];
		$d['bytes'] += $v['bytes'];
		$d['conns'] += $v['conns'];

		/* the strongest evidence this device has wins the label */
		$rank = array('page' => 0, 'guess' => 1, 'stream' => 2);
		if (($rank[$v['how']] ?? 0) > ($rank[$d['how']] ?? 0)) {
			$d['how'] = $v['how'];
		}
		if (!empty($v['host'])) {
			$h = $v['host'];
			if (!isset($d['hosts'][$h])) {
				$d['hosts'][$h] = array('host' => $h, 'bytes' => 0, 'conns' => 0);
			}
			$d['hosts'][$h]['bytes'] += $v['bytes'];
			$d['hosts'][$h]['conns'] += $v['conns'];
		}
		unset($d);
	}

	/* flatten, sort each level by size, and count who is really watching */
	$out = array();
	foreach ($svc as $k => $g) {
		$devs = array();
		foreach ($g['devices'] as $d) {
			$hosts = array_values($d['hosts']);
			usort($hosts, function($a, $b) { return $b['bytes'] <=> $a['bytes']; });
			$d['hosts'] = $hosts;
			if ($d['how'] === 'stream') {
				$g['watching']++;
			}
			$devs[] = $d;
		}
		usort($devs, function($a, $b) { return $b['bytes'] <=> $a['bytes']; });
		$g['devices'] = $devs;
		$g['count']   = count($devs);
		unset($g['kind']);
		$g['kind'] = $k;
		$out[] = $g;
	}
	usort($out, function($a, $b) { return $b['bytes'] <=> $a['bytes']; });
	return $out;
}

/* ------------------------------------------------- per-device speed */

/*
 * How fast each device is moving data right now, in Mbit/s.
 *
 * The dummynet counters are cumulative, so a single reading says nothing
 * about current speed. Taking two readings a second apart per device would
 * make the page crawl, so instead every call stores the counters with a
 * timestamp and compares against the previous store: the gap between two
 * page refreshes becomes the sampling window.
 *
 * The first call after a restart has nothing to compare against and reports
 * zero rather than guessing; the next one is accurate.
 */
function nc_rate_sample($usage) {
	$file = '/var/db/netcontrol_rate.json';
	$now  = microtime(true);
	$prev = array();
	if (is_readable($file)) {
		$j = @json_decode(@file_get_contents($file), true);
		if (is_array($j)) {
			$prev = $j;
		}
	}

	$snap = array('t' => $now, 'ip' => array());
	$out  = array();

	foreach ($usage as $ip => $u) {
		$bytes = (int)($u['bytes'] ?? 0);
		$snap['ip'][$ip] = $bytes;

		$pt = (float)($prev['t'] ?? 0);
		$pb = $prev['ip'][$ip] ?? null;
		if ($pb === null || $pt <= 0) {
			$out[$ip] = 0.0;
			continue;
		}
		$dt = $now - $pt;
		/*
		 * Below a second the quantisation noise swamps the signal, and above
		 * a couple of minutes the average stops describing "now". Outside
		 * that window report nothing rather than something misleading.
		 */
		if ($dt < 1.0 || $dt > 120.0) {
			$out[$ip] = 0.0;
			continue;
		}
		$db = $bytes - (int)$pb;
		if ($db < 0) {           /* counter reset: pipe reconfigured */
			$out[$ip] = 0.0;
			continue;
		}
		$out[$ip] = round($db * 8 / $dt / 1000000, 2);
	}

	@file_put_contents($file, json_encode($snap), LOCK_EX);
	return $out;
}

/* ------------------------------------------------- device fingerprint */

/*
 * A device using a Private WiFi Address can change its MAC at will, and a
 * MAC-keyed block dies with it. For those devices we key on something the
 * phone carries across the change: the hostname it announces over DHCP.
 *
 * This is weaker than a MAC - a hostname can be edited, and two handsets of
 * the same model ship the same default - so it is used only to RE-APPLY a
 * block an admin already made, never to create one. The worst case is that a
 * block the admin wanted follows the device one hop too far, which is the
 * safer direction to fail in.
 */
function nc_fingerprint($hostname) {
	/*
	 * nc_lease_names() hands back array('host'=>..,'descr'=>..) per MAC, and
	 * casting that to a string yields the literal "array" - which made every
	 * device share one fingerprint and let a block jump between unrelated
	 * phones. Take the host field when given the whole record, and refuse
	 * anything that still is not a string.
	 */
	if (is_array($hostname)) {
		$hostname = $hostname['host'] ?? ($hostname['descr'] ?? '');
	}
	if (!is_string($hostname)) {
		return '';
	}
	$h = strtolower(trim($hostname));
	$h = preg_replace('/[^a-z0-9]+/', '-', $h);
	$h = trim($h, '-');
	/*
	 * Drop the "-2", "-3" suffix DHCP appends when a name is already taken,
	 * but only when what precedes it already ends in a letter. Stripping
	 * digits blindly turns "redmi-note-14" into "redmi-note" and makes two
	 * different models look like the same handset.
	 */
	$h = preg_replace('/(?<=[a-z])-[23456789]$/', '', $h);
	$h = trim($h, '-');
	if (strlen($h) < 4) {
		return '';            /* too short to identify anything */
	}
	return $h;
}

/*
 * Hostnames that name a model rather than a handset. Two people with the same
 * phone collide here, so a fingerprint block is never applied on these - that
 * would block a stranger for something their neighbour did.
 */
function nc_fp_is_generic($fp) {
	static $generic = array(
		'android', 'localhost', 'iphone', 'ipad', 'phone', 'mobile',
		'samsung', 'xiaomi', 'redmi', 'realme', 'oppo', 'vivo', 'huawei',
		'honor', 'infinix', 'tecno', 'nokia', 'galaxy', 'poco',
	);
	if (in_array($fp, $generic, true)) {
		return true;
	}
	/* a bare model designation with no owner part, e.g. "galaxy-a14" */
	foreach ($generic as $g) {
		if (preg_match('/^' . preg_quote($g, '/') . '-?[a-z]?\d+[a-z]*$/', $fp)) {
			return true;
		}
	}
	return false;
}

/* --------------------------------------------- captive portal sessions */

/*
 * Who is logged in to the portal right now, keyed by MAC and by IP.
 *
 * This matters for two reasons: the page shows the username next to the
 * device, and the new-device policy treats a portal login as the admin's
 * permission — a device whose user authenticated is not "unknown" any more,
 * so the policy leaves it alone.
 */
function nc_portal_sessions() {
	global $config, $cpzone, $cpzoneid;

	$out = array('by_mac' => array(), 'by_ip' => array());
	if (!function_exists('captiveportal_read_db')) {
		@include_once('captiveportal.inc');
	}
	if (!function_exists('captiveportal_read_db')) {
		return $out;
	}

	/*
	 * captiveportal_read_db() reads $cpzone and $cpzoneid from the global
	 * scope; without them it quietly returns an empty set. pfSense's own
	 * status page sets them before calling, so we do the same — once per
	 * configured zone.
	 */
	$saved_zone = $cpzone ?? null;
	$saved_id   = $cpzoneid ?? null;

	foreach (($config['captiveportal'] ?? array()) as $zone => $cfg) {
		$cpzone   = $zone;
		$cpzoneid = $cfg['zoneid'] ?? null;

		$db = @captiveportal_read_db();
		if (!is_array($db)) {
			continue;
		}
		foreach ($db as $row) {
			if (!is_array($row)) {
				continue;
			}
			$rec = array(
				'username' => $row['username'] ?? '',
				'ip'       => $row['ip']       ?? '',
				'mac'      => nc_norm_mac($row['mac'] ?? ''),
				'since'    => (int)($row['allow_time'] ?? 0),
				'zone'     => $zone,
			);
			if ($rec['mac']) { $out['by_mac'][$rec['mac']] = $rec; }
			if ($rec['ip'])  { $out['by_ip'][$rec['ip']]   = $rec; }
		}
	}

	$cpzone   = $saved_zone;
	$cpzoneid = $saved_id;
	return $out;
}

/* Is the captive portal actually enforcing on the LAN? */
function nc_portal_active() {
	global $config;
	foreach (($config['captiveportal'] ?? array()) as $z => $c) {
		/*
		 * A zone with no 'enable' key but a zoneid and an interface is still
		 * enforcing — pfSense writes the key only in some versions, so judge
		 * by whether the zone is configured at all.
		 */
		if (!empty($c['enable']) || (!empty($c['zoneid']) && !empty($c['interface']))) {
			return true;
		}
	}
	return false;
}

/* ------------------------------------------------- new-device policy */

/*
 * Automatic handling of devices the network has not seen before.
 *
 * The rule a device matches is decided from what we can actually observe:
 * its OS family (guessed from the MAC vendor, the DHCP hostname and whether
 * the MAC is randomised) and the time of day it first appeared. A device is
 * "new" until the policy has acted on it once; after that it is on the
 * known list and the policy leaves it alone, so a phone is not re-blocked
 * every time it reconnects.
 *
 * Nothing here can touch a protected address: nc_apply() filters those.
 */

function nc_policy_defaults() {
	return array(
		'enabled'    => false,
		'action'     => 'block',     // block | limit | allow
		'limit_mb'   => 1,           // used when action = limit
		'duration'   => 'perm',      // perm | 60 | 180 | eod
		'targets'    => array('mobile'),   // which OS families it applies to
		'schedule'   => array(
			'enabled' => false,
			'from'    => '08:00',
			'to'      => '17:00',
			'days'    => 'all',      // all | work (Sun-Thu) | weekend
		),
		'notify'         => true,
		'respect_portal' => true,   // a portal login exempts the device
	);
}

function nc_policy_get() {
	$store = nc_store_load();
	return array_merge(nc_policy_defaults(), $store['policy'] ?? array());
}

function nc_policy_set($p) {
	$store = nc_store_load();
	$store['policy'] = array_merge(nc_policy_get(), $p);
	nc_store_save($store);
	return $store['policy'];
}

/*
 * OS family for a device. Returns one of:
 *   android | ios | windows | mac | linux | iot | unknown
 *
 * There is no fingerprinting here beyond what DHCP and the OUI already give
 * us, so this is a guess — the UI says so rather than presenting it as fact.
 */
function nc_os_family($mac, $hostname, $vendor, $type) {
	$h = strtolower($hostname);
	$v = strtolower($vendor);

	/* the hostname is the strongest signal when it says something */
	if (preg_match('/android|galaxy|redmi|xiaomi|huawei|oppo|vivo|realme|infinix|tecno|poco/i', $h)) {
		return 'android';
	}
	if (preg_match('/iphone|ipad|ipod/i', $h)) {
		return 'ios';
	}
	if (preg_match('/macbook|imac|mac-mini|\bmac\b/i', $h)) {
		return 'mac';
	}
	if (preg_match('/desktop-|laptop-|^pc-|^ws\d|win-/i', $h)) {
		return 'windows';
	}
	if (preg_match('/raspberry|rpi|openwrt|ubuntu|debian|linux/i', $h)) {
		return 'linux';
	}

	/* then the vendor */
	if (strpos($v, 'apple') !== false)   { return 'ios'; }
	if (strpos($v, 'xiaomi') !== false
	    || strpos($v, 'samsung') !== false
	    || strpos($v, 'google') !== false) { return 'android'; }
	if (strpos($v, 'raspberry') !== false) { return 'linux'; }

	/* a device type we already classified */
	if ($type === 'camera' || $type === 'iot' || $type === 'printer') {
		return 'iot';
	}
	if ($type === 'pc') {
		return 'windows';
	}

	/*
	 * A randomised MAC with no name is almost always a phone: both iOS and
	 * modern Android randomise per network, desktops do not.
	 */
	if (nc_mac_is_random($mac)) {
		return 'mobile_unknown';
	}
	return 'unknown';
}

/* Does this family fall under one of the policy's targets? */
function nc_policy_targets_match($family, $targets) {
	foreach ($targets as $t) {
		if ($t === 'all') {
			return true;
		}
		if ($t === 'mobile' && in_array($family, array('android', 'ios', 'mobile_unknown'))) {
			return true;
		}
		if ($t === $family) {
			return true;
		}
	}
	return false;
}

/* Is the policy's schedule open right now? */
function nc_policy_in_window($sched) {
	if (empty($sched['enabled'])) {
		return true;                      // always on
	}
	$now  = time();
	$dow  = (int)date('w', $now);         // 0 = Sunday
	$days = $sched['days'] ?? 'all';
	if ($days === 'work' && ($dow === 5 || $dow === 6)) {
		return false;                     // Fri/Sat off
	}
	if ($days === 'weekend' && !($dow === 5 || $dow === 6)) {
		return false;
	}
	$mins = (int)date('H', $now) * 60 + (int)date('i', $now);
	$f = explode(':', $sched['from'] ?? '00:00');
	$t = explode(':', $sched['to']   ?? '23:59');
	$from = ((int)$f[0]) * 60 + (int)($f[1] ?? 0);
	$to   = ((int)$t[0]) * 60 + (int)($t[1] ?? 0);

	return ($from <= $to)
		? ($mins >= $from && $mins < $to)
		: ($mins >= $from || $mins < $to);   // window crosses midnight
}

/*
 * Run the policy over whatever is on the network now.
 * $dry_run returns what it would do without doing it, which is what the
 * page uses to preview the rule before it is switched on.
 */
function nc_policy_run($dry_run = false) {
	$pol = nc_policy_get();
	if (empty($pol['enabled']) && !$dry_run) {
		return array();
	}

	$store  = nc_store_load();
	$known  = $store['policy_seen'] ?? array();
	$in_win = nc_policy_in_window($pol['schedule']);
	$acted  = array();

	foreach (nc_devices() as $d) {
		if ($d['protected'] || !$d['online'] || !$d['mac']) {
			continue;
		}
		if (isset($known[$d['mac']])) {
			continue;                        // already handled once
		}
		/* a device we already have any rule for is not new */
		if (!empty($store['devices'][$d['mac']]['blocked'])
		    || isset($store['devices'][$d['mac']]['limit'])) {
			continue;
		}
		/*
		 * A portal login is the admin's permission: the user proved who they
		 * are, so the policy does not treat the device as unknown.
		 */
		if (!empty($pol['respect_portal']) && !empty($d['cp_user'])) {
			continue;
		}
		$family = nc_os_family($d['mac'], $d['hostname'], $d['vendor'], $d['type']);
		if (!nc_policy_targets_match($family, $pol['targets'])) {
			continue;
		}
		if (!$in_win) {
			continue;                        // outside the policy's hours
		}

		$row = array(
			'mac'     => $d['mac'],
			'ip'      => $d['ip'],
			'name'    => $d['name'] ?: $d['hostname'],
			'family'  => $family,
			'action'  => $pol['action'],
			'cp_user' => $d['cp_user'] ?? '',
		);
		$acted[] = $row;

		if ($dry_run) {
			continue;
		}

		$exp = null;
		if ($pol['duration'] === '60')  { $exp = time() + 3600; }
		if ($pol['duration'] === '180') { $exp = time() + 10800; }
		if ($pol['duration'] === 'eod') { $exp = strtotime('today 23:59:59'); }

		$cur = $store['devices'][$d['mac']] ?? array();
		$cur['ip']        = $d['ip'];
		$cur['name']      = $d['name'];
		$cur['last_seen'] = time();
		if ($pol['action'] === 'block') {
			$cur['blocked'] = true;
			$cur['expires'] = $exp;
			/*
			 * Mark this as the policy's doing, not an admin's. Only a policy
			 * block is lifted again automatically when the user logs in to
			 * the portal; a block someone made by hand stays until they
			 * undo it themselves.
			 */
			$cur['by_policy'] = true;
			/* remember the fingerprint now, so the block can follow a MAC change */
			if (empty($cur['fp'])) {
				$f = nc_fingerprint($d['hostname'] ?? '');
				if ($f !== '' && !nc_fp_is_generic($f)) {
					$cur['fp'] = $f;
				}
			}
		} elseif ($pol['action'] === 'limit') {
			$cur['limit']   = max(1, (int)$pol['limit_mb']);
			$cur['expires'] = $exp;
		}
		$store['devices'][$d['mac']] = $cur;
		$known[$d['mac']] = time();

		nc_log(sprintf('POLICY %s %s (%s) — %s %s',
			strtoupper($pol['action']), $d['mac'], $d['ip'], $family,
			$exp ? 'حتى ' . date('H:i', $exp) : 'دائم'));
	}

	if (!$dry_run && count($acted)) {
		$store['policy_seen'] = $known;
		nc_store_save($store);
		nc_apply();
	}
	return $acted;
}

/* Devices the policy has acted on, newest first. */
function nc_policy_history($limit = 30) {
	$store = nc_store_load();
	$seen  = $store['policy_seen'] ?? array();
	arsort($seen);
	$out = array();
	foreach (array_slice($seen, 0, $limit, true) as $mac => $when) {
		$rule = $store['devices'][$mac] ?? array();
		$out[] = array(
			'mac'     => $mac,
			'ip'      => $rule['ip'] ?? '',
			'name'    => $rule['name'] ?? '',
			'at'      => $when,
			'blocked' => !empty($rule['blocked']),
			'limit'   => $rule['limit'] ?? null,
		);
	}
	return $out;
}

/* Forget a device, so the policy treats it as new again. */
function nc_policy_forget($mac) {
	$store = nc_store_load();
	unset($store['policy_seen'][nc_norm_mac($mac)]);
	nc_store_save($store);
}

/* ------------------------------------------------- live traffic by domain */

/*
 * Reverse map of the resolver cache: external IP -> the name that resolved
 * to it. This is what lets the page say "facebook" instead of "157.240.196.2".
 * Cached for a minute; dumping the cache is cheap but not free.
 */
/*
 * Names we have seen before, so a flow stays identifiable after its DNS
 * entry expires. Capped and pruned, because this file would otherwise grow
 * for as long as the firewall runs.
 */
function nc_dns_memory($learn = array()) {
	$file = '/var/db/netcontrol_dns.json';
	$mem  = array();
	if (is_readable($file)) {
		$j = @json_decode(@file_get_contents($file), true);
		if (is_array($j)) {
			$mem = $j;
		}
	}
	if (!$learn) {
		return $mem;
	}

	$now = time();
	$dirty = false;
	foreach ($learn as $ip => $host) {
		if ($host === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
			continue;
		}
		if (!isset($mem[$ip]) || $mem[$ip][0] !== $host) {
			$dirty = true;
		}
		$mem[$ip] = array($host, $now);
	}
	if (!$dirty) {
		return $mem;
	}

	/* forget anything untouched for a day, then cap the rest by recency */
	foreach ($mem as $ip => $rec) {
		if (!is_array($rec) || ($now - (int)($rec[1] ?? 0)) > 86400) {
			unset($mem[$ip]);
		}
	}
	if (count($mem) > 4000) {
		uasort($mem, function($a, $b) { return $b[1] <=> $a[1]; });
		$mem = array_slice($mem, 0, 4000, true);
	}
	@file_put_contents($file, json_encode($mem), LOCK_EX);
	return $mem;
}

function nc_dns_map($max_age = 60) {
	$cache = '/tmp/nc_dnsmap.json';
	if (file_exists($cache) && (time() - filemtime($cache)) < $max_age) {
		$j = json_decode(@file_get_contents($cache), true);
		if (is_array($j)) {
			return $j;
		}
	}
	$map = array();
	$raw = shell_exec('/usr/local/sbin/unbound-control -c /var/unbound/unbound.conf '
	                  . 'dump_cache 2>/dev/null');
	foreach (explode("\n", (string)$raw) as $line) {
		/* "name.  TTL  IN  A  1.2.3.4" */
		if (!preg_match('/^([a-z0-9][a-z0-9._-]*)\.\s+\d+\s+IN\s+A\s+(\d+\.\d+\.\d+\.\d+)/i',
		                $line, $m)) {
			continue;
		}
		$name = strtolower($m[1]);
		$ip   = $m[2];
		if (!isset($map[$ip])) {
			$map[$ip] = $name;
			continue;
		}
		/*
		 * Several names resolve to one CDN address. Prefer a name we can
		 * actually classify (facebook, youtube...) over a shorter but
		 * meaningless one; only then prefer the shorter.
		 */
		$cur_known = (nc_service_of($map[$ip]) !== nc_registrable($map[$ip]));
		$new_known = (nc_service_of($name)     !== nc_registrable($name));
		if ($new_known && !$cur_known) {
			$map[$ip] = $name;
		} elseif ($new_known === $cur_known && strlen($name) < strlen($map[$ip])) {
			$map[$ip] = $name;
		}
	}
	@file_put_contents($cache, json_encode($map));

	/*
	 * Record what we just learned, then fill the gaps from what we knew
	 * before. A stream that has outlived its DNS entry keeps its name this
	 * way instead of degrading to a bare address.
	 */
	$mem = nc_dns_memory($map);
	foreach ($mem as $ip => $rec) {
		if (!isset($map[$ip]) && is_array($rec) && !empty($rec[0])) {
			$map[$ip] = $rec[0];
		}
	}
	return $map;
}

/* "a.b.example.com" -> "example.com" */
function nc_registrable($host) {
	$parts = explode('.', strtolower($host));
	$n = count($parts);
	return ($n >= 2) ? $parts[$n - 2] . '.' . $parts[$n - 1] : strtolower($host);
}

/*
 * Collapse a hostname to the service people recognise.
 * "scontent-hbe1-1.xx.fbcdn.net" -> "facebook"
 */
function nc_service_of($host) {
	$known = array(
		'youtube'   => array('youtube.com','googlevideo.com','ytimg.com','youtu.be'),
		'facebook'  => array('facebook.com','fbcdn.net','fbsbx.com','messenger.com'),
		'instagram' => array('instagram.com','cdninstagram.com'),
		'tiktok'    => array('tiktok.com','tiktokcdn.com','tiktokv.com','byteoversea.com'),
		'whatsapp'  => array('whatsapp.net','whatsapp.com'),
		'netflix'   => array('netflix.com','nflxvideo.net','nflximg.net'),
		'twitter'   => array('twitter.com','x.com','twimg.com'),
		'snapchat'  => array('snapchat.com','sc-cdn.net'),
		'telegram'  => array('telegram.org','t.me','cdn-telegram.org'),
		'google'    => array('google.com','gstatic.com','googleapis.com','googleusercontent.com'),
		'microsoft' => array('microsoft.com','windowsupdate.com','msftconnecttest.com',
		                     'office.com','live.com','azureedge.net'),
		'apple'     => array('apple.com','icloud.com','mzstatic.com'),
		'chatgpt'   => array('openai.com','chatgpt.com','oaistatic.com'),
		'cloudflare'=> array('cloudflare.com','cloudflare-dns.com'),
		'zoom'      => array('zoom.us','zoom.com'),
		'steam'     => array('steampowered.com','steamcontent.com','steamstatic.com'),
		'torrent'   => array('torrent','tracker','piratebay','1337x'),
	);
	$h = strtolower($host);
	foreach ($known as $svc => $pats) {
		foreach ($pats as $p) {
			if (strpos($h, $p) !== false) {
				return $svc;
			}
		}
	}
	return nc_registrable($h);
}

/*
 * Some services answer from address blocks that never appear in the resolver
 * cache under a useful name (Facebook's edge, for one). Recognise those by
 * prefix so the page does not fall back to a bare IP for the biggest talkers.
 */
function nc_service_by_ip($ip) {
	$blocks = array(
		'157.240.' => 'facebook',   '31.13.'   => 'facebook',
		'57.144.'  => 'facebook',   '102.132.' => 'facebook',
		'179.60.'  => 'facebook',   '185.60.'  => 'facebook',
		'142.250.' => 'google',     '172.217.' => 'google',
		'216.58.'  => 'google',     '142.251.' => 'google',
		'74.125.'  => 'google',     '173.194.' => 'google',
		'52.84.'   => 'amazon',     '13.107.'  => 'microsoft',
		'20.190.'  => 'microsoft',  '40.99.'   => 'microsoft',
		'23.56.'   => 'apple',      '17.'      => 'apple',
		'104.244.' => 'twitter',    '192.133.' => 'tiktok',
	);
	foreach ($blocks as $p => $svc) {
		if (strpos($ip, $p) === 0) {
			return $svc;
		}
	}
	return $ip;
}

/*
 * What every LAN device is talking to right now.
 *
 * Uses `pfctl -vss`, which adds an age and byte counters to each state, so the
 * page can show how long a session has been open and how much it moved. The
 * verbose output is three lines per state: the header, the TCP window line,
 * and the age/bytes line — so we parse it as a small state machine.
 *
 * Returns client IP => array(services, conns, bytes, oldest_age, newest_age).
 */
function nc_live_traffic() {
	$dns = nc_dns_map();
	$out = array();

	$raw = shell_exec('/sbin/pfctl -vss 2>/dev/null');
	$client = null; $remote = null; $port = null;

	foreach (explode("\n", (string)$raw) as $line) {
		/*
		 * A state header starts a new record.
		 *
		 * Read the WAN side, not the LAN side. pf keeps a state on each
		 * interface, but only the outbound one carries the byte counters;
		 * the LAN copy has none, so parsing em1 produced flows with no
		 * traffic attached and a video could never be seen. The NATed form
		 * is:
		 *
		 *   em2 udp 192.168.2.4:12135 (192.168.1.216:52959) -> 142.251.154.4:443
		 *
		 * where the address in parentheses is the real client.
		 *
		 * UDP matters as much as TCP here: YouTube and most Google video
		 * run over QUIC on UDP 443, which is exactly the traffic that was
		 * missing.
		 */
		if (preg_match('/^\S+\s+(tcp|udp)\s+\d+\.\d+\.\d+\.\d+:\d+\s+' .
		               '\((\d+\.\d+\.\d+\.\d+):\d+\)\s*->\s*' .
		               '(\d+\.\d+\.\d+\.\d+):(\d+)/',
		               $line, $m)) {
			$client = $m[2];
			$remote = $m[3];
			$port   = (int)$m[4];
			if (strpos($client, nc_cfg('lan_subnet', '192.168.1.')) !== 0
			    || strpos($remote, '192.168.') === 0
			    || strpos($remote, '10.') === 0
			    || !in_array($port, array(80, 443, 8080, 8443))) {
				$client = null;
			}
			continue;
		}

		/*
		 * Traffic that is not NATed (a client reaching pfSense itself, or a
		 * routed subnet) has no parenthesised address, so take the LAN form
		 * too rather than losing it.
		 */
		$lanif = preg_quote(NC_LAN_IF, '/');
		if (preg_match('/^' . $lanif . '\s+(tcp|udp)\s+(\d+\.\d+\.\d+\.\d+):(\d+)\s*<-\s*' .
		               '(\d+\.\d+\.\d+\.\d+):\d+/', $line, $m)) {
			$remote = $m[2];
			$port   = (int)$m[3];
			$client = $m[4];
			if (strpos($client, nc_cfg('lan_subnet', '192.168.1.')) !== 0
			    || strpos($remote, '192.168.') === 0
			    || strpos($remote, '10.') === 0
			    || !in_array($port, array(80, 443, 8080, 8443))) {
				$client = null;
			}
			continue;
		}
		if ($client === null) {
			continue;
		}
		/* the age/bytes line closes the record */
		if (!preg_match('/age (\d+):(\d+):(\d+).*?([\d]+):([\d]+) bytes/', $line, $m)) {
			continue;
		}
		$age   = ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (int)$m[3];
		$bytes = (int)$m[4] + (int)$m[5];

		$host = $dns[$remote] ?? '';
		$svc  = $host ? nc_service_of($host) : nc_service_by_ip($remote);

		if (!isset($out[$client])) {
			$out[$client] = array('services' => array(), 'conns' => 0,
			                      'bytes' => 0, 'oldest' => 0, 'newest' => PHP_INT_MAX);
		}
		$c = &$out[$client];
		if (!isset($c['services'][$svc])) {
			$c['services'][$svc] = array('service' => $svc, 'host' => $host,
			                             'conns' => 0, 'bytes' => 0, 'age' => 0,
			                             'named' => (bool)$host);
		}
		$c['services'][$svc]['conns']++;
		$c['services'][$svc]['bytes'] += $bytes;
		$c['services'][$svc]['age']    = max($c['services'][$svc]['age'], $age);
		$c['conns']++;
		$c['bytes']  += $bytes;
		$c['oldest']  = max($c['oldest'], $age);
		$c['newest']  = min($c['newest'], $age);
		unset($c);
		$client = null;                 /* record consumed */
	}

	foreach ($out as $ip => &$c) {
		usort($c['services'], function($a, $b) { return $b['bytes'] <=> $a['bytes']; });
		$c['services'] = array_slice($c['services'], 0, 10);
		if ($c['newest'] === PHP_INT_MAX) { $c['newest'] = 0; }
	}
	unset($c);
	return $out;
}

/*
 * The device-centric view the page renders: one row per device, carrying its
 * name, MAC, type, session age and what it has open.
 */
function nc_live_devices() {
	$live    = nc_live_traffic();
	$devices = nc_devices();
	$byip    = array();
	foreach ($devices as $d) {
		if ($d['ip']) { $byip[$d['ip']] = $d; }
	}

	$rows = array();
	foreach ($live as $ip => $c) {
		$d = $byip[$ip] ?? array();
		$rows[] = array(
			'ip'        => $ip,
			'mac'       => $d['mac']       ?? '',
			'name'      => $d['name']      ?? '',
			'hostname'  => $d['hostname']  ?? '',
			'vendor'    => $d['vendor']    ?? '',
			'type_name' => $d['type_name'] ?? '',
			'random'    => $d['random']    ?? false,
			'protected' => $d['protected'] ?? false,
			'limit'     => $d['limit']     ?? null,
			'blocked'   => $d['blocked']   ?? false,
			'group'     => $d['group']     ?? '',
			'cp_user'   => $d['cp_user']   ?? '',
			'conns'     => $c['conns'],
			'bytes'     => $c['bytes'],
			'session'   => $c['oldest'],     /* longest open connection */
			'newest'    => $c['newest'],
			'services'  => array_values($c['services']),
		);
	}
	usort($rows, function($a, $b) { return $b['bytes'] <=> $a['bytes']; });
	return $rows;
}

/* The same thing rolled up across the whole network, for the service view. */
function nc_top_services($limit = 20) {
	$live = nc_live_traffic();
	$agg  = array();
	foreach ($live as $client => $c) {
		foreach ($c['services'] as $s) {
			$k = $s['service'];
			if (!isset($agg[$k])) {
				$agg[$k] = array('service' => $k, 'host' => $s['host'],
				                 'conns' => 0, 'bytes' => 0,
				                 'devices' => array(), 'named' => $s['named']);
			}
			$agg[$k]['conns'] += $s['conns'];
			$agg[$k]['bytes'] += $s['bytes'];
			$agg[$k]['devices'][$client] = true;
		}
	}
	foreach ($agg as &$a) {
		$a['device_count'] = count($a['devices']);
		$a['devices'] = array_keys($a['devices']);
	}
	unset($a);
	usort($agg, function($x, $y) { return $y['bytes'] <=> $x['bytes']; });
	return array_slice(array_values($agg), 0, $limit);
}

/* Firewall state count and gateway health, for the summary strip. */
function nc_system_stats() {
	$states = 0; $limit = 0;
	$raw = shell_exec('/sbin/pfctl -si 2>/dev/null');
	if (preg_match('/current entries\s+(\d+)/', (string)$raw, $m)) {
		$states = (int)$m[1];
	}
	$raw2 = shell_exec('/sbin/pfctl -sm 2>/dev/null');
	if (preg_match('/states\s+hard limit\s+(\d+)/', (string)$raw2, $m)) {
		$limit = (int)$m[1];
	}
	$rtt = null; $loss = null;
	$gw = shell_exec('/usr/local/sbin/pfSsh.php playback gatewaystatus 2>/dev/null');
	if (preg_match('/([\d.]+)ms\s+[\d.]+ms\s+([\d.]+)%/', (string)$gw, $m)) {
		$rtt  = (float)$m[1];
		$loss = (float)$m[2];
	}
	return array('states' => $states, 'states_limit' => $limit ?: 98000,
	             'rtt' => $rtt, 'loss' => $loss);
}

/* ------------------------------------------------------------- stale MACs */

/*
 * Reservations and stored rules whose MAC has not been seen for $days.
 * Returns what would be removed; it does not remove anything.
 */
function nc_stale($days = 30) {
	$store = nc_store_load();
	$arp   = nc_arp_table();
	$res   = nc_dhcp_reservations();
	$cut   = time() - ($days * 86400);
	$prot  = array_flip(nc_protected_ips());
	$out   = array();

	foreach ($res as $mac => $r) {
		if (isset($arp[$mac])) {
			continue;
		}
		/*
		 * Never offer infrastructure for deletion. A switch or an access
		 * point that is simply offline when this runs looks exactly like an
		 * abandoned reservation, and removing it moves that device onto a
		 * different address at its next renewal.
		 */
		if (isset($prot[$r['ip'] ?? ''])) {
			continue;
		}
		$seen = $store['devices'][$mac]['last_seen'] ?? null;
		if ($seen !== null && $seen > $cut) {
			continue;
		}
		$out[] = array(
			'mac'   => $mac,
			'ip'    => $r['ip'],
			'descr' => $r['descr'],
			'days'  => $seen ? (int)floor((time() - $seen) / 86400) : null,
			'kind'  => 'حجز DHCP',
			'random' => nc_mac_is_random($mac),
		);
	}
	foreach ($store['devices'] as $mac => $rule) {
		if (isset($arp[$mac]) || isset($res[$mac])) {
			continue;
		}
		if (isset($prot[$rule['ip'] ?? ''])) {
			continue;
		}
		$seen = $rule['last_seen'] ?? null;
		if ($seen !== null && $seen > $cut) {
			continue;
		}
		$out[] = array(
			'mac'   => $mac,
			'ip'    => $rule['ip'] ?? '',
			'descr' => $rule['name'] ?? '',
			'days'  => $seen ? (int)floor((time() - $seen) / 86400) : null,
			'kind'  => 'قاعدة مخزّنة',
			'random' => nc_mac_is_random($mac),
		);
	}
	usort($out, function($a, $b) { return ($b['days'] ?? 9999) <=> ($a['days'] ?? 9999); });
	return $out;
}

/* ------------------------------------------------------------ housekeeping */

/*
 * Called from cron. Does three jobs:
 *   1. notes the current IP of every known MAC, so rules follow a DHCP change
 *   2. expires temporary rules whose deadline has passed
 *   3. re-applies the resulting rule set to pf and dummynet
 */
function nc_tick() {
	$store = nc_store_load();
	$arp   = nc_arp_table();
	$names = nc_lease_names();
	$now   = time();
	$dirty = false;

	foreach ($store['devices'] as $mac => &$rule) {
		if (isset($arp[$mac])) {
			$ip = $arp[$mac]['ip'];
			/* record the fingerprint while the device is still reachable */
			if (empty($rule['fp'])) {
				$f = nc_fingerprint($names[$mac]['host'] ?? '');
				if ($f !== '' && !nc_fp_is_generic($f)) {
					$rule['fp'] = $f;
					$dirty = true;
				}
			}
			if (($rule['ip'] ?? '') !== $ip) {
				nc_log(sprintf("IP-MOVED %s: %s -> %s (الحظر والحدود اتنقلت)",
					$mac, $rule['ip'] ?? '-', $ip));
				$rule['ip'] = $ip;
				$dirty = true;
			}
			$rule['last_seen'] = $now;
			$dirty = true;
		}
		if (!empty($rule['expires']) && $rule['expires'] <= $now) {
			nc_log(sprintf("EXPIRED %s: القاعدة المؤقتة انتهت، رجع للوضع الطبيعي", $mac));
			$rule['blocked'] = false;
			$rule['limit']   = null;
			$rule['expires'] = null;
			$dirty = true;
		}
	}
	unset($rule);

	/*
	 * Catch the escape: a blocked phone that rotated its randomized MAC now
	 * looks like a brand-new, unblocked device. If its fingerprint matches a
	 * block we are already holding, carry that block onto the new MAC.
	 */
	/* record what each device used since the last tick */
	nc_usage_record();

	$dirty = nc_video_limits($store) || $dirty;
	$dirty = nc_reattach_rules($store) || $dirty;

	/* and the reverse: let an authenticated user out of a policy block */
	$dirty = nc_release_authenticated($store) || $dirty;

	if ($dirty) {
		nc_store_save($store);
	}
	nc_apply();
	return $dirty;
}

/*
 * Apply the standing video limits to whoever is streaming right now.
 *
 * Setting the rule caps the devices watching at that moment; this catches
 * everyone who starts afterwards. It only ever tightens: a device the admin
 * capped lower keeps its own limit.
 */
function nc_video_limits(&$store) {
	$rules = array();
	$now = time();
	foreach (($store['video'] ?? array()) as $v) {
		if (($v['mode'] ?? '') !== 'limit') {
			continue;
		}
		$exp = (int)($v['expires'] ?? 0);
		if ($exp > 0 && $exp <= $now) {
			continue;                     /* lapsed */
		}
		$rules[$v['key']] = array(
			'mb'   => max(1, min(50, (int)($v['mb'] ?? 1))),
			'macs' => $v['macs'] ?? array(),
			'exp'  => $exp ?: null,
		);
	}
	if (!$rules) {
		return false;
	}

	$dirty = false;
	foreach (nc_live_video() as $vv) {
		$r = $rules[$vv['kind']] ?? null;
		if (!$r || empty($vv['mac']) || ($vv['how'] ?? '') === 'page') {
			continue;
		}
		if ($r['macs'] && !in_array($vv['mac'], $r['macs'], true)) {
			continue;
		}
		$mac = $vv['mac'];
		$cur = $store['devices'][$mac] ?? array();
		if (!empty($cur['blocked'])) {
			continue;
		}
		if (!empty($cur['limit']) && (int)$cur['limit'] <= $r['mb']) {
			continue;                     /* already as tight or tighter */
		}
		$cur['ip']        = $vv['ip'];
		$cur['limit']     = $r['mb'];
		$cur['expires']   = $r['exp'];
		$cur['by_video']  = $vv['kind'];
		$cur['last_seen'] = time();
		$store['devices'][$mac] = $cur;
		nc_log(sprintf("VIDEO-LIMIT %s (%s): %s — حد %d ميجا",
			$mac, $vv['ip'] ?: '-', $vv['kind'], $r['mb']));
		$dirty = true;
	}
	return $dirty;
}

/* ------------------------------------------- identity follow-through */

/*
 * Move a block onto the new MAC a device picked up.
 *
 * Only blocks travel this way, never speed limits. Re-applying a limit to the
 * wrong handset is an annoyance nobody would think to report, so it would sit
 * there unnoticed; a wrongly moved block is visible at once and gets fixed.
 */
function nc_reattach_rules(&$store) {
	$dirty = false;
	$names = nc_lease_names();
	$arp   = nc_arp_table();
	$prot  = nc_protected_ips();

	/* the fingerprints we hold a block for, and which MAC holds each */
	$held = array();
	foreach ($store['devices'] as $mac => $rule) {
		if (!empty($rule['blocked']) && !empty($rule['fp'])) {
			$held[$rule['fp']] = $mac;
		}
	}
	if (!$held) {
		return false;
	}

	foreach ($arp as $mac => $a) {
		if (!empty($store['devices'][$mac]['blocked'])) {
			continue;                 /* already blocked, nothing to carry */
		}
		if (!nc_mac_is_random($mac)) {
			continue;                 /* a burned-in MAC is not a rotation */
		}
		$fp = nc_fingerprint($names[$mac]['host'] ?? '');
		if ($fp === '' || nc_fp_is_generic($fp) || !isset($held[$fp])) {
			continue;
		}
		$ip = $a['ip'] ?? '';
		if ($ip !== '' && in_array($ip, $prot, true)) {
			continue;                 /* never touch infrastructure */
		}
		$old = $held[$fp];
		$store['devices'][$mac] = array(
			'ip'        => $ip,
			'name'      => $store['devices'][$old]['name']    ?? '',
			'blocked'   => true,
			'limit'     => null,
			'expires'   => $store['devices'][$old]['expires'] ?? null,
			'by_policy' => !empty($store['devices'][$old]['by_policy']),
			'fp'        => $fp,
			'from_mac'  => $old,
			'last_seen' => time(),
		);
		nc_log(sprintf("MAC-CHANGED %s -> %s (%s): نفس الجهاز ببصمة '%s' — الحظر اتنقل معاه",
			$old, $mac, $ip ?: '-', $fp));
		$dirty = true;
	}
	return $dirty;
}

/*
 * Release a device the policy blocked before its user had logged in.
 *
 * The policy already exempts an authenticated device, but only at the instant
 * it runs. A phone that joins the WiFi and is blocked a second before its user
 * types their password stays blocked for good, because nothing looks at it
 * again. This closes that gap.
 *
 * A block an admin made by hand is left alone: that decision outranks a login.
 */
function nc_release_authenticated(&$store) {
	$pol = nc_policy_get();
	if (empty($pol['respect_portal'])) {
		return false;
	}
	$cp = nc_portal_sessions();
	if (!$cp['by_mac'] && !$cp['by_ip']) {
		return false;
	}
	$dirty = false;
	foreach ($store['devices'] as $mac => &$rule) {
		if (empty($rule['blocked']) || empty($rule['by_policy'])) {
			continue;                 /* only policy blocks auto-release */
		}
		$ip   = $rule['ip'] ?? '';
		$sess = $cp['by_mac'][$mac] ?? ($ip !== '' ? ($cp['by_ip'][$ip] ?? null) : null);
		if (!$sess) {
			continue;
		}
		$rule['blocked']   = false;
		$rule['by_policy'] = false;
		$rule['expires']   = null;
		nc_log(sprintf("PORTAL-RELEASE %s (%s): سجّل دخول بـ '%s' — الحظر اترفع",
			$mac, $ip ?: '-', $sess['username'] ?: '?'));
		$dirty = true;
	}
	unset($rule);
	return $dirty;
}

/* -------------------------------------------------- site blocking */

/*
 * Write the blocked domains into the resolver and reload it.
 *
 * The store only records an intent; without this the page listed blocked
 * sites while every one of them still resolved. Blocking happens in DNS
 * rather than in pf because a domain has no fixed address - youtube.com
 * answers from hundreds of IPs that change constantly, so a firewall rule
 * would need a list nobody can keep current.
 *
 * An expired entry is skipped here rather than deleted, so a temporary block
 * lapses on its own and the admin can still see it in the list.
 */
function nc_dns_apply() {
	$store = nc_store_load();
	$now   = time();
	$file  = '/var/unbound/netcontrol.conf';

	$lines = array("# generated by netcontrol - do not edit by hand\n");
	$count = 0;
	$seen  = array();

	foreach (($store['sites'] ?? array()) as $site) {
		if (empty($site['enabled'])) {
			continue;
		}
		$exp = (int)($site['expires'] ?? 0);
		if ($exp > 0 && $exp <= $now) {
			continue;                       /* lapsed; left in the list */
		}
		$dom = strtolower(trim($site['domain'] ?? ''));
		/* a domain, not a hostname pattern: no slashes, spaces or wildcards */
		if ($dom === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $dom)) {
			continue;
		}
		if (isset($seen[$dom])) {
			continue;
		}
		$seen[$dom] = true;

		/*
		 * "refuse" rather than an address: it fails immediately instead of
		 * sending the browser to something that then times out, and it
		 * cannot be mistaken for a real answer.
		 */
		/* trailing dot: pfSense writes its own zones fully qualified */
		$lines[] = sprintf("local-zone: \"%s.\" refuse\n", rtrim($dom, "."));
		$count++;
	}

	$body = implode('', $lines);

	/*
	 * Put the include INSIDE unbound's server: block.
	 *
	 * Appending it to the end of the file puts it outside every block, which
	 * unbound rejects as a syntax error - and since pfSense starts unbound
	 * from that file, the daemon then refuses to start and the whole network
	 * loses DNS. pfSense's own includes (host_entries, access_lists) all sit
	 * inside server:, so anchor on one of those and insert alongside it.
	 *
	 * The config is validated before unbound is asked to reload it: an
	 * invalid file must never reach a running resolver.
	 */
	$conf = '/var/unbound/unbound.conf';
	$inc  = 'include: ' . $file;
	$cur  = is_readable($conf) ? (string)@file_get_contents($conf) : '';
	$added_inc = false;

	if ($cur !== '' && strpos($cur, $inc) === false) {
		$anchor = 'include: /var/unbound/host_entries.conf';
		if (strpos($cur, $anchor) !== false) {
			$next = str_replace($anchor, $anchor . "\n" . $inc, $cur);
			$tmp  = $conf . '.nctest';
			if (@file_put_contents($tmp, $next) !== false) {
				/* write the zone file first, so the check sees what it includes */
				@file_put_contents($file, $body);
				$rc = mwexec('/usr/local/sbin/unbound-checkconf ' .
				             escapeshellarg($tmp) . ' >/dev/null 2>&1');
				if ($rc === 0) {
					@copy($conf, $conf . '.ncbak');
					@rename($tmp, $conf);
					$added_inc = true;
				} else {
					@unlink($tmp);
					nc_log('DNS-ERROR unbound-checkconf refused the include');
					return -1;
				}
			}
		} else {
			nc_log('DNS-ERROR could not find the include anchor in unbound.conf');
			return -1;
		}
	}

	$old = is_readable($file) ? (string)@file_get_contents($file) : null;
	if ($old === $body && !$added_inc) {
		return $count;                      /* nothing changed; skip the reload */
	}
	if (@file_put_contents($file, $body) === false) {
		nc_log('DNS-ERROR cannot write the block file');
		return -1;
	}

	/* never hand a running resolver a file it cannot parse */
	if (mwexec('/usr/local/sbin/unbound-checkconf ' . escapeshellarg($conf) .
	           ' >/dev/null 2>&1') !== 0) {
		@file_put_contents($file, "# disabled: config check failed\n");
		nc_log('DNS-ERROR config check failed; blocks disabled to protect DNS');
		return -1;
	}

	/* a reload keeps the cache and existing clients; a restart would not */
	$rc = mwexec('/usr/local/sbin/unbound-control -c /var/unbound/unbound.conf reload 2>/dev/null');
	if ($rc !== 0) {
		mwexec('/usr/local/sbin/pfSsh.php playback svc restart unbound 2>/dev/null');
	}
	nc_log(sprintf('DNS-APPLY %d نطاق محظور', $count));
	return $count;
}

/* How many site blocks are live right now (ignoring lapsed ones)? */
function nc_dns_active() {
	$store = nc_store_load();
	$now   = time();
	$n = 0;
	foreach (($store['sites'] ?? array()) as $x) {
		if (empty($x['enabled'])) continue;
		$exp = (int)($x['expires'] ?? 0);
		if ($exp > 0 && $exp <= $now) continue;
		$n++;
	}
	return $n;
}

/* ------------------------------------------------------- login log */

/*
 * Parse the captive portal's own auth log.
 *
 * pfSense writes one line per event, for example:
 *   Nov 16 10:14:56 pfSense logportalauth[801]: Zone: mainlan - ACCEPT: \
 *     YoussefDr, 2c:44:fd:29:f2:de, 192.168.1.134
 *
 * The file is a circular log, so it is read from the end rather than
 * streamed: on a busy network the start of it is already overwritten.
 */
function nc_login_log($limit = 300) {
	$file = '/var/log/portalauth.log';
	if (!is_readable($file)) {
		return array();
	}

	/* read the tail only - this file can be hundreds of KB */
	$want = max(1, (int)$limit);
	$size = max(0, (int)@filesize($file));
	$take = min($size, $want * 220);
	$txt  = '';
	if ($take > 0 && ($fh = @fopen($file, 'r'))) {
		@fseek($fh, -$take, SEEK_END);
		$txt = (string)@fread($fh, $take);
		@fclose($fh);
	}

	$out  = array();
	$year = (int)date('Y');
	foreach (explode("\n", $txt) as $line) {
		if (strpos($line, 'logportalauth') === false) {
			continue;
		}
		if (!preg_match('/^(\w{3}\s+\d+\s+\d\d:\d\d:\d\d).*?- ([A-Z ]+?):\s*' .
		                '([^,]*),\s*([0-9a-f:]{17})?,?\s*([\d.]*)/i',
		                $line, $m)) {
			continue;
		}
		$kind = trim($m[2]);
		$ts   = strtotime($m[1] . ' ' . $year);
		/*
		 * The log carries no year. A timestamp in the future means the entry
		 * is from last December read in January, so step it back a year
		 * rather than showing a date that has not happened.
		 */
		if ($ts && $ts > time() + 86400) {
			$ts = strtotime($m[1] . ' ' . ($year - 1));
		}
		$out[] = array(
			'at'   => $ts ?: null,
			'kind' => $kind,
			'user' => trim($m[3]),
			'mac'  => nc_norm_mac($m[4] ?? ''),
			'ip'   => trim($m[5] ?? ''),
		);
	}
	$out = array_reverse($out);              /* newest first */
	return array_slice($out, 0, $want);
}

/* A per-user summary built from the same log. */
function nc_login_summary($limit = 600) {
	$by = array();
	foreach (nc_login_log($limit) as $e) {
		$u = $e['user'];
		if ($u === '') {
			continue;
		}
		if (!isset($by[$u])) {
			$by[$u] = array('user' => $u, 'logins' => 0, 'kicked' => 0,
			                'last' => null, 'macs' => array(), 'ips' => array());
		}
		if (stripos($e['kind'], 'ACCEPT') !== false) {
			$by[$u]['logins']++;
		}
		if (stripos($e['kind'], 'TERMINAT') !== false ||
		    stripos($e['kind'], 'CONCURRENT') !== false) {
			$by[$u]['kicked']++;
		}
		if ($e['mac']) { $by[$u]['macs'][$e['mac']] = 1; }
		if ($e['ip'])  { $by[$u]['ips'][$e['ip']]   = 1; }
		if ($e['at'] && (!$by[$u]['last'] || $e['at'] > $by[$u]['last'])) {
			$by[$u]['last'] = $e['at'];
		}
	}
	foreach ($by as &$u) {
		$u['devices'] = count($u['macs']);
		$u['macs'] = array_keys($u['macs']);
		$u['ips']  = array_keys($u['ips']);
	}
	unset($u);
	uasort($by, function($a, $b) { return $b['logins'] <=> $a['logins']; });
	return array_values($by);
}

/* ---------------------------------------------------- usage history */

/*
 * Keep a rolling record of what each device consumed.
 *
 * The live counters reset whenever a pipe is reconfigured, so "how much did
 * this person use today" cannot be answered from them. Each tick appends the
 * delta to a daily total instead, which survives both resets and reboots.
 */
function nc_usage_record() {
	$file = '/var/db/netcontrol_usage.json';
	$day  = date('Y-m-d');
	$hist = array();
	if (is_readable($file)) {
		$j = @json_decode(@file_get_contents($file), true);
		if (is_array($j)) {
			$hist = $j;
		}
	}

	/*
	 * Resolve each address to the device holding it RIGHT NOW, and record
	 * against that MAC.
	 *
	 * Keying on the address was wrong here: the DHCP pool recycles fast
	 * enough that one IP served thirteen devices in a day, so a daily total
	 * under one name was really several people added together. A MAC stays
	 * with its device, so the total follows the right person even after the
	 * address changes.
	 */
	$arp = nc_arp_table();
	$byip = array();
	foreach ($arp as $mac => $a) {
		if (!empty($a['ip'])) {
			$byip[$a['ip']] = $mac;
		}
	}

	$prev = $hist['_last'] ?? array();
	$now  = array();
	foreach (nc_pipe_usage() as $ip => $u) {
		$mac = $byip[$ip] ?? null;
		if (!$mac) {
			continue;              /* address we cannot attribute - skip it */
		}
		$now[$mac] = (int)($u['bytes'] ?? 0);
	}

	foreach ($now as $mac => $bytes) {
		$was = (int)($prev[$mac] ?? 0);
		$d   = $bytes - $was;
		if ($d <= 0) {
			continue;              /* counter reset, or idle */
		}
		/*
		 * A device that moved to a new address starts from a fresh counter,
		 * so the first delta after a move would be the whole counter value.
		 * Cap a single tick at what the line could physically carry in the
		 * interval, so one move cannot invent gigabytes.
		 */
		$cap = 60 * 60 * 1024 * 1024;        /* ~1 GB per tick, generous */
		if ($d > $cap) {
			$d = 0;
		}
		if (!isset($hist[$day])) {
			$hist[$day] = array();
		}
		$hist[$day][$mac] = (int)($hist[$day][$mac] ?? 0) + $d;
	}
	$hist['_last'] = $now;

	/* keep two weeks; anything older is noise nobody acts on */
	foreach (array_keys($hist) as $k) {
		if ($k === '_last') {
			continue;
		}
		if (strtotime($k) < strtotime('-14 days')) {
			unset($hist[$k]);
		}
	}
	@file_put_contents($file, json_encode($hist), LOCK_EX);
	return isset($hist[$day]) ? count($hist[$day]) : 0;
}

/* The recorded usage, newest day first, with names attached. */
function nc_usage_history($days = 7) {
	$file = '/var/db/netcontrol_usage.json';
	if (!is_readable($file)) {
		return array();
	}
	$hist = @json_decode(@file_get_contents($file), true);
	if (!is_array($hist)) {
		return array();
	}
	unset($hist['_last']);

	/* name and current address for each MAC, so the table reads properly */
	$name = array();
	$ipof = array();
	foreach (nc_devices() as $d) {
		if (!empty($d['mac'])) {
			$name[$d['mac']] = $d['name'] ?: ($d['hostname'] ?: '');
			$ipof[$d['mac']] = $d['ip'] ?? '';
		}
	}

	krsort($hist);
	$out = array();
	foreach (array_slice($hist, 0, max(1, (int)$days), true) as $day => $rows) {
		arsort($rows);
		$items = array();
		$total = 0;
		foreach ($rows as $mac => $bytes) {
			$total += $bytes;
			$items[] = array(
				'mac'   => $mac,
				'ip'    => $ipof[$mac] ?? '',
				'name'  => $name[$mac] ?? '',
				'bytes' => $bytes,
			);
		}
		$out[] = array('day' => $day, 'total' => $total,
		               'devices' => count($items),
		               'items' => array_slice($items, 0, 30));
	}
	return $out;
}

/* ------------------------------------------------ DNS enforcement */

/*
 * Known DNS-over-HTTPS endpoints.
 *
 * DoH hides a DNS query inside an ordinary HTTPS request, so the resolver
 * never sees it and a blocked domain resolves anyway. The addresses below
 * serve DoH and nothing else a browser needs, so refusing them on 443 makes
 * the browser fall back to normal DNS, which we do control.
 *
 * Deliberately NOT here: the plain-DNS addresses pfSense forwards to. Those
 * must stay reachable from the firewall itself or the whole network loses
 * name resolution.
 */
function nc_doh_hosts() {
	return array(
		/* Google */
		'8.8.8.8', '8.8.4.4',
		/* Cloudflare */
		'1.1.1.1', '1.0.0.1',
		'104.16.248.249', '104.16.249.249',
		/* Quad9 */
		'9.9.9.9', '149.112.112.112',
		/* OpenDNS */
		'208.67.222.222', '208.67.220.220',
		/* AdGuard */
		'94.140.14.14', '94.140.15.15',
	);
}

/*
 * Force DNS through the firewall and close the DoH escape.
 *
 * Two parts, both written into our own anchor so pfSense's rules are left
 * alone:
 *
 *   1. a redirect that sends any client DNS query to unbound, whatever
 *      server the client asked for. Clients that hardcode 8.8.8.8 keep
 *      working, they just get our answers - and our blocks.
 *
 *   2. a block on the DoH endpoints over 443, from LAN clients only. The
 *      firewall's own traffic is untouched, because unbound forwards to two
 *      of those very addresses.
 */
function nc_dns_enforce($enable = true) {
	$lan  = NC_LAN_IF;
	$self = NC_LAN_IP;

	if (!$enable) {
		mwexec("/sbin/pfctl -a 'natearly/ncdns' -F nat 2>/dev/null");
		mwexec("/sbin/pfctl -a 'userrules/ncdns' -F rules 2>/dev/null");
		nc_log('DNS-ENFORCE off');
		return true;
	}

	/* --- the redirect ------------------------------------------------ */
	$nat = "rdr pass on {$lan} inet proto { tcp udp } from any to ! {$self} " .
	       "port 53 -> {$self} port 53\n";
	$nf = '/tmp/ncdns.nat';
	@file_put_contents($nf, $nat);

	/* --- the DoH block ------------------------------------------------ */
	$rules = '';
	foreach (nc_doh_hosts() as $ip) {
		$rules .= "block return in quick on {$lan} inet proto tcp " .
		          "from any to {$ip} port 443\n";
	}
	$rf = '/tmp/ncdns.rules';
	@file_put_contents($rf, $rules);

	/*
	 * Check both files before loading either. A rule the parser rejects
	 * would leave the anchor half-applied, and a mistake in a DNS redirect
	 * takes the network's name resolution with it.
	 */
	$c1 = mwexec("/sbin/pfctl -a 'natearly/ncdns' -n -N -f {$nf} 2>/dev/null");
	$c2 = mwexec("/sbin/pfctl -a 'userrules/ncdns' -n -f {$rf} 2>/dev/null");
	if ($c1 !== 0 || $c2 !== 0) {
		nc_log("DNS-ENFORCE-ERROR pf rejected the rules (nat={$c1} filter={$c2})");
		return false;
	}

	/*
	 * The redirect goes in natearly, not userrules: the main ruleset calls
	 * only natearly, natrules and tftp-proxy for NAT, so a rdr placed in
	 * userrules loads without error and is then never evaluated.
	 */
	mwexec("/sbin/pfctl -a 'natearly/ncdns' -N -f {$nf} 2>/dev/null");
	mwexec("/sbin/pfctl -a 'userrules/ncdns' -f {$rf} 2>/dev/null");
	nc_log(sprintf('DNS-ENFORCE on (%d DoH endpoints blocked)',
		count(nc_doh_hosts())));
	return true;
}

/*
 * Is our zone file actually included by the resolver?
 *
 * Checked separately from "does the file exist", because the file can be
 * perfectly correct while unbound never reads it - which is exactly the
 * state pfSense leaves behind when it regenerates its config.
 */
function nc_dns_included() {
	$conf = '/var/unbound/unbound.conf';
	if (!is_readable($conf)) {
		return false;
	}
	return (strpos((string)@file_get_contents($conf),
	               'include: /var/unbound/netcontrol.conf') !== false);
}

/* Is DNS enforcement loaded right now? */
function nc_dns_enforced() {
	$n = shell_exec("/sbin/pfctl -a 'natearly/ncdns' -s nat 2>/dev/null");
	return (strpos((string)$n, 'port = domain') !== false ||
	        strpos((string)$n, 'port 53') !== false ||
	        strpos((string)$n, 'domain') !== false);
}

/* ------------------------------------------------ rule enforcement */

/*
 * Install the pf rule that actually enforces the block table.
 *
 * Filling a table does nothing on its own: without a rule that reads it, the
 * addresses sit there and the traffic passes. pfSense's own LAN rules are
 * "pass quick", so the first matching rule wins and a block placed after them
 * would never be reached. The userrules anchor is evaluated before them,
 * which makes it the one place a block can take effect.
 *
 * The rule is written into the anchor, not into config.xml, so it survives a
 * filter reload without touching the user's own firewall rules — and a wrong
 * rule here can be removed by flushing one anchor rather than by editing
 * their configuration.
 */
function nc_install_anchor() {
	$table = NC_TABLE;
	$lan   = NC_LAN_IF;

	/*
	 * Block in both directions. Inbound alone stops the device reaching the
	 * internet, but replies to connections opened before the block would
	 * still flow back, so an existing stream keeps playing; killing the
	 * states (in nc_apply) plus the outbound half closes that.
	 */
	/*
	 * Per-device speed limits.
	 *
	 * A dnctl pipe only shapes traffic a rule hands to it, so each limited
	 * device needs a pass rule naming its pipes. They come before the block
	 * rules below because a blocked device should be cut, not merely slowed.
	 *
	 * Two pipes per device: one for each direction. The download pipe masks
	 * on dst-ip so each device gets its own bucket; the upload one is the
	 * same pipe number plus a fixed offset, masked on src-ip.
	 */
	$shape = '';
	$store = nc_store_load();
	$now   = time();
	foreach (($store['devices'] ?? array()) as $mac => $rule) {
		$ip = $rule['ip'] ?? '';
		if ($ip === '' || !empty($rule['blocked'])) {
			continue;
		}
		$mb = (int)($rule['limit'] ?? 0);
		if ($mb <= 0) {
			continue;
		}
		$exp = (int)($rule['expires'] ?? 0);
		if ($exp > 0 && $exp <= $now) {
			continue;                       /* lapsed */
		}
		$dn = NC_PIPE_BASE + (crc32($mac) % 500);
		$up = $dn + 1000;
		$shape .= sprintf("pass in  quick on %s from %s to any keep state dnpipe(%d, %d)\n",
			$lan, $ip, $up, $dn);
	}

	$rules = $shape .
	         "block in  quick on {$lan} from <{$table}> to any\n" .
	         "block out quick on {$lan} from any to <{$table}>\n";

	$file = '/tmp/netcontrol.rules';
	if (@file_put_contents($file, $rules) === false) {
		nc_log('ANCHOR-ERROR ماقدرش يكتب ملف القواعد');
		return false;
	}

	/* the table must exist before a rule can reference it */
	mwexec("/sbin/pfctl -t {$table} -T add 127.0.0.255 2>/dev/null");
	mwexec("/sbin/pfctl -t {$table} -T delete 127.0.0.255 2>/dev/null");

	/*
	 * It must go under userrules, not in a top-level anchor of its own: the
	 * main ruleset only calls openvpn, ipsec, userrules and tftp-proxy, so a
	 * "netcontrol" anchor loads fine and is then never evaluated. userrules
	 * is also reached before the LAN pass rules, which is what lets a block
	 * win against them.
	 */
	$rc = mwexec("/sbin/pfctl -a 'userrules/netcontrol' -f {$file} 2>/dev/null");
	if ($rc !== 0) {
		nc_log("ANCHOR-ERROR pfctl رجع {$rc}");
		return false;
	}
	return true;
}

/* Is the enforcement rule actually loaded right now? */
function nc_anchor_active() {
	$out = shell_exec("/sbin/pfctl -a 'userrules/netcontrol' -sr 2>/dev/null");
	return (strpos((string)$out, NC_TABLE) !== false);
}

/* ------------------------------------------------------------- apply rules */

/*
 * Push the stored rules into the kernel:
 *   - blocked devices go into the pf table NC_TABLE (by IP, resolved from MAC)
 *   - per-device limits become one dummynet pipe each
 * The pf rule that references the table lives in the firewall ruleset and is
 * installed once by nc_install_anchor().
 */
function nc_apply() {
	$store = nc_store_load();
	$block_ips = array();

	/*
	 * Make sure the enforcing rule is loaded. A filter reload or a reboot
	 * drops the anchor's contents while the table keeps its addresses, which
	 * is how blocks can look applied on the page while traffic flows.
	 */
	/*
	 * Always rebuild the anchor: it now carries a pass rule per limited
	 * device, so it has to change whenever a limit is added or removed,
	 * not only when the anchor has gone missing entirely.
	 */
	nc_install_anchor();

	/*
	 * Re-assert DNS enforcement too. A filter reload flushes our anchors
	 * while leaving the store intact, so without this the redirect and the
	 * DoH block quietly disappear the next time pfSense rewrites its rules.
	 */
	if (!nc_dns_enforced()) {
		nc_dns_enforce(true);
	}

	/*
	 * And the resolver's include. pfSense regenerates unbound.conf whenever
	 * its DNS settings are touched, dropping our line with it; the blocked
	 * domains then resolve normally while the page still lists them as
	 * blocked. Re-adding it here costs one string search per tick.
	 */
	if (!nc_dns_included()) {
		/*
		 * Restore it even when nothing is blocked right now. Waiting until
		 * a block exists means the first block after a config rewrite does
		 * nothing, and the page reports it as applied - the failure is
		 * invisible precisely when it matters.
		 */
		nc_log('DNS-HEAL the resolver include was missing - restoring');
		nc_dns_apply();
	}

	foreach ($store['devices'] as $mac => $rule) {
		$ip = $rule['ip'] ?? '';
		if (!$ip) {
			continue;
		}
		if (!empty($rule['blocked'])) {
			$block_ips[] = $ip;
		}
		if (isset($rule['limit']) && $rule['limit'] !== null && (int)$rule['limit'] > 0) {
			$pipe = NC_PIPE_BASE + (crc32($mac) % 500);
			$bw   = (int)$rule['limit'];
			/*
			 * "queue" and "codel" cannot both be given: dnctl rejects the
			 * whole command with "unrecognised option", so every per-device
			 * limit silently failed to create its pipe. codel manages the
			 * queue itself, so it is the one to keep.
			 */
			/* download: the device is the destination, so mask on dst-ip */
			$rc = mwexec("/sbin/dnctl pipe {$pipe} config bw {$bw}Mbit/s " .
			             "mask dst-ip 0xffffffff codel 2>/dev/null");
			/* upload: same device, now the source - a separate pipe number */
			$upb = max(1, (int)round($bw / 2));
			$rc2 = mwexec("/sbin/dnctl pipe " . ($pipe + 1000) .
			              " config bw {$upb}Mbit/s " .
			              "mask src-ip 0xffffffff codel 2>/dev/null");
			if ($rc !== 0 || $rc2 !== 0) {
				nc_log("PIPE-ERROR {$mac}: dnctl رفض الأنبوب {$pipe} (rc={$rc}/{$rc2})");
			}
		}
	}

	/* Replace the table contents in one shot so there is no open window. */
	$tmp = '/tmp/nc_blocked.txt';
	file_put_contents($tmp, implode("\n", array_unique($block_ips)) . "\n");
	mwexec('/sbin/pfctl -t ' . NC_TABLE . ' -T replace -f ' . escapeshellarg($tmp) . ' 2>/dev/null');
	@unlink($tmp);

	/*
	 * Drop existing states for anything blocked. A "block" rule only stops
	 * new connections; a stream already running keeps flowing through its
	 * open state until it times out, which looks exactly like the block
	 * having no effect.
	 */
	foreach ($block_ips as $ip) {
		mwexec('/sbin/pfctl -k ' . escapeshellarg($ip) . ' 2>/dev/null');
	}
	return count($block_ips);
}

?>
