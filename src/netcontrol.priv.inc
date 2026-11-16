<?php
/*
 * netcontrol.priv.inc
 *
 * Privilege definition for the Network Control page.
 * Granting only this privilege lets a user open Services > Network Control
 * and nothing else in the pfSense GUI.
 */

global $priv_list;

$priv_list['page-services-netcontrol'] = array();
$priv_list['page-services-netcontrol']['name'] = "WebCfg - Services: Network Control";
$priv_list['page-services-netcontrol']['descr'] =
	"Allow access to the Network Control page: view devices, set per-device " .
	"bandwidth limits, block or unblock devices, manage site and video rules.";
$priv_list['page-services-netcontrol']['match'] = array();
$priv_list['page-services-netcontrol']['match'][] = "services_netcontrol.php*";
$priv_list['page-services-netcontrol']['match'][] = "netcontrol_api.php*";

?>
