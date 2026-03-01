<?php
if (!defined('ABSPATH')) exit;

class DIA_IPG_Blocker {

  public static function init() {
    add_action('init', [__CLASS__, 'maybe_block_request'], 0);
  }

  public static function maybe_block_request() {
    if (is_admin()) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (defined('DOING_CRON') && DOING_CRON) return;

    $ip = DIA_IPG_Logger::get_client_ip();
    if (!$ip) return;

    $blocked = DIA_IPG_Core::blocked_list();
    if (in_array($ip, $blocked, true)) {
      status_header(403);
      header('Content-Type: text/plain; charset=utf-8');
      echo "403 Forbidden";
      exit;
    }
  }
}