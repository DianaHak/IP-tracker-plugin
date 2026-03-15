<?php
if (!defined('ABSPATH')) exit;

class DIA_IPG_Core {

  const OPTION_CFG   = 'dia_ipg_cfg';
  const OPTION_BLK   = 'dia_ipg_blocked';
  const OPTION_DBVER = 'dia_ipg_db_version';
  const DB_VERSION   = '1.1';

  public static function init() {
    // Init runtime parts
    if (class_exists('DIA_IPG_Logger'))  DIA_IPG_Logger::init();
    if (class_exists('DIA_IPG_Blocker')) DIA_IPG_Blocker::init();

    if (is_admin() && class_exists('DIA_IPG_Admin')) {
      DIA_IPG_Admin::init();
    }

    // Cron handler
    add_action('dia_ipg_cleanup', ['DIA_IPG_Logger', 'cleanup_logs']);
  }

  public static function defaults(): array {
    return [
      'ip_source'        => 'auto', // auto | remote_addr | cf | xff
      'retention_days'   => 30,
      'ignore_admins'    => 1,
      'track_logged_in'  => 0,

      'geo_mode'          => 'auto',      // auto | off | cf | maxmind | remote
      'remote_geo'        => 0,
      'remote_geo_vendor' => 'ipapi_co',  // ipapi_co | ip_api_com
      'maxmind_mmdb_path' => '',
    ];
  }

  public static function cfg(): array {
    $cfg = get_option(self::OPTION_CFG, []);
    $cfg = is_array($cfg) ? $cfg : [];
    return array_merge(self::defaults(), $cfg);
  }

  public static function set_cfg(array $new): void {
    update_option(self::OPTION_CFG, array_merge(self::cfg(), $new));
  }

  public static function blocked_list(): array {
    $blk = get_option(self::OPTION_BLK, []);
    return is_array($blk) ? $blk : [];
  }

  public static function set_blocked_list(array $ips): void {
    $ips = array_values(array_unique(array_filter($ips, fn($x) => is_string($x) && $x !== '')));
    update_option(self::OPTION_BLK, $ips);
  }

  public static function on_activate() {
    // DB
    if (class_exists('DIA_IPG_Logger')) {
      DIA_IPG_Logger::create_or_upgrade_table();
    }

    // Defaults
    if (!get_option(self::OPTION_CFG)) add_option(self::OPTION_CFG, self::defaults());
    if (!get_option(self::OPTION_BLK)) add_option(self::OPTION_BLK, []);

    // Cron
    if (!wp_next_scheduled('dia_ipg_cleanup')) {
      wp_schedule_event(time() + 3600, 'daily', 'dia_ipg_cleanup');
    }

    update_option(self::OPTION_DBVER, self::DB_VERSION);
  }

  public static function on_deactivate() {
    wp_clear_scheduled_hook('dia_ipg_cleanup');
  }

  public static function is_admin_user(): bool {
    return is_user_logged_in() && current_user_can('manage_options');
  }
}