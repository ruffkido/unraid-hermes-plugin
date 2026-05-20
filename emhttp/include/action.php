<?php
/* Hermes plugin — AJAX action endpoint
 * Lives at /usr/local/emhttp/plugins/hermes/include/action.php
 * Called by Hermes.page via $.post; always returns JSON.
 */

header('Content-Type: application/json');

$PLUGIN_DIR = '/boot/config/plugins/hermes';
$CFG        = "$PLUGIN_DIR/hermes.cfg";
$YAML       = "$PLUGIN_DIR/.hermes/config.yaml";
$ENV        = "$PLUGIN_DIR/.hermes/.env";

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
  $out = ['WEBUI_ENABLED'=>'yes', 'GATEWAY_ENABLED'=>'no', 'WEBUI_PORT'=>'9000'];
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
    $body  = "# Hermes plugin settings (persistent) — managed by Settings/Hermes\n";
    $body .= 'WEBUI_ENABLED="'.clean_cfg_value($_POST['WEBUI_ENABLED']   ?? 'no'  )."\"\n";
    $body .= 'GATEWAY_ENABLED="'.clean_cfg_value($_POST['GATEWAY_ENABLED'] ?? 'no'  )."\"\n";
    $body .= 'WEBUI_PORT="'.clean_cfg_value($_POST['WEBUI_PORT']      ?? '9000')."\"\n";
    if (!atomic_write($CFG, $body, 0644)) respond(false, 'Could not write hermes.cfg');
    respond(true, 'Settings saved.');

  case 'save_yaml':
    if (!atomic_write($YAML, (string)($_POST['content'] ?? ''), 0644)) respond(false, 'Could not write config.yaml');
    respond(true, 'config.yaml saved.');

  case 'save_env':
    if (!atomic_write($ENV, (string)($_POST['content'] ?? ''), 0600)) respond(false, 'Could not write .env');
    respond(true, '.env saved.');

  case 'reload_file':
    $which = $_POST['which'] ?? '';
    $path  = $which === 'yaml' ? $YAML : ($which === 'env' ? $ENV : null);
    if (!$path) respond(false, 'Bad file');
    respond(true, '', ['content' => is_file($path) ? file_get_contents($path) : '']);

  default:
    respond(false, 'Unknown action');
}
