<?php
/**
 * Plugin Name: MM Critical Alerts
 * Description: Sends an immediate email alert when a fatal/critical PHP error occurs, and logs the error for review in wp-admin.
 * Version: 1.0.02
 * Author: Meridian Media
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

define('MMCA_VERSION', '1.0.0');
define('MMCA_PLUGIN_FILE', __FILE__);
define('MMCA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MMCA_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once MMCA_PLUGIN_DIR . 'includes/logger.php';
require_once MMCA_PLUGIN_DIR . 'includes/admin.php';

/*
  Register shutdown capture as early as possible.
  This is the key change. Do not register another shutdown handler later.
*/
if (!defined('MM_CA_REGISTERED')) {
  define('MM_CA_REGISTERED', true);
  register_shutdown_function('mm_ca_shutdown_capture');
}

function mm_ca_shutdown_capture() {
  if (!function_exists('error_get_last')) return;

  $error = error_get_last();
  if (!$error || empty($error['type'])) return;

  $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
  if (!in_array((int) $error['type'], $fatal_types, true)) return;

  // Delegate to the main handler
  if (function_exists('mm_ca_handle_fatal')) {
    mm_ca_handle_fatal($error);
  }
}

function mmca_mail_content_type() {
  return 'text/plain; charset=UTF-8';
}

/**
 * Main fatal handler. Logs and emails.
 * Keep this function "plain" so it can run at shutdown even if other hooks did not fire.
 */
function mm_ca_handle_fatal(array $error) {
  $settings = get_option('mmca_settings', array());
  $settings = is_array($settings) ? $settings : array();

  // Ignore CLI by default (WP CLI, cron jobs, etc)
  $sapi = function_exists('php_sapi_name') ? php_sapi_name() : '';
  if (!empty($settings['ignore_cli']) && ($sapi === 'cli' || $sapi === 'phpdbg')) {
    return;
  }

  // Ignore WP cron by default
  if (!empty($settings['ignore_wp_cron']) && defined('DOING_CRON') && DOING_CRON) {
    return;
  }

  // Optional: only check front end requests
  if (!empty($settings['only_frontend']) && function_exists('is_admin') && is_admin()) {
    return;
  }

  $message = isset($error['message']) ? (string) $error['message'] : '';
  $file    = isset($error['file']) ? (string) $error['file'] : '';
  $line    = isset($error['line']) ? (int) $error['line'] : 0;
  $type    = isset($error['type']) ? (int) $error['type'] : 0;

  $sig = md5($message . '|' . $file . '|' . $line . '|' . $type);

  $url = '';
  if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $req    = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
    $url    = $scheme . '://' . $_SERVER['HTTP_HOST'] . $req;
  }

  $user_id = 0;
  if (!empty($settings['include_user']) && function_exists('get_current_user_id')) {
    $user_id = (int) get_current_user_id();
  }

  // Ensure logger exists. If it does not, still attempt an email.
  $log_id = 0;
  if (class_exists('MMCA_Logger') && method_exists('MMCA_Logger', 'log')) {
    $log_id = (int) MMCA_Logger::log(array(
      'signature'  => $sig,
      'error_type' => $type,
      'message'    => $message,
      'file'       => $file,
      'line'       => $line,
      'url'        => $url,
      'user_id'    => $user_id,
      'php_sapi'   => $sapi,
    ));
  }

  // Throttle email per unique signature
  $throttle_minutes = isset($settings['throttle_minutes']) ? (int) $settings['throttle_minutes'] : 30;
  if ($throttle_minutes < 0) $throttle_minutes = 0;

  $last_sent = get_option('mmca_last_sent', array());
  if (!is_array($last_sent)) $last_sent = array();

  $now = time();
  $min_gap = $throttle_minutes * 60;

  if ($min_gap > 0 && isset($last_sent[$sig]) && ($now - (int) $last_sent[$sig]) < $min_gap) {
    return;
  }

  $to = isset($settings['to_email']) ? sanitize_email($settings['to_email']) : '';
  if (!$to) $to = get_option('admin_email');
  if (!$to || !is_email($to)) return;

  $subject_prefix = isset($settings['subject_prefix']) ? (string) $settings['subject_prefix'] : '🚨 Critical error';
  $site_name = function_exists('get_bloginfo') ? wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) : 'WordPress site';
  $home = function_exists('home_url') ? home_url() : '';

  $subject = trim($subject_prefix) . ' - ' . $site_name;

  $admin_log_url = function_exists('admin_url') ? admin_url('tools.php?page=mm-critical-alerts') : '';
  $site_health_url = function_exists('admin_url') ? admin_url('site-health.php?tab=debug') : '';
  $hosting_logs_url = isset($settings['hosting_logs_url']) ? esc_url_raw($settings['hosting_logs_url']) : '';

  $type_label = (class_exists('MMCA_Logger') && method_exists('MMCA_Logger', 'error_type_label'))
    ? MMCA_Logger::error_type_label($type)
    : 'Fatal';

  $lines = array();
  $lines[] = 'A fatal/critical PHP error was detected.';
  $lines[] = '';
  $lines[] = 'Site: ' . $site_name;
  if ($home) $lines[] = 'Home: ' . $home;
  $lines[] = 'Time (UTC): ' . gmdate('Y-m-d H:i:s');
  $lines[] = '';
  $lines[] = 'Type: ' . $type_label . ' (' . $type . ')';
  $lines[] = 'Message: ' . $message;
  $lines[] = 'File: ' . $file;
  $lines[] = 'Line: ' . $line;

  if (!empty($settings['include_request'])) {
    $lines[] = '';
    $lines[] = 'URL: ' . ($url ? $url : '(unknown)');
    $lines[] = 'Request URI: ' . (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '(unknown)');
    $lines[] = 'Method: ' . (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '(unknown)');
    $lines[] = 'IP: ' . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '(unknown)');
    $lines[] = 'User Agent: ' . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '(unknown)');
  }

  if (!empty($settings['include_user'])) {
    $lines[] = '';
    $lines[] = 'User ID: ' . (string) $user_id;
  }

  $lines[] = '';
  if ($log_id > 0) {
    $lines[] = 'Plugin log entry: #' . (string) $log_id;
  } else {
    $lines[] = 'Plugin log entry: (not recorded)';
  }

  if ($admin_log_url) $lines[] = 'View logs (wp-admin): ' . $admin_log_url;
  if ($site_health_url) $lines[] = 'Site Health debug: ' . $site_health_url;

  if (!empty($hosting_logs_url)) {
    $lines[] = 'Hosting error logs: ' . $hosting_logs_url;
  } else {
    $lines[] = 'Hosting error logs: (add a URL in Tools > Critical alerts)';
  }

  if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
    $debug_log_path = trailingslashit(WP_CONTENT_DIR) . 'debug.log';
    $lines[] = 'WP debug log path: ' . $debug_log_path;
  }

  $body = implode("\n", $lines);

  add_filter('wp_mail_content_type', 'mmca_mail_content_type');
  $sent = wp_mail($to, $subject, $body);
  remove_filter('wp_mail_content_type', 'mmca_mail_content_type');

  if ($sent) {
    $last_sent[$sig] = $now;
    update_option('mmca_last_sent', $last_sent, false);
  }
}

register_activation_hook(__FILE__, function () {
  if (class_exists('MMCA_Logger') && method_exists('MMCA_Logger', 'install')) {
    MMCA_Logger::install();
  }

  if (get_option('mmca_settings', null) === null) {
    add_option('mmca_settings', array(
      'to_email'           => get_option('admin_email'),
      'subject_prefix'     => '🚨 Critical error',
      'throttle_minutes'   => 30,
      'include_request'    => 1,
      'include_user'       => 1,
      'hosting_logs_url'   => '',
      'only_frontend'      => 0,
      'ignore_cli'         => 1,
      'ignore_wp_cron'     => 1,
    ));
  }
});

add_action('plugins_loaded', function () {
  if (class_exists('MMCA_Logger') && method_exists('MMCA_Logger', 'init')) {
    MMCA_Logger::init();
  }
  if (class_exists('MMCA_Admin') && method_exists('MMCA_Admin', 'init')) {
    MMCA_Admin::init();
  }
}, 1);
