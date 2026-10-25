<?php
/**
 * Plugin Name: MM Critical Alerts
 * Description: Sends an immediate email alert when a fatal/critical PHP error breaks a site, ignores background noise, and logs everything for review in wp-admin.
 * Version: 1.1.0
 * Requires PHP: 7.2
 * Author: Meridian Media
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

// Already loaded (e.g. by the MU loader) - do nothing the second time round.
if (defined('MMCA_VERSION')) return;

define('MMCA_VERSION', '1.1.0');
define('MMCA_PLUGIN_FILE', __FILE__);

// Includes (use __DIR__ so this works as MU-loaded or normal plugin)
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/handler.php';
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
    add_option('mmca_settings', mm_ca_default_settings());
  }

  // Replaced by mmca_state in 1.1.0
  delete_option('mmca_last_sent');

  update_option('mmca_installed_version', MMCA_VERSION, false);
}
add_action('init', 'mmca_maybe_install', 1);

/*
  Register shutdown capture as early as possible.
  Do not register another shutdown handler later.
*/
if (!defined('MM_CA_REGISTERED')) {
  define('MM_CA_REGISTERED', true);
  // Reserved memory, released inside the handler so it can still work after an out-of-memory fatal.
  $GLOBALS['mm_ca_reserve'] = str_repeat('x', 65536);
  register_shutdown_function('mm_ca_shutdown_capture');
}

add_action('plugins_loaded', function () {
  if (class_exists('MMCA_Logger') && method_exists('MMCA_Logger', 'init')) {
    MMCA_Logger::init();
  }
  if (class_exists('MMCA_Admin') && method_exists('MMCA_Admin', 'init')) {
    MMCA_Admin::init();
  }
}, 1);
