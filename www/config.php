<?php
	//Local define of base diretory for routines here
	define('LBASE_DIR',dirname(__FILE__));
	//Global defines and utility functions
	// version string
	define('APP_VERSION', 'v7.0.1');

	// name of this application
	define('APP_NAME', 'RPi Cam Control');

	// the host running the application
	define('HOST_NAME', php_uname('n'));

	//define main starting php
	define('ROOT_PHP', 'index.php');

	// name of this camera
	define('CAM_NAME', 'mycam');

	// unique camera string build from application name, camera name, host name
	define('CAM_STRING', APP_NAME . " " . APP_VERSION . ": " . CAM_NAME . '@' . HOST_NAME);

	// file where default settings changes are stored
	define('CONFIG_FILE1', 'raspimjpeg');

	// file where user specific settings changes are stored
	define('CONFIG_FILE2', 'uconfig');

	// file where user specific settings changes are stored
	define('MEDIA_PATH', 'media');

	// character used to flatten file paths
	define('SUBDIR_CHAR', '@');

	// character used to flatten file paths
	define('THUMBNAIL_EXT', '.th.jpg');

	// file where a debug file is stored
	define('LOGFILE_DEBUG', 'debugLog.txt');

	// file where schedule log is stored
	define('LOGFILE_SCHEDULE', 'scheduleLog.txt');

	// control how filesize is extracted, 0 is fast and works for files up to 4GB, 1 is slower
	define('FILESIZE_METHOD', '0');

	// defines for user level
	define('USERLEVEL_FILE', 'userLevel');
	define('USERLEVEL_MIN', '0');
	define('USERLEVEL_MINP', '2'); //minimum+preview
	define('USERLEVEL_MEDIUM', '3');
	define('USERLEVEL_MAX', '6');

	// debug log function
	function writeDebugLog($msg) {
		$log = fopen(LBASE_DIR . '/' . LOGFILE_DEBUG, 'a');
		$time = date('[Y/m/d H:i:s]');
		fwrite($log, "$time $msg" . PHP_EOL);
		fclose($log);
	}

	// schedule log function
	function writeLog($msg) {
		global $logSize;
		if ($logSize > 0) {
			$log = @fopen(getLogFile(), 'a');
			if ($log === false) return;
			$time = date('[Y/m/d H:i:s]');
			fwrite($log, "$time $msg" . PHP_EOL);
			fclose($log);
		}
	}

	// functions to read and save config data
	function readConfigs() {
		global $config, $logFile, $logSize;
		$config = readConfig($config, LBASE_DIR . '/' . CONFIG_FILE1);
		$config = readConfig($config, LBASE_DIR . '/' . CONFIG_FILE2);
		$logFile = cfg('log_file');
		$logSize = cfg('log_size', 1);
	}

	function readConfig($config, $configFile) {
		if (file_exists($configFile)) {
			$lines = array();
			$data = file_get_contents($configFile);
			$lines = explode("\n", $data);
			foreach($lines as $line) {
				if (strlen($line) && substr($line, 0, 1) != '#') {
					$index = strpos($line, ' ');
					if ($index !== false) {
						$key = substr($line, 0, $index);
						$value = substr($line, $index +1);
						if ($value == 'true') $value = 1;
						if ($value == 'false') $value = 0;
						$config[$key] = $value;
					} else {
						$config[$line] = "";
					}
				}
			}
		}
		return $config;
	}

	// read a config value without triggering undefined index warnings
	function cfg($key, $default = '') {
		global $config;
		return (is_array($config) && array_key_exists($key, $config)) ? $config[$key] : $default;
	}

	function saveUserConfig($config) {
		$cstring= "";
		foreach($config as $key => $value) {
			$cstring .= $key . ' ' . $value . "\n";
		}
		if ($cstring != "") {
			$fp = fopen(LBASE_DIR . '/' . CONFIG_FILE2, 'w');
			fwrite($fp, "#User config file\n");
			fwrite($fp, $cstring);
			fclose($fp);
		}
	}

	// functions to find and delete data files

	function getSortedFiles($ascending = true) {
		$scanfiles = scandir(LBASE_DIR . '/' . MEDIA_PATH);
		$files = array();
		foreach($scanfiles as $file) {
			if(($file != '.') && ($file != '..') && isThumbnail($file)) {
				$fDate = filemtime(LBASE_DIR . '/' . MEDIA_PATH . "/$file");
				$files[$file] = $fDate;
			}
		}
		if ($ascending)
			asort($files);
		else
			arsort($files);
		return array_keys($files);
	}

	function findLapseFiles($d) {
		//return an arranged in time order and then must have a matching 4 digit batch and an incrementing lapse number
		$batch = getFileIndex($d);
		$padlen = strlen($batch);
		$fullname = LBASE_DIR . '/' . MEDIA_PATH . '/' . dataFilename($d);
		$path = dirname($fullname);
		$lapsefiles = array();
		if ($batch === '' || !file_exists($fullname)) return $lapsefiles;
		$start = filemtime("$fullname");
		$files = array();
		$scanfiles = scandir($path);
		foreach($scanfiles as $file) {
			if (strpos($file, $batch) !== false) {
				if (!isThumbnail($file) && strcasecmp(fileext($file), "jpg") == 0) {
					$fDate = filemtime("$path/$file");
					if ($fDate >= $start) {
						$files[$file] = $fDate . $file;
					}
				}
			}
		}
		asort($files);
		$lapseCount = 1;
		foreach($files as $key => $value) {
			if (strpos($key, str_pad($lapseCount, $padlen, 0, STR_PAD_LEFT)) !== false) {
				$lapsefiles[] = "$path/$key";
				$lapseCount++;
			} else {
				break;
			}
		}
		return $lapsefiles;
   }

	//function to get filesize (native php has 2GB limit)
	function filesize_n($path) {
		if (FILESIZE_METHOD == '0') {
			$size = @filesize($path);
			if ($size === false) return 0;
			if ($size >= 0)
				return $size;
			else
				return 4294967296 + $size;
		} else {
			return (float)trim(shell_exec('stat -c%s ' . escapeshellarg($path)));
		}
	}

	//function to delete all files associated with a thumb name
	//returns space freed in kB
	//if $del = false just calculate space which would be freed
	function deleteFile($d, $del = true) {
		$size = 0;
		$mediaDir = LBASE_DIR . '/' . MEDIA_PATH;
		if(!is_writeable("$mediaDir/$d")) return 0;
		$t = getFileType($d);
		if ($t == 't') {
			// For time lapse try to delete all from this batch
			$files = findLapseFiles($d);
			foreach($files as $file) {
				$size += filesize_n($file);
				if ($del) @unlink($file);
			}
		} else {
			$tFile = dataFilename($d);
			if ($tFile != '' && file_exists("$mediaDir/$tFile")) {
				$size += filesize_n("$mediaDir/$tFile");
				if ($del) @unlink("$mediaDir/$tFile");
			}
			if ($t == 'v') {
				$rFile = substr($tFile,0, strrpos($tFile, '.'));
				foreach(array("$tFile.dat", "$rFile.h264", "$rFile.h264.bad", "$rFile.h264.log") as $extra) {
					if (file_exists("$mediaDir/$extra")) {
						$size += filesize_n("$mediaDir/$extra");
						if ($del) @unlink("$mediaDir/$extra");
					}
				}
			}
		}
		$size += filesize_n("$mediaDir/$d");
		if ($del) @unlink("$mediaDir/$d");
		return $size / 1024;
	}

	//function to lock or unlock all files associated with a thumb name
	function lockFile($d, $lock) {
		if($lock == 1) $attr = 0444; else $attr = 0644;
		$mediaDir = LBASE_DIR . '/' . MEDIA_PATH;
		$t = getFileType($d);
		if ($t == 't') {
			// For time lapse lock all from this batch
			$files = findLapseFiles($d);
			foreach($files as $file) {
					@chmod($file, $attr);
			}
		} else {
			$tFile = dataFilename($d);
			if (file_exists("$mediaDir/$tFile")) {
				@chmod("$mediaDir/$tFile", $attr);
			}
			if ($t == 'v') {
				$rFile = substr($tFile,0, strrpos($tFile, '.'));
				if (file_exists("$mediaDir/$tFile.dat")) @chmod("$mediaDir/$tFile.dat", $attr);
				if (file_exists("$mediaDir/$rFile.h264")) @chmod("$mediaDir/$rFile.h264", $attr);
			}
		}
		@chmod("$mediaDir/$d", $attr);
	}


	//Support naming functions
	function dataFilename($file) {
		if (strlen($file) < 8) return "";
		$i = strrpos($file, '.', -8);
		if ($i !== false)
			return str_replace(SUBDIR_CHAR, '/', substr($file, 0, $i));
		else
			return "";
	}

	function dataFileext($file) {
		$f = dataFileName($file);
		return fileext($f);
	}

	function fileext($f) {
		if ($f <> "") {
			$i = strrpos($f, '.');
			if ($i !== false)
				return substr($f, $i+1);
		}
		return "";
	}

	function isThumbnail($file) {
		return (substr($file, -7) == THUMBNAIL_EXT);
	}

	function getFileType($file) {
		if (strlen($file) < 8) return "";
		$i = strrpos($file, '.', -8);
		if ($i !== false)
			return substr($file, $i + 1, 1);
		else
			return "";
	}

	function getFileIndex($file) {
		if (strlen($file) < 8) return "";
		$i = strrpos($file, '.', -8);
		if ($i !== false)
			return substr($file, $i + 2, strlen($file) - $i - 9);
		else
			return "";
	}

	function getLogFile() {
		global $logFile;
		if ($logFile != "")
			return $logFile;
		else
			return LBASE_DIR . '/' . LOGFILE_SCHEDULE;
	}

	function getLogSize() {
		global $logSize;
		readConfigs();
		return $logSize;
	}

	function getUser() {
		$serverSoftware = isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '';
		$user = '';
		if(stripos($serverSoftware, 'apache') !== false && function_exists('apache_getenv')) {
			$user = apache_getenv("REMOTE_USER");
		}
		if (!$user && !empty($_SERVER['REMOTE_USER'])) {
			$user = $_SERVER['REMOTE_USER'];
		}
		if (!$user && !empty($_SERVER['PHP_AUTH_USER'])) {
			$user = $_SERVER['PHP_AUTH_USER'];
		}
		return $user ? $user : '';
	}

	function getUserLevel($user) {
		global $userLevels, $userInit;
		if(strlen($user) == 0) {
			return USERLEVEL_MAX;
		} else if (file_exists(LBASE_DIR . '/' . USERLEVEL_FILE)) {
			//Initialise userLevels array if possible
			if($userInit == 0) {
				$userInit = 1;
				$lines = array();
				$data = file_get_contents(LBASE_DIR . '/' . USERLEVEL_FILE);
				$lines = explode("\n", $data);
				foreach($lines as $line) {
					$line = trim($line);
					if (strlen($line)) {
						$index = strpos($line, ':');
						if ($index !== false) {
							$userLevels[substr($line, 0, $index)] = (int)substr($line, $index +1);
						}
					}
				}
			}
			if(array_key_exists($user, $userLevels))
				return $userLevels[$user];
			else
				return USERLEVEL_MIN;
		} else
			return USERLEVEL_MAX;
	}

	// user level of the user making the current web request (cached)
	function currentUserLevel() {
		static $level = null;
		if ($level === null) {
			$level = (int)getUserLevel(getUser());
		}
		return $level;
	}

	// Stop processing the request with a plain text error
	function denyRequest($code, $msg) {
		if (!headers_sent()) {
			http_response_code($code);
			header('Content-Type: text/plain; charset=utf-8');
		}
		echo $msg;
		exit;
	}

	function requireUserLevel($min) {
		if (currentUserLevel() < (int)$min) {
			denyRequest(403, 'Forbidden: insufficient user level');
		}
	}

	// Detect requests triggered by a foreign web site (CSRF).
	// Scripts and tools like curl send no Sec-Fetch-Site/Origin header and stay allowed.
	function isCrossSiteRequest() {
		$site = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? strtolower($_SERVER['HTTP_SEC_FETCH_SITE']) : '';
		if ($site !== '') {
			return $site === 'cross-site';
		}
		if (!empty($_SERVER['HTTP_ORIGIN'])) {
			$origin = $_SERVER['HTTP_ORIGIN'];
			if ($origin === 'null') return true;
			$originHost = parse_url($origin, PHP_URL_HOST);
			$host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST']) : '';
			if (!is_string($originHost)) return true;
			return strcasecmp(trim($originHost, '[]'), trim($host, '[]')) !== 0;
		}
		return false;
	}

	function rejectCrossSiteRequest() {
		if (isCrossSiteRequest()) {
			denyRequest(403, 'Forbidden: cross-site request');
		}
	}

   $config = array();
   $userLevels = array();
   $userInit = 0;
   $logFile = "";
   $logSize = 1;
   readConfigs();
?>
