<?php
  header("Access-Control-Allow-Origin: *");
  header("Content-Type: image/jpeg");
  header("Cache-Control: no-store");
  // delay in microseconds, clamped to 0..2s so a request cannot tie up a worker
  $preview_delay = isset($_GET["pDelay"]) ? (int)$_GET["pDelay"] : 10000;
  $preview_delay = max(0, min($preview_delay, 2000000));
  usleep($preview_delay);
  if (!@readfile("/dev/shm/mjpeg/cam.jpg")) {
     http_response_code(503);
  }

?>
