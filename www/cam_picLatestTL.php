<?php
  // returns the most recent time lapse image
  define('BASE_DIR', dirname(__FILE__));
  header("Access-Control-Allow-Origin: *");
  $files = scandir(BASE_DIR.'/media', SCANDIR_SORT_DESCENDING);
  $newest_file = "";
  foreach ($files as $file) {
	if(substr($file,0,2) == "tl" && substr($file, -7) != ".th.jpg" && strcasecmp(substr($file, -4), ".jpg") == 0) {
		$newest_file = $file;
		break;
	}
  }
  if($newest_file != "") {
	header("Content-Type: image/jpeg");
	header("Cache-Control: no-store");
	readfile(BASE_DIR . "/media/$newest_file");
  } else {
	http_response_code(404);
	header("Content-Type: text/plain");
	echo "Not found";
  }
?>
