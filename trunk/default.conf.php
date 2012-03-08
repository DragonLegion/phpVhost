#!/usr/local/bin/php
<?php
if (file_exists('./vhostbot.pid')) {
	$pid = getmypid();
	$old = file_get_contents('./vhostbot.pid');
	$fp = fopen('./vhostbot.pid','w');
	if (exec('ps -p '.$old)) {
		exec('kill -9 '.$old);
		fwrite($fp,$pid);
	} else {
		fwrite($fp,$pid);
	}
} else {
	$pid = getmypid();
	$fp = fopen('./vhostbot.pid','w');
	fwrite($fp,$pid);
}
$_CONF['servIP'] = '';
$_CONF['servName'] = '';
$_CONF['port'] = '6667';
$_CONF['gecos'] = 'Vhost Bot';
$_CONF['ident'] = 'Vhost';
$_CONF['nick'] = 'Vhost';
$_CONF['taken'] = 'Vhosts';
$_CONF['connect'] = 'fopen'; // values = fopen or socket
/* Extensions */
$_CONF['addon'][0] = 'bLog';
	/* Subpart of bot logging */
	$_CONF['bLog']['type'] = 'html';
	$_CONF['bLog']['dir'] = '../public_html/vhost_logs/';
$_CONF['addon'][1] = 'sql';
	/* Subpart of sql configuration */
	$_CONF['database'] = 'mysql';
	$_CONF['sql']['user'] = '';
	$_CONF['sql']['pass'] = '';
	$_CONF['sql']['host'] = 'localhost';
	$_CONF['sql']['db'] = '';
$_CONF['addon'][2] = 'vhost';
	/* Subpart of servertype to use for vhosting */
	$_CONF['vhost']['server'] = 'unreal'; //possible: unreal, inspircd, services (only anope supported as yet)
$_CONF['addon'][3] = 'oper';
	/* Subpart of oper and identification configuration */
	$_CONF['operNick'] = '';
	$_CONF['operPass'] = '';
	$_CONF['ns']['alias'] = 'NickServ';
	$_CONF['ns']['type'] = 'id';
	$_CONF['ns']['pass'] = '';
$_CONF['addon'][4] = 'channel';
	/* Subpart of channel configuration */
	$_CONF['channel'][0]['name'] = '#vhosting';
	$_CONF['channel'][0]['modes'] = '+tn';
	$_CONF['channel'][1]['name'] = '#opers';
	$_CONF['channel'][1]['modes'] = '+tnsO';
	$_CONF['vChan'] = '#vhosting';
$_CONF['addon'][5] = 'admin';
	/* Subpart of admin configuration.  Change this after first run or bot will exit */
	$_CONF['admin']['add'] = TRUE;
	$_CONF['admin']['nick'] = '';
$_CONF['addon'][6] = 'private';
error_reporting(E_ALL);
include('vhost.core.php');
$irc = new Vhost();
$irc->check();
$irc->connect();
function restart() {
	global $irc;
	$irc->reboot("Rebooting");
	$dead = exec('default.conf.php &>/dev/null &');
	exit;
}
