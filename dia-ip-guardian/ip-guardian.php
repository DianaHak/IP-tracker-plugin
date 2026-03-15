<?php
/**
 * Plugin Name: Zaygl IP blocker and anti-DDoS
 * Description: Logs visitor IPs and shows top IPs (24h / 7d) with easy block/unblock.
 * Version: 1.0.0
 * Author: Diana Hakobyan
 */

if (!defined('ABSPATH')) exit;

if (!defined('DIA_IPG_VERSION')) define('DIA_IPG_VERSION', '1.0.0');
if (!defined('DIA_IPG_FILE'))    define('DIA_IPG_FILE', __FILE__);
if (!defined('DIA_IPG_PATH'))    define('DIA_IPG_PATH', plugin_dir_path(__FILE__));
if (!defined('DIA_IPG_URL'))     define('DIA_IPG_URL', plugin_dir_url(__FILE__));

if (!function_exists('dia_ipg_require')) {
  function dia_ipg_require($rel_path) {
    $file = DIA_IPG_PATH . ltrim($rel_path, '/');
    if (file_exists($file)) require_once $file;
  }
}

/**
 * Load files
 * NOTE: table class must be loaded before admin render uses it.
 */
dia_ipg_require('includes/helpers.php');              // if you have it
dia_ipg_require('includes/class-ipg-core.php');
dia_ipg_require('includes/class-ipg-logger.php');
dia_ipg_require('includes/class-ipg-geo.php');
dia_ipg_require('includes/class-ipg-blocker.php');
dia_ipg_require('includes/class-ipg-table.php');      // ✅ IMPORTANT
dia_ipg_require('includes/class-ipg-admin.php');
dia_ipg_require('includes/class-ipg-ajax.php');       // if you have it

register_activation_hook(__FILE__, ['DIA_IPG_Core', 'on_activate']);
register_deactivation_hook(__FILE__, ['DIA_IPG_Core', 'on_deactivate']);

add_action('plugins_loaded', function () {
  if (class_exists('DIA_IPG_Core')) {
    DIA_IPG_Core::init();
      DIA_IPG_Ajax::init();
  }
  
});