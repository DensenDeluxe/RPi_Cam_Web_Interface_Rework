<?php
   // Settings of the external motion daemon (motion_external = 1), edited through its HTTP control port.
   define('BASE_DIR', dirname(__FILE__));
   require_once(BASE_DIR.'/config.php');
   require_once(BASE_DIR.'/ui.php');

   define('MOTION_URL', "http://127.0.0.1:6642/0/");
   define('MOTION_CONFIGBACKUP', "motionPars.json");
   define('MOTION_PARS', "motionPars");

   requireUserLevel(USERLEVEL_MAX);

   $filterPars = array("switchfilter","threshold","threshold_tune","noise_level","noise_tune","despeckle","despeckle_filter","area_detect","mask_file","smart_mask_speed","lightswitch","minimum_motion_frames","framerate","minimum_frame_time","netcam_url","netcam_userpass","gap","event_gap","on_event_start","on_event_end","on_motion_detected","on_area_detected");

   function checkMotion() {
      $pids = array();
      exec("pgrep motion", $pids);
      return !empty($pids);
   }

   function motionRequest($path) {
      $ctx = stream_context_create(array('http' => array('timeout' => 5)));
      return @file_get_contents(MOTION_URL . $path, false, $ctx);
   }

   function motionPars() {
      $list = motionRequest("config/list");
      if ($list === false) return array();
      $pars = @parse_ini_string($list, false, INI_SCANNER_RAW);
      return is_array($pars) ? $pars : array();
   }

   function setMotionPar($k, $v) {
      motionRequest("config/set?" . rawurlencode($k) . "=" . urlencode($v));
   }

   function writeMotionPars() {
      motionRequest("config/write");
   }

   //restart and fetch updated config list
   function restartMotion() {
      motionRequest("action/restart");
      for ($retry = 20; $retry > 0; $retry--) {
         sleep(1);
         if (motionRequest("config/list")) return;
      }
   }

   function redirectMotion($done, $all) {
      header('Location: motion.php?done=' . urlencode($done) . ($all ? '&all=1' : ''), true, 303);
      exit;
   }

   $motionReady = checkMotion();
   $showAll = isset($_GET['all']) || isset($_POST['all']);
   $motionPars = $motionReady ? motionPars() : array();

   if ($motionReady && $_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
      rejectCrossSiteRequest();
      switch($_POST['action']) {
         case 'save':
            $changed = false;
            $values = isset($_POST['par']) && is_array($_POST['par']) ? $_POST['par'] : array();
            foreach($values as $key => $value) {
               if (array_key_exists($key, $motionPars) && $value != $motionPars[$key]) {
                  setMotionPar($key, $value);
                  $changed = true;
               }
            }
            if ($changed) {
               writeMotionPars();
               restartMotion();
               redirectMotion('saved', $showAll);
            }
            redirectMotion('unchanged', $showAll);
         case 'backup':
            file_put_contents(BASE_DIR . '/' . MOTION_CONFIGBACKUP, json_encode(array(MOTION_PARS => $motionPars)));
            redirectMotion('backup', $showAll);
         case 'restore':
            $restore = file_exists(BASE_DIR . '/' . MOTION_CONFIGBACKUP) ? json_decode(file_get_contents(BASE_DIR . '/' . MOTION_CONFIGBACKUP), true) : null;
            if (is_array($restore) && isset($restore[MOTION_PARS]) && is_array($restore[MOTION_PARS])) {
               foreach ($restore[MOTION_PARS] as $mKey => $mValue) {
                  setMotionPar($mKey, $mValue);
               }
               writeMotionPars();
               restartMotion();
               redirectMotion('restored', $showAll);
            }
            redirectMotion('nobackup', $showAll);
      }
   }

   $flashes = array('saved' => array('motion.saved', 'success'), 'unchanged' => array('motion.unchanged', 'info'),
      'backup' => array('motion.backed_up', 'success'), 'restored' => array('motion.restored', 'success'), 'nobackup' => array('motion.no_backup', 'error'));
   pageHeader(t('motion.title'), 'motion');
   if (isset($_GET['done']) && isset($flashes[$_GET['done']])) {
      echo flashMessage(t($flashes[$_GET['done']][0]), $flashes[$_GET['done']][1]);
   }
?>
<form method="post" action="motion.php<?php echo $showAll ? '?all=1' : ''; ?>">
<div class="page-head">
   <h1><?php et('motion.title'); ?></h1>
<?php if ($motionReady) { ?>
   <div class="searchbox"><?php echo icon('search'); ?><input class="input" type="search" id="parFilter" placeholder="<?php et('motion.filter'); ?>"></div>
   <a class="btn btn-ghost" href="motion.php<?php echo $showAll ? '' : '?all=1'; ?>"><?php echo icon('sliders'); ?><?php et($showAll ? 'motion.show_less' : 'motion.show_all'); ?></a>
   <button class="btn btn-ghost" type="submit" name="action" value="backup"><?php echo icon('download'); ?><?php et('btn.backup'); ?></button>
   <button class="btn btn-ghost" type="submit" name="action" value="restore" data-confirm="<?php et('btn.restore'); ?>?"><?php echo icon('upload'); ?><?php et('btn.restore'); ?></button>
   <button class="btn btn-primary" type="submit" name="action" value="save"><?php echo icon('save'); ?><?php et('btn.save'); ?></button>
<?php } ?>
</div>
<?php if (!$motionReady) { ?>
<div class="card empty">
   <?php echo icon('activity'); ?>
   <strong><?php et('motion.not_running'); ?></strong>
   <span><?php et('motion.not_running_hint'); ?></span>
</div>
<?php } else { ?>
<section class="card"><div class="card-body">
   <table class="kv-table" id="parTable">
<?php foreach ($motionPars as $key => $value) {
      if (!$showAll && !in_array($key, $filterPars)) continue; ?>
      <tr><td><label for="par_<?php echo h($key); ?>"><?php echo h($key); ?></label></td>
         <td><input class="input input-sm mono" type="text" autocomplete="off" spellcheck="false" id="par_<?php echo h($key); ?>" name="par[<?php echo h($key); ?>]" value="<?php echo h($value); ?>" data-initial="<?php echo h($value); ?>"></td></tr>
<?php } ?>
   </table>
</div></section>
<?php } ?>
</form>
<script>
(function () {
   var filter = document.getElementById('parFilter');
   var rows = Array.prototype.slice.call(document.querySelectorAll('#parTable tr'));
   if (filter) filter.addEventListener('input', function () {
      var q = filter.value.trim().toLowerCase();
      rows.forEach(function (tr) { tr.hidden = q !== '' && tr.textContent.toLowerCase().indexOf(q) < 0 && tr.querySelector('input').value.toLowerCase().indexOf(q) < 0; });
   });
   rows.forEach(function (tr) {
      var input = tr.querySelector('input');
      input.addEventListener('input', function () { tr.classList.toggle('changed', input.value !== input.getAttribute('data-initial')); });
   });
})();
</script>
<?php
   pageFooter();
?>
