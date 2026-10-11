<?php
//Blantly ripped off from https://github.com/donatj/mjpeg-php/blob/master/mjpeg.php
//And then modified to suit out needs

define('BASE_DIR', dirname(__FILE__));
require_once(BASE_DIR.'/config.php');

// delay between frames in microseconds, clamped to 10ms..2s
$preview_delay = isset($_GET["pDelay"]) ? (int)$_GET["pDelay"] : 10000;
$preview_delay = max(10000, min($preview_delay, 2000000));

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
ignore_user_abort(false);

while(!connection_aborted()) 
{	
	$fileContents = @file_get_contents("/dev/shm/mjpeg/cam.jpg");
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
