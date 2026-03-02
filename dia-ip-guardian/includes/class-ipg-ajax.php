<?php
if (!defined('ABSPATH')) exit;

class DIA_IPG_Ajax {

  const NONCE_ACTION = 'dia_ipg_ajax';

  public static function init() {
    add_action('wp_ajax_dia_ipg_table', [__CLASS__, 'ajax_table']);
    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin']);
  }

  public static function enqueue_admin($hook) {
    // Only load on our plugin page
    if ($hook !== 'toplevel_page_dia-ip-guardian') return;

    $src  = DIA_IPG_URL . 'assets/admin.js';
    $path = DIA_IPG_PATH . 'assets/admin.js';
    $ver  = file_exists($path) ? filemtime($path) : (defined('DIA_IPG_VERSION') ? DIA_IPG_VERSION : '1.0.0');

    wp_enqueue_script(
      'dia-ipg-admin',
      $src,
      [],
      $ver,
      true
    );

    wp_localize_script('dia-ipg-admin', 'IPG_AJAX', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce(self::NONCE_ACTION),
    ]);
  }

  public static function ajax_table() {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_send_json_error(['message' => 'Bad nonce'], 400);
    }

    $table = isset($_POST['table']) ? sanitize_key((string) $_POST['table']) : '';
    if (!in_array($table, ['top24', 'top3d', 'top7d', 'recent'], true)) {
      wp_send_json_error(['message' => 'Invalid table'], 400);
    }

    $page     = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;

    $per_page = isset($_POST['per_page']) ? (int) $_POST['per_page'] : 50;
    $allowed_pp = [20, 50, 100, 500];
    if (!in_array($per_page, $allowed_pp, true)) $per_page = 50;

    $orderby = isset($_POST['orderby']) ? sanitize_key((string) $_POST['orderby']) : '';
    $order   = isset($_POST['order']) ? strtoupper(sanitize_key((string) $_POST['order'])) : 'DESC';
    if (!in_array($order, ['ASC', 'DESC'], true)) $order = 'DESC';

    // ✅ Country filter (optional)
    $country = isset($_POST['country']) ? strtoupper(trim(sanitize_text_field((string) $_POST['country']))) : '';
    if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) $country = '';

    $blocked = DIA_IPG_Core::blocked_list();

    ob_start();

    // ---------------- TOP TABLES ----------------
    if (in_array($table, ['top24', 'top3d', 'top7d'], true)) {

      if ($table === 'top24') {
        $hours = 24;
        $title = 'Top IPs — last 24 hours';
      } elseif ($table === 'top3d') {
        $hours = 72;
        $title = 'Top IPs — last 3 days';
      } else {
        $hours = 168;
        $title = 'Top IPs — last 7 days';
      }

      if (!in_array($orderby, ['hits', 'last_seen'], true)) $orderby = 'hits';

      // ✅ Populate dropdown from this range ALWAYS (even if filter is active)
      $countries = DIA_IPG_Logger::top_countries($hours);

      // ✅ Query rows using filter (if any)
      $data = DIA_IPG_Logger::top_ips_paged($hours, $page, $per_page, $orderby, $order, $country);

      DIA_IPG_Table::render_top_ips_table([
        'table_key' => $table,
        'title'     => $title,
        'rows'      => $data['rows'],
        'total'     => $data['total'],
        'page'      => $page,
        'per_page'  => $per_page,
        'orderby'   => $orderby,
        'order'     => $order,
        'blocked'   => $blocked,

        // ✅ for country filter UI
        'countries' => $countries,
        'country'   => $country,
      ]);

    } else {

      // ---------------- RECENT TABLE ----------------
      $recent_hours  = isset($_POST['recent_hours']) ? (int) $_POST['recent_hours'] : 24;
      $allowed_hours = [1, 6, 24, 168, 720, 0];
      if (!in_array($recent_hours, $allowed_hours, true)) $recent_hours = 24;

      // You kept orderby fixed for recent (good)
      $orderby = 'created_at';

      // (Optional) If later you want country filtering on recent too,
      // we can update DIA_IPG_Logger::recent_visits_paged() to accept $country.
      $data = DIA_IPG_Logger::recent_visits_paged($page, $per_page, $recent_hours, $order);

      DIA_IPG_Table::render_recent_table([
        'title'        => 'recent visitor activity — full URLs & browser info
',
        'rows'         => $data['rows'],
        'total'        => $data['total'],
        'page'         => $page,
        'per_page'     => $per_page,
        'order'        => $order,
        'recent_hours' => $recent_hours,
        'blocked'      => $blocked,
      ]);
    }

    $html = ob_get_clean();
    wp_send_json_success(['html' => $html]);
  }
}