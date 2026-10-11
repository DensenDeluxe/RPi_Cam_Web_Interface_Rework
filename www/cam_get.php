<?php

   touch("status_mjpeg.txt");
   header("Content-Type: image/jpeg");
   header("Cache-Control: no-store");
   if (!@readfile("/dev/shm/mjpeg/cam.jpg")) {
      http_response_code(503);
   }

?>
