<?php
if (!defined('ABSPATH')) exit;

/**
 * Default settings. Missing keys in saved settings fall back to these,
 * so upgrading never needs a settings migration.
 */
function mm_ca_default_settings() {
  return array(
    'to_email'         => 'jon@meridian-media.co.uk',
    'subject_prefix'   => '🚨 This website has a critical error 🚨',
    'throttle_minutes' => 30,
    'include_request'  => 1,
    'include_user'     => 1,
    'hosting_logs_url' => '',
    'only_frontend'    => 0,
    'ignore_cli'       => 1,
    'ignore_wp_cron'   => 1,
    // Background requests (cron, MailPoet router, XML-RPC, CLI) only alert when the
    // same error repeats this many times inside the window.
    'repeat_count'     => 3,
    'repeat_window'    => 30,
    // One slug per line. A fatal in these plugins is logged but only emailed if it
    // breaks a normal page (front-end or login), not in cron/admin/ajax/background.
    'quiet_plugins'    => "mailpoet\nmailpoet-premium",
    // One per line. Plain text, case-insensitive, matched against message and file path.
    'ignore_patterns'  => '',
  );
}

function mm_ca_get_settings() {
  $saved = function_exists('get_option') ? get_option('mmca_settings', array()) : array();
  if (!is_array($saved)) $saved = array();
  return array_merge(mm_ca_default_settings(), $saved);
}

function mm_ca_lines($text) {
  $out = array();
  foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
    $line = trim($line);
    if ($line !== '') $out[] = $line;
  }
  return $out;
}

/**
 * Work out which plugin/theme a path belongs to. Returns array(type, slug).
 */
function mm_ca_slug_from_path($path) {
  $path = str_replace('\\', '/', (string) $path);
  if (preg_match('#/(?:mu-plugins|plugins)/([^/\s:()\'"]+)#', $path, $m)) {
    return array('plugin', strtolower(preg_replace('/\.php$/i', '', $m[1])));
  }
  if (preg_match('#/themes/([^/\s:()\'"]+)#', $path, $m)) {
    return array('theme', strtolower($m[1]));
  }
  return array('', '');
}

/**
 * What kind of request was this? Used to decide whether a visitor/admin would have seen it.
 * Returns one of: cli, cron, router, xmlrpc, rest, ajax, login, admin, frontend
 */
function mm_ca_detect_context() {
  $sapi = function_exists('php_sapi_name') ? php_sapi_name() : '';
  if ($sapi === 'cli' || $sapi === 'phpdbg' || (defined('WP_CLI') && WP_CLI)) return 'cli';
  if (defined('DOING_CRON') && DOING_CRON) return 'cron';

  $uri    = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
  $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';

  // Machine-driven requests that are not WP-Cron (MailPoet's own cron daemon, Action Scheduler runner).
  $markers = array('mailpoet_router', 'as_async_request_queue_runner');
  if (function_exists('apply_filters')) $markers = (array) apply_filters('mmca_background_markers', $markers);
  foreach ($markers as $marker) {
    if ($marker !== '' && (stripos($uri, $marker) !== false || $action === $marker)) return 'router';
  }

  if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return 'xmlrpc';
  if ((defined('REST_REQUEST') && REST_REQUEST) || strpos($uri, '/wp-json/') !== false || isset($_GET['rest_route'])) return 'rest';
  if (defined('DOING_AJAX') && DOING_AJAX) return 'ajax';

  $script = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : '';
  if ($script === 'wp-login.php') return 'login';
  if (defined('WP_ADMIN') && WP_ADMIN) return 'admin';
  return 'frontend';
}

/**
 * True while a manual plugin/theme/core install or update is running.
 * Files are being swapped mid-request so "cannot redeclare" style fatals are expected noise.
 */
function mm_ca_is_updating() {
  if (defined('WP_INSTALLING') && WP_INSTALLING) return true;
  if (defined('ABSPATH') && file_exists(ABSPATH . '.maintenance')) return true;

  $script = isset($_SERVER['SCRIPT_NAME']) ? basename((string) $_SERVER['SCRIPT_NAME']) : '';
  if (in_array($script, array('update.php', 'update-core.php', 'plugin-install.php', 'theme-install.php', 'upgrade.php'), true)) return true;

  $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
  $update_actions = array('update-plugin', 'update-theme', 'install-plugin', 'install-theme', 'upgrade-plugin', 'upgrade-theme', 'update-selected', 'delete-plugin', 'delete-theme');
  return in_array($action, $update_actions, true);
}

/**
 * Same real-world error should produce the same signature every time.
 * Numbers are stripped (memory errors say "tried to allocate N bytes"), and for
 * memory / timeout errors the file and line are ignored because they land anywhere.
 */
function mm_ca_signature($type, $message, $file, $line, $culprit_slug) {
  $first = strtok((string) $message, "\n");
  $first = ($first === false) ? '' : $first;
  $first = preg_replace('/\b0x[0-9a-f]+\b/i', '#', $first);
  $first = preg_replace('/\d+/', '#', $first);

  if (preg_match('/Allowed memory size|Maximum execution time/i', (string) $message)) {
    return md5($type . '|' . $first . '|' . $culprit_slug);
  }
  return md5($type . '|' . $first . '|' . $file . '|' . $line);
}

/**
 * Decide whether this fatal deserves an email.
 * Returns array(mode, reason) where mode is:
 *   alert  - email now (subject to throttle)
 *   repeat - email only if it keeps happening
 *   log    - record it, never email
 */
function mm_ca_classify($ctx, $file_slug, $message, $file, $settings) {
  foreach (mm_ca_lines($settings['ignore_patterns']) as $pattern) {
    if (stripos($message, $pattern) !== false || stripos($file, $pattern) !== false) {
      return array('log', 'matches ignore pattern "' . $pattern . '"');
    }
  }

  if (mm_ca_is_updating()) {
    return array('log', 'happened during a plugin/theme/core update');
  }

  // Quiet plugins only matter if they break a page a visitor can see.
  if ($file_slug !== '' && !in_array($ctx, array('frontend', 'login'), true)) {
    $quiet = array_map('strtolower', mm_ca_lines($settings['quiet_plugins']));
    if (in_array($file_slug, $quiet, true)) {
      return array('log', 'quiet plugin "' . $file_slug . '" failed in a ' . $ctx . ' request, not a visitor page');
    }
  }

  if ($ctx === 'cron') {
    return !empty($settings['ignore_wp_cron'])
      ? array('log', 'WP-Cron request (ignored by setting)')
      : array('repeat', 'WP-Cron request');
  }

  if ($ctx === 'admin' && !empty($settings['only_frontend'])) {
    return array('log', 'admin request (front-end only setting is on)');
  }

  if (in_array($ctx, array('cli', 'router', 'xmlrpc'), true)) {
    return array('repeat', 'background request (' . $ctx . ')');
  }

  return array('alert', 'visitor/admin-facing request (' . $ctx . ')');
}

/**
 * Send the alert. Handles the case where the fatal happened while plugins were still
 * loading: pluggable.php (which defines wp_mail) is loaded AFTER plugins, so wp_mail
 * does not exist yet. Falls back to PHP mail() if wp_mail is unavailable or fails.
 * Returns: sent | sent_fallback | failed
 */
function mm_ca_send_mail($to, $subject, $html) {
  try {
    if (!function_exists('wp_mail') && defined('ABSPATH') && defined('WPINC')) {
      $pluggable = ABSPATH . WPINC . '/pluggable.php';
      if (is_readable($pluggable)) require_once $pluggable;
    }
    if (function_exists('wp_mail')) {
      if (wp_mail($to, $subject, $html, array('Content-Type: text/html; charset=UTF-8'))) return 'sent';
    }
  } catch (\Throwable $e) {
    // fall through to fallback
  }

  try {
    $host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^a-z0-9.\-]/i', '', (string) $_SERVER['HTTP_HOST']) : '';
    if ($host === '') $host = 'localhost';
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: WordPress <wordpress@" . $host . ">\r\n";
    $encoded_subject = '=?UTF-8?B?' . base64_encode((string) $subject) . '?=';
    if (function_exists('mail') && @mail($to, $encoded_subject, $html, $headers)) return 'sent_fallback';
  } catch (\Throwable $e) {
    // nothing else we can do
  }
  return 'failed';
}

function mm_ca_build_email($d) {
  $esc = function ($v) {
    return function_exists('esc_html') ? esc_html((string) $v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
  };
  $link = function ($v) {
    return function_exists('esc_url') ? esc_url($v) : htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
  };
  $row = function ($label, $value_html) {
    return '<tr><td style="padding:4px 12px 4px 0;vertical-align:top;"><strong>' . $label . '</strong></td><td style="padding:4px 0;">' . $value_html . '</td></tr>';
  };

  $b  = '<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5;">';
  $b .= '<h2 style="margin:0 0 12px 0;">A fatal/critical PHP error was detected</h2>';
  $b .= '<table cellpadding="0" cellspacing="0" style="border-collapse: collapse;">';
  $b .= $row('Site', $esc($d['site_name']));
  if ($d['home']) $b .= $row('Home', '<a href="' . $link($d['home']) . '">' . $esc($d['home']) . '</a>');
  if ($d['url'])  $b .= $row('Request URL', '<a href="' . $link($d['url']) . '">' . $esc($d['url']) . '</a>');
  $b .= $row('Time (UTC)', $esc(gmdate('Y-m-d H:i:s')));
  $b .= $row('Type', $esc($d['type_label']) . ' (' . (int) $d['type'] . ')');
  $b .= $row('Request kind', $esc($d['context']));
  if ($d['culprit']) $b .= $row('Likely culprit', '<strong>' . $esc($d['culprit']) . '</strong>');
  $b .= $row('Why alerted', $esc($d['reason']));
  if (!empty($d['suppressed'])) $b .= $row('Repeats since last alert', (int) $d['suppressed'] . ' similar error(s) were suppressed');
  $b .= '</table>';

  $b .= '<hr style="margin:14px 0;">';
  $b .= '<p style="margin:0 0 8px 0;"><strong>Message</strong><br>' . nl2br($esc($d['message'])) . '</p>';
  $b .= '<p style="margin:0 0 8px 0;"><strong>File</strong><br>' . $esc($d['file']) . '</p>';
  $b .= '<p style="margin:0 0 8px 0;"><strong>Line</strong><br>' . (int) $d['line'] . '</p>';

  if (!empty($d['include_request'])) {
    $b .= '<hr style="margin:14px 0;">';
    $b .= '<p style="margin:0 0 6px 0;"><strong>Request details</strong></p><ul style="margin:0 0 0 18px; padding:0;">';
    $b .= '<li><strong>Request URI:</strong> ' . $esc(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '(unknown)') . '</li>';
    $b .= '<li><strong>Method:</strong> ' . $esc(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '(unknown)') . '</li>';
    $b .= '<li><strong>IP:</strong> ' . $esc(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '(unknown)') . '</li>';
    $b .= '<li><strong>User agent:</strong> ' . $esc(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '(unknown)') . '</li>';
    $b .= '</ul>';
  }
  if (!empty($d['include_user'])) {
    $b .= '<p style="margin:10px 0 0 0;"><strong>User ID:</strong> ' . (int) $d['user_id'] . '</p>';
  }

  $b .= '<hr style="margin:14px 0;"><p style="margin:0 0 6px 0;"><strong>Quick links</strong></p><ul style="margin:0 0 0 18px; padding:0;">';
  if ($d['admin_log_url'])   $b .= '<li><a href="' . $link($d['admin_log_url']) . '">View logs in wp-admin</a> (log entry #' . (int) $d['log_id'] . ')</li>';
  if ($d['site_health_url']) $b .= '<li><a href="' . $link($d['site_health_url']) . '">Site Health debug</a></li>';
  if ($d['hosting_logs_url']) $b .= '<li><a href="' . $link($d['hosting_logs_url']) . '">Hosting error logs (20i)</a></li>';
  if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG && defined('WP_CONTENT_DIR')) {
    $b .= '<li><strong>WP debug log path:</strong> ' . $esc(rtrim(WP_CONTENT_DIR, '/') . '/debug.log') . '</li>';
  }
  $b .= '</ul>';

  if (!empty($d['culprit_plugin_slug'])) {
    $b .= '<p style="margin:14px 0 0 0;color:#555;">If the site is down: rename <code>wp-content/plugins/' . $esc($d['culprit_plugin_slug']) . '</code> to <code>' . $esc($d['culprit_plugin_slug']) . '-off</code> (File Manager/SFTP) to deactivate it.</p>';
  }
  $b .= '</div>';
  return $b;
}

/**
 * Main fatal handler. Classifies, logs, and (when warranted) emails.
 * $opts: force (bool, used by the admin "test alert" button), context (string override)
 * Returns array(decision, mail, log_id) - handy for the test button.
 */
function mm_ca_handle_fatal(array $error, array $opts = array()) {
  $result = array('decision' => '', 'mail' => '', 'log_id' => 0);

  try {
    // Fatals from memory/time limits leave very little headroom to work with.
    if (function_exists('set_time_limit')) @set_time_limit(30);
    $message = isset($error['message']) ? (string) $error['message'] : '';
    if (stripos($message, 'Allowed memory size') !== false) @ini_set('memory_limit', '512M');

    $s     = mm_ca_get_settings();
    $force = !empty($opts['force']);
    $ctx   = isset($opts['context']) ? (string) $opts['context'] : mm_ca_detect_context();

    if ($ctx === 'cli' && !empty($s['ignore_cli']) && !$force) return $result;

    $file = isset($error['file']) ? (string) $error['file'] : '';
    $line = isset($error['line']) ? (int) $error['line'] : 0;
    $type = isset($error['type']) ? (int) $error['type'] : 0;

    // Who is to blame? File path is authoritative; the stack trace in the message is a hint only.
    list($file_type, $file_slug) = mm_ca_slug_from_path($file);
    $culprit = '';
    $culprit_plugin_slug = '';
    if ($file_slug !== '') {
      $culprit = $file_type . ':' . $file_slug;
      if ($file_type === 'plugin') $culprit_plugin_slug = $file_slug;
    } else {
      list($msg_type, $msg_slug) = mm_ca_slug_from_path($message);
      if ($msg_slug !== '') $culprit = $msg_type . ':' . $msg_slug . ' (via stack trace)';
    }

    $sig = mm_ca_signature($type, $message, $file, $line, $file_slug);
    $now = time();

    // Occurrence counters: drives "alert only if it keeps happening" and throttling.
    $state = get_option('mmca_state', array());
    if (!is_array($state)) $state = array();
    foreach ($state as $k => $v) {
      if (!is_array($v) || ($now - (isset($v['l']) ? (int) $v['l'] : 0)) > 7 * 86400) unset($state[$k]);
    }
    $e = isset($state[$sig]) ? $state[$sig] : array('f' => $now, 'n' => 0, 's' => 0, 'p' => 0, 'l' => $now);
    $window = max(1, (int) $s['repeat_window']) * 60;
    if (($now - (int) $e['f']) > $window) { $e['f'] = $now; $e['n'] = 0; }
    $e['n']++;
    $e['l'] = $now;

    if ($force) {
      $class = array('alert', 'test alert from wp-admin');
    } else {
      $class = mm_ca_classify($ctx, $file_slug, $message, $file, $s);
    }
    $mode   = $class[0];
    $reason = $class[1];
    $send   = false;

    if ($mode === 'alert') {
      $send = true;
    } elseif ($mode === 'repeat') {
      $need = max(1, (int) $s['repeat_count']);
      if ((int) $e['n'] >= $need) {
        $send = true;
        $reason .= ' - repeated ' . (int) $e['n'] . 'x in ' . (int) $s['repeat_window'] . ' min';
      } else {
        $reason .= ' - seen ' . (int) $e['n'] . ' of ' . $need . ' times needed to alert';
      }
    }

    $suppressed = (int) $e['p'];
    if ($send && !$force) {
      $gap = max(0, (int) $s['throttle_minutes']) * 60;
      if ($gap > 0 && !empty($e['s']) && ($now - (int) $e['s']) < $gap) {
        $send = false;
        $e['p'] = $suppressed + 1;
        $reason = 'throttled (already alerted ' . (int) round(($now - (int) $e['s']) / 60) . ' min ago)';
      }
    }

    $decision = ($send ? 'ALERT: ' : 'logged only: ') . $reason;
    $result['decision'] = $decision;

    $url = '';
    if (!empty($_SERVER['HTTP_HOST'])) {
      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $req    = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
      $url    = $scheme . '://' . $_SERVER['HTTP_HOST'] . $req;
    }

    $user_id = 0;
    if (!empty($s['include_user']) && function_exists('get_current_user_id')) {
      $user_id = (int) get_current_user_id();
    }

    // Log (storm protection: stop writing rows if one error floods)
    $log_id = 0;
    if ((int) $e['n'] <= 100 && class_exists('MMCA_Logger')) {
      $log_id = (int) MMCA_Logger::log(array(
        'signature'   => $sig,
        'error_type'  => $type,
        'message'     => $message,
        'file'        => $file,
        'line'        => $line,
        'url'         => $url,
        'user_id'     => $user_id,
        'php_sapi'    => function_exists('php_sapi_name') ? php_sapi_name() : '',
        'context'     => $ctx,
        'culprit'     => $culprit,
        'decision'    => $decision,
        // 'pending' stays on the row if the process dies while sending: a visible clue that mail is broken.
        'mail_status' => $send ? 'pending' : 'n/a',
      ));
    }
    $result['log_id'] = $log_id;

    if ($send) {
      $to = !empty($s['to_email']) ? sanitize_email($s['to_email']) : '';
      if (!$to && function_exists('get_option')) $to = sanitize_email(get_option('admin_email'));

      if ($to && is_email($to)) {
        $site_name = function_exists('get_bloginfo') ? wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) : 'WordPress site';
        $subject   = trim((string) $s['subject_prefix']);
        $subject   = ($subject !== '' ? $subject : '🚨 Critical error') . ' - ' . $site_name;
        if ($culprit_plugin_slug !== '') $subject .= ' [' . $culprit_plugin_slug . ']';

        $body = mm_ca_build_email(array(
          'site_name'           => $site_name,
          'home'                => function_exists('home_url') ? home_url() : '',
          'url'                 => $url,
          'type'                => $type,
          'type_label'          => (class_exists('MMCA_Logger') ? MMCA_Logger::error_type_label($type) : 'Fatal'),
          'context'             => $ctx,
          'culprit'             => $culprit,
          'culprit_plugin_slug' => $culprit_plugin_slug,
          'reason'              => $reason,
          'suppressed'          => $suppressed,
          'message'             => $message,
          'file'                => $file,
          'line'                => $line,
          'include_request'     => !empty($s['include_request']),
          'include_user'        => !empty($s['include_user']),
          'user_id'             => $user_id,
          'log_id'              => $log_id,
          'admin_log_url'       => function_exists('admin_url') ? admin_url('tools.php?page=mm-critical-alerts') : '',
          'site_health_url'     => function_exists('admin_url') ? admin_url('site-health.php?tab=debug') : '',
          'hosting_logs_url'    => !empty($s['hosting_logs_url']) ? esc_url_raw($s['hosting_logs_url']) : '',
        ));

        $mail = mm_ca_send_mail($to, $subject, $body);
      } else {
        $mail = 'failed';
      }

      $result['mail'] = $mail;
      if ($log_id && class_exists('MMCA_Logger')) MMCA_Logger::update_mail_status($log_id, $mail);

      if ($mail !== 'failed') {
        $e['s'] = $now;
        $e['p'] = 0;
      }
    }

    $state[$sig] = $e;
    update_option('mmca_state', $state, false);
  } catch (\Throwable $t) {
    // The alerting code must never be the thing that breaks a request.
  }

  return $result;
}

function mm_ca_shutdown_capture() {
  // Free the memory reserved at startup so we have room to work after an out-of-memory fatal.
  unset($GLOBALS['mm_ca_reserve']);

  if (!function_exists('error_get_last')) return;
  $error = error_get_last();
  if (!$error || empty($error['type'])) return;

  $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR);
  if (!in_array((int) $error['type'], $fatal_types, true)) return;

  mm_ca_handle_fatal($error);
}
