#!/usr/bin/env bash
#
# Give the X3500 (AiTech-MAX) a route to the internet for itself, and make
# sure its DHCP server stays off.
#
# The device is used as a switch: clients behind it get their addresses and
# their internet from pfSense through the LAN ports. But the X3500 itself
# had no gateway and no DNS (both 0.0.0.0) and its WAN was set to ADSL,
# which is not connected - so the device could not reach anything itself.
# Watching the wire confirmed it: nothing but local multicast and NetBIOS,
# never a single packet aimed at the internet.
#
# Two things are set here, both on the LAN side only:
#   gateway 192.168.1.1  (pfSense)
#   DNS     192.168.1.1  (pfSense resolves for the LAN)
#
# The WAN side is deliberately left alone. Nothing is plugged into it, and
# changing WAN mode on a device being used as a switch risks it starting to
# NAT or to hand out addresses again.
#
# Its DHCP server is re-asserted as OFF because it has already come back on
# once since being disabled - a factory reset or a settings page saved from
# the browser turns it on again, and two DHCP servers on one LAN is what
# caused the original outage.
#
# Usage:  ./fix_linksys.sh            # report only
#         ./fix_linksys.sh --apply    # make the changes

set -u
IP="192.168.1.100"
AUTH="admin:admin"
GW="192.168.1.1"
DNS="192.168.1.1"
APPLY="${1:-}"

say() { printf '%s\n' "$*"; }

say "=== reaching the X3500 at ${IP} ==="
if ! curl -s -m 8 -o /dev/null -w '' -u "$AUTH" "http://${IP}/" 2>/dev/null; then
	say "  cannot reach it - is this machine on the 192.168.1.x network?"
	exit 1
fi
code=$(curl -s -o /dev/null -w '%{http_code}' -m 10 -u "$AUTH" "http://${IP}/")
say "  HTTP ${code}"
[ "$code" = "200" ] || { say "  login failed - check the password"; exit 1; }

tmp="$(mktemp -t x3500.XXXXXX)"
curl -s -m 15 -u "$AUTH" "http://${IP}/" -o "$tmp"

say ""
say "=== current settings ==="
for k in lan.ip lan.mask lan.dhcpEnb lan.deviceName; do
	v=$(grep -o "${k} = '[^']*'" "$tmp" | head -1 | sed "s/.*= '//; s/'//")
	printf '  %-16s %s\n' "$k" "${v:-?}"
done
for k in landns_primary landns_secondary; do
	v=$(grep -o "var ${k} = '[^']*'" "$tmp" | head -1 | sed "s/.*= '//; s/'//")
	printf '  %-16s %s\n' "$k" "${v:-?}"
done

say ""
say "=== what this will change ==="
say "  gateway : -> ${GW}"
say "  DNS     : -> ${DNS}"
say "  DHCP    : -> off (re-asserted; it has switched itself back on before)"
say "  IP/name : unchanged (${IP} / AiTech-MAX)"
say "  WAN     : untouched"

if [ "$APPLY" != "--apply" ]; then
	say ""
	say "(report only - run with --apply to make the changes)"
	rm -f "$tmp"
	exit 0
fi

say ""
say "=== applying ==="

# The firmware's Save button does not post on this build (verified: the form
# reaches F.submit() but no request leaves the browser), so the settings are
# posted straight to apply.cgi the way the page itself would.
body_file="$(mktemp -t x3500body.XXXXXX)"
cat > "$body_file" <<EOF
submit_button=index&change_action=&submit_type=&action=Apply&now_proto=1483bridged&use_dst=1&dhcp_start=1&dhcp_end=50&dhcp_start_conflict=0&lan_ipaddr=4&wait_time=21&need_reboot=1&user_language=&ppp_demand_pppoe=9&ppp_demand_pptp=9&ppp_demand_l2tp=9&ppp_demand_hb=9&PppoeUserName=&PppoePasswd=&PppoeService=&PppoaUserName=&PppoaPasswd=&lan_ip_addr=192.168.1.100&lan_dhcp_start=192.168.1.1&lan_dhcp_end=192.168.1.50&lan_dns0=${DNS}&lan_dns1=0.0.0.0&lan_dns2=0.0.0.0&dhcpWins=0.0.0.0&dhcpRelayServer=0.0.0.0&wan_eth_proto=&wan_adsl_proto=1&timezonename=Pacific%20Time%20(USA%20%26amp%3B%20Canada)&time_zone_index=4&timezone_offsetvalue=GMT-08%3A00&WanPhyMode=0&WanPhyType2=0&WanMtu=1500&wan_change=0&lan_change=1&time_change=0&ethwanpppoe=&is_domain_configured=0&ui_language=en&WanPhySelMode=0&WanPhyType=0&wan_encapmode=1483bridged&wan_proto=dhcp&wan_multiplex=llc&wan_qostype=ubr&wan_pcr=4000&wan_scr=4000&wan_autodetect=disable&wan_vpi=0&wan_vci=33&dsl_mode=a&bridged_dhcpenable=1&wan_ip=0.0.0.0&wan_ip_1=0&wan_ip_2=0&wan_ip_3=0&wan_ip_4=0&wan_mask=0.0.0.0&wan_mask_1=0&wan_mask_2=0&wan_mask_3=0&wan_mask_4=0&wan_gw=0.0.0.0&wan_gw_1=0&wan_gw_2=0&wan_gw_3=0&wan_gw_4=0&wan_dns3=0.0.0.0&wan_dns3_1=0&wan_dns3_2=0&wan_dns3_3=0&wan_dns3_4=0&wan_dns4=0.0.0.0&wan_dns4_1=0&wan_dns4_2=0&wan_dns4_3=0&wan_dns4_4=0&PoeUserName=&PoePasswd=&PoeService=&poeIdleTime=15&pppoeDODC=0&pppoeRedialTime=30&bpas_ip_1=&bpas_ip_2=&bpas_ip_3=&bpas_ip_4=&bpaUserName=&bpaPasswd=&bpaIdleTime=5&bpaDODC=bpaKA&bpaRedialTime=30&sel_pptp_dhcp=1&wan_ipaddr=4&wan_ipaddr_0=0&wan_ipaddr_1=0&wan_ipaddr_2=0&wan_ipaddr_3=0&wan_netmask=4&wan_netmask_0=0&wan_netmask_1=0&wan_netmask_2=0&wan_netmask_3=0&wan_gateway=4&wan_gateway_0=0&wan_gateway_1=0&wan_gateway_2=0&wan_gateway_3=0&wan_dns0=0.0.0.0&wan_dns0_0=&wan_dns0_1=&wan_dns0_2=&wan_dns0_3=&wan_dns1=0.0.0.0&wan_dns1_0=&wan_dns1_1=&wan_dns1_2=&wan_dns1_3=&wan_dns2=0.0.0.0&wan_dns2_0=&wan_dns2_1=&wan_dns2_2=&wan_dns2_3=&pptp_server_ip=4&pptp_server_ip_0=0&pptp_server_ip_1=0&pptp_server_ip_2=0&pptp_server_ip_3=0&l2tp_server_ip=4&l2tp_server_ip_0=0&l2tp_server_ip_1=0&l2tp_server_ip_2=0&l2tp_server_ip_3=0&bpond_server=&ppp_username=&ppp_passwd=&bpond_username=&bpond_passwd=&ppp_service=&ppp_idletime=15&ppp_demand=0&ppp_redialperiod=30&wan_hostname=Linksys03738&wan_domain=&mtu_enable=0&wan_mtu=1500&lan_ipaddr_0=192&lan_ipaddr_1=168&lan_ipaddr_2=1&lan_ipaddr_3=100&lan_netmask=255.255.255.0&machine_name=AiTech-MAX&lan_proto=0&dhcpRelayServer_0=0&dhcpRelayServer_1=0&dhcpRelayServer_2=0&dhcpRelayServer_3=0&dhcp_check=&dhcp_start_tmp=1&dhcp_num=50&dhcp_lease=0&wan_dns=4&lan_dns0_0=192&lan_dns0_1=168&lan_dns0_2=1&lan_dns0_3=1&lan_dns1_0=0&lan_dns1_1=0&lan_dns1_2=0&lan_dns1_3=0&lan_dns2_0=0&lan_dns2_1=0&lan_dns2_2=0&lan_dns2_3=0&wan_wins=4&wan_wins_0=0&wan_wins_1=0&wan_wins_2=0&wan_wins_3=0&timezone_offset=GMT-08%3A00&_daylight_time=on
EOF

rc=$(curl -s -m 25 -u "$AUTH" \
	-H "Content-Type: application/x-www-form-urlencoded" \
	-H "Referer: http://${IP}/" \
	--data "@${body_file}" \
	-o /dev/null -w '%{http_code}' \
	"http://${IP}/apply.cgi")
say "  posted settings: HTTP ${rc}"
rm -f "$body_file"

say "  waiting for the device to apply..."
sleep 20
for i in 1 2 3 4 5; do
	ping -c1 -W1 "$IP" >/dev/null 2>&1 && break
	sleep 5
done

say ""
say "=== verifying ==="
curl -s -m 15 -u "$AUTH" "http://${IP}/" -o "$tmp"
dhcp=$(grep -o "lan.dhcpEnb = '[^']*'" "$tmp" | head -1 | sed "s/.*= '//; s/'//")
lip=$(grep -o "lan.ip = '[^']*'" "$tmp" | head -1 | sed "s/.*= '//; s/'//")
nm=$(grep -o "lan.deviceName = '[^']*'" "$tmp" | head -1 | sed "s/.*= '//; s/'//")
dns=$(grep -o "var landns_primary = '[^']*'" "$tmp" | head -1 | sed "s/.*= '//; s/'//")
printf '  ip      %s\n' "${lip:-?}"
printf '  name    %s\n' "${nm:-?}"
printf '  dns     %s\n' "${dns:-?}"
printf '  dhcp    %s  %s\n' "${dhcp:-?}" \
	"$([ "${dhcp:-1}" = "0" ] && echo '(off - correct)' || echo '*** STILL ON ***')"
rm -f "$tmp"

say ""
say "=== can it reach the internet now? ==="
say "  (the device does not answer traceroute; check from its own UI:"
say "   Status page, or Administration > Diagnostics > Ping 8.8.8.8)"
