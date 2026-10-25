<?php
/**
 * Plugin Name: MM Critical Alerts (MU loader)
 * Description: Loads MM Critical Alerts before every normal plugin, so a fatal error while other plugins are loading is still caught and emailed.
 * Version: 1.0.0
 * Author: Meridian Media
 *
 * Install: copy this single file to wp-content/mu-plugins/ (create the folder if it does not exist).
 * The plugin itself stays in wp-content/plugins/ as normal.
 */

if (!defined('ABSPATH')) exit;

$mmca_candidates = array(
  'Citical-Error-Report/mm-critical-alerts.php',
  'critical-error-report/mm-critical-alerts.php',
  'mm-critical-alerts/mm-critical-alerts.php',
);

foreach ($mmca_candidates as $mmca_rel) {
  $mmca_file = WP_PLUGIN_DIR . '/' . $mmca_rel;
  if (file_exists($mmca_file)) {
    if (!defined('MMCA_LOADED_AS_MU')) define('MMCA_LOADED_AS_MU', true);
    require_once $mmca_file;
    break;
  }
}
unset($mmca_candidates, $mmca_rel, $mmca_file);
