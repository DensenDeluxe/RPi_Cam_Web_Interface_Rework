<?php
	// Minimal full window live view, e.g. for kiosk displays or embedding
	define('BASE_DIR', dirname(__FILE__));
	require_once(BASE_DIR . '/config.php');
	require_once(BASE_DIR . '/ui.php');

	pageHeader(t('nav.live'), 'min', array('bare' => true));
?>
<div class="stage" id="stage">
	<img id="mjpeg_dest" alt="<?php et('nav.live'); ?>" draggable="false">
	<span class="stage-badge" id="recBadge" hidden><span class="dot"></span><span>REC</span></span>
	<div class="stage-overlay" id="stageOverlay" hidden>
		<svg class="icon" aria-hidden="true"><use href="img/icons.svg#power"></use></svg>
		<strong></strong>
		<p></p>
	</div>
	<div class="stage-tools">
		<button type="button" class="icon-btn" id="fullscreenBtn" title="<?php et('live.fullscreen'); ?>" aria-label="<?php et('live.fullscreen'); ?>"><?php echo icon('maximize'); ?></button>
	</div>
</div>
<?php
	pageFooter(array('js/live.js'), array('live' => array(
		'mjpeg' => isset($_COOKIE['stream_mode']) && $_COOKIE['stream_mode'] == 'MJPEG-Stream',
		'videoFps' => (int)cfg('video_fps', 25),
		'divider' => (int)cfg('divider', 1),
		'panMode' => 0,
	)));
?>
