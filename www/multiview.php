<?php
//Blantly ripped off from https://github.com/donatj/mjpeg-php/blob/master/mjpeg.php
//And then modified to suit out needs
//multiview version of cam_pic_new.php for multiple hosts

define('BASE_DIR', dirname(__FILE__));
require_once(BASE_DIR.'/config.php');
$multi = @json_decode(@file_get_contents(BASE_DIR . "/multiview.json"), true);
if (!is_array($multi) || empty($multi["hosts"])) {
	denyRequest(404, "multiview.json not configured (see multiview.jsonD)");
}

function hostIndex($param, $multi) {
	$i = isset($_GET[$param]) ? (int)$_GET[$param] : 0;
	if (!array_key_exists($i, $multi["hosts"])) denyRequest(404, "Unknown host");
	return $i;
}

if (isset($_GET["rHost"])) {
	$host = $multi["hosts"][hostIndex("rHost", $multi)];
	header('Location: '.$host);
} else {
	$hostI = hostIndex("pHost", $multi);
	$host = $multi["hosts"][$hostI];
	$preview_delay = isset($multi["delays"][$hostI]) ? (int)$multi["delays"][$hostI] : 40000;
	$preview_delay = max(10000, $preview_delay);

	//writeLog("mjpeg stream with $preview_delay delay");

	// Used to separate multipart
	$boundary = "PIderman";

	// We start with the standard headers. PHP allows us this much
	header ("Content-type: multipart/x-mixed-replace; boundary=$boundary");
	header ("Cache-Control: no-cache");
	header ("Pragma: no-cache");
	header ("Connection: close");

	while (ob_get_level() > 0) ob_end_flush();
	flush();		//Push out the content we already have (gets the headers to the browser as quickly as possible)

	set_time_limit(0); // Set this so PHP doesn't timeout during a long stream

	while(!connection_aborted()) {
		$fileContents = @file_get_contents($host . 'cam_get.php');
		if ($fileContents !== false && $fileContents !== '') {
			echo "--$boundary\r\n";
			echo "Content-type: image/jpeg\r\n";
			echo "Content-Length:" . strlen($fileContents) . "\r\n";
			echo "\r\n";
			echo $fileContents;
			echo "\r\n";
			flush();
		}
		usleep($preview_delay);
	}	
}
