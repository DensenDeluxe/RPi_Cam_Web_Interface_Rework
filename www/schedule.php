<?php
   // Scheduler: runs as a background CLI process (php schedule.php) and provides the schedule settings page.
   // Note: install.sh used to rewrite paths in this file, keep it free of absolute install paths.
   define('BASE_DIR', dirname(__FILE__));
   require_once(BASE_DIR.'/config.php');

   define('SCHEDULE_CONFIG', 'schedule.json');
   define('SCHEDULE_CONFIGBACKUP', 'scheduleBackup.json');

   define('SCHEDULE_START', '1');
   define('SCHEDULE_STOP', '0');
   define('SCHEDULE_RESET', '9');

   define('SCHEDULE_TIMES_MAX', '12');

   define('SCHEDULE_FIFOIN', 'Fifo_In');
   define('SCHEDULE_FIFOOUT', 'Fifo_Out');
   define('SCHEDULE_CMDPOLL', 'Cmd_Poll');
   define('SCHEDULE_MODEPOLL', 'Mode_Poll');
   define('SCHEDULE_MAXCAPTURE', 'Max_Capture');
   define('SCHEDULE_LATITUDE', 'Latitude');
   define('SCHEDULE_LONGITUDE', 'Longitude');
   define('SCHEDULE_GMTOFFSET', 'GMTOffset');
   define('SCHEDULE_DAWNSTARTMINUTES', 'DawnStart_Minutes');
   define('SCHEDULE_DAYSTARTMINUTES', 'DayStart_Minutes');
   define('SCHEDULE_DAYENDMINUTES', 'DayEnd_Minutes');
   define('SCHEDULE_DUSKENDMINUTES', 'DuskEnd_Minutes');
   define('SCHEDULE_ALLDAY', 'AllDay');
   define('SCHEDULE_DAYMODE', 'DayMode');
   define('SCHEDULE_MODE_SUN', '0');
   define('SCHEDULE_MODE_ALLDAY', '1');
   define('SCHEDULE_MODE_FIXED', '2');
   define('SCHEDULE_FIXEDTIMES', 'FixedTimes');
   define('SCHEDULE_MANAGEMENTINTERVAL', 'Management_Interval');
   define('SCHEDULE_MANAGEMENTCOMMAND', 'Management_Command');
   define('SCHEDULE_PURGEVIDEOHOURS', 'PurgeVideo_Hours');
   define('SCHEDULE_PURGEIMAGEHOURS', 'PurgeImage_Hours');
   define('SCHEDULE_PURGELAPSEHOURS', 'PurgeLapse_Hours');
   define('SCHEDULE_PURGESPACEMODE', 'PurgeSpace_ModeEx');
   define('SCHEDULE_PURGESPACELEVEL', 'PurgeSpace_Level');
   define('SCHEDULE_AUTOCAPTUREINTERVAL', 'AutoCapture_Interval');
   define('SCHEDULE_AUTOCAMERAINTERVAL', 'AutoCamera_Interval');
   define('SCHEDULE_COMMANDSON', 'Commands_On');
   define('SCHEDULE_COMMANDSOFF', 'Commands_Off');
   define('SCHEDULE_MODES', 'Modes');
   define('SCHEDULE_TIMES', 'Times');
   define('SCHEDULE_DAYS', 'Days');

   $schedulePars = loadPars(BASE_DIR . '/' . SCHEDULE_CONFIG);
   $cliCall = isCli();

   function isCli() {
       if( defined('STDIN') ) {
           return true;
       }
       if( empty($_SERVER['REMOTE_ADDR']) and !isset($_SERVER['HTTP_USER_AGENT']) and isset($_SERVER['argv']) and count($_SERVER['argv']) > 0) {
           return true;
       }
       return false;
   }

   function getSchedulePID() {
      $pids = array();
      exec("ps -ef", $pids);
      $pidId = 0;
      foreach ($pids as $pid) {
         if (strpos($pid, 'schedule.php') !== false) {
            $fields = preg_split('#\s+#', $pid, -1, PREG_SPLIT_NO_EMPTY);
            if (isset($fields[1]) && is_numeric($fields[1])) {
               $pidId = $fields[1];
            }
         }
      }
      return $pidId;
   }

   function startSchedule() {
      exec('cd ' . escapeshellarg(BASE_DIR) . ' && php schedule.php >/dev/null 2>&1 &');
   }

   function stopSchedule($pid) {
      if ((int)$pid > 0) exec('kill ' . (int)$pid);
   }

   function loadPars($config) {
      $pars = initPars();
      $input = null;
      if (file_exists($config)) {
         $input = json_decode(file_get_contents($config), true);
      }
      if (is_array($input)) {
         //get pars from config file and update only values which exist in initPars
         foreach($pars as $key => $value) {
            if (array_key_exists($key, $input)) {
               $pars[$key] = $input[$key];
            }
         }
         //Backwards compatibility fixes go here
         if (array_key_exists(SCHEDULE_ALLDAY,$input)) $pars[SCHEDULE_DAYMODE] = SCHEDULE_MODE_ALLDAY;
         if (array_key_exists('Longtitude',$input)) $pars[SCHEDULE_LONGITUDE] = $input['Longtitude'];
         foreach (array(SCHEDULE_COMMANDSON, SCHEDULE_COMMANDSOFF, SCHEDULE_MODES, SCHEDULE_TIMES) as $key) {
            if (!is_array($pars[$key])) $pars[$key] = array();
            $pars[$key] = array_values($pars[$key]);
         }
         //Duplicate old Day to First AllDay
         if (count($pars[SCHEDULE_COMMANDSON]) < 11) {
            array_unshift($pars[SCHEDULE_COMMANDSON], isset($pars[SCHEDULE_COMMANDSON][2]) ? $pars[SCHEDULE_COMMANDSON][2] : '');
            array_unshift($pars[SCHEDULE_COMMANDSOFF], isset($pars[SCHEDULE_COMMANDSOFF][2]) ? $pars[SCHEDULE_COMMANDSOFF][2] : '');
            array_unshift($pars[SCHEDULE_MODES], isset($pars[SCHEDULE_MODES][2]) ? $pars[SCHEDULE_MODES][2] : '');
         }
      }
      // the FIFOs are fixed and never taken from the saved file
      $init = initPars();
      $pars[SCHEDULE_FIFOIN] = $init[SCHEDULE_FIFOIN];
      $pars[SCHEDULE_FIFOOUT] = $init[SCHEDULE_FIFOOUT];
      // Add in any extra SCHEDULE_TIMES settings up to maximum count
      for($i = count($pars[SCHEDULE_TIMES]); $i < SCHEDULE_TIMES_MAX; $i++) {
         $pars[SCHEDULE_TIMES][$i] = sprintf("%02d", $i+9).":00";
      }
      // every period row (5 fixed rows + times) needs a days list; rows missing in a saved form had no day ticked
      $hadDays = is_array($input) && isset($input[SCHEDULE_DAYS]) && is_array($input[SCHEDULE_DAYS]);
      $days = $hadDays ? $input[SCHEDULE_DAYS] : array();
      $pars[SCHEDULE_DAYS] = array();
      for ($row = 0; $row < count($pars[SCHEDULE_TIMES]) + 5; $row++) {
         if (isset($days[$row]) && is_array($days[$row])) {
            $pars[SCHEDULE_DAYS][$row] = array_map('intval', array_values($days[$row]));
         } else {
            $pars[SCHEDULE_DAYS][$row] = $hadDays ? array() : array(0,1,2,3,4,5,6);
         }
      }
      // commands for every row
      foreach (array(SCHEDULE_COMMANDSON, SCHEDULE_COMMANDSOFF, SCHEDULE_MODES) as $key) {
         for ($row = count($pars[$key]); $row < count($pars[SCHEDULE_TIMES]) + 5; $row++) $pars[$key][$row] = '';
      }
      return $pars;
   }

   function initPars() {
      $pars = array(
         SCHEDULE_FIFOIN => BASE_DIR.'/FIFO1',
         SCHEDULE_FIFOOUT => BASE_DIR.'/FIFO',
         SCHEDULE_CMDPOLL => '0.03',
         SCHEDULE_MODEPOLL => '10',
         SCHEDULE_MANAGEMENTINTERVAL => '3600',
         SCHEDULE_MANAGEMENTCOMMAND => '',
         SCHEDULE_PURGEVIDEOHOURS => '0',
         SCHEDULE_PURGEIMAGEHOURS => '0',
         SCHEDULE_PURGELAPSEHOURS => '0',
         SCHEDULE_GMTOFFSET => '0',
         SCHEDULE_PURGESPACEMODE => '0',
         SCHEDULE_PURGESPACELEVEL => '10',
         SCHEDULE_DAWNSTARTMINUTES => '-180',
         SCHEDULE_DAYSTARTMINUTES => '0',
         SCHEDULE_DAYENDMINUTES => '0',
         SCHEDULE_DUSKENDMINUTES => '180',
         SCHEDULE_LATITUDE => '52.00',
         SCHEDULE_LONGITUDE => '0.00',
         SCHEDULE_MAXCAPTURE => '0',
         SCHEDULE_DAYMODE => SCHEDULE_MODE_ALLDAY,
         SCHEDULE_AUTOCAPTUREINTERVAL => '0',
         SCHEDULE_AUTOCAMERAINTERVAL => '0',
         SCHEDULE_TIMES => array("09:00"),
         SCHEDULE_DAYS => array(array(0,1,2,3,4,5,6)),
         SCHEDULE_COMMANDSON => array("ca 1","","","ca 1","","","","","","",""),
         SCHEDULE_COMMANDSOFF => array("ca 0","","","ca 0","","","","","","",""),
         SCHEDULE_MODES => array("","em night","md 1;em night","em auto","md 0;em night","","","","","","")
      );
      return $pars;
   }

   // only known keys from a posted form, FIFO paths can not be changed
   function parsFromPost($post) {
      $save = array();
      foreach (initPars() as $key => $value) {
         if ($key == SCHEDULE_FIFOIN || $key == SCHEDULE_FIFOOUT) continue;
         if (!isset($post[$key])) continue;
         $v = $post[$key];
         if (is_array($value)) {
            if (!is_array($v)) continue;
            if ($key == SCHEDULE_DAYS) {
               $days = array();
               foreach ($v as $row => $list) {
                  if (is_array($list)) $days[(int)$row] = array_values(array_unique(array_map('intval', array_filter($list, 'is_numeric'))));
               }
               $v = $days;
            } else {
               $v = array_map('strval', array_values($v));
            }
         } else if (is_array($v)) {
            continue;
         } else {
            $v = trim((string)$v);
         }
         $save[$key] = $v;
      }
      // rows without any ticked day are not posted, keep them as empty lists
      if (!isset($save[SCHEDULE_DAYS])) $save[SCHEDULE_DAYS] = array();
      return $save;
   }

   function writePars($file, $pars) {
      unset($pars[SCHEDULE_FIFOIN], $pars[SCHEDULE_FIFOOUT]);
      return file_put_contents(BASE_DIR . '/' . $file, json_encode($pars)) !== false;
   }

   function sendReset() {
      global $schedulePars;
      writeLog("Send Schedule reset");
      // r+ never blocks, even when the scheduler is not running
      $fifo = @fopen($schedulePars[SCHEDULE_FIFOIN], "r+");
      if ($fifo) {
         fwrite($fifo, SCHEDULE_RESET);
         fclose($fifo);
         sleep(1);
      }
   }

   function sendCmds($cmdString, $period = false) {
      global $schedulePars;
      if($cmdString && ($period === false || isDayActive($period))) {
         $cmds = explode(';', $cmdString);
         foreach ($cmds as $cmd) {
            $cmd = trim($cmd);
            if ($cmd != "") {
               writeLog("Send $cmd");
               $fifo = fopen($schedulePars[SCHEDULE_FIFOOUT], "w");
               fwrite($fifo, $cmd . "\n");
               fclose($fifo);
               sleep(2);
            }
         }
      }
   }

   function getTimeOffset() {
      global $schedulePars;
      $tz = $schedulePars[SCHEDULE_GMTOFFSET];
      if (is_numeric($tz)) {
         return (float)$tz;
      }
      if (!@date_default_timezone_set(trim($tz))) {
         return 0;
      }
      return date_offset_get(new DateTime("now")) / 3600;
   }

   // current local time using the configured offset or time zone
   function localDate($format) {
      global $schedulePars;
      $offset = getTimeOffset();
      if (is_numeric($schedulePars[SCHEDULE_GMTOFFSET])) {
         return gmdate($format, time() + (int)round($offset * 3600));
      }
      return date($format);
   }

   function getCurrentLocalTime($Minutes) {
      $localTime = localDate("H:i");
      if ($Minutes) {
         $localTime = substr($localTime,0,2) * 60 + substr($localTime,3,2);
      }
      return $localTime;
   }

   // sunrise or sunset as decimal hours in local time, null when the sun does not rise/set (polar day or night)
   function sunHours($which) {
      global $schedulePars;
      $info = date_sun_info(time(), (float)$schedulePars[SCHEDULE_LATITUDE], (float)$schedulePars[SCHEDULE_LONGITUDE]);
      if (!isset($info[$which]) || !is_int($info[$which])) return null;
      $h = fmod($info[$which] / 3600.0 + getTimeOffset(), 24.0);
      return $h < 0 ? $h + 24 : $h;
   }

   function formatHours($h) {
      if ($h === null) return '--:--';
      $m = (int)round($h * 60) % 1440;
      return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
   }

   function getSunrise() {
      return sunHours('sunrise');
   }

   function getSunset() {
      return sunHours('sunset');
   }

   function findFixedTimePeriod($cMins) {
      global $schedulePars;
      $times = $schedulePars[SCHEDULE_TIMES];
      $period = count($times) - 1;$maxLessV = -1;
      for ($i=0; $i < count($times); $i++) {
         $fMins = $times[$i];
         $j = strpos($fMins, ':');
         if ($j !== false) {
           $fMins = (int)substr($fMins, 0, $j) * 60 + (int)substr($fMins, $j+1);
           if ($fMins < $cMins) {
             if ($fMins > $maxLessV) {
               $maxLessV = $fMins;
               $period = $i;
             }
           }
         }
      }
      return $period + 5;
   }

   function isDayActive($period) {
      global $schedulePars;
      $days = $schedulePars[SCHEDULE_DAYS];
      if (!isset($days[$period]) || !is_array($days[$period])) return false;
      return in_array((int)localDate("w"), $days[$period]);
   }

   //Return period of day 0=AllDay 1=Night,2=Dawn,3=Day,4=Dusk, 5+ fixed times
   function dayPeriod() {
      global $schedulePars;
      $t = getCurrentLocalTime(true);
      $period = 0;
      switch($schedulePars[SCHEDULE_DAYMODE]) {
         case SCHEDULE_MODE_SUN:
            $sr = getSunrise();
            $ss = getSunset();
            if ($sr === null || $ss === null) {
               // polar day or night: decide by whether the sun is up at noon
               $info = date_sun_info(time(), (float)$schedulePars[SCHEDULE_LATITUDE], (float)$schedulePars[SCHEDULE_LONGITUDE]);
               $period = (isset($info['sunrise']) && $info['sunrise'] === true) ? 3 : 1;
               break;
            }
            $sr *= 60;
            $ss *= 60;
            if ($t < ($sr + $schedulePars[SCHEDULE_DAWNSTARTMINUTES])) {
               $period = 1;
            } else if ($t < ($sr + $schedulePars[SCHEDULE_DAYSTARTMINUTES])) {
               $period = 2;
            } else if ($t > ($ss + $schedulePars[SCHEDULE_DUSKENDMINUTES])) {
               $period = 1;
            } else if ($t > ($ss + $schedulePars[SCHEDULE_DAYENDMINUTES])) {
               $period = 4;
            } else {
               $period = 3;
            }
            break;
         case SCHEDULE_MODE_ALLDAY:
            $period = 0;
            break;
         case SCHEDULE_MODE_FIXED:
            $period = findFixedTimePeriod($t);
            break;
      }
      return $period;
   }

   function openPipe($pipeName) {
      if (!file_exists($pipeName)) {
         writeLog("Making Pipe to receive capture commands $pipeName");
         posix_mkfifo($pipeName,0666);
         chmod($pipeName, 0666);
      } else {
         writeLog("Capture Pipe already exists $pipeName");
      }
      $pipe = fopen($pipeName,'r+');
      stream_set_blocking($pipe,false);
      return $pipe;
   }

   function checkMotion($pipe) {
      if ( is_bool( $pipe ) ) { // Fail gracefully, if pipe was not opened successfully.
         return "";
      }
      try {
         $ret = fread($pipe, 1);
      } catch (Exception $e) {
         $ret = "";
      }
      return $ret;
   }

   function purgeLog() {
      $logSize = getLogSize();
      if (file_exists(getLogFile()) && $logSize > 0) {
         $logLines = file(getLogFile());
         $logCount = count($logLines);
         if($logCount > $logSize) {
            file_put_contents(getLogFile(), implode('', array_slice($logLines, -$logSize)));
         }
      }
   }

   function purgeFiles() {
      global $schedulePars;
      $videoHours = $schedulePars[SCHEDULE_PURGEVIDEOHOURS];
      $imageHours = $schedulePars[SCHEDULE_PURGEIMAGEHOURS];
      $lapseHours = $schedulePars[SCHEDULE_PURGELAPSEHOURS];
      $purgeCount = 0;
      if ($videoHours > 0 || $imageHours > 0 || $lapseHours > 0) {
         $files = scandir(BASE_DIR . '/' . MEDIA_PATH);
         $currentHours = time() / 3600;
         foreach($files as $file) {
            if(($file != '.') && ($file != '..') && isThumbnail($file)) {
               $fType = getFileType($file);
               $purgeHours = 0;
               switch ($fType) {
                  case 'i': $purgeHours = $imageHours;
                     break;
                  case 't': $purgeHours = $lapseHours;
                     break;
                  case 'v': $purgeHours = $videoHours;
                     break;
               }
               if ($purgeHours > 0) {
                  $fModHours = filemtime(BASE_DIR . '/' . MEDIA_PATH . "/$file") / 3600;
                  if ($fModHours > 0 && ($currentHours - $fModHours) > $purgeHours) {
                     deleteFile($file);
                     $purgeCount++;
                  }
               }
            } else if ($videoHours > 0) {
               if(pathinfo(BASE_DIR . '/' . MEDIA_PATH . "/$file", PATHINFO_EXTENSION) == 'zip') {
                  $fModHours = filemtime(BASE_DIR . '/' . MEDIA_PATH . "/$file") / 3600;
                  if ($fModHours > 0 && ($currentHours - $fModHours) > $videoHours) {
                     unlink(BASE_DIR . '/' . MEDIA_PATH . "/$file");
                     writeLog("Purged orphan zip $file");
                  }
               }
            }
         }
      }

      if ($schedulePars[SCHEDULE_PURGESPACEMODE] > 0) {
         $totalSize = disk_total_space(BASE_DIR . '/' . MEDIA_PATH) / 1024; //KB
         $level = (float)str_replace(array('%','G','B', 'g','b'), '', $schedulePars[SCHEDULE_PURGESPACELEVEL]);
         switch ($schedulePars[SCHEDULE_PURGESPACEMODE]) {
            case 1:
            case 2:
               $level = min(max($level, 3), 97) * $totalSize / 100;
               break;
            case 3:
            case 4:
               $level = $level * 1048576.0;
               break;
         }
         switch ($schedulePars[SCHEDULE_PURGESPACEMODE]) {
            case 1: //Free Space
            case 3:
               $currentAvailable = disk_free_space(BASE_DIR . '/' . MEDIA_PATH) / 1024; //KB
               if ($currentAvailable < $level) {
                  $pFiles = getSortedFiles(false); //files in latest to earliest order
                  while($currentAvailable < $level && count($pFiles) > 0){
                     $currentAvailable += deleteFile(array_pop($pFiles));
                     $purgeCount++;
                  }
               }
               break;
            case 2: // Max usage
            case 4:
               $pFiles = getSortedFiles(false); //files in latest to earliest order
               foreach ($pFiles as $pFile) {
                  $del = ($level <= 0);
                  $level -= deleteFile($pFile, $del);
                  if ($del) $purgeCount++;
               }
               break;
         }
      }
      if($purgeCount > 0){
        writeLog("Purged $purgeCount Files");
      }
   }

   function mainCLI() {
      global $schedulePars;
      writeLog("RaspiCam support started");
      $captureStart = 0;
      $pipeIn = openPipe($schedulePars[SCHEDULE_FIFOIN]);
      $timeout = 0;
      $timeoutMax = 0; //Loop test will terminate after this (seconds) (used in test), set to 0 forever
      while($timeoutMax == 0 || $timeout < $timeoutMax) {
         writeLog("Scheduler loop is started");
         $lastOnCommand = -1;
         $lastDayPeriod = -1;
         $pollTime = $schedulePars[SCHEDULE_CMDPOLL];
         $slowPoll = 0;
         $managechecktime = time();
         $autocameratime =$managechecktime;
         $modechecktime = $managechecktime;
         if ($schedulePars[SCHEDULE_AUTOCAPTUREINTERVAL] > $schedulePars[SCHEDULE_MAXCAPTURE] ) {
            $autocapturetime = $managechecktime;
            $autocapture = 2;
         } else {
            $autocapturetime = 0;
            $autocapture = 0;
         }
         $lastStatusTime = @filemtime(BASE_DIR . "/status_mjpeg.txt");
         while($timeoutMax == 0 || $timeout < $timeoutMax) {
            usleep($pollTime * 1000000);
            //Check for incoming motion capture requests
            $cmd = "";
            $cmd = checkMotion($pipeIn);
            if ($cmd == SCHEDULE_STOP && $autocapture == 0) {
               if ($lastOnCommand >= 0) {
                  writeLog('Stop capture requested');
                  $send = $schedulePars[SCHEDULE_COMMANDSOFF][$lastOnCommand];
                  if ($send) {
                     sendCmds($send, $lastDayPeriod);
                     $lastOnCommand = -1;
                  }
               } else {
                  writeLog('Stop capture request ignored, already stopped');
               }
            } else if ($cmd == SCHEDULE_START || $autocapture == 1) {
               if ($lastDayPeriod >= 0) {
                  if ($autocapture == 1) {
                     $autocapture = 2;
                     writeLog('Start triggered by autocapture');
                  } else {
                     writeLog('Start capture requested from Pipe');
                  }
                  $send = $schedulePars[SCHEDULE_COMMANDSON][$lastDayPeriod];
                  if ($send) {
                     sendCmds($send, $lastDayPeriod);
                     $lastOnCommand = $lastDayPeriod;
                     $captureStart = time();
                  }
               } else {
                  writeLog('Start capture request ignored, day period not initialised yet');
               }
            } else if ($cmd == SCHEDULE_RESET) {
               writeLog("Reload parameters command requested");
               $schedulePars = loadPars(BASE_DIR . '/' . SCHEDULE_CONFIG);
               //start outer loop
               break;
            } else if ($cmd !="") {
               writeLog("Ignore FIFO char $cmd");
            }

            //slow Poll actions done every 10 fast loops times
            $slowPoll--;
            if ($slowPoll < 0) {
               $slowPoll = 10;
               $timenow = time();
               $forcePeriodCheck = 0;
               if ($lastOnCommand >= 0) {
                  //Capture in progress, Check for maximum
                  if ($schedulePars[SCHEDULE_MAXCAPTURE] > 0) {
                     if (($timenow - $captureStart) >= $schedulePars[SCHEDULE_MAXCAPTURE]) {
                        writeLog("Maximum Capture reached. Sending off command");
                        sendCmds($schedulePars[SCHEDULE_COMMANDSOFF][$lastOnCommand]);
                        $lastOnCommand = -1;
                        $autocapture = 0;
                        $forcePeriodCheck = 1;
                     }
                  }
               }
               //Action period time change checks at MODE_POLL intervals
               if ($timenow > $modechecktime || $forcePeriodCheck == 1) {
                  //Set next period check time
                  $modechecktime = $timenow + $schedulePars[SCHEDULE_MODEPOLL];
                  $forcePeriodCheck = 0;
                  if ($lastOnCommand < 0) {
                     //No capture in progress, Check if day period changing
                     $newDayPeriod = dayPeriod();
                     if ($newDayPeriod != $lastDayPeriod) {
                        writeLog("New period detected $newDayPeriod");
                        sendCmds($schedulePars[SCHEDULE_MODES][$newDayPeriod], $newDayPeriod);
                        $lastDayPeriod = $newDayPeriod;
                     }
                  }
               }
               if ($timenow > $managechecktime) {
                  // Run management tasks
                  //Set next check time
                  $managechecktime = $timenow + $schedulePars[SCHEDULE_MANAGEMENTINTERVAL];
                  writeLog("Scheduled management tasks. Next at $managechecktime");
                  purgeFiles();
                  $cmd = $schedulePars[SCHEDULE_MANAGEMENTCOMMAND];
                  if ($cmd != '') {
                     writeLog("exec_macro: $cmd");
                     sendCmds("sy $cmd");
                  }
                  purgeLog();
               }
               if ($autocapturetime > 0 && $timenow > $autocapturetime) {
                  // Request autocapture and set next interval
                  $autocapturetime = $timenow + $schedulePars[SCHEDULE_AUTOCAPTUREINTERVAL];
                  writeLog("Autocapture request.");
                  $autocapture = 1;
               }
               //Check for auto camera on/off based on status update timing (active browser)
               if (($schedulePars[SCHEDULE_AUTOCAMERAINTERVAL] > 0) && $timenow > $autocameratime) {
                  // 2 seconds between tests to allow time for commands to take effect
                  $autocameratime = $timenow + 2;
                  clearstatcache();
                  $modTime = @filemtime(BASE_DIR . "/status_mjpeg.txt");
                  if (@file_get_contents(BASE_DIR . "/status_mjpeg.txt") == 'halted') {
                     if ($modTime > $lastStatusTime) {
                        writeLog("autocamera startup");
                        sendCmds('ru 1');
                     }
                  } else {
                     if (($timenow - $modTime) > $schedulePars[SCHEDULE_AUTOCAMERAINTERVAL]) {
                        writeLog("autocamera shutdown");
                        sendCmds('md 0;ru 0');
                        //allow a bit of time to ensure it doesn't switch straight back on
                        $lastStatusTime = $timenow + 5;
                     } else {
                        $lastStatusTime = $timenow;
                     }
                  }
               }
            }
         }
      }
   }

   if ($cliCall) {
      mainCLI();
      exit;
   }

   // ------------------------------------------------------------------
   // Web page
   // ------------------------------------------------------------------
   require_once(BASE_DIR . '/ui.php');
   requireUserLevel(USERLEVEL_MAX);

   function redirectSchedule($done = '', $extra = '') {
      header('Location: schedule.php' . ($done ? '?done=' . urlencode($done) : '') . $extra, true, 303);
      exit;
   }

   if (isset($_REQUEST['action']) && $_REQUEST['action'] == 'downloadlog') {
      if (file_exists(getLogFile())) {
         header("Content-Type: text/plain");
         header("Content-Disposition: attachment; filename=\"" . date('Ymd-His-') . LOGFILE_SCHEDULE . "\"");
         readfile(getLogFile());
         exit;
      }
      redirectSchedule();
   }

   if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
      rejectCrossSiteRequest();
      switch($_POST['action']) {
         case 'start':
            startSchedule();
            sleep(1);
            redirectSchedule();
         case 'stop':
            stopSchedule(getSchedulePID());
            sleep(1);
            redirectSchedule();
         case 'save':
            writeLog('Saved schedule settings');
            writePars(SCHEDULE_CONFIG, parsFromPost($_POST));
            $schedulePars = loadPars(BASE_DIR . '/' . SCHEDULE_CONFIG);
            sendReset();
            redirectSchedule('saved');
         case 'backup':
            writeLog('Backed up schedule settings');
            writePars(SCHEDULE_CONFIGBACKUP, $schedulePars);
            redirectSchedule('backup');
         case 'restore':
            if (file_exists(BASE_DIR . '/' . SCHEDULE_CONFIGBACKUP)) {
               writeLog('Restored schedule settings');
               writePars(SCHEDULE_CONFIG, loadPars(BASE_DIR . '/' . SCHEDULE_CONFIGBACKUP));
               $schedulePars = loadPars(BASE_DIR . '/' . SCHEDULE_CONFIG);
               sendReset();
               redirectSchedule('restored');
            }
            redirectSchedule('nobackup');
         case 'clearlog':
            if (file_exists(getLogFile())) {
               unlink(getLogFile());
            }
            redirectSchedule('logcleared', '&log=1');
      }
      redirectSchedule();
   }

   $commandReference = array(
      array('ca', '0/1 [t]', 'Stop/start video capture, optional t = capture seconds'),
      array('im', '', 'Capture image'),
      array('tl', '0/1', 'Stop/start timelapse'),
      array('md', '0/1', 'Stop/start motion detection'),
      array('tv', 'n', 'Timelapse interval in 1/10 seconds'),
      array('vi', 'n', 'Video split interval in seconds, 0 = off'),
      array('an', 'text', 'Set annotation'),
      array('ab', '0/1', 'Annotation background'),
      array('as', 'n', 'Annotation text size 0-99'),
      array('at', 'E Y U V', 'Custom text colour (E = enable)'),
      array('ac', 'E Y U V', 'Custom background colour (E = enable)'),
      array('px', 'AAAA BBBB CC DD EEEE FFFF GG', 'Video A x B px at C fps, boxed at D fps, image E x F px, divider G'),
      array('sh', 'n', 'Sharpness -100..100, default 0'),
      array('co', 'n', 'Contrast -100..100, default 0'),
      array('br', 'n', 'Brightness 0..100, default 50'),
      array('sa', 'n', 'Saturation -100..100, default 0'),
      array('is', 'n', 'ISO 100..800, 0 = auto'),
      array('vs', '0/1', 'Video stabilisation off/on'),
      array('ec', 'n', 'Exposure compensation -10..10, default 0'),
      array('em', 'keyword', 'Exposure mode: off/auto/night/nightpreview/backlight/spotlight/sports/snow/beach/verylong/fixedfps/antishake/fireworks'),
      array('wb', 'keyword', 'White balance: off/auto/sun/cloudy/shade/tungsten/fluorescent/incandescent/flash/horizon/greyworld'),
      array('ag', 'RRRR BBBB', 'Red and blue gain when white balance is off (100 = 1.0)'),
      array('mm', 'keyword', 'Metering mode: average/spot/backlit/matrix'),
      array('ie', 'keyword', 'Image effect: none/negative/solarise/sketch/denoise/emboss/oilpaint/hatch/gpen/pastel/watercolour/film/blur/saturation/colourswap/washedout/posterise/cartoon'),
      array('ce', 'A BB CC', 'Colour effect, A = enable, effect U = B, V = C'),
      array('ro', 'n', 'Rotation 0/90/180/270'),
      array('fl', 'n', 'Flip 0 = none, 1 = horizontal, 2 = vertical, 3 = both'),
      array('ri', 'A B C D', 'Sensor region x = A, y = B, w = C, h = D'),
      array('ss', 'n', 'Shutter speed in µs, 0 = auto'),
      array('qu', 'n', 'Image quality 0..100'),
      array('pv', 'QQ WWW DD', 'Preview quality, width and divider'),
      array('bu', 'n', 'Pre-trigger video buffer in ms'),
      array('bi', 'n', 'Video bitrate 0..25000000'),
      array('bo', 'n', 'MP4Box mode 0 = off, 1 = inline, 2 = background'),
      array('rl', '0/1', 'Raw layer off/on'),
      array('st', '0/1', 'Image statistics off/on'),
      array('qp', 'A BB CC', 'H.264 encoding: minimise_frag A, initial_quant B, encode_qp C'),
      array('hp', '0/1', 'HDMI preview off/on'),
      array('cn', '1/2', 'Select camera (Compute Module)'),
      array('wd', 'II EE', 'Watchdog interval (1/10 s) and error count'),
      array('rs', '1', 'Reset user config to default'),
      array('ru', '0/1', 'Halt/restart raspimjpeg and release camera'),
      array('sc', '1', 'Rescan video and image indexes'),
      array('sy', 'macro', 'Execute a macro from the macros folder'),
      array('um', 'n macro', 'Update job macro n'),
      array('vp', '0/1', 'Motion vector preview off/on'),
      array('mx', '0/1/2', 'Motion detection internal/external/monitor'),
      array('mn', 'n', 'Motion noise level'),
      array('mt', 'n', 'Motion threshold'),
      array('mc', 'n', 'Motion clipping factor'),
      array('mi', 'file', 'Motion mask image'),
      array('ms', 'n', 'Motion initial frames to ignore'),
      array('mb', 'n', 'Motion change frames to start'),
      array('me', 'n', 'Motion still frames to stop'),
      array('mf', '0/1', 'Save motion vectors to .dat file'),
      array('mz', '0/1', 'Disable motion mask'),
      array('ls', 'n', 'Max. log size in lines, 0 = logging off'),
   );

   function paramInput($key, $type = 'text', $hint = null, $attrs = '') {
      global $schedulePars;
      echo '<label>' . h(t('sp.' . $key));
      echo '<input class="input" type="' . $type . '" name="' . h($key) . '" value="' . h($schedulePars[$key]) . '" autocomplete="off"' . $attrs . '>';
      if ($hint) echo '<small>' . h(t($hint)) . '</small>';
      echo '</label>';
   }

   $showLog = isset($_GET['log']);
   $schedulePID = getSchedulePID();
   $flashes = array(
      'saved' => array('sched.saved', 'success'), 'backup' => array('sched.backed_up', 'success'),
      'restored' => array('sched.restored', 'success'), 'nobackup' => array('motion.no_backup', 'error'),
      'logcleared' => array('sched.log_cleared', 'success'),
   );
   pageHeader(t('sched.title'), 'schedule');
   if (isset($_GET['done']) && isset($flashes[$_GET['done']])) {
      echo flashMessage(t($flashes[$_GET['done']][0]), $flashes[$_GET['done']][1]);
   }

   if ($showLog) {
      $log = file_exists(getLogFile()) ? file_get_contents(getLogFile()) : '';
?>
<div class="page-head">
   <h1><?php et('sched.log'); ?></h1>
   <a class="btn btn-ghost" href="schedule.php"><?php echo icon('chevron-left'); ?><?php et('sched.back'); ?></a>
   <a class="btn btn-soft" href="schedule.php?action=downloadlog"><?php echo icon('download'); ?><?php et('sched.download_log'); ?></a>
   <form method="post" action="schedule.php"><button class="btn btn-soft-danger" type="submit" name="action" value="clearlog" data-confirm="<?php echo h(t('js.sched.confirm_clear_log')); ?>" data-danger><?php echo icon('trash'); ?><?php et('sched.clear_log'); ?></button></form>
</div>
<section class="card"><div class="card-body">
<?php if ($log === '') { ?>
   <p class="muted"><?php et('sched.log_empty'); ?></p>
<?php } else { ?>
   <pre class="log" id="log"><?php echo h($log); ?></pre>
<?php } ?>
</div></section>
<?php
      pageFooter(array('js/schedule.js'));
      exit;
   }

   $d = dayPeriod();
   $periodNames = array(t('sched.p_allday'), t('sched.p_night'), t('sched.p_dawn'), t('sched.p_day'), t('sched.p_dusk'));
   $times = $schedulePars[SCHEDULE_TIMES];
   $periodLabel = $d < 5 ? $periodNames[$d] : t('sched.p_fixed', array('t' => $times[$d - 5]));
   $dayNames = explode(',', t('sched.days'));
?>
<form method="post" action="schedule.php" id="scheduleForm">
<div class="page-head">
   <h1><?php et('sched.title'); ?></h1>
   <span class="sched-status<?php echo $schedulePID ? ' running' : ''; ?>"><span class="dot"></span><?php et($schedulePID ? 'sched.running' : 'sched.stopped'); ?></span>
<?php if ($schedulePID) { ?>
   <button class="btn btn-soft-danger" type="submit" name="action" value="stop"><?php echo icon('stop'); ?><?php et('sched.stop'); ?></button>
<?php } else { ?>
   <button class="btn btn-soft" type="submit" name="action" value="start"><?php echo icon('play'); ?><?php et('sched.start'); ?></button>
<?php } ?>
   <a class="btn btn-ghost" href="schedule.php?log=1"><?php echo icon('file-text'); ?><?php et('sched.log'); ?></a>
   <button class="btn btn-ghost" type="submit" name="action" value="backup"><?php echo icon('download'); ?><?php et('btn.backup'); ?></button>
   <button class="btn btn-ghost" type="submit" name="action" value="restore" data-confirm="<?php et('sched.confirm_restore'); ?>"><?php echo icon('upload'); ?><?php et('btn.restore'); ?></button>
   <button class="btn btn-primary" type="submit" name="action" value="save"><?php echo icon('save'); ?><?php et('btn.save'); ?></button>
</div>

<div class="stack">
   <section class="card">
      <div class="card-body stack">
         <div class="row" style="justify-content:space-between">
            <div class="row">
               <strong><?php et('sched.daymode'); ?></strong>
               <div class="segmented" role="radiogroup" aria-label="<?php et('sched.daymode'); ?>" id="dayModeSwitch">
<?php foreach (array(SCHEDULE_MODE_SUN => 'sched.mode_sun', SCHEDULE_MODE_ALLDAY => 'sched.mode_allday', SCHEDULE_MODE_FIXED => 'sched.mode_fixed') as $mode => $label) { ?>
                  <button type="button" role="radio" data-mode="<?php echo $mode; ?>" aria-checked="<?php echo $schedulePars[SCHEDULE_DAYMODE] == $mode ? 'true' : 'false'; ?>" class="<?php echo $schedulePars[SCHEDULE_DAYMODE] == $mode ? 'active' : ''; ?>"><?php et($label); ?></button>
<?php } ?>
               </div>
               <input type="hidden" name="<?php echo SCHEDULE_DAYMODE; ?>" id="DayMode" value="<?php echo h($schedulePars[SCHEDULE_DAYMODE]); ?>">
            </div>
         </div>
         <div class="sun-info">
            <div><?php echo icon('globe'); ?><span><small><?php et('sched.offset'); ?></small><strong><?php echo h(sprintf('%+g h', getTimeOffset())); ?></strong></span></div>
            <div><?php echo icon('sunrise'); ?><span><small><?php et('sched.sunrise'); ?></small><strong><?php echo h(formatHours(getSunrise())); ?></strong></span></div>
            <div><?php echo icon('sunset'); ?><span><small><?php et('sched.sunset'); ?></small><strong><?php echo h(formatHours(getSunset())); ?></strong></span></div>
            <div><?php echo icon('clock'); ?><span><small><?php et('sched.now'); ?></small><strong><?php echo h(getCurrentLocalTime(false)); ?></strong></span></div>
            <div class="current"><?php echo icon('calendar'); ?><span><small><?php et('sched.period'); ?></small><strong><?php echo h($periodLabel); ?></strong></span></div>
         </div>
      </div>
   </section>

   <section class="card">
      <div class="card-head"><h2><?php echo icon('terminal'); ?><?php et('sched.periods'); ?></h2></div>
      <div class="card-body">
         <p class="muted small"><?php et('sched.periods_hint'); ?></p>
         <div class="table-wrap">
         <table class="periods">
            <thead><tr>
               <th><?php et('sched.col_period'); ?></th><th><?php et('sched.col_days'); ?></th>
               <th><?php et('sched.col_mstart'); ?></th><th><?php et('sched.col_mstop'); ?></th><th><?php et('sched.col_pstart'); ?></th>
            </tr></thead>
            <tbody>
<?php
   for ($row = 0; $row < count($times) + 5; $row++) {
      $class = $row == 0 ? 'day' : ($row < 5 ? 'sun' : 'fixed');
      if ($row == $d) $class .= ' current';
      echo '<tr class="' . $class . '">';
      echo '<td class="period-name">';
      if ($row < 5) {
         echo h($periodNames[$row]);
      } else {
         $time = $times[$row - 5];
         if (preg_match('/^(\d{1,2}):(\d{1,2})/', $time, $m)) $time = sprintf('%02d:%02d', $m[1], $m[2]);
         echo '<input class="input input-sm time-input" type="time" name="' . SCHEDULE_TIMES . '[]" value="' . h($time) . '" aria-label="' . h(t('sched.col_period')) . '">';
      }
      echo '</td><td data-label="' . h(t('sched.col_days')) . '"><span class="days">';
      for ($dy = 0; $dy < 7; $dy++) {
         $checked = in_array($dy, $schedulePars[SCHEDULE_DAYS][$row]) ? ' checked' : '';
         echo '<label class="day-chip" title="' . h($dayNames[$dy]) . '"><input type="checkbox" name="' . SCHEDULE_DAYS . '[' . $row . '][]" value="' . $dy . '"' . $checked . '><span>' . h($dayNames[$dy]) . '</span></label>';
      }
      echo '</span></td>';
      foreach (array(SCHEDULE_COMMANDSON => 'sched.col_mstart', SCHEDULE_COMMANDSOFF => 'sched.col_mstop', SCHEDULE_MODES => 'sched.col_pstart') as $key => $label) {
         echo '<td data-label="' . h(t($label)) . '"><input class="input input-sm mono" type="text" autocomplete="off" spellcheck="false" name="' . $key . '[]" value="' . h($schedulePars[$key][$row]) . '" aria-label="' . h(t($label)) . '"></td>';
      }
      echo "</tr>\n";
   }
?>
            </tbody>
         </table>
         </div>
      </div>
   </section>

   <section class="card">
      <div class="card-head"><h2><?php echo icon('sliders'); ?><?php et('sched.params'); ?></h2></div>
      <div class="card-body">
         <h3 class="subhead"><?php et('sched.location'); ?></h3>
         <div class="form-grid">
<?php
         paramInput(SCHEDULE_LATITUDE, 'text', null, ' inputmode="decimal"');
         paramInput(SCHEDULE_LONGITUDE, 'text', null, ' inputmode="decimal"');
         paramInput(SCHEDULE_GMTOFFSET, 'text', 'sp.GMTOffset_hint', ' list="tzlist"');
?>
         </div>
         <datalist id="tzlist"><?php foreach (DateTimeZone::listIdentifiers() as $tz) echo '<option value="' . h($tz) . '">'; ?></datalist>
         <div class="sun-only">
         <h3 class="subhead"><?php et('sched.sunlimits'); ?></h3>
         <div class="form-grid">
<?php
         paramInput(SCHEDULE_DAWNSTARTMINUTES, 'number');
         paramInput(SCHEDULE_DAYSTARTMINUTES, 'number');
         paramInput(SCHEDULE_DAYENDMINUTES, 'number');
         paramInput(SCHEDULE_DUSKENDMINUTES, 'number');
?>
         </div>
         </div>
         <h3 class="subhead"><?php et('sched.capture'); ?></h3>
         <div class="form-grid">
<?php
         paramInput(SCHEDULE_MAXCAPTURE, 'number', 'sp.Max_Capture_hint', ' min="0"');
         paramInput(SCHEDULE_AUTOCAPTUREINTERVAL, 'number', 'sp.AutoCapture_Interval_hint', ' min="0"');
         paramInput(SCHEDULE_AUTOCAMERAINTERVAL, 'number', 'sp.AutoCamera_Interval_hint', ' min="0"');
?>
         </div>
         <h3 class="subhead"><?php et('sched.maintenance'); ?></h3>
         <div class="form-grid">
<?php
         paramInput(SCHEDULE_PURGEVIDEOHOURS, 'number', 'sp.purge_hint', ' min="0"');
         paramInput(SCHEDULE_PURGEIMAGEHOURS, 'number', 'sp.purge_hint', ' min="0"');
         paramInput(SCHEDULE_PURGELAPSEHOURS, 'number', 'sp.purge_hint', ' min="0"');
?>
            <label><?php et('sp.' . SCHEDULE_PURGESPACEMODE); ?>
               <select class="select" name="<?php echo SCHEDULE_PURGESPACEMODE; ?>">
<?php foreach (array('sp.purge_off', 'sp.purge_minpct', 'sp.purge_maxpct', 'sp.purge_mingb', 'sp.purge_maxgb') as $i => $label) { ?>
                  <option value="<?php echo $i; ?>"<?php echo $schedulePars[SCHEDULE_PURGESPACEMODE] == $i ? ' selected' : ''; ?>><?php et($label); ?></option>
<?php } ?>
               </select>
            </label>
<?php
         paramInput(SCHEDULE_PURGESPACELEVEL, 'text');
         paramInput(SCHEDULE_MANAGEMENTINTERVAL, 'number', null, ' min="1"');
         paramInput(SCHEDULE_MANAGEMENTCOMMAND, 'text');
?>
         </div>
      </div>
      <details class="section">
         <summary><?php et('sched.advanced'); ?></summary>
         <div class="section-body form-grid">
<?php
         paramInput(SCHEDULE_CMDPOLL, 'text', null, ' inputmode="decimal"');
         paramInput(SCHEDULE_MODEPOLL, 'number', null, ' min="1"');
         paramInput(SCHEDULE_FIFOIN, 'text', null, ' disabled');
         paramInput(SCHEDULE_FIFOOUT, 'text', null, ' disabled');
?>
         </div>
      </details>
   </section>
</div>
</form>

   <section class="card" style="margin-top:16px">
      <details class="section" style="border-top:0">
         <summary><?php echo icon('book'); ?><?php et('sched.cmdref'); ?></summary>
         <div class="section-body">
            <div class="searchbox" style="margin-bottom:10px"><?php echo icon('search'); ?><input class="input" type="search" id="cmdFilter" placeholder="<?php et('sched.cmdref_filter'); ?>"></div>
            <div class="table-wrap">
            <table class="ref-table" id="cmdTable">
               <thead><tr><th><?php et('sched.cmd'); ?></th><th><?php et('sched.params'); ?></th><th><?php et('sched.desc'); ?></th></tr></thead>
               <tbody>
<?php foreach ($commandReference as $c) { ?>
                  <tr><td><?php echo h($c[0]); ?></td><td><?php echo h($c[1]); ?></td><td><?php echo h($c[2]); ?></td></tr>
<?php } ?>
               </tbody>
            </table>
            </div>
         </div>
      </details>
   </section>
<?php
   pageFooter(array('js/schedule.js'));
?>
