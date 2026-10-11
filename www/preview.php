<?php
	define('BASE_DIR', dirname(__FILE__));
	require_once(BASE_DIR . '/config.php');
	require_once(BASE_DIR . '/ui.php');

	define('CONVERT_CMD', 'convertCmd.txt');
	define('AGE_FILTER_MAX', 8);

	requireUserLevel(USERLEVEL_MINP);
	$canEdit = currentUserLevel() >= (int)USERLEVEL_MEDIUM;

	// ---------- view options (query string, remembered in cookies) ----------
	function viewOption($name, $allowed, $default) {
		$value = isset($_GET[$name]) ? $_GET[$name] : (isset($_COOKIE['media_' . $name]) ? $_COOKIE['media_' . $name] : $default);
		if (!in_array((string)$value, $allowed, true)) $value = $default;
		if (isset($_GET[$name])) setcookie('media_' . $name, $value, time() + 86400 * 365, '/');
		return $value;
	}
	$showType = viewOption('type', array('all', 'images', 'videos'), 'all');
	$sortOrder = viewOption('sort', array('newest', 'oldest'), 'newest');
	$ageFilter = (int)viewOption('age', array('1', '2', '3', '4', '5', '6', '7', '8'), '1');

	function checkMediaPath($path) {
		$path = (string)$path;
		return $path !== '' && strpos($path, '/') === false && strpos($path, "\0") === false
			&& isThumbnail($path) && file_exists(BASE_DIR . '/' . MEDIA_PATH . "/$path");
	}

	function postedList() {
		$list = array();
		if (!empty($_POST['check_list']) && is_array($_POST['check_list'])) {
			foreach ($_POST['check_list'] as $f) {
				if (checkMediaPath($f)) $list[] = $f;
			}
		}
		return $list;
	}

	function redirectWith($done, $n = 0) {
		$q = array('done' => $done);
		if ($n) $q['n'] = $n;
		header('Location: preview.php?' . http_build_query($q), true, 303);
		exit;
	}

	// streams a zip archive (uncompressed) of the given thumbnails' media files
	function sendZip($thumbs) {
		$files = array();
		foreach ($thumbs as $thumb) {
			$t = getFileType($thumb);
			if ($t == 't') {
				$files = array_merge($files, findLapseFiles($thumb));
			} else if ($t == 'v' || $t == 'i') {
				$base = BASE_DIR . '/' . MEDIA_PATH . '/' . dataFilename($thumb);
				if (file_exists($base)) $files[] = $base;
				if ($t == 'v' && file_exists("$base.dat")) $files[] = "$base.dat";
			}
		}
		if (!count($files)) redirectWith('nosel');
		$cmd = 'zip -0 -q -j -';
		foreach ($files as $f) $cmd .= ' ' . escapeshellarg($f);
		writeLog('Generating ZIP of ' . count($files) . ' files');
		header('Content-Type: application/zip');
		header('Content-Disposition: attachment; filename="cam_' . date('Ymd_His') . '.zip"');
		$zip = popen($cmd, 'r');
		fpassthru($zip);
		pclose($zip);
		exit;
	}

	function sendFile($thumb) {
		if (getFileType($thumb) == 't') sendZip(array($thumb));
		$file = BASE_DIR . '/' . MEDIA_PATH . '/' . dataFilename($thumb);
		if (!is_file($file)) redirectWith('missing');
		header('Content-Type: ' . (strcasecmp(fileext($file), 'jpg') == 0 ? 'image/jpeg' : 'video/mp4'));
		header('Content-Disposition: attachment; filename="' . basename($file) . '"');
		header('Content-Length: ' . filesize_n($file));
		readfile($file);
		exit;
	}

	// builds an mp4 from a timelapse batch in the background
	function startVideoConvert($thumb) {
		$ft = getFileType($thumb);
		$fi = getFileIndex($thumb);
		if ($ft != 't' || !is_numeric($fi)) return false;
		$tFiles = findLapseFiles($thumb);
		if (!count($tFiles)) return false;
		$tmp = BASE_DIR . '/' . MEDIA_PATH . '/' . $ft . $fi;
		if (!file_exists($tmp)) mkdir($tmp, 0777, true);
		foreach ($tFiles as $i => $tFile) {
			@symlink($tFile, $tmp . '/' . sprintf('i_%05d', $i) . '.jpg');
		}
		$vFile = substr(dataFilename($thumb), 0, -3) . 'mp4';
		$cmd = trim((string)@file_get_contents(BASE_DIR . '/' . CONVERT_CMD));
		if ($cmd === '') $cmd = '/usr/bin/ffmpeg -f image2 -i i_%05d.jpg';
		$cmd = str_replace('i_%05d', escapeshellarg($tmp) . '/i_%05d', $cmd);
		$cmd = '(' . $cmd . ' ' . escapeshellarg(BASE_DIR . '/' . MEDIA_PATH . "/$vFile") . ' ; rm -rf ' . escapeshellarg($tmp) . ';) >/dev/null 2>&1 &';
		writeLog("start lapse convert:$cmd");
		system($cmd);
		copy(BASE_DIR . '/' . MEDIA_PATH . "/$thumb", BASE_DIR . '/' . MEDIA_PATH . '/' . str_replace('/', SUBDIR_CHAR, $vFile) . '.v' . $fi . THUMBNAIL_EXT);
		return true;
	}

	// deletes files and folders recursively, empty subfolders get removed, top dir is kept
	function maintainFolders($path, $deleteMainFiles, $deleteSubFiles, $root = true) {
		$empty = true;
		foreach (glob("$path/*") as $file) {
			if (is_dir($file)) {
				if (!maintainFolders($file, $deleteMainFiles, $deleteSubFiles, false)) $empty = false;
			} else {
				if (($deleteSubFiles && !$root) || ($deleteMainFiles && $root)) {
					if (is_writeable($file)) @unlink($file); else $empty = false;
				} else {
					$empty = false;
				}
			}
		}
		return $empty && !$root && @rmdir($path);
	}

	// ---------- actions ----------
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		rejectCrossSiteRequest();
		$mediaDir = BASE_DIR . '/' . MEDIA_PATH;
		if (isset($_POST['download1'])) {
			if (checkMediaPath($_POST['download1'])) sendFile($_POST['download1']);
			redirectWith('missing');
		}
		$action = isset($_POST['action']) ? $_POST['action'] : '';
		if ($action == 'zipSel') sendZip(postedList());
		if (!$canEdit) denyRequest(403, 'Forbidden: insufficient user level');
		if (isset($_POST['delete1'])) {
			if (!checkMediaPath($_POST['delete1'])) redirectWith('missing');
			deleteFile($_POST['delete1']);
			maintainFolders($mediaDir, false, false);
			redirectWith('deleted', 1);
		}
		if (isset($_POST['convert'])) {
			if (checkMediaPath($_POST['convert']) && startVideoConvert($_POST['convert'])) redirectWith('converting');
			redirectWith('missing');
		}
		switch ($action) {
			case 'deleteAll':
				maintainFolders($mediaDir, true, true);
				redirectWith('deletedall');
			case 'deleteSel':
				$list = postedList();
				foreach ($list as $f) deleteFile($f);
				maintainFolders($mediaDir, false, false);
				redirectWith('deleted', count($list));
			case 'lockSel':
			case 'unlockSel':
				$list = postedList();
				foreach ($list as $f) lockFile($f, $action == 'lockSel' ? 1 : 0);
				redirectWith($action == 'lockSel' ? 'locked' : 'unlocked', count($list));
		}
		redirectWith('none');
	}

	// ---------- data for the page ----------
	function formatBytes($bytes) {
		$units = array('B', 'KB', 'MB', 'GB', 'TB');
		$i = 0;
		while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
		return ($i == 0 ? $bytes : number_format($bytes, $bytes < 10 ? 1 : 0, uiLang() == 'de' ? ',' : '.', '')) . ' ' . $units[$i];
	}

	function formatDuration($s) {
		return $s >= 60 ? sprintf('%d:%02d', floor($s / 60), $s % 60) : $s . ' s';
	}

	function mediaItems($showType, $ageFilter, $sortOrder) {
		$items = array();
		$now = time();
		$dir = BASE_DIR . '/' . MEDIA_PATH;
		foreach (scandir($dir) as $file) {
			if ($file == '.' || $file == '..' || !isThumbnail($file)) continue;
			$thumbTime = filemtime("$dir/$file");
			if ($ageFilter > 1) {
				$age = $now - $thumbTime;
				$from = 86400 * ($ageFilter - 2);
				if ($age < $from || ($ageFilter < AGE_FILTER_MAX && $age >= $from + 86400)) continue;
			}
			$type = getFileType($file);
			if ($showType == 'images' && $type != 'i' && $type != 't') continue;
			if ($showType == 'videos' && $type != 'v') continue;
			$data = dataFilename($file);
			$item = array('thumb' => $file, 'url' => MEDIA_PATH . '/' . $data, 'type' => $type, 'index' => getFileIndex($file),
				'size' => 0, 'time' => $thumbTime, 'duration' => 0, 'frames' => 0, 'locked' => !is_writeable("$dir/$file"));
			if ($data !== '' && file_exists("$dir/$data")) {
				$item['size'] = filesize_n("$dir/$data");
				$item['time'] = filemtime("$dir/$data");
				if ($type == 'v') $item['duration'] = max(0, $item['time'] - $thumbTime);
			}
			if ($type == 't') {
				$lapse = findLapseFiles($file);
				$item['frames'] = count($lapse);
				foreach ($lapse as $l) $item['size'] += filesize_n($l);
			}
			$items[] = $item;
		}
		usort($items, function ($a, $b) use ($sortOrder) {
			return $sortOrder == 'oldest' ? $a['time'] - $b['time'] : $b['time'] - $a['time'];
		});
		return $items;
	}

	function diskUsage() {
		$lines = file_exists(BASE_DIR . '/diskUsage.txt') ? explode("\n", file_get_contents(BASE_DIR . '/diskUsage.txt')) : array('local:local');
		$disks = array();
		foreach ($lines as $line) {
			$fields = explode(':', trim($line), 2);
			if (strlen(trim($line)) < 3 || count($fields) < 2) continue;
			$path = $fields[1] == 'local' ? BASE_DIR . '/' . MEDIA_PATH : $fields[1];
			$total = @disk_total_space($path);
			$free = @disk_free_space($path);
			if (!$total) continue;
			$disks[] = array('name' => $fields[0], 'total' => $total, 'free' => $free, 'pct' => round(($total - $free) / $total * 100, 1));
		}
		return $disks;
	}

	$items = mediaItems($showType, $ageFilter, $sortOrder);
	$disks = diskUsage();
	$typeIcons = array('i' => 'image', 'v' => 'video', 't' => 'clock');
	$typeNames = array('i' => t('media.type_i'), 'v' => t('media.type_v'), 't' => t('media.type_t'));

	$flash = '';
	if (isset($_GET['done'])) {
		$n = isset($_GET['n']) ? (int)$_GET['n'] : 0;
		switch ($_GET['done']) {
			case 'deleted': $flash = flashMessage(t('media.deleted', array('n' => $n))); break;
			case 'deletedall': $flash = flashMessage(t('media.deleted_all')); break;
			case 'locked': $flash = flashMessage(t('media.locked_msg')); break;
			case 'unlocked': $flash = flashMessage(t('media.unlocked_msg')); break;
			case 'converting': $flash = flashMessage(t('media.convert_started'), 'info'); break;
			case 'nosel': $flash = flashMessage(t('media.no_selection'), 'error'); break;
		}
	}

	pageHeader(t('media.title'), 'media');
	echo $flash;
?>
<div class="page-head">
	<h1><?php et('media.title'); ?> <span class="muted small" style="font-weight:500"><?php et('media.items', array('n' => count($items))); ?></span></h1>
<?php foreach ($disks as $d) {
	$cls = $d['pct'] > 98 ? ' crit' : ($d['pct'] > 90 ? ' warn' : ''); ?>
	<div class="storage-row" style="min-width:min(320px,100%)" title="<?php et('media.storage'); ?>">
		<?php echo icon('hard-drive'); ?>
		<div class="meter<?php echo $cls; ?>"><span style="width:<?php echo h($d['pct']); ?>%"></span></div>
		<span class="muted"><?php echo h($d['name'] . ' · ' . t('media.storage_free', array('free' => formatBytes($d['free']), 'total' => formatBytes($d['total'])))); ?></span>
	</div>
<?php } ?>
</div>

<form id="mediaForm" method="post" action="preview.php"></form>

<div class="card toolbar">
	<div class="segmented" role="group" aria-label="<?php et('media.filter'); ?>">
<?php foreach (array('all' => 'media.filter_all', 'images' => 'media.filter_images', 'videos' => 'media.filter_videos') as $k => $label) { ?>
		<a href="?type=<?php echo $k; ?>" class="<?php echo $showType == $k ? 'active' : ''; ?>"<?php echo $showType == $k ? ' aria-current="true"' : ''; ?>><?php et($label); ?></a>
<?php } ?>
	</div>
	<form method="get" action="preview.php" class="row" id="viewForm">
		<label class="inline"><?php et('media.age'); ?>
			<select class="select" name="age" onchange="this.form.submit()">
				<option value="1"><?php et('media.age_all'); ?></option>
<?php for ($a = 2; $a < AGE_FILTER_MAX; $a++) { ?>
				<option value="<?php echo $a; ?>"<?php echo $ageFilter == $a ? ' selected' : ''; ?>><?php et('media.age_range', array('from' => ($a - 2) * 24, 'to' => ($a - 1) * 24)); ?></option>
<?php } ?>
				<option value="<?php echo AGE_FILTER_MAX; ?>"<?php echo $ageFilter == AGE_FILTER_MAX ? ' selected' : ''; ?>><?php et('media.age_older', array('h' => (AGE_FILTER_MAX - 2) * 24)); ?></option>
			</select>
		</label>
		<label class="inline"><?php et('media.sort'); ?>
			<select class="select" name="sort" onchange="this.form.submit()">
				<option value="newest"<?php echo $sortOrder == 'newest' ? ' selected' : ''; ?>><?php et('media.sort_newest'); ?></option>
				<option value="oldest"<?php echo $sortOrder == 'oldest' ? ' selected' : ''; ?>><?php et('media.sort_oldest'); ?></option>
			</select>
		</label>
		<noscript><button class="btn btn-sm" type="submit"><?php et('btn.apply'); ?></button></noscript>
	</form>
	<span class="spacer"></span>
	<label class="inline thumbsize"><?php echo icon('grid'); ?><span class="sr-only"><?php et('media.thumbsize'); ?></span>
		<input type="range" id="thumbSize" min="120" max="420" step="10" value="220" aria-label="<?php et('media.thumbsize'); ?>">
	</label>
<?php if (count($items)) { ?>
	<button type="button" class="btn btn-ghost btn-sm" id="selectAll"><?php echo icon('check-square'); ?><span><?php echo h(t('js.media.select_all')); ?></span></button>
<?php if ($canEdit) { ?>
	<button type="submit" form="mediaForm" name="action" value="deleteAll" class="btn btn-soft-danger btn-sm" data-confirm="<?php echo h(t('js.media.confirm_delete_all')); ?>" data-danger data-ok="<?php et('media.delete_all'); ?>"><?php echo icon('trash'); ?><span><?php et('media.delete_all'); ?></span></button>
<?php } ?>
<?php } ?>
</div>

<?php if (!count($items)) { ?>
<div class="card empty">
	<?php echo icon('image'); ?>
	<strong><?php et('media.empty'); ?></strong>
	<span><?php et('media.empty_hint'); ?></span>
</div>
<?php } else {
	$lastDay = '';
	$lightbox = array();
	foreach ($items as $i => $item) {
		$day = date('Y-m-d', $item['time']);
		if ($day != $lastDay) {
			if ($lastDay != '') echo "</div>\n";
			echo '<div class="media-group"><h2 data-date="' . h($day) . '">' . h($day) . '</h2></div>' . "\n";
			echo '<div class="media-grid">' . "\n";
			$lastDay = $day;
		}
		$busy = $item['size'] == 0;
		$meta = $typeNames[$item['type']] ?? '';
		if ($item['type'] == 'v' && $item['duration']) $meta = formatDuration($item['duration']);
		if ($item['type'] == 't') $meta = t('media.frames', array('n' => $item['frames']));
		$lightbox[] = array('thumb' => $item['thumb'], 'url' => $item['url'], 'type' => $item['type'], 'index' => $item['index'],
			'when' => date('Y-m-d H:i:s', $item['time']), 'size' => formatBytes($item['size']), 'meta' => $meta, 'busy' => $busy);
?>
	<article class="media-card" data-index="<?php echo $i; ?>">
		<label class="media-check"><input type="checkbox" name="check_list[]" value="<?php echo h($item['thumb']); ?>" form="mediaForm" aria-label="<?php echo h($item['index']); ?>"></label>
		<button type="button" class="media-thumb" data-open="<?php echo $i; ?>" title="<?php echo h(basename($item['url'])); ?>">
			<img loading="lazy" decoding="async" src="<?php echo h(MEDIA_PATH . '/' . $item['thumb']); ?>" alt="">
			<span class="media-type"><?php echo icon($typeIcons[$item['type']] ?? 'image'); ?><?php echo h($meta); ?></span>
<?php if ($item['locked']) { ?>
			<span class="media-lock" title="<?php et('media.lock'); ?>"><?php echo icon('lock'); ?></span>
<?php } ?>
<?php if ($busy) { ?>
			<span class="media-busy">…</span>
<?php } ?>
		</button>
		<div class="media-meta">
			<span class="name">#<?php echo h($item['index']); ?></span>
			<span class="time"><?php echo h(date('H:i:s', $item['time'])); ?></span>
			<span class="size"><?php echo $busy ? '–' : h(formatBytes($item['size'])); ?></span>
		</div>
	</article>
<?php
	}
	echo "</div>\n";
} ?>

<div class="bulkbar" id="bulkbar" hidden>
	<span class="count" id="selCount"></span>
	<button type="button" class="btn btn-ghost btn-sm" id="selectNone" aria-label="<?php echo h(t('js.media.select_none')); ?>" title="<?php echo h(t('js.media.select_none')); ?>"><?php echo icon('x'); ?></button>
	<button type="submit" form="mediaForm" name="action" value="zipSel" class="btn btn-soft btn-sm" title="<?php et('media.zip'); ?>"><?php echo icon('archive'); ?><span><?php et('media.zip'); ?></span></button>
<?php if ($canEdit) { ?>
	<button type="submit" form="mediaForm" name="action" value="lockSel" class="btn btn-ghost btn-sm" title="<?php et('media.lock'); ?>"><?php echo icon('lock'); ?><span><?php et('media.lock'); ?></span></button>
	<button type="submit" form="mediaForm" name="action" value="unlockSel" class="btn btn-ghost btn-sm" title="<?php et('media.unlock'); ?>"><?php echo icon('unlock'); ?><span><?php et('media.unlock'); ?></span></button>
	<button type="submit" form="mediaForm" name="action" value="deleteSel" class="btn btn-danger btn-sm" id="deleteSel" data-danger data-ok="<?php et('media.delete'); ?>" title="<?php et('media.delete'); ?>"><?php echo icon('trash'); ?><span><?php et('media.delete'); ?></span></button>
<?php } ?>
</div>

<div class="lightbox" id="lightbox" hidden role="dialog" aria-modal="true" aria-labelledby="lbTitle">
	<div class="lightbox-head">
		<div class="title" id="lbTitle"></div>
		<button type="submit" form="mediaForm" name="download1" class="btn btn-ghost btn-sm" id="lbDownload"><?php echo icon('download'); ?><span><?php et('media.download'); ?></span></button>
		<a class="btn btn-ghost btn-sm" id="lbOpen" target="_blank" rel="noopener"><?php echo icon('external'); ?><span><?php et('media.open'); ?></span></a>
<?php if ($canEdit) { ?>
		<button type="submit" form="mediaForm" name="convert" class="btn btn-ghost btn-sm" id="lbConvert"><?php echo icon('film'); ?><span><?php et('media.convert'); ?></span></button>
		<button type="submit" form="mediaForm" name="delete1" class="btn btn-ghost btn-sm" id="lbDelete" data-confirm="<?php echo h(t('js.media.confirm_delete')); ?>" data-danger data-ok="<?php et('media.delete'); ?>"><?php echo icon('trash'); ?><span><?php et('media.delete'); ?></span></button>
<?php } ?>
		<button type="button" class="icon-btn" id="lbClose" aria-label="<?php echo h(t('js.media.close')); ?>"><?php echo icon('x'); ?></button>
	</div>
	<div class="lightbox-body">
		<div id="lbMedia"></div>
		<button type="button" class="icon-btn lightbox-nav prev" id="lbPrev" aria-label="<?php echo h(t('js.media.prev')); ?>"><?php echo icon('chevron-left'); ?></button>
		<button type="button" class="icon-btn lightbox-nav next" id="lbNext" aria-label="<?php echo h(t('js.media.next')); ?>"><?php echo icon('chevron-right'); ?></button>
	</div>
</div>
<?php
	pageFooter(array('js/media.js'), array('media' => isset($lightbox) ? $lightbox : array()));
?>
