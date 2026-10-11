<?php
// Shared helpers for the web interface: escaping, translations, icons and the page frame.
if (!defined('BASE_DIR')) define('BASE_DIR', dirname(__FILE__));
require_once(BASE_DIR . '/config.php');

define('UI_LANGS', 'en,de');
// optional user stylesheet loaded after the built-in one
define('UI_CUSTOM_CSS', 'css/custom.css');

function h($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function uiLang() {
	static $lang = null;
	if ($lang !== null) return $lang;
	$available = explode(',', UI_LANGS);
	$lang = 'en';
	if (isset($_COOKIE['lang']) && in_array($_COOKIE['lang'], $available, true)) {
		$lang = $_COOKIE['lang'];
	} else if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
		foreach (explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']) as $part) {
			$code = strtolower(substr(trim($part), 0, 2));
			if (in_array($code, $available, true)) {
				$lang = $code;
				break;
			}
		}
	}
	return $lang;
}

function uiStrings() {
	static $strings = null;
	if ($strings === null) {
		$strings = include(BASE_DIR . '/lang/en.php');
		$lang = uiLang();
		if ($lang != 'en' && file_exists(BASE_DIR . "/lang/$lang.php")) {
			$strings = array_merge($strings, include(BASE_DIR . "/lang/$lang.php"));
		}
	}
	return $strings;
}

// translated text, {name} placeholders are replaced from $vars
function t($key, $vars = array()) {
	$strings = uiStrings();
	$text = isset($strings[$key]) ? $strings[$key] : $key;
	foreach ($vars as $k => $v) {
		$text = str_replace('{' . $k . '}', $v, $text);
	}
	return $text;
}

// echo translated and escaped text
function et($key, $vars = array()) {
	echo h(t($key, $vars));
}

function icon($name, $class = '') {
	return '<svg class="icon' . ($class !== '' ? ' ' . h($class) : '') . '" aria-hidden="true" focusable="false"><use href="img/icons.svg#' . h($name) . '"></use></svg>';
}

function jsonForScript($data) {
	return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

function assetUrl($path) {
	$file = BASE_DIR . '/' . $path;
	$v = file_exists($file) ? filemtime($file) : APP_VERSION;
	return h($path . '?v=' . $v);
}

function navItems() {
	$level = currentUserLevel();
	$items = array(array('live', 'index.php', 'aperture', t('nav.live')));
	if ($level >= (int)USERLEVEL_MINP) $items[] = array('media', 'preview.php', 'image', t('nav.media'));
	if ($level >= (int)USERLEVEL_MAX) {
		$items[] = array('schedule', 'schedule.php', 'calendar', t('nav.schedule'));
		if (cfg('motion_external') == '1') $items[] = array('motion', 'motion.php', 'activity', t('nav.motion'));
	}
	return $items;
}

// prints everything up to the opening of <main>
function pageHeader($title, $active, $options = array()) {
	$bodyClass = 'page-' . $active . (isset($options['bodyClass']) ? ' ' . $options['bodyClass'] : '');
	$fullTitle = $title . ' · ' . CAM_NAME . '@' . HOST_NAME;
	?><!DOCTYPE html>
<html lang="<?php echo h(uiLang()); ?>" data-theme="auto">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="color-scheme" content="light dark">
	<meta name="theme-color" content="#f4f6f9" media="(prefers-color-scheme: light)">
	<meta name="theme-color" content="#0e1116" media="(prefers-color-scheme: dark)">
	<title><?php echo h($fullTitle); ?></title>
	<link rel="icon" href="img/favicon.svg" type="image/svg+xml">
	<script>try{var t=localStorage.getItem('rpicam.theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t);}catch(e){}</script>
	<link rel="stylesheet" href="<?php echo assetUrl('css/app.css'); ?>">
<?php if (file_exists(BASE_DIR . '/' . UI_CUSTOM_CSS)) { ?>
	<link rel="stylesheet" href="<?php echo assetUrl(UI_CUSTOM_CSS); ?>">
<?php } ?>
</head>
<body class="<?php echo h($bodyClass); ?>">
<a class="skip-link" href="#main"><?php et('skip'); ?></a>
<?php if (empty($options['bare'])) { ?>
<header class="topbar">
	<a class="brand" href="<?php echo ROOT_PHP; ?>">
		<span class="brand-logo"><?php echo icon('camera'); ?></span>
		<span class="brand-text"><strong>RPi Cam</strong><small><?php echo h(CAM_NAME . '@' . HOST_NAME); ?></small></span>
	</a>
	<nav class="mainnav" aria-label="<?php et('nav.main'); ?>">
<?php foreach (navItems() as $item) {
		$isActive = ($item[0] == $active);
		echo '		<a class="navlink' . ($isActive ? ' active' : '') . '" href="' . h($item[1]) . '"' . ($isActive ? ' aria-current="page"' : '') . '>' . icon($item[2]) . '<span>' . h($item[3]) . "</span></a>\n";
	} ?>
	</nav>
	<div class="topbar-actions">
<?php if (!empty($options['statusPill'])) { ?>
		<span class="status-pill" id="statusPill" data-state="unknown" role="status"><span class="dot"></span><span class="label"></span></span>
<?php } ?>
		<button type="button" class="icon-btn" id="langToggle" data-lang="<?php echo uiLang() == 'de' ? 'en' : 'de'; ?>" title="<?php et('lang.toggle'); ?>" aria-label="<?php et('lang.toggle'); ?>"><span class="lang-code"><?php echo h(strtoupper(uiLang())); ?></span></button>
		<button type="button" class="icon-btn" id="themeToggle" title="<?php et('theme.toggle'); ?>" aria-label="<?php et('theme.toggle'); ?>"><?php echo icon('monitor', 'theme-auto') . icon('sun', 'theme-light') . icon('moon', 'theme-dark'); ?></button>
	</div>
</header>
<?php } ?>
<main id="main" class="main main-<?php echo h($active); ?>">
<?php
}

// closes <main>, adds shared UI elements and the scripts
function pageFooter($scripts = array(), $data = array()) {
	$js = array();
	foreach (uiStrings() as $k => $v) {
		if (strncmp($k, 'js.', 3) == 0) $js[substr($k, 3)] = $v;
	}
	$data['i18n'] = $js;
	$data['lang'] = uiLang();
	$data['userLevel'] = currentUserLevel();
	?>
</main>
<div class="toasts" id="toasts" aria-live="polite" aria-atomic="false"></div>
<dialog class="dialog" id="confirmDialog">
	<form method="dialog">
		<p class="dialog-text"></p>
		<div class="dialog-actions">
			<button class="btn btn-ghost" value="cancel" type="submit"></button>
			<button class="btn btn-primary" value="ok" type="submit"></button>
		</div>
	</form>
</dialog>
<script>window.RPI = <?php echo jsonForScript($data); ?>;</script>
<script src="<?php echo assetUrl('js/app.js'); ?>"></script>
<?php foreach ($scripts as $script) { ?>
<script src="<?php echo assetUrl($script); ?>"></script>
<?php } ?>
</body>
</html>
<?php
}

// small server side flash message rendered as toast by app.js
function flashMessage($text, $type = 'success') {
	return '<div class="flash" data-type="' . h($type) . '" hidden>' . h($text) . '</div>';
}
?>
