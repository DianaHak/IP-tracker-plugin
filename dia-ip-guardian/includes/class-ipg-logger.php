<?php
if (!defined('ABSPATH'))
  exit;

class DIA_IPG_Logger
{
  const TABLE_LOGS = 'dia_ipg_logs';

  /**
   * Return UTC datetime string for “now minus N seconds”,
   * where “now” is based on WordPress website timezone.
   *
   * We store created_at in UTC (current_time('mysql', true)),
   * so all comparisons must be in UTC as well.
   */
  private static function wp_cutoff_utc_str(int $seconds_back): string
  {
    $seconds_back = max(0, (int) $seconds_back);

    // Website timezone (Settings -> General)
    $site_tz = function_exists('wp_timezone')
      ? wp_timezone()
      : new DateTimeZone(wp_timezone_string());

    // "Now" in site timezone
    $dt = new DateTime('now', $site_tz);
    if ($seconds_back > 0) {
      $dt->modify('-' . $seconds_back . ' seconds');
    }

    // Convert that site-time cutoff to UTC for DB comparison
    $dt->setTimezone(new DateTimeZone('UTC'));
    return $dt->format('Y-m-d H:i:s');
  }
  public static function recent_visits_paged_minutes(int $page, int $per_page, int $minutes, string $order): array
  {
    global $wpdb;

    $page = max(1, (int) $page);

    $allowed_pp = [20, 50, 100, 500];
    $per_page = (int) $per_page;
    if (!in_array($per_page, $allowed_pp, true))
      $per_page = 50;

    $offset = ($page - 1) * $per_page;

    $minutes = max(0, (int) $minutes);

    $order = strtoupper((string) $order);
    if (!in_array($order, ['ASC', 'DESC'], true))
      $order = 'DESC';

    $where = '';
    $params = [];

    if ($minutes > 0) {
      // ✅ logs are stored in UTC (current_time('mysql', true)), so cutoff must be UTC too
      $cutoff_ts = current_time('timestamp', true) - ($minutes * MINUTE_IN_SECONDS);
      $cutoff_str = gmdate('Y-m-d H:i:s', $cutoff_ts);

      $where = "WHERE created_at >= %s";
      $params[] = $cutoff_str;
    }

    // total
    if ($minutes > 0) {
      $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . self::table_name() . " {$where}",
        $params[0]
      ));
    } else {
      $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table_name());
    }

    $sql = "SELECT id, ip, country, created_at, url, user_agent
          FROM " . self::table_name() . "
          {$where}
          ORDER BY created_at {$order}
          LIMIT %d OFFSET %d";

    if ($minutes > 0) {
      $rows = $wpdb->get_results($wpdb->prepare($sql, $params[0], $per_page, $offset), ARRAY_A);
    } else {
      $rows = $wpdb->get_results($wpdb->prepare($sql, $per_page, $offset), ARRAY_A);
    }

    return [
      'rows' => is_array($rows) ? $rows : [],
      'total' => $total,
    ];
  }

  public static function init()
  {
    add_action('template_redirect', [__CLASS__, 'track_visit'], 1);
  }

  public static function table_name(): string
  {
    global $wpdb;
    return $wpdb->prefix . self::TABLE_LOGS;
  }

  public static function create_or_upgrade_table(): void
  {
    global $wpdb;
    $table = self::table_name();
    $charset_collate = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      ip VARCHAR(64) NOT NULL,
      country CHAR(2) NULL,
      user_agent VARCHAR(255) NULL,
      url TEXT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY ip_created (ip, created_at),
      KEY created_at (created_at),
      KEY country_created (country, created_at)
    ) {$charset_collate};";

    dbDelta($sql);
  }

  public static function get_client_ip(): string
  {
    $cfg = DIA_IPG_Core::cfg();
    $source = (string) ($cfg['ip_source'] ?? 'auto');

    $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';
    $cf = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']) : '';
    $xff = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim((string) $_SERVER['HTTP_X_FORWARDED_FOR']) : '';

    $first_from_xff = function ($v) {
      $parts = array_map('trim', explode(',', (string) $v));
      foreach ($parts as $p) {
        if (filter_var($p, FILTER_VALIDATE_IP))
          return $p;
      }
      return '';
    };

    if ($source === 'remote_addr') {
      $ip = $remote;
    } elseif ($source === 'cf') {
      $ip = $cf ?: $remote;
    } elseif ($source === 'xff') {
      $ip = $first_from_xff($xff) ?: $remote;
    } else {
      $ip = $cf ?: ($first_from_xff($xff) ?: $remote);
    }

    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
  }

  public static function track_visit()
  {
    if (is_admin())
      return;

    $cfg = DIA_IPG_Core::cfg();

    if (!empty($cfg['ignore_admins']) && DIA_IPG_Core::is_admin_user())
      return;
    if (empty($cfg['track_logged_in']) && is_user_logged_in())
      return;

    $ip = self::get_client_ip();
    if (!$ip)
      return;

    $blocked = DIA_IPG_Core::blocked_list();
    if (is_array($blocked) && in_array($ip, $blocked, true))
      return;

    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (stripos($uri, '/wp-json/') === 0)
      return;
    if (stripos($uri, '/wp-admin/') === 0)
      return;
    if (stripos($uri, 'admin-ajax.php') !== false)
      return;

    $uri = wp_unslash($uri);
    $url = home_url($uri);
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

    // Throttle: same IP+URL once per 60 sec
    $key = 'dia_ipg_' . md5($ip . '|' . $uri);
    if (get_transient($key))
      return;
    set_transient($key, 1, 60);

    // Country: fast headers only (Cloudflare). Remote lookups are admin-only.
    $country = DIA_IPG_Geo::country_from_fast_headers();
    $country = $country ? strtoupper((string) $country) : '';

    global $wpdb;
    $wpdb->insert(
      self::table_name(),
      [
        'ip' => $ip,
        'country' => $country ?: null,
        'user_agent' => $ua,
        'url' => $url,
        // ✅ store UTC for consistency
        'created_at' => current_time('mysql', true),
      ],
      ['%s', '%s', '%s', '%s', '%s']
    );
  }

  public static function cleanup_logs()
  {
    $cfg = DIA_IPG_Core::cfg();
    $days = max(1, (int) ($cfg['retention_days'] ?? 30));

    // ✅ Website-time based cutoff converted to UTC
    $cutoff_utc = self::wp_cutoff_utc_str($days * DAY_IN_SECONDS);

    global $wpdb;
    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM " . self::table_name() . " WHERE created_at < %s",
        $cutoff_utc
      )
    );
  }

  /* =========================================================
   * Top tables (hours-based)
   * ======================================================= */

  public static function top_ips_paged($hours, $page, $per_page, $orderby, $order, $country = '', $ip_search = ''): array
  {
    global $wpdb;

    $hours = max(1, (int) $hours);
    $page = max(1, (int) $page);

    $allowed_pp = [20, 50, 100, 500];
    $per_page = (int) $per_page;
    if (!in_array($per_page, $allowed_pp, true))
      $per_page = 50;

    $offset = ($page - 1) * $per_page;

    if (!in_array($orderby, ['hits', 'last_seen'], true))
      $orderby = 'hits';
    $order = strtoupper((string) $order);
    if (!in_array($order, ['ASC', 'DESC'], true))
      $order = 'DESC';

    $country = strtoupper(trim((string) $country));
    if ($country === 'UK')
      $country = 'GB';
    if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country))
      $country = '';

    $ip_search = trim((string) $ip_search);
    $ip_search = preg_replace('/[^0-9a-fA-F\.\:\s]/', '', $ip_search);
    $ip_search = trim($ip_search);

    // ✅ cutoff based on website time, converted to UTC for DB compare
    $cutoff_str = self::wp_cutoff_utc_str($hours * HOUR_IN_SECONDS);

    $where = "WHERE created_at >= %s";
    $params = [$cutoff_str];

    if ($country !== '') {
      if ($country === 'GB') {
        $where .= " AND country IN (%s, %s)";
        $params[] = 'GB';
        $params[] = 'UK';
      } else {
        $where .= " AND country = %s";
        $params[] = $country;
      }
    }

    if ($ip_search !== '') {
      $like = '%' . $wpdb->esc_like($ip_search) . '%';
      $where .= " AND ip LIKE %s";
      $params[] = $like;
    }

    $total_sql = "SELECT COUNT(DISTINCT ip) FROM " . self::table_name() . " {$where}";
    $total = (int) $wpdb->get_var($wpdb->prepare($total_sql, ...$params));

    $order_sql = ($orderby === 'hits') ? "hits {$order}" : "last_seen {$order}";

    $rows_sql = "SELECT ip,
                      COUNT(*) AS hits,
                      MAX(created_at) AS last_seen,
                      MAX(country) AS country
                 FROM " . self::table_name() . "
                 {$where}
                 GROUP BY ip
                 ORDER BY {$order_sql}
                 LIMIT %d OFFSET %d";

    $rows_params = array_merge($params, [$per_page, $offset]);
    $rows = $wpdb->get_results($wpdb->prepare($rows_sql, ...$rows_params), ARRAY_A);

    return [
      'rows' => is_array($rows) ? $rows : [],
      'total' => $total,
    ];
  }

  /* =========================================================
   * Top tables (minutes-based)
   * ======================================================= */

  public static function top_ips_paged_minutes($minutes, $page, $per_page, $orderby, $order, $country = '', $ip_search = ''): array
  {
    global $wpdb;

    $minutes = max(1, (int) $minutes);
    $page = max(1, (int) $page);

    $allowed_pp = [20, 50, 100, 500];
    $per_page = (int) $per_page;
    if (!in_array($per_page, $allowed_pp, true))
      $per_page = 50;

    $offset = ($page - 1) * $per_page;

    if (!in_array($orderby, ['hits', 'last_seen'], true))
      $orderby = 'hits';
    $order = strtoupper((string) $order);
    if (!in_array($order, ['ASC', 'DESC'], true))
      $order = 'DESC';

    $country = strtoupper(trim((string) $country));
    if ($country === 'UK')
      $country = 'GB';
    if ($country !== '' && !preg_match('/^[A-Z]{2}$/', $country))
      $country = '';

    $ip_search = trim((string) $ip_search);
    $ip_search = preg_replace('/[^0-9a-fA-F\.\:\s]/', '', $ip_search);
    $ip_search = trim($ip_search);

    // ✅ cutoff based on website time, converted to UTC for DB compare
    $cutoff_str = self::wp_cutoff_utc_str($minutes * MINUTE_IN_SECONDS);

    $where = "WHERE created_at >= %s";
    $params = [$cutoff_str];

    if ($country !== '') {
      if ($country === 'GB') {
        $where .= " AND country IN (%s, %s)";
        $params[] = 'GB';
        $params[] = 'UK';
      } else {
        $where .= " AND country = %s";
        $params[] = $country;
      }
    }

    if ($ip_search !== '') {
      $like = '%' . $wpdb->esc_like($ip_search) . '%';
      $where .= " AND ip LIKE %s";
      $params[] = $like;
    }

    $total_sql = "SELECT COUNT(DISTINCT ip) FROM " . self::table_name() . " {$where}";
    $total = (int) $wpdb->get_var($wpdb->prepare($total_sql, ...$params));

    $order_sql = ($orderby === 'hits') ? "hits {$order}" : "last_seen {$order}";

    $rows_sql = "SELECT ip,
                      COUNT(*) AS hits,
                      MAX(created_at) AS last_seen,
                      MAX(country) AS country
                 FROM " . self::table_name() . "
                 {$where}
                 GROUP BY ip
                 ORDER BY {$order_sql}
                 LIMIT %d OFFSET %d";

    $rows_params = array_merge($params, [$per_page, $offset]);
    $rows = $wpdb->get_results($wpdb->prepare($rows_sql, ...$rows_params), ARRAY_A);

    return [
      'rows' => is_array($rows) ? $rows : [],
      'total' => $total,
    ];
  }

  /* =========================================================
   * Countries (hours/minutes)
   * ======================================================= */

  public static function top_countries(int $hours): array
  {
    global $wpdb;

    $hours = max(1, (int) $hours);
    $cutoff_str = self::wp_cutoff_utc_str($hours * HOUR_IN_SECONDS);

    $rows = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT country
         FROM " . self::table_name() . "
         WHERE created_at >= %s
           AND country IS NOT NULL
           AND country <> ''
         ORDER BY country ASC",
        $cutoff_str
      )
    );

    $out = [];
    foreach ((array) $rows as $cc) {
      $cc = strtoupper(trim((string) $cc));
      if (preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX')
        $out[] = $cc;
    }

    return array_values(array_unique($out));
  }

  public static function top_countries_minutes(int $minutes): array
  {
    global $wpdb;

    $minutes = max(1, (int) $minutes);
    $cutoff_str = self::wp_cutoff_utc_str($minutes * MINUTE_IN_SECONDS);

    $rows = $wpdb->get_col(
      $wpdb->prepare(
        "SELECT DISTINCT country
         FROM " . self::table_name() . "
         WHERE created_at >= %s
           AND country IS NOT NULL
           AND country <> ''
         ORDER BY country ASC",
        $cutoff_str
      )
    );

    $out = [];
    foreach ((array) $rows as $cc) {
      $cc = strtoupper(trim((string) $cc));
      if (preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX')
        $out[] = $cc;
    }

    return array_values(array_unique($out));
  }

  /* =========================================================
   * Recent table
   * ======================================================= */

  public static function recent_visits_paged(int $page, int $per_page, int $hours, string $order): array
  {
    global $wpdb;

    $page = max(1, (int) $page);

    $allowed_pp = [20, 50, 100, 500];
    $per_page = (int) $per_page;
    if (!in_array($per_page, $allowed_pp, true))
      $per_page = 50;

    $offset = ($page - 1) * $per_page;

    $hours = max(0, (int) $hours);
    $order = strtoupper((string) $order);
    if (!in_array($order, ['ASC', 'DESC'], true))
      $order = 'DESC';

    $where = '';
    $params = [];

    if ($hours > 0) {
      // ✅ website-time based cutoff converted to UTC for DB compare
      $cutoff_str = self::wp_cutoff_utc_str($hours * HOUR_IN_SECONDS);
      $where = "WHERE created_at >= %s";
      $params[] = $cutoff_str;
    }

    if ($hours > 0) {
      $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM " . self::table_name() . " {$where}",
        $params[0]
      ));
    } else {
      $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . self::table_name());
    }

    $sql = "SELECT id, ip, country, created_at, url, user_agent
            FROM " . self::table_name() . "
            {$where}
            ORDER BY created_at {$order}
            LIMIT %d OFFSET %d";

    if ($hours > 0) {
      $rows = $wpdb->get_results($wpdb->prepare($sql, $params[0], $per_page, $offset), ARRAY_A);
    } else {
      $rows = $wpdb->get_results($wpdb->prepare($sql, $per_page, $offset), ARRAY_A);
    }

    return [
      'rows' => is_array($rows) ? $rows : [],
      'total' => $total,
    ];
  }
}