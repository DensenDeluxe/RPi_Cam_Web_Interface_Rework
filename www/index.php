<?php
	define('BASE_DIR', dirname(__FILE__));
	require_once(BASE_DIR . '/config.php');
	require_once(BASE_DIR . '/ui.php');

	$macros = array('error_soft','error_hard','start_img','end_img','start_vid','end_vid','end_box','do_cmd','motion_event','startstop');
	$options_mm = array('Average' => 'average', 'Spot' => 'spot', 'Backlit' => 'backlit', 'Matrix' => 'matrix');
	$options_em = array('Off' => 'off', 'Auto' => 'auto', 'Night' => 'night', 'Nightpreview' => 'nightpreview', 'Backlight' => 'backlight', 'Spotlight' => 'spotlight', 'Sports' => 'sports', 'Snow' => 'snow', 'Beach' => 'beach', 'Verylong' => 'verylong', 'Fixedfps' => 'fixedfps');
	$options_wb = array('Off' => 'off', 'Auto' => 'auto', 'Sun' => 'sun', 'Cloudy' => 'cloudy', 'Shade' => 'shade', 'Tungsten' => 'tungsten', 'Fluorescent' => 'fluorescent', 'Incandescent' => 'incandescent', 'Flash' => 'flash', 'Horizon' => 'horizon', 'Greyworld' => 'greyworld');
	// Colourpoint and colourbalance are left out because they kill the camera
	$options_ie = array('None' => 'none', 'Negative' => 'negative', 'Solarise' => 'solarise', 'Sketch' => 'sketch', 'Denoise' => 'denoise', 'Emboss' => 'emboss', 'Oilpaint' => 'oilpaint', 'Hatch' => 'hatch', 'Gpen' => 'gpen', 'Pastel' => 'pastel', 'Watercolour' => 'watercolour', 'Film' => 'film', 'Blur' => 'blur', 'Saturation' => 'saturation', 'Colourswap' => 'colourswap', 'Washedout' => 'washedout', 'Posterise' => 'posterise', 'Cartoon' => 'cartoon');
	$options_ro = array('0°' => '0', '90°' => '90', '180°' => '180', '270°' => '270');
	$options_fl = array(t('opt.none') => '0', t('opt.horizontal') => '1', t('opt.vertical') => '2', t('opt.both') => '3');
	$options_bo = array(t('off') => '0', t('opt.background') => '2');
	$options_mx = array(t('opt.internal') => '0', t('opt.external') => '1', t('opt.monitor') => '2');
	$options_cn = array(t('opt.first') => '1', t('opt.second') => '2');
	$options_af = array(t('opt.af_manual') => '0', t('opt.af_auto') => '1', t('opt.af_continuous') => '2');

	$userLevel = currentUserLevel();
	$user = getUser();
	if ($user !== '') writeLog("Logged in user:" . $user . ": UserLevel " . $userLevel);

	$isAdmin = $userLevel >= (int)USERLEVEL_MAX;
	$canCapture = $userLevel >= (int)USERLEVEL_MEDIUM;
	$mjpegMode = isset($_COOKIE['stream_mode']) && $_COOKIE['stream_mode'] == 'MJPEG-Stream';

	// current value of a setting as shown in the form
	function settingValue($key) {
		switch ($key) {
			case 'flip':
				return ((cfg('vflip') == 1 ? 2 : 0) + (cfg('hflip') == 1 ? 1 : 0));
			case 'MP4Box':
				$v = cfg('MP4Box');
				return ($v === 'background') ? 2 : (int)$v;
			case 'tl_interval':
				return cfg('tl_interval', 30) / 10;
			case 'watchdog_interval':
				return cfg('watchdog_interval', 0) / 10;
			default:
				return cfg($key);
		}
	}

	function controlAttrs($def) {
		$a = ' id="' . h($def['id']) . '"';
		if (isset($def['cmd'])) $a .= ' data-cmd="' . h($def['cmd']) . '" data-label="' . h(t($def['label'])) . '"';
		foreach (array('min', 'max', 'step', 'maxlength') as $k) {
			if (isset($def[$k])) $a .= ' ' . $k . '="' . h($def[$k]) . '"';
		}
		if (isset($def['scale'])) $a .= ' data-scale="' . h($def['scale']) . '"';
		if (isset($def['after'])) $a .= ' data-after="' . h($def['after']) . '"';
		if (!empty($def['recordingLock'])) $a .= ' data-recording-lock';
		return $a;
	}

	function renderControl($def, $small = false) {
		$key = isset($def['key']) ? $def['key'] : $def['id'];
		$value = settingValue($key);
		$attrs = controlAttrs($def);
		$cls = $small ? ' input-sm' : '';
		switch ($def['type']) {
			case 'range':
				echo '<div class="range"><input type="range"' . $attrs . ' value="' . h($value) . '"><output for="' . h($def['id']) . '">' . h($value) . '</output></div>';
				break;
			case 'number':
				echo '<input class="input' . $cls . '" type="number" inputmode="decimal"' . $attrs . ' value="' . h($value) . '">';
				break;
			case 'text':
				echo '<input class="input' . $cls . '" type="text" autocomplete="off"' . $attrs . ' value="' . h($value) . '">';
				break;
			case 'toggle':
				echo '<label class="switch"><input type="checkbox"' . $attrs . ($value == 1 ? ' checked' : '') . '><span class="track"></span><span class="sr-only">' . h(t($def['label'])) . '</span></label>';
				break;
			case 'select':
				echo '<select class="select' . $cls . '"' . $attrs . '>';
				foreach ($def['options'] as $name => $optValue) {
					$sel = ((string)$value === (string)$optValue) ? ' selected' : '';
					echo '<option value="' . h($optValue) . '"' . $sel . '>' . h($name) . '</option>';
				}
				echo '</select>';
				break;
		}
	}

	// capabilities written by the rpicamd camera daemon; missing for the legacy raspimjpeg
	function cameraInfo() {
		static $info = false;
		if ($info === false) {
			$info = null;
			$file = dirname(cfg('status_file', '/dev/shm/mjpeg/status_mjpeg.txt')) . '/camera_info.json';
			if (is_readable($file)) {
				$data = json_decode((string)@file_get_contents($file), true);
				if (is_array($data)) $info = $data;
			}
		}
		return $info;
	}

	function supported($cmd) {
		$info = cameraInfo();
		return !$info || empty($info['unsupported']) || !in_array($cmd, $info['unsupported'], true);
	}

	function field($def) {
		if (isset($def['cmd']) && !supported($def['cmd'])) return;
		$wide = !empty($def['wide']) ? ' wide' : '';
		echo '<div class="field' . $wide . '">';
		echo '<label class="field-label" for="' . h($def['id']) . '">' . h(t($def['label'])) . icon('check', 'applied-mark') . '</label>';
		echo '<div class="field-control">';
		renderControl($def);
		if (isset($def['extra'])) echo $def['extra'];
		if (isset($def['default'])) {
			echo '<button type="button" class="btn btn-ghost btn-sm btn-icon" data-default-for="' . h($def['id']) . '" data-default="' . h($def['default']) . '" title="' . h(t('default_is', array('v' => $def['default']))) . '" aria-label="' . h(t('default_is', array('v' => $def['default']))) . '">' . icon('rotate-ccw') . '</button>';
		}
		echo '</div>';
		if (isset($def['hint'])) echo '<div class="field-hint">' . h(t($def['hint'])) . '</div>';
		echo '</div>';
	}

	// several values sent together as one command
	function group($cmd, $label, $fields, $hint = null, $extra = '') {
		if (!supported($cmd)) return;
		$ids = array();
		foreach ($fields as $f) $ids[] = $f['id'];
		echo '<div class="field-group" data-group-cmd="' . h($cmd) . '" data-fields="' . h(implode(' ', $ids)) . '" data-label="' . h(t($label)) . '">';
		echo '<div class="field-label">' . h(t($label)) . icon('check', 'applied-mark') . '</div>';
		if ($hint) echo '<div class="field-hint">' . h(t($hint)) . '</div>';
		echo '<div class="mini-grid">';
		foreach ($fields as $f) {
			if ($f['type'] == 'toggle') {
				echo '<div class="mini-toggle"><span>' . h(t($f['label'])) . '</span>';
				renderControl($f, true);
				echo '</div>';
			} else {
				echo '<label for="' . h($f['id']) . '">' . h(t($f['label']));
				renderControl($f, true);
				echo '</label>';
			}
		}
		echo '</div>';
		echo '<div class="group-actions">' . $extra . '<button type="button" class="btn btn-soft btn-sm" data-apply>' . icon('check') . h(t('btn.apply')) . '</button></div>';
		echo '</div>';
	}

	// custom annotation colours are stored as Y U V, the form uses a colour picker
	function yuvToHex($y, $u, $v) {
		$y = (float)$y; $u = (float)$u - 128; $v = (float)$v - 128;
		$rgb = array($y + 1.402 * $v, $y - 0.344136 * $u - 0.714136 * $v, $y + 1.772 * $u);
		$hex = '#';
		foreach ($rgb as $c) $hex .= sprintf('%02x', max(0, min(255, (int)round($c))));
		return $hex;
	}

	function colourGroup($cmd, $label, $prefix) {
		$hex = yuvToHex(cfg($prefix . '_Y', 255), cfg($prefix . '_U', 128), cfg($prefix . '_V', 128));
		echo '<div class="field-group" data-group-cmd="' . h($cmd) . '" data-fields="' . h($cmd) . '_en ' . h($cmd) . '_colour" data-label="' . h(t($label)) . '">';
		echo '<div class="field-label">' . h(t($label)) . icon('check', 'applied-mark') . '</div>';
		echo '<div class="row" style="justify-content:space-between;margin-top:8px">';
		echo '<label class="switch"><input type="checkbox" id="' . h($cmd) . '_en"' . (cfg($prefix . '_colour') == 1 ? ' checked' : '') . '><span class="track"></span><span>' . h(t('s.colour_enabled')) . '</span></label>';
		echo '<span class="row"><input class="input" type="color" id="' . h($cmd) . '_colour" data-yuv value="' . h($hex) . '" aria-label="' . h(t($label)) . '">';
		echo '<button type="button" class="btn btn-soft btn-sm" data-apply>' . icon('check') . h(t('btn.apply')) . '</button></span>';
		echo '</div></div>';
	}

	function presetOptions() {
		echo '<option value="">' . h(t('s.preset_choose')) . '</option>';
		if (file_exists(BASE_DIR . '/uPresets.html')) {
			// user supplied list of <option value="w h fps boxfps iw ih [divider]">
			$data = file_get_contents(BASE_DIR . '/uPresets.html');
			if (preg_match_all('/<option[^>]*value=["\']([^"\']*)["\'][^>]*>(.*?)<\/option>/is', $data, $m, PREG_SET_ORDER)) {
				foreach ($m as $i => $opt) {
					if ($i == 0 && stripos($opt[2], 'select') !== false) continue;
					echo '<option value="' . h($opt[1]) . '">' . h(strip_tags($opt[2])) . '</option>';
				}
			}
		} else {
			// "-" keeps the current image size, so the presets suit every camera module
			$presets = array(
				'1920 1080 25 25 - - 1' => 'Full HD 1080p 16:9',
				'1280 0720 25 25 - - 1' => 'HD-ready 720p 16:9',
				'1296 972 25 25 - - 1' => 'Max View 972p 4:3',
				'768 576 25 25 - - 1' => 'SD TV 576p 4:3',
				'1920 1080 01 30 - - 1' => 'Full HD Timelapse (x30) 1080p 16:9',
			);
			foreach ($presets as $v => $name) echo '<option value="' . h($v) . '">' . h($name) . '</option>';
		}
	}

	function userButtons() {
		$buttons = array();
		if (file_exists(BASE_DIR . '/userbuttons')) {
			foreach (explode("\n", file_get_contents(BASE_DIR . '/userbuttons')) as $line) {
				if (strlen(trim($line)) && substr($line, 0, 1) != '#' && count($buttons) < 12) {
					$f = explode(',', $line, 4);
					if (count($f) > 1) {
						$cls = isset($f[2]) ? $f[2] : '';
						$style = 'btn-soft';
						if (strpos($cls, 'danger') !== false) $style = 'btn-soft-danger';
						else if (strpos($cls, 'primary') !== false) $style = 'btn-primary';
						$buttons[] = array('name' => trim($f[0]), 'macro' => trim($f[1]), 'style' => $style, 'attrs' => isset($f[3]) ? trim($f[3]) : '');
					}
				}
			}
		}
		return $buttons;
	}

	$panMode = 0;
	$panInit = array(100, 100);
	if (file_exists(BASE_DIR . '/pipan_on')) {
		$panMode = 1;
		if (file_exists(BASE_DIR . '/pipan_bak.txt')) {
			$vals = explode(' ', trim(file_get_contents(BASE_DIR . '/pipan_bak.txt')));
			if (count($vals) >= 2) $panInit = array((int)$vals[0], (int)$vals[1]);
		}
	} else if (file_exists(BASE_DIR . '/servo_on')) {
		$panMode = 2;
	}
	$piLight = file_exists(BASE_DIR . '/pilight_on');
	$buttons = userButtons();

	pageHeader(t('nav.live'), 'live', array('statusPill' => true));
?>
<div class="live-layout<?php echo $isAdmin ? ' has-settings' : ''; ?>">
	<div class="viewer-col">
		<div class="stage<?php echo $panMode == 1 ? ' pipan' : ''; ?>" id="stage">
			<img id="mjpeg_dest" alt="<?php et('nav.live'); ?>" draggable="false">
			<span class="stage-badge" id="recBadge" hidden><span class="dot"></span><span>REC</span></span>
			<div class="stage-overlay" id="stageOverlay" hidden>
				<svg class="icon" aria-hidden="true"><use href="img/icons.svg#power"></use></svg>
				<strong></strong>
				<p></p>
<?php if ($isAdmin) { ?>
				<button type="button" class="btn btn-primary" id="overlayStart" hidden><?php echo icon('play'); ?><span><?php echo h(t('js.act.camera_start')); ?></span></button>
<?php } ?>
			</div>
			<div class="stage-tools">
				<button type="button" class="icon-btn" id="fullscreenBtn" title="<?php et('live.fullscreen'); ?>" aria-label="<?php et('live.fullscreen'); ?>"><?php echo icon('maximize'); ?></button>
			</div>
		</div>

<?php if ($canCapture) { ?>
		<div class="actions-bar" role="group" aria-label="<?php et('live.actions'); ?>">
			<button type="button" class="action" data-action="video" disabled><?php echo icon('record'); ?><span class="label"><?php echo h(t('js.act.video')); ?></span></button>
			<button type="button" class="action" data-action="image" disabled><?php echo icon('camera'); ?><span class="label"><?php echo h(t('js.act.image')); ?></span></button>
			<button type="button" class="action" data-action="timelapse" disabled><?php echo icon('clock'); ?><span class="label"><?php echo h(t('js.act.timelapse')); ?></span></button>
<?php if ($isAdmin) { ?>
			<button type="button" class="action" data-action="motion" disabled><?php echo icon('activity'); ?><span class="label"><?php echo h(t('js.act.motion')); ?></span></button>
			<button type="button" class="action" data-action="camera" disabled><?php echo icon('power'); ?><span class="label"><?php echo h(t('js.act.camera_stop')); ?></span></button>
<?php } ?>
		</div>
<?php } ?>

<?php if ($panMode || $piLight || count($buttons)) { ?>
		<div class="extras">
<?php if ($panMode) { ?>
			<section class="card">
				<div class="card-head"><h2><?php echo icon('crosshair'); ?><?php et('live.pan'); ?></h2><span class="spacer"></span><span class="muted small"><?php et('live.pan_hint'); ?></span></div>
				<div class="card-body">
					<div class="dpad">
						<button type="button" class="btn up" data-pan="up" aria-label="<?php et('live.up'); ?>"><?php echo icon('chevron-up'); ?></button>
						<button type="button" class="btn left" data-pan="left" aria-label="<?php et('live.left'); ?>"><?php echo icon('chevron-left'); ?></button>
						<span class="center"><?php echo icon('camera'); ?></span>
						<button type="button" class="btn right" data-pan="right" aria-label="<?php et('live.right'); ?>"><?php echo icon('chevron-right'); ?></button>
						<button type="button" class="btn down" data-pan="down" aria-label="<?php et('live.down'); ?>"><?php echo icon('chevron-down'); ?></button>
					</div>
				</div>
			</section>
<?php } ?>
<?php if ($piLight) { ?>
			<section class="card">
				<div class="card-head"><h2><?php echo icon('bulb'); ?><?php et('live.pilight'); ?></h2></div>
				<div class="card-body row">
					<input class="input" type="color" id="pilightColour" value="#ffffff" aria-label="<?php et('live.pilight'); ?>">
					<button type="button" class="btn btn-soft" id="pilightToggle"><?php echo icon('bulb'); ?><?php et('live.pilight_toggle'); ?></button>
				</div>
			</section>
<?php } ?>
<?php if (count($buttons)) { ?>
			<section class="card">
				<div class="card-head"><h2><?php echo icon('zap'); ?><?php et('live.userbuttons'); ?></h2></div>
				<div class="card-body userbuttons">
<?php foreach ($buttons as $b) { ?>
					<button type="button" class="btn <?php echo $b['style']; ?>" data-macro="<?php echo h($b['macro']); ?>" <?php echo $b['attrs']; ?>><?php echo h($b['name']); ?></button>
<?php } ?>
				</div>
			</section>
<?php } ?>
		</div>
<?php } ?>
	</div>

<?php if ($isAdmin) { ?>
	<section class="card settings-card" aria-labelledby="settingsTitle">
		<div class="card-head"><h2 id="settingsTitle"><?php echo icon('sliders'); ?><?php et('live.settings'); ?></h2></div>
		<div class="tabs" role="tablist">
			<button type="button" class="tab" role="tab" id="tab-image" aria-controls="panel-image" aria-selected="true"><?php echo icon('image'); ?><?php et('tab.image'); ?></button>
			<button type="button" class="tab" role="tab" id="tab-exposure" aria-controls="panel-exposure" aria-selected="false"><?php echo icon('sun'); ?><?php et('tab.exposure'); ?></button>
			<button type="button" class="tab" role="tab" id="tab-video" aria-controls="panel-video" aria-selected="false"><?php echo icon('film'); ?><?php et('tab.video'); ?></button>
			<button type="button" class="tab" role="tab" id="tab-overlay" aria-controls="panel-overlay" aria-selected="false"><?php echo icon('file-text'); ?><?php et('tab.overlay'); ?></button>
			<button type="button" class="tab" role="tab" id="tab-motion" aria-controls="panel-motion" aria-selected="false"><?php echo icon('activity'); ?><?php et('tab.motion'); ?></button>
			<button type="button" class="tab" role="tab" id="tab-system" aria-controls="panel-system" aria-selected="false"><?php echo icon('hard-drive'); ?><?php et('tab.system'); ?></button>
		</div>

		<div class="tab-panel" role="tabpanel" id="panel-image" aria-labelledby="tab-image">
			<div class="field">
				<label class="field-label" for="preset"><?php et('s.preset'); ?></label>
				<div class="field-control"><select class="select" id="preset"><?php presetOptions(); ?></select></div>
				<div class="field-hint"><?php et('s.preset_hint'); ?></div>
			</div>
<?php
			group('px', 's.res', array(
				// the VideoCore H.264 encoder records at most 1920x1080
				array('id' => 'video_width', 'label' => 's.video_width', 'type' => 'number', 'min' => 64, 'max' => 1920),
				array('id' => 'video_height', 'label' => 's.video_height', 'type' => 'number', 'min' => 64, 'max' => 1080),
				array('id' => 'video_fps', 'label' => 's.video_fps', 'type' => 'number', 'min' => 1, 'max' => 200),
				array('id' => 'MP4Box_fps', 'label' => 's.MP4Box_fps', 'type' => 'number', 'min' => 1, 'max' => 200),
				array('id' => 'image_width', 'label' => 's.image_width', 'type' => 'number', 'min' => 64, 'max' => 4056),
				array('id' => 'image_height', 'label' => 's.image_height', 'type' => 'number', 'min' => 64, 'max' => 3040),
				// order matters: px expects the divider last
				array('id' => 'fps_divider', 'label' => 's.fps_divider', 'type' => 'number', 'min' => 1, 'max' => 16),
			), 's.res_hint');
			field(array('id' => 'image_quality', 'cmd' => 'qu', 'label' => 's.image_quality', 'type' => 'range', 'min' => 0, 'max' => 100, 'default' => 10));
			field(array('id' => 'sharpness', 'cmd' => 'sh', 'label' => 's.sharpness', 'type' => 'range', 'min' => -100, 'max' => 100, 'default' => 0));
			field(array('id' => 'contrast', 'cmd' => 'co', 'label' => 's.contrast', 'type' => 'range', 'min' => -100, 'max' => 100, 'default' => 0));
			field(array('id' => 'brightness', 'cmd' => 'br', 'label' => 's.brightness', 'type' => 'range', 'min' => 0, 'max' => 100, 'default' => 50));
			field(array('id' => 'saturation', 'cmd' => 'sa', 'label' => 's.saturation', 'type' => 'range', 'min' => -100, 'max' => 100, 'default' => 0));
			field(array('id' => 'image_effect', 'cmd' => 'ie', 'label' => 's.image_effect', 'type' => 'select', 'options' => $options_ie));
			field(array('id' => 'rotation', 'cmd' => 'ro', 'label' => 's.rotation', 'type' => 'select', 'options' => $options_ro));
			field(array('id' => 'flip', 'cmd' => 'fl', 'label' => 's.flip', 'type' => 'select', 'options' => $options_fl));
			group('ce', 's.ce', array(
				array('id' => 'ce_en', 'key' => 'colour_effect_en', 'label' => 's.colour_effect_en', 'type' => 'toggle'),
				array('id' => 'ce_u', 'key' => 'colour_effect_u', 'label' => 's.colour_effect_u', 'type' => 'number', 'min' => 0, 'max' => 255),
				array('id' => 'ce_v', 'key' => 'colour_effect_v', 'label' => 's.colour_effect_v', 'type' => 'number', 'min' => 0, 'max' => 255),
			), 's.ce_hint', '<span class="swatch" id="ce_swatch"></span><span class="spacer"></span>');
			group('ri', 's.roi', array(
				array('id' => 'roi_x', 'key' => 'sensor_region_x', 'label' => 'x', 'type' => 'number', 'min' => 0, 'max' => 65536),
				array('id' => 'roi_y', 'key' => 'sensor_region_y', 'label' => 'y', 'type' => 'number', 'min' => 0, 'max' => 65536),
				array('id' => 'roi_w', 'key' => 'sensor_region_w', 'label' => 'w', 'type' => 'number', 'min' => 0, 'max' => 65536),
				array('id' => 'roi_h', 'key' => 'sensor_region_h', 'label' => 'h', 'type' => 'number', 'min' => 0, 'max' => 65536),
			), 's.roi_hint');
			field(array('id' => 'raw_layer', 'cmd' => 'rl', 'label' => 's.raw_layer', 'type' => 'toggle'));
			field(array('id' => 'stat_pass', 'cmd' => 'st', 'label' => 's.stat_pass', 'type' => 'toggle'));
			$camInfo = cameraInfo();
			if (cfg('camera_num') > 0 || ($camInfo && isset($camInfo['cameras']) && $camInfo['cameras'] > 1)) {
				field(array('id' => 'camera_num', 'cmd' => 'cn', 'label' => 's.camera_num', 'type' => 'select', 'options' => $options_cn));
			}
?>
		</div>

		<div class="tab-panel" role="tabpanel" id="panel-exposure" aria-labelledby="tab-exposure" hidden>
<?php
			field(array('id' => 'exposure_mode', 'cmd' => 'em', 'label' => 's.exposure_mode', 'type' => 'select', 'options' => $options_em));
			field(array('id' => 'metering_mode', 'cmd' => 'mm', 'label' => 's.metering_mode', 'type' => 'select', 'options' => $options_mm));
			field(array('id' => 'exposure_compensation', 'cmd' => 'ec', 'label' => 's.exposure_compensation', 'type' => 'range', 'min' => -10, 'max' => 10, 'default' => 0));
			field(array('id' => 'iso', 'cmd' => 'is', 'label' => 's.iso', 'type' => 'number', 'min' => 0, 'max' => 800, 'step' => 1, 'default' => 0, 'hint' => 's.iso_hint'));
			field(array('id' => 'shutter_speed', 'cmd' => 'ss', 'label' => 's.shutter_speed', 'type' => 'number', 'min' => 0, 'step' => 1, 'default' => 0, 'hint' => 's.shutter_speed_hint'));
			if ($camInfo && !empty($camInfo['autofocus'])) {
				field(array('id' => 'af_mode', 'cmd' => 'af', 'label' => 's.af_mode', 'type' => 'select', 'options' => $options_af, 'hint' => 's.af_mode_hint'));
				field(array('id' => 'lens_position', 'cmd' => 'lp', 'label' => 's.lens_position', 'type' => 'number', 'min' => 0, 'max' => 32, 'step' => 0.1, 'hint' => 's.lens_position_hint'));
			}
			field(array('id' => 'white_balance', 'cmd' => 'wb', 'label' => 's.white_balance', 'type' => 'select', 'options' => $options_wb));
			group('ag', 's.ag', array(
				array('id' => 'ag_r', 'key' => 'autowbgain_r', 'label' => 's.autowbgain_r', 'type' => 'number', 'min' => 0, 'max' => 800),
				array('id' => 'ag_b', 'key' => 'autowbgain_b', 'label' => 's.autowbgain_b', 'type' => 'number', 'min' => 0, 'max' => 800),
			), 's.ag_hint');
?>
		</div>

		<div class="tab-panel" role="tabpanel" id="panel-video" aria-labelledby="tab-video" hidden>
<?php
			field(array('id' => 'video_bitrate', 'cmd' => 'bi', 'label' => 's.video_bitrate', 'type' => 'number', 'min' => 0, 'max' => 25000000, 'step' => 100000, 'default' => 17000000, 'hint' => 's.video_bitrate_hint'));
			field(array('id' => 'video_stabilisation', 'cmd' => 'vs', 'label' => 's.video_stabilisation', 'type' => 'toggle'));
			field(array('id' => 'video_split', 'cmd' => 'vi', 'label' => 's.video_split', 'type' => 'number', 'min' => 0, 'default' => 0, 'hint' => 's.video_split_hint'));
			field(array('id' => 'video_buffer', 'cmd' => 'bu', 'label' => 's.video_buffer', 'type' => 'number', 'min' => 0, 'step' => 100, 'default' => 0, 'hint' => 's.video_buffer_hint'));
			field(array('id' => 'MP4Box', 'cmd' => 'bo', 'label' => 's.MP4Box', 'type' => 'select', 'options' => $options_bo, 'hint' => 's.MP4Box_hint'));
			field(array('id' => 'tl_interval', 'cmd' => 'tv', 'label' => 's.tl_interval', 'type' => 'number', 'min' => 0.1, 'max' => 3200, 'step' => 0.1, 'scale' => 10, 'default' => 3, 'hint' => 's.tl_interval_hint'));
			group('qp', 's.qp', array(
				array('id' => 'minimise_frag', 'label' => 's.minimise_frag', 'type' => 'number', 'min' => 0, 'max' => 1),
				array('id' => 'initial_quant', 'label' => 's.initial_quant', 'type' => 'number', 'min' => 0, 'max' => 51),
				array('id' => 'encode_qp', 'label' => 's.encode_qp', 'type' => 'number', 'min' => 0, 'max' => 51),
			));
?>
		</div>

		<div class="tab-panel" role="tabpanel" id="panel-overlay" aria-labelledby="tab-overlay" hidden>
<?php
			field(array('id' => 'annotation', 'cmd' => 'an', 'label' => 's.annotation', 'type' => 'text', 'maxlength' => 127, 'wide' => true, 'hint' => 's.annotation_hint',
				'extra' => '<button type="button" class="btn btn-ghost btn-sm" id="annotationDefault">' . icon('rotate-ccw') . h(t('s.annotation_default')) . '</button>'));
			field(array('id' => 'anno_background', 'cmd' => 'ab', 'label' => 's.anno_background', 'type' => 'toggle'));
			field(array('id' => 'anno_text_size', 'cmd' => 'as', 'label' => 's.anno_text_size', 'type' => 'range', 'min' => 0, 'max' => 99, 'default' => 50));
			colourGroup('at', 's.at', 'anno3_custom_text');
			colourGroup('ac', 's.ac', 'anno3_custom_background');
?>
		</div>

		<div class="tab-panel" role="tabpanel" id="panel-motion" aria-labelledby="tab-motion" hidden>
<?php
			field(array('id' => 'motion_external', 'cmd' => 'mx', 'label' => 's.motion_external', 'type' => 'select', 'options' => $options_mx, 'after' => 'reload', 'hint' => 's.motion_external_hint'));
			if (cfg('motion_external') == '1') {
				echo '<p style="margin-top:14px"><a class="btn btn-soft" href="motion.php">' . icon('external') . h(t('s.motion_ext_link')) . '</a></p>';
			} else {
				field(array('id' => 'vector_preview', 'cmd' => 'vp', 'label' => 's.vector_preview', 'type' => 'toggle', 'after' => 'reload', 'recordingLock' => true));
				field(array('id' => 'motion_noise', 'cmd' => 'mn', 'label' => 's.motion_noise', 'type' => 'number', 'min' => 1, 'max' => 2000, 'hint' => 's.motion_noise_hint'));
				field(array('id' => 'motion_threshold', 'cmd' => 'mt', 'label' => 's.motion_threshold', 'type' => 'number', 'min' => 1, 'max' => 32000, 'hint' => 's.motion_threshold_hint'));
				field(array('id' => 'motion_clip', 'cmd' => 'mc', 'label' => 's.motion_clip', 'type' => 'number', 'min' => 0, 'max' => 50, 'default' => 3, 'hint' => 's.motion_clip_hint'));
				field(array('id' => 'motion_image', 'cmd' => 'mi', 'label' => 's.motion_image', 'type' => 'text', 'hint' => 's.motion_image_hint'));
				field(array('id' => 'motion_initframes', 'cmd' => 'ms', 'label' => 's.motion_initframes', 'type' => 'number', 'min' => 0));
				field(array('id' => 'motion_startframes', 'cmd' => 'mb', 'label' => 's.motion_startframes', 'type' => 'number', 'min' => 1));
				field(array('id' => 'motion_stopframes', 'cmd' => 'me', 'label' => 's.motion_stopframes', 'type' => 'number', 'min' => 1));
				field(array('id' => 'motion_file', 'cmd' => 'mf', 'label' => 's.motion_file', 'type' => 'toggle'));
			}
?>
		</div>

		<div class="tab-panel" role="tabpanel" id="panel-system" aria-labelledby="tab-system" hidden>
<?php
			group('pv', 's.preview', array(
				array('id' => 'quality', 'label' => 's.quality', 'type' => 'number', 'min' => 1, 'max' => 100),
				array('id' => 'width', 'label' => 's.width', 'type' => 'number', 'min' => 128, 'max' => 1024),
				array('id' => 'divider', 'label' => 's.divider', 'type' => 'number', 'min' => 1, 'max' => 16),
			));
?>
			<div class="field">
				<label class="field-label" for="streamMode"><?php et('s.stream_mode'); ?></label>
				<div class="field-control">
					<select class="select" id="streamMode">
						<option value="Default-Stream"<?php echo $mjpegMode ? '' : ' selected'; ?>><?php et('s.stream_default'); ?></option>
						<option value="MJPEG-Stream"<?php echo $mjpegMode ? ' selected' : ''; ?>><?php et('s.stream_mjpeg'); ?></option>
					</select>
				</div>
			</div>
<?php
			field(array('id' => 'hdmi_preview', 'cmd' => 'hp', 'label' => 's.hdmi_preview', 'type' => 'toggle'));
			group('wd', 's.watchdog', array(
				array('id' => 'watchdog_interval', 'label' => 's.watchdog_interval', 'type' => 'number', 'min' => 0, 'step' => 0.1, 'scale' => 10),
				array('id' => 'watchdog_errors', 'label' => 's.watchdog_errors', 'type' => 'number', 'min' => 0),
			));
			field(array('id' => 'log_size', 'cmd' => 'ls', 'label' => 's.log_size', 'type' => 'number', 'min' => 0, 'default' => 5000, 'hint' => 's.log_size_hint'));
?>
			<div class="field-group">
				<div class="field-label"><?php et('s.macros'); ?></div>
				<div class="field-hint"><?php et('s.macros_hint'); ?></div>
				<div class="stack" style="gap:8px">
<?php foreach ($macros as $i => $macro) {
					$value = cfg($macro);
					$enabled = substr($value, 0, 1) != '-';
					if (!$enabled) $value = substr($value, 1);
?>
					<div class="row macro-row" data-index="<?php echo $i; ?>">
						<label class="switch" title="<?php echo h($macro); ?>"><input type="checkbox" class="macro-enabled"<?php echo $enabled ? ' checked' : ''; ?>><span class="track"></span><span class="sr-only"><?php echo h($macro); ?></span></label>
						<code style="min-width:7.5em"><?php echo h($macro); ?></code>
						<input class="input input-sm macro-value" type="text" autocomplete="off" value="<?php echo h($value); ?>" aria-label="<?php echo h($macro); ?>" style="flex:1;min-width:120px">
						<button type="button" class="btn btn-soft btn-sm btn-icon macro-apply" title="<?php et('btn.apply'); ?>" aria-label="<?php et('btn.apply'); ?>"><?php echo icon('check'); ?></button>
					</div>
<?php } ?>
				</div>
			</div>
			<div class="field-group">
				<div class="field-label"><?php et('s.time'); ?></div>
				<div class="field-hint"><?php et('s.time_hint'); ?></div>
				<div class="row">
					<button type="button" class="btn btn-soft btn-sm" id="timeSync"><?php echo icon('clock'); ?><?php et('s.time_sync'); ?></button>
					<input class="input input-sm" type="text" id="timestr" placeholder="<?php et('s.time_manual'); ?>" style="flex:1;min-width:160px">
					<button type="button" class="btn btn-soft btn-sm" id="timeSet"><?php et('btn.apply'); ?></button>
				</div>
			</div>
			<div class="field-group">
				<div class="field-label"><?php et('s.danger'); ?></div>
				<div class="danger-zone" style="margin-top:8px">
					<button type="button" class="btn btn-soft-danger btn-sm" data-system="reset"><?php echo icon('rotate-ccw'); ?><?php et('s.reset'); ?></button>
					<button type="button" class="btn btn-soft-danger btn-sm" data-system="reboot"><?php echo icon('refresh'); ?><?php et('s.reboot'); ?></button>
					<button type="button" class="btn btn-danger btn-sm" data-system="shutdown"><?php echo icon('power'); ?><?php et('s.shutdown'); ?></button>
				</div>
			</div>
			<div class="field-group">
				<div class="field-label"><?php et('s.help'); ?></div>
				<ul class="link-list">
					<li><a href="https://github.com/DensenDeluxe/RPi_Cam_Web_Interface_Rework" target="_blank" rel="noopener"><?php echo icon('github'); ?>GitHub (Rework)</a></li>
					<li><a href="https://github.com/silvanmelchior/RPi_Cam_Web_Interface" target="_blank" rel="noopener"><?php echo icon('github'); ?>GitHub (Original)</a></li>
					<li><a href="https://elinux.org/RPi-Cam-Web-Interface" target="_blank" rel="noopener"><?php echo icon('book'); ?>Wiki</a></li>
					<li><a href="https://forums.raspberrypi.com/viewtopic.php?t=63276" target="_blank" rel="noopener"><?php echo icon('message'); ?>Forum</a></li>
				</ul>
				<p class="muted small" style="margin-top:10px"><?php echo h(CAM_STRING); ?><br>
					<?php if ($camInfo) { echo h(t('s.camera_info', array('model' => isset($camInfo['model']) ? $camInfo['model'] : '?', 'backend' => (isset($camInfo['backend']) ? $camInfo['backend'] : '?') . ', ' . (isset($camInfo['daemon']) ? $camInfo['daemon'] : '')))); } else { et('s.camera_legacy'); } ?></p>
			</div>
		</div>
	</section>
<?php } ?>
</div>
<?php
	pageFooter(array('js/live.js'), array('live' => array(
		'mjpeg' => $mjpegMode,
		'videoFps' => (int)cfg('video_fps', 25),
		'divider' => (int)cfg('divider', 1),
		'panMode' => $panMode,
		'pan' => $panInit[0],
		'tilt' => $panInit[1],
		'fullscreen' => cfg('fullscreen') == 1,
	)));
?>
