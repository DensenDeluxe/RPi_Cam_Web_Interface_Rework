<?php

  // long poll: wait up to 3 seconds for the raspimjpeg status to differ from the client's last known value
  header("Content-Type: text/plain; charset=utf-8");
  header("Cache-Control: no-store");
  $last = isset($_GET["last"]) ? $_GET["last"] : "";
  $file_content = "";
  for($i=0; $i<30; $i++) {
    $file_content = @file_get_contents("status_mjpeg.txt");
    if($file_content != $last) break;
    usleep(100000);
  }
  @touch("status_mjpeg.txt");
  echo $file_content;

?>
