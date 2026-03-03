<?php
if (!defined('ABSPATH')) exit;

class DIA_IPG_Ajax
{
  const NONCE_ACTION = 'dia_ipg_ajax';
  const NOTES_OPTION = 'dia_ipg_notes'; // stored in wp_options as array

  public static function init()
  {
    add_action('wp_ajax_dia_ipg_table', [__CLASS__, 'ajax_table']);
    add_action('wp_ajax_dia_ipg_export_csv', [__CLASS__, 'export_csv']);
    add_action('wp_ajax_dia_ipg_export_print', [__CLASS__, 'export_print']);

    // ✅ Notes (new)
    add_action('wp_ajax_dia_ipg_notes_list', [__CLASS__, 'notes_list']);
    add_action('wp_ajax_dia_ipg_notes_save', [__CLASS__, 'notes_save']);
    add_action('wp_ajax_dia_ipg_notes_delete', [__CLASS__, 'notes_delete']);

    add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin']);
  }

  public static function enqueue_admin($hook)
  {
    // Only load on our plugin page
    if ($hook !== 'toplevel_page_dia-ip-guardian') return;

    $src  = DIA_IPG_URL . 'assets/admin.js';
    $path = DIA_IPG_PATH . 'assets/admin.js';
    $ver  = file_exists($path) ? filemtime($path) : (defined('DIA_IPG_VERSION') ? DIA_IPG_VERSION : '1.0.0');

    wp_enqueue_script('dia-ipg-admin', $src, [], $ver, true);

    wp_localize_script('dia-ipg-admin', 'IPG_AJAX', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce(self::NONCE_ACTION),
    ]);
  }

  /* =========================================================
   * Helpers
   * ======================================================= */

  private static function require_admin_and_nonce(string $nonce_field = 'nonce'): void
  {
    if (!current_user_can('manage_options')) {
      wp_send_json_error(['message' => 'Forbidden'], 403);
    }

    $nonce = isset($_REQUEST[$nonce_field]) ? (string) $_REQUEST[$nonce_field] : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
      wp_send_json_error(['message' => 'Bad nonce'], 400);
    }
  }

  private static function top_hours_from_table(string $table): int
  {
    if ($table === 'top24') return 24;
    if ($table === 'top3d') return 72;
    if ($table === 'top7d') return 168;
    return 24;
  }

  private static function sanitize_cc(string $cc): string
  {
    $cc = strtoupper(trim($cc));
    if ($cc === 'UK') $cc = 'GB'; // normalize
    return (preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX') ? $cc : '';
  }

  private static function sanitize_ip_str(string $ip): string
  {
    $ip = trim($ip);
    if ($ip === '') return '';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
  }

  private static function sanitize_note(string $note): string
  {
    $note = wp_strip_all_tags((string) $note, true);
    $note = trim(preg_replace('/\s+/', ' ', $note));
    // keep it short-ish
    if (function_exists('mb_substr')) $note = mb_substr($note, 0, 300);
    else $note = substr($note, 0, 300);
    return $note;
  }

  private static function get_notes(): array
  {
    $raw = get_option(self::NOTES_OPTION, []);
    return is_array($raw) ? $raw : [];
  }

  private static function set_notes(array $notes): void
  {
    update_option(self::NOTES_OPTION, $notes, false);
  }

  private static function render_notes_html(array $notes): string
  {
    // newest first
    uasort($notes, function ($a, $b) {
      $ta = isset($a['updated_at']) ? (int) $a['updated_at'] : 0;
      $tb = isset($b['updated_at']) ? (int) $b['updated_at'] : 0;
      return $tb <=> $ta;
    });

    ob_start();
    ?>
    <table class="widefat striped" style="max-width:980px;">
      <thead>
        <tr>
          <th style="width:220px;">IP</th>
          <th>Comment</th>
          <th style="width:220px;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($notes)): ?>
          <tr><td colspan="3" style="opacity:.8;">No notes yet. Add one above 👆</td></tr>
        <?php else: ?>
          <?php foreach ($notes as $ip => $row):
            $ip = (string) $ip;
            $comment = isset($row['comment']) ? (string) $row['comment'] : '';
            $comment_attr = esc_attr($comment);
            ?>
            <tr>
              <td><code><?php echo esc_html($ip); ?></code></td>
              <td>
                <span class="ipg-note-text"><?php echo esc_html($comment); ?></span>
              </td>
              <td>
                <button
                  type="button"
                  class="button ipg-note-edit"
                  data-ip="<?php echo esc_attr($ip); ?>"
                  data-comment="<?php echo $comment_attr; ?>"
                >Edit</button>

                <button
                  type="button"
                  class="button button-link-delete ipg-note-delete"
                  data-ip="<?php echo esc_attr($ip); ?>"
                  style="margin-left:8px;"
                >Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>

    <p style="margin-top:10px;opacity:.75;font-size:12px;">
      Notes are stored locally in your WordPress database (wp_options).
    </p>
    <?php
    return (string) ob_get_clean();
  }

  /* =========================================================
   * Tables AJAX (existing)
   * ======================================================= */

  public static function ajax_table()
  {
    self::require_admin_and_nonce('nonce');

    $table = isset($_POST['table']) ? sanitize_key((string) $_POST['table']) : '';
    if (!in_array($table, ['top24', 'top3d', 'top7d', 'recent'], true)) {
      wp_send_json_error(['message' => 'Invalid table'], 400);
    }

    $page = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;

    $per_page = isset($_POST['per_page']) ? (int) $_POST['per_page'] : 50;
    $allowed_pp = [20, 50, 100, 500];
    if (!in_array($per_page, $allowed_pp, true)) $per_page = 50;

    $orderby = isset($_POST['orderby']) ? sanitize_key((string) $_POST['orderby']) : '';
    $order = isset($_POST['order']) ? strtoupper(sanitize_key((string) $_POST['order'])) : 'DESC';
    if (!in_array($order, ['ASC', 'DESC'], true)) $order = 'DESC';

    // Country filter (optional)
    $country = isset($_POST['country']) ? strtoupper(trim(sanitize_text_field((string) $_POST['country']))) : '';
    if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country)) $country = '';
    if ($country === 'UK') $country = 'GB';

    // IP search (optional)
    $ip_search = isset($_POST['ip_search']) ? sanitize_text_field((string) $_POST['ip_search']) : '';
    $ip_search = preg_replace('/[^0-9a-fA-F\.\:\s]/', '', $ip_search);
    $ip_search = trim($ip_search);

    $blocked = DIA_IPG_Core::blocked_list();

    ob_start();

    // TOP TABLES
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

      // Populate dropdown from this range ALWAYS
      $countries = DIA_IPG_Logger::top_countries($hours);

      // Query rows using filters
      $data = DIA_IPG_Logger::top_ips_paged($hours, $page, $per_page, $orderby, $order, $country, $ip_search);

      DIA_IPG_Table::render_top_ips_table([
        'table_key'  => $table,
        'title'      => $title,
        'rows'       => $data['rows'],
        'total'      => $data['total'],
        'page'       => $page,
        'per_page'   => $per_page,
        'orderby'    => $orderby,
        'order'      => $order,
        'blocked'    => $blocked,
        'ip_search'  => $ip_search,
        'countries'  => $countries,
        'country'    => $country,
      ]);

    } else {

      // RECENT TABLE
      $recent_hours  = isset($_POST['recent_hours']) ? (int) $_POST['recent_hours'] : 24;
      $allowed_hours = [1, 6, 24, 168, 720, 0];
      if (!in_array($recent_hours, $allowed_hours, true)) $recent_hours = 24;

      $data = DIA_IPG_Logger::recent_visits_paged($page, $per_page, $recent_hours, $order);

      DIA_IPG_Table::render_recent_table([
        'title'        => 'Recent visitor activity — full URLs & browser info',
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

  /* =========================================================
   * Notes AJAX (new)
   * ======================================================= */

  public static function notes_list()
  {
    self::require_admin_and_nonce('nonce');

    $notes = self::get_notes();
    $html  = self::render_notes_html($notes);

    wp_send_json_success(['html' => $html]);
  }

  public static function notes_save()
  {
    self::require_admin_and_nonce('nonce');

    $ip      = isset($_POST['ip']) ? self::sanitize_ip_str((string) $_POST['ip']) : '';
    $comment = isset($_POST['comment']) ? self::sanitize_note((string) $_POST['comment']) : '';

    if ($ip === '') {
      wp_send_json_error(['message' => 'Invalid IP'], 400);
    }
    if ($comment === '') {
      wp_send_json_error(['message' => 'Comment is empty'], 400);
    }

    $notes = self::get_notes();
    $notes[$ip] = [
      'comment'    => $comment,
      'updated_at' => time(),
    ];
    self::set_notes($notes);

    wp_send_json_success([
      'message' => 'Saved',
      'html'    => self::render_notes_html(self::get_notes()),
    ]);
  }

  public static function notes_delete()
  {
    self::require_admin_and_nonce('nonce');

    $ip = isset($_POST['ip']) ? self::sanitize_ip_str((string) $_POST['ip']) : '';
    if ($ip === '') {
      wp_send_json_error(['message' => 'Invalid IP'], 400);
    }

    $notes = self::get_notes();
    if (isset($notes[$ip])) {
      unset($notes[$ip]);
      self::set_notes($notes);
    }

    wp_send_json_success([
      'message' => 'Deleted',
      'html'    => self::render_notes_html(self::get_notes()),
    ]);
  }

  /* =========================================================
   * Export (existing, with full-range loop)
   * ======================================================= */

  public static function export_csv()
  {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);

    $nonce = isset($_GET['nonce']) ? (string) $_GET['nonce'] : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Bad nonce', 400);

    $table = isset($_GET['table']) ? sanitize_text_field((string) $_GET['table']) : 'top24';
    if (!in_array($table, ['top24', 'top3d', 'top7d'], true)) $table = 'top24';

    $orderby = isset($_GET['orderby']) ? sanitize_text_field((string) $_GET['orderby']) : 'hits';
    if (!in_array($orderby, ['hits', 'last_seen'], true)) $orderby = 'hits';

    $order = isset($_GET['order']) ? strtoupper(sanitize_text_field((string) $_GET['order'])) : 'DESC';
    if (!in_array($order, ['ASC', 'DESC'], true)) $order = 'DESC';

    $country = isset($_GET['country']) ? self::sanitize_cc((string) $_GET['country']) : '';
    $hours   = self::top_hours_from_table($table);

    // Export ALL rows (loop pages)
    $all_rows = [];
    $page = 1;

    // chunk size per request
    $per_page = 2000;

    // hard cap to protect server
    $max_rows = 50000;

    do {
      // keep ip_search empty in exports
      $data  = DIA_IPG_Logger::top_ips_paged($hours, $page, $per_page, $orderby, $order, $country, '');
      $rows  = (array) ($data['rows'] ?? []);
      $total = (int) ($data['total'] ?? 0);

      if (empty($rows)) break;

      $all_rows = array_merge($all_rows, $rows);
      $page++;

      if ($total > 0 && count($all_rows) >= $total) break;

      if (count($all_rows) >= $max_rows) {
        $all_rows = array_slice($all_rows, 0, $max_rows);
        break;
      }
    } while (true);

    $fname = 'ip-guardian-' . $table . '-' . gmdate('Y-m-d') . '.csv';

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $fname);

    // UTF-8 BOM for Excel friendliness
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    fputcsv($out, ['IP', 'Country', 'Hits', 'Last seen (WP time)']);

    foreach ($all_rows as $r) {
      $ip        = (string) ($r['ip'] ?? '');
      $hits      = (int) ($r['hits'] ?? 0);
      $last_seen = (string) ($r['last_seen'] ?? '');

      $cc = (string) ($r['country'] ?? '');
      if ($cc === '' && $ip !== '') $cc = (string) DIA_IPG_Geo::resolve_country_for_ip($ip);
      $cc = self::sanitize_cc($cc);

      fputcsv($out, [
        $ip,
        $cc,
        $hits,
        DIA_IPG_Table::export_fmt_dt($last_seen),
      ]);
    }

    fclose($out);
    exit;
  }

  public static function export_print()
  {
    if (!current_user_can('manage_options')) wp_die('Forbidden', 403);

    $nonce = isset($_GET['nonce']) ? (string) $_GET['nonce'] : '';
    if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) wp_die('Bad nonce', 400);

    $table = isset($_GET['table']) ? sanitize_text_field((string) $_GET['table']) : 'top24';
    if (!in_array($table, ['top24', 'top3d', 'top7d'], true)) $table = 'top24';

    $orderby = isset($_GET['orderby']) ? sanitize_text_field((string) $_GET['orderby']) : 'hits';
    if (!in_array($orderby, ['hits', 'last_seen'], true)) $orderby = 'hits';

    $order = isset($_GET['order']) ? strtoupper(sanitize_text_field((string) $_GET['order'])) : 'DESC';
    if (!in_array($order, ['ASC', 'DESC'], true)) $order = 'DESC';

    $country = isset($_GET['country']) ? self::sanitize_cc((string) $_GET['country']) : '';
    $hours   = self::top_hours_from_table($table);

    // Export ALL rows (loop pages)
    $all_rows = [];
    $page = 1;
    $per_page = 2000;
    $max_rows = 50000;

    do {
      $data  = DIA_IPG_Logger::top_ips_paged($hours, $page, $per_page, $orderby, $order, $country, '');
      $rows  = (array) ($data['rows'] ?? []);
      $total = (int) ($data['total'] ?? 0);

      if (empty($rows)) break;

      $all_rows = array_merge($all_rows, $rows);
      $page++;

      if ($total > 0 && count($all_rows) >= $total) break;

      if (count($all_rows) >= $max_rows) {
        $all_rows = array_slice($all_rows, 0, $max_rows);
        break;
      }
    } while (true);

    $title = ($table === 'top24')
      ? 'Top IPs — last 24 hours'
      : (($table === 'top3d') ? 'Top IPs — last 3 days' : 'Top IPs — last 7 days');

    if ($country) $title .= ' — ' . esc_html($country);

    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');

    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . esc_html($title) . '</title>';
    echo '<style>
      body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;padding:20px;}
      h1{font-size:18px;margin:0 0 10px;}
      .meta{opacity:.7;font-size:12px;margin-bottom:14px;}
      table{width:100%;border-collapse:collapse;font-size:12px;}
      th,td{border:1px solid #ddd;padding:8px;text-align:left;vertical-align:top;}
      th{background:#f6f7f7;}
      @media print {.no-print{display:none;}}
    </style></head><body>';

    echo '<div class="no-print" style="margin-bottom:12px;"><button onclick="window.print()">Print / Save as PDF</button></div>';
    echo '<h1>' . esc_html($title) . '</h1>';
    echo '<div class="meta">Generated: ' . esc_html(wp_date('Y-m-d H:i')) . '</div>';

    echo '<table><thead><tr><th>IP</th><th>Country</th><th>Hits</th><th>Last seen (WP time)</th></tr></thead><tbody>';

    foreach ($all_rows as $r) {
      $ip        = (string) ($r['ip'] ?? '');
      $hits      = (int) ($r['hits'] ?? 0);
      $last_seen = (string) ($r['last_seen'] ?? '');

      $cc = (string) ($r['country'] ?? '');
      if ($cc === '' && $ip !== '') $cc = (string) DIA_IPG_Geo::resolve_country_for_ip($ip);
      $cc = self::sanitize_cc($cc);

      echo '<tr>';
      echo '<td><code>' . esc_html($ip) . '</code></td>';
      echo '<td>' . esc_html($cc) . '</td>';
      echo '<td>' . esc_html((string) $hits) . '</td>';
      echo '<td>' . esc_html(DIA_IPG_Table::export_fmt_dt($last_seen)) . '</td>';
      echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</body></html>';
    exit;
  }
}