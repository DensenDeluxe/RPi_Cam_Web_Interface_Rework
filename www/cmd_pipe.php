<?php
	// Sends a command to raspimjpeg through its control FIFO.
	// Accepts ?cmd=... (GET, kept for compatibility with scripts and integrations) or a POSTed cmd field.
	define('BASE_DIR', dirname(__FILE__));
	require_once(BASE_DIR.'/config.php');

	rejectCrossSiteRequest();

	$cmd = isset($_POST['cmd']) ? $_POST['cmd'] : (isset($_GET['cmd']) ? $_GET['cmd'] : '');
	// one command per request; drop control characters so a request cannot inject extra lines
	$cmd = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)$cmd));
	if ($cmd === '') {
		denyRequest(400, 'Missing cmd');
	}

	// macro names may only contain plain file name characters (no paths, no shell syntax)
	function safeMacro($s) {
		return (bool)preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.,=+\- ]*$/', $s) && strpos($s, '..') === false;
	}

	function userButtonMacros() {
		$macros = array();
		if (file_exists(BASE_DIR . '/userbuttons')) {
			foreach (explode("\n", file_get_contents(BASE_DIR . '/userbuttons')) as $line) {
				if (strlen(trim($line)) && substr($line, 0, 1) != '#') {
					$fields = explode(',', $line);
					if (count($fields) > 1) $macros[] = trim($fields[1]);
				}
			}
		}
		return $macros;
	}

	$code = strtolower(substr($cmd, 0, 2));
	$arg = trim(substr($cmd, 2));

	// command validation
	if ($code == 'sy' && !safeMacro($arg)) {
		denyRequest(400, 'Invalid macro name');
	}
	if ($code == 'um') {
		// um <index> <[-][&]macroname>
		if (!preg_match('/^\d+( -?&?([A-Za-z0-9_][A-Za-z0-9_.\-]*)?)?$/', $arg) || strpos($arg, '..') !== false) {
			denyRequest(400, 'Invalid macro update');
		}
	}

	// user level checks (no authentication configured means full access)
	$level = currentUserLevel();
	if ($level < (int)USERLEVEL_MAX) {
		$allowed = false;
		if ($level >= (int)USERLEVEL_MEDIUM && in_array($code, array('ca', 'im', 'tl'))) {
			$allowed = true;
		}
		if ($code == 'sy' && in_array($arg, userButtonMacros(), true)) {
			$allowed = true;
		}
		if (!$allowed) {
			denyRequest(403, 'Forbidden: insufficient user level');
		}
	}

	// open read/write so the request never blocks when raspimjpeg is not running
	$pipe = @fopen(BASE_DIR . '/FIFO', 'r+');
	if ($pipe === false) {
		denyRequest(503, 'Control FIFO not available');
	}
	fwrite($pipe, $cmd . "\n");
	fclose($pipe);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'OK';
?>
