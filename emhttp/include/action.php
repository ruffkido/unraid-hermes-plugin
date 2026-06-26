<?php
/* Hermes plugin — AJAX action endpoint
 * Lives at /usr/local/emhttp/plugins/hermes/include/action.php
 * Called by Hermes.page via $.post; always returns JSON.
 */

header('Content-Type: application/json');

$PLUGIN_DIR = '/boot/config/plugins/hermes';
$CFG        = "$PLUGIN_DIR/hermes.cfg";

// Resolve HERMES_HOME from plugin settings (default for legacy installs)
$HERMES_HOME = '/boot/config/plugins/hermes/.hermes';
if (is_file($CFG)) {
  foreach (file($CFG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match('/^\s*HERMES_HOME_PATH="?([^"]*)"?\s*$/', $line, $m)) $HERMES_HOME = $m[1];
  }
}
$YAML       = "$HERMES_HOME/config.yaml";
$ENV        = "$HERMES_HOME/.env";


function respond($ok, $msg, $extra = []) {
  echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra));
  exit;
}

function atomic_write($path, $content, $mode = 0644) {
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $tmp = $path.'.tmp';
  if (file_put_contents($tmp, $content) === false) return false;
  chmod($tmp, $mode);
  return rename($tmp, $path);
}

function load_cfg($path) {
  $out = ['WEBUI_ENABLED'=>'yes', 'GATEWAY_ENABLED'=>'no', 'WEBUI_PORT'=>'9000', 'HERMES_HOME_PATH'=>'/boot/config/plugins/hermes/.hermes', 'HERMES_HOME_MIGRATED_FROM'=>''];
  if (!is_file($path)) return $out;
  foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match('/^\s*([A-Z_]+)="?([^"]*)"?\s*$/', $line, $m)) $out[$m[1]] = $m[2];
  }
  return $out;
}

function clean_cfg_value($v) {
  return preg_replace('/[^A-Za-z0-9_\-\.]/', '', (string)$v);
}

function service_status($name) {
  $rc = "/etc/rc.d/rc.hermes-$name";
  if (!is_executable($rc)) return ['installed'=>false, 'running'=>false, 'pid'=>null];
  exec("$rc status 2>&1", $out, $code);
  $pid = null;
  foreach ($out as $l) if (preg_match('/PID:\s*(\d+)/', $l, $m)) $pid = (int)$m[1];
  return ['installed'=>true, 'running'=>$code===0, 'pid'=>$pid];
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

  case 'status':
    respond(true, '', [
      'cfg'     => load_cfg($CFG),
      'webui'   => service_status('webui'),
      'gateway' => service_status('gateway'),
    ]);

  case 'service':
    $svc = $_POST['svc'] ?? '';
    $op  = $_POST['op']  ?? '';
    if (!in_array($svc, ['webui','gateway'], true)) respond(false, 'Bad service');
    if (!in_array($op,  ['start','stop','restart'], true)) respond(false, 'Bad op');
    $rc = "/etc/rc.d/rc.hermes-$svc";
    if (!is_executable($rc)) respond(false, "Service script not installed: $rc");
    exec("$rc $op 2>&1", $out, $code);
    respond($code === 0,
      ($code === 0 ? ucfirst($svc).' '.$op.'ed.' : 'Failed: '.implode(' / ', $out)),
      ['log' => implode("\n", $out)]
    );

  case 'save_cfg':
    $old = load_cfg($CFG);
    $old_path = $old['HERMES_HOME_PATH'] ?? '/boot/config/plugins/hermes/.hermes';
    $new_path = clean_cfg_value($_POST['HERMES_HOME_PATH'] ?? '/boot/config/plugins/hermes/.hermes');
    $migrate_from = "";

    if ($new_path !== $old_path) {
      if (!is_dir($new_path)) @mkdir($new_path, 0700, true);
      if (is_dir($old_path) && ($files = glob($old_path.'/*')) && count($files)) {
        exec('cp -a '.escapeshellarg($old_path).'/* '.escapeshellarg($new_path).'/ 2>&1', $out, $code);
        if ($code !== 0) respond(false, 'Migration failed: '.implode('; ', $out));
        $migrate_from = $old_path;
      }
    } else {
      $migrate_from = $old['HERMES_HOME_MIGRATED_FROM'] ?? "";
    }

    $body  = "# Hermes plugin settings (persistent) — managed by Settings/Hermes\n";
    $body .= 'WEBUI_ENABLED="'.clean_cfg_value($_POST['WEBUI_ENABLED']   ?? 'no'  )."\n";
    $body .= 'GATEWAY_ENABLED="'.clean_cfg_value($_POST['GATEWAY_ENABLED'] ?? 'no'  )."\n";
    $body .= 'WEBUI_PORT="'.clean_cfg_value($_POST['WEBUI_PORT']      ?? '9000')."\n";
    $body .= 'HERMES_HOME_PATH="'.$new_path."\n";
    $body .= 'HERMES_HOME_MIGRATED_FROM="'.$migrate_from."\n";
    if (!atomic_write($CFG, $body, 0644)) respond(false, 'Could not write hermes.cfg');

    if ($new_path !== $old_path) {
      exec('/etc/rc.d/rc.hermes-webui restart 2>&1', $out_webui, $w);
      exec('/etc/rc.d/rc.hermes-gateway restart 2>&1', $out_gw,   $g);
      respond(true, 'Settings saved. Files migrated and services restarted.', [
        'migrated_from' => $migrate_from,
        'new_path'      => $new_path,
      ]);
    }
    respond(true, 'Settings saved.');

  case 'save_yaml':
    if (!atomic_write($YAML, (string)($_POST['content'] ?? ''), 0644)) respond(false, 'Could not write config.yaml');
    respond(true, 'config.yaml saved.');

  case 'save_env':
    if (!atomic_write($ENV, (string)($_POST['content'] ?? ''), 0600)) respond(false, 'Could not write .env');
    respond(true, '.env saved.');

  case 'cleanup_migration':
    $cfg = load_cfg($CFG);
    $old = $cfg['HERMES_HOME_MIGRATED_FROM'] ?? '';
    if (empty($old)) respond(false, 'No migration pending.');
    if (!is_dir($old)) respond(false, 'Old directory already removed: '.htmlspecialchars($old));
    exec('rm -rf '.escapeshellarg($old).' 2>&1', $out, $code);
    if ($code !== 0) respond(false, 'Cleanup failed: '.implode('; ', $out));
    // Clear the migrated-from marker
    $body = file_get_contents($CFG);
    $body = preg_replace('/^HERMES_HOME_MIGRATED_FROM=.*$/m', 'HERMES_HOME_MIGRATED_FROM=""', $body);
    if ($body === null || !atomic_write($CFG, $body, 0644)) respond(false, 'Could not update hermes.cfg');
    respond(true, 'Old directory removed: '.htmlspecialchars($old));

  case 'reload_file':
    $which = $_POST['which'] ?? '';
    $path  = $which === 'yaml' ? $YAML : ($which === 'env' ? $ENV : null);
    if (!$path) respond(false, 'Bad file');
    respond(true, '', ['content' => is_file($path) ? file_get_contents($path) : '']);

  default:
    respond(false, 'Unknown action');
}
