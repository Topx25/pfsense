<?php
/*
 * Give every device its own upload queue, and stop shaping the gateway.
 *
 * The upload pipe holds one flow carrying the whole network - source
 * 192.168.2.4, which is the firewall's OWN em2 interface and the path all
 * internet traffic takes. Everything is squeezed through a single 2 Mb
 * queue while the 31 Mb line sits 60% idle.
 *
 * Cause: filter rule key=2 ("WEB_PORTS -> STABLE_WEB") carries
 * gateway=STABLE_WEB, which pf renders as route-to (em2 192.168.2.1).
 * Outbound NAT on em2 rewrites every LAN source to 192.168.2.4 BEFORE that
 * rule's dnpipe applies, so NoLimit_Up's srcaddress mask - which is
 * correct, and which works perfectly on the download side - sees one
 * address for the entire network.
 *
 * Fix: take the pipes off that one rule. Rules key=4 and key=5 already
 * shape the same traffic without a gateway, so they apply pre-NAT where
 * the real client address is still visible, and each device gets its own
 * queue. Routing is untouched: the gateway assignment stays, only the
 * shaping is removed from it.
 *
 * Run with --apply to write. Without it, this only reports.
 */
require_once("/etc/inc/config.inc");
require_once("/etc/inc/util.inc");
require_once("/etc/inc/filter.inc");

$apply = in_array('--apply', $argv, true);
global $config;

$target = null;
foreach (($config['filter']['rule'] ?? array()) as $k => $r) {
    if (($r['gateway'] ?? '') === 'STABLE_WEB' &&
        (($r['dnpipe'] ?? '') !== '' || ($r['pdnpipe'] ?? '') !== '')) {
        $target = $k;
        break;
    }
}

if ($target === null) {
    echo "no gateway rule carries a pipe - nothing to change\n";
    exit(0);
}

$r = $config['filter']['rule'][$target];
printf("rule to change: key=%s  descr=%s\n", $target, $r['descr'] ?? '');
printf("  gateway : %s   (kept - routing is not changed)\n", $r['gateway'] ?? '-');
printf("  dnpipe  : %s -> (removed)\n", $r['dnpipe'] ?? '-');
printf("  pdnpipe : %s -> (removed)\n", $r['pdnpipe'] ?? '-');

if (!$apply) {
    echo "\n(dry run - pass --apply to write)\n";
    exit(0);
}

/* keep a copy of what we are about to change */
$backup = '/root/filter_rule_' . $target . '_' . date('Hi') . '.json';
@file_put_contents($backup, json_encode($r, JSON_PRETTY_PRINT));
printf("\nbacked up the original rule to %s\n", $backup);

unset($config['filter']['rule'][$target]['dnpipe']);
unset($config['filter']['rule'][$target]['pdnpipe']);

write_config("netcontrol: remove shaping from the route-to rule so uploads " .
             "are shaped per device before NAT");
echo "config written\n";

$err = filter_configure();
printf("filter reloaded (rc=%s)\n", var_export($err, true));
