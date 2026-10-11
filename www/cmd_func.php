<?php
	// System functions: reboot, shutdown and setting the clock. Requires full user level.
	define('BASE_DIR', dirname(__FILE__));
	require_once(BASE_DIR.'/config.php');

	rejectCrossSiteRequest();
	requireUserLevel(USERLEVEL_MAX);

	function sys_cmd($cmd) {
		if(strncmp($cmd, "reboot", strlen("reboot")) == 0) {
			shell_exec('sudo shutdown -r now');
		} else if(strncmp($cmd, "shutdown", strlen("shutdown")) == 0) {
			shell_exec('sudo shutdown -h now');
		} else if(strncmp($cmd, "settime", strlen("settime")) == 0) {
			$timestr = isset($_REQUEST['timestr']) ? trim($_REQUEST['timestr']) : '';
			// no leading dash (option injection) and must be a parseable date
			if($timestr !== "" && strpos($timestr, "-") === false && date_create($timestr) !== FALSE) {
				shell_exec('sudo date -s ' . escapeshellarg($timestr));
			} else {
				denyRequest(400, 'Invalid time');
			}
		} else {
			denyRequest(400, 'Unknown command');
		}
	}

	$cmd = isset($_POST['cmd']) ? $_POST['cmd'] : (isset($_GET['cmd']) ? $_GET['cmd'] : '');
	if($cmd !== '') {
		sys_cmd($cmd);
		header('Content-Type: text/plain; charset=utf-8');
		echo 'OK';
	}
?>
