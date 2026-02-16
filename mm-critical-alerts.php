<?php
/**
 * Plugin Name: MM Critical Alerts
 * Description: Sends an immediate email alert when a fatal/critical PHP error occurs, and logs the error for review in wp-admin.
 * Version: 1.0.2
 * Author: Meridian Media
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

define('MMCA_VERSION', '1.0.2');
define('MMCA_PLUGIN_FILE', __FILE__);

// Includes (use __DIR__ so this works as MU-loaded or normal plugin)
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/admin.php';

/**
 * Install / upgrade tasks (MU-safe).
 * MU plugins do not run register_activation_hook, so we version-gate installs here.
 */
function mmca_maybe_install() {
  $installed = get_option('mmca_installed_version', '');
  if ($installed === MMCA_VERSION) {
    return;
  }

  if (class_exists('MMCA_Logger') && method_exists('MMCA_Logger', 'install')) {
    MMCA_Logger::install();
  }

  if (get_option('mmca_settings', null) === null) {
    add_option('mmca_settings', array(
      'to_email'           => 'jon@meridian-media.co.uk',
      'subject_prefix'     => '🚨 This website has a critical error 🚨',
      'throttle_minutes'   => 30,
      'include_request'    => 1,
      'include_user'       => 1,
      'hosting_logs_url'   => '',
      'only_frontend'      => 0,
      'ignore_cli'         => 1,
      'ignore_wp_cron'     => 1,
    ));
  }

  update_option('mmca_installed_version', MMCA_VERSION, false);
}
add_action('init', 'mmca_maybe_install', 1);

/*
  Register shutdown capture as early as possible.
  Do not register another shutdown handler later.
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

  mm_ca_handle_fatal($error);
}

/**
 * Main fatal handler. Logs and emails.
 */
function mm_ca_handle_fatal(array $error) {
  $settings = get_option('mmca_settings', array());
  $settings = is_array($settings) ? $settings : array();

  // Ignore CLI by default
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

  // Log if available
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

  // Throttle email per signature
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

  $hosting_logs_url = isset($settings['hosting_logs_url']) ? trim((string) $settings['hosting_logs_url']) : '';
  $hosting_logs_url = $hosting_logs_url ? esc_url_raw($hosting_logs_url) : '';

  $type_label = (class_exists('MMCA_Logger') && method_exists('MMCA_Logger', 'error_type_label'))
    ? MMCA_Logger::error_type_label($type)
    : 'Fatal';

  $esc = function($v) {
    return esc_html((string) $v);
  };

  // Build HTML email
  $body  = '<div style="font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5;">';
  $body .= '<h2 style="margin:0 0 12px 0;">A fatal/critical PHP error was detected</h2>';

  $body .= '<table cellpadding="0" cellspacing="0" style="border-collapse: collapse;">';
  $body .= '<tr><td style="padding:4px 12px 4px 0;"><strong>Site</strong></td><td style="padding:4px 0;">' . $esc($site_name) . '</td></tr>';

  if ($home) {
    $body .= '<tr><td style="padding:4px 12px 4px 0;"><strong>Home</strong></td><td style="padding:4px 0;"><a href="' . esc_url($home) . '">' . $esc($home) . '</a></td></tr>';
  }

  if ($url) {
    $body .= '<tr><td style="padding:4px 12px 4px 0;"><strong>Request URL</strong></td><td style="padding:4px 0;"><a href="' . esc_url($url) . '">' . $esc($url) . '</a></td></tr>';
  }

  $body .= '<tr><td style="padding:4px 12px 4px 0;"><strong>Time (UTC)</strong></td><td style="padding:4px 0;">' . $esc(gmdate('Y-m-d H:i:s')) . '</td></tr>';
  $body .= '<tr><td style="padding:4px 12px 4px 0;"><strong>Type</strong></td><td style="padding:4px 0;">' . $esc($type_label) . ' (' . (int) $type . ')</td></tr>';
  $body .= '</table>';

  $body .= '<hr style="margin:14px 0;">';
  $body .= '<p style="margin:0 0 8px 0;"><strong>Message</strong><br>' . $esc($message) . '</p>';
  $body .= '<p style="margin:0 0 8px 0;"><strong>File</strong><br>' . $esc($file) . '</p>';
  $body .= '<p style="margin:0 0 8px 0;"><strong>Line</strong><br>' . (int) $line . '</p>';

  if (!empty($settings['include_request'])) {
    $body .= '<hr style="margin:14px 0;">';
    $body .= '<p style="margin:0 0 6px 0;"><strong>Request details</strong></p>';
    $body .= '<ul style="margin:0 0 0 18px; padding:0;">';
    $body .= '<li><strong>Request URI:</strong> ' . $esc(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '(unknown)') . '</li>';
    $body .= '<li><strong>Method:</strong> ' . $esc(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '(unknown)') . '</li>';
    $body .= '<li><strong>IP:</strong> ' . $esc(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '(unknown)') . '</li>';
    $body .= '<li><strong>User agent:</strong> ' . $esc(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '(unknown)') . '</li>';
    $body .= '</ul>';
  }

  if (!empty($settings['include_user'])) {
    $body .= '<p style="margin:10px 0 0 0;"><strong>User ID:</strong> ' . (int) $user_id . '</p>';
  }

  $body .= '<hr style="margin:14px 0;">';
  $body .= '<p style="margin:0 0 6px 0;"><strong>Quick links</strong></p>';
  $body .= '<ul style="margin:0 0 0 18px; padding:0;">';

  if ($admin_log_url) {
    $body .= '<li><a href="' . esc_url($admin_log_url) . '">View logs in wp-admin</a> (log entry #' . (int) $log_id . ')</li>';
  }
  if ($site_health_url) {
    $body .= '<li><a href="' . esc_url($site_health_url) . '">Site Health debug</a></li>';
  }
  if (!empty($hosting_logs_url)) {
    $body .= '<li><a href="' . esc_url($hosting_logs_url) . '">Hosting error logs (20i)</a></li>';
  }
  if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
    $body .= '<li><strong>WP debug log path:</strong> ' . $esc(trailingslashit(WP_CONTENT_DIR) . 'debug.log') . '</li>';
  }

  $body .= '</ul>';
  $body .= '</div>';

  $headers = array('Content-Type: text/html; charset=UTF-8');
  $sent = wp_mail($to, $subject, $body, $headers);

  if ($sent) {
    $last_sent[$sig] = $now;
    update_option('mmca_last_sent', $last_sent, false);
  }
}

add_action('plugins_loaded', function () {
  if (class_exists('MMCA_Logger') && method_exists('MMCA_Logger', 'init')) {
    MMCA_Logger::init();
  }
  if (class_exists('MMCA_Admin') && method_exists('MMCA_Admin', 'init')) {
    MMCA_Admin::init();
  }
}, 1);
