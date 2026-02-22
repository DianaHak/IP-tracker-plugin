<?php
/**
 * Plugin Name: DIA IP Guardian (Mini)
 * Description: Logs visitor IPs and shows top IPs (24h / 7d) with easy block/unblock.
 * Version: 1.0.0
 * Author: Diana
 */

if (!defined('ABSPATH'))
  exit;

class DIA_IP_Guardian
{
  const TABLE_LOGS = 'dia_ipg_logs';
  const OPTION_CFG = 'dia_ipg_cfg';
  const OPTION_BLK = 'dia_ipg_blocked';
  const CRON_HOOK = 'dia_ipg_cleanup';

  public static function init()
  {
    register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);
    register_deactivation_hook(__FILE__, [__CLASS__, 'on_deactivate']);

    add_action('init', [__CLASS__, 'maybe_block_request'], 0);
    add_action('template_redirect', [__CLASS__, 'track_visit'], 1);

    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_post_dia_ipg_action', [__CLASS__, 'handle_admin_action']);

    add_action(self::CRON_HOOK, [__CLASS__, 'cleanup_logs']);
  }

  public static function on_activate()
  {
    global $wpdb;

    $table = $wpdb->prefix . self::TABLE_LOGS;
    $charset_collate = $wpdb->get_charset_collate();

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $sql = "CREATE TABLE {$table} (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
      ip VARCHAR(64) NOT NULL,
      user_agent VARCHAR(255) NULL,
      url TEXT NULL,
      created_at DATETIME NOT NULL,
      PRIMARY KEY (id),
      KEY ip_created (ip, created_at),
      KEY created_at (created_at)
    ) {$charset_collate};";

    dbDelta($sql);

    // Default config
    if (!get_option(self::OPTION_CFG)) {
      add_option(self::OPTION_CFG, [
        'ip_source' => 'auto',   // auto | remote_addr | cf | xff
        'retention_days' => 30,
        'ignore_admins' => 1,
        'track_logged_in' => 0,  // 0 = only guests by default
      ]);
    }

    if (!get_option(self::OPTION_BLK)) {
      add_option(self::OPTION_BLK, []); // array of blocked IPs
    }

    if (!wp_next_scheduled(self::CRON_HOOK)) {
      wp_schedule_event(time() + 3600, 'daily', self::CRON_HOOK);
    }
  }

  public static function on_deactivate()
  {
    wp_clear_scheduled_hook(self::CRON_HOOK);
  }

  private static function cfg()
  {
    $cfg = get_option(self::OPTION_CFG, []);
    $defaults = [
      'ip_source' => 'auto',
      'retention_days' => 30,
      'ignore_admins' => 1,
      'track_logged_in' => 0,
    ];
    return array_merge($defaults, is_array($cfg) ? $cfg : []);
  }

  private static function is_admin_user()
  {
    return is_user_logged_in() && current_user_can('manage_options');
  }

  public static function get_client_ip()
  {
    $cfg = self::cfg();
    $source = $cfg['ip_source'];

    $remote = isset($_SERVER['REMOTE_ADDR']) ? trim((string) $_SERVER['REMOTE_ADDR']) : '';

    $cf = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']) : '';
    $xff = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim((string) $_SERVER['HTTP_X_FORWARDED_FOR']) : '';

    // Helper: choose first valid IP from XFF
    $first_from_xff = function ($v) {
      $parts = array_map('trim', explode(',', (string) $v));
      foreach ($parts as $p) {
        if (filter_var($p, FILTER_VALIDATE_IP))
          return $p;
      }
      return '';
    };

    $ip = '';

    if ($source === 'remote_addr') {
      $ip = $remote;
    } elseif ($source === 'cf') {
      $ip = $cf ?: $remote;
    } elseif ($source === 'xff') {
      $ip = $first_from_xff($xff) ?: $remote;
    } else { // auto
      // Prefer CF if present, then XFF, else REMOTE_ADDR
      $ip = $cf ?: ($first_from_xff($xff) ?: $remote);
    }

    // Validate
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
      return '';
    }
    return $ip;
  }

  public static function maybe_block_request()
  {
    // Do not block WP admin area / ajax / cron
    if (is_admin())
      return;
    if (defined('DOING_AJAX') && DOING_AJAX)
      return;
    if (defined('DOING_CRON') && DOING_CRON)
      return;

    $ip = self::get_client_ip();
    if (!$ip)
      return;

    $blocked = get_option(self::OPTION_BLK, []);
    if (!is_array($blocked))
      $blocked = [];

    if (in_array($ip, $blocked, true)) {
      status_header(403);
      header('Content-Type: text/plain; charset=utf-8');
      echo "403 Forbidden";
      exit;
    }
  }

  public static function track_visit()
  {
    // Don’t track wp-admin, feeds, REST, etc.
    if (is_admin())
      return;

    $cfg = self::cfg();

    // Ignore admins (optional)
    if (!empty($cfg['ignore_admins']) && self::is_admin_user())
      return;

    // Track logged-in users?
    if (empty($cfg['track_logged_in']) && is_user_logged_in())
      return;

    $ip = self::get_client_ip();
    if (!$ip)
      return;

    // Don’t track blocked (already handled earlier, but just in case)
    $blocked = get_option(self::OPTION_BLK, []);
    if (is_array($blocked) && in_array($ip, $blocked, true))
      return;

    // Avoid tracking static assets & admin-ajax, etc.
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if (stripos($uri, '/wp-json/') === 0)
      return;
    if (stripos($uri, '/wp-admin/') === 0)
      return;
    if (stripos($uri, 'admin-ajax.php') !== false)
      return;

    $url = home_url($uri);
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : '';

    global $wpdb;
    $table = $wpdb->prefix . self::TABLE_LOGS;

    // Simple throttling: don’t log same IP+URL more than once per 60 seconds
    $key = 'dia_ipg_' . md5($ip . '|' . $uri);
    if (get_transient($key))
      return;
    set_transient($key, 1, 60);

    $wpdb->insert(
      $table,
      [
        'ip' => $ip,
        'user_agent' => $ua,
        'url' => $url,
        'created_at' => current_time('mysql'),
      ],
      ['%s', '%s', '%s', '%s']
    );
  }

  public static function cleanup_logs()
  {
    $cfg = self::cfg();
    $days = max(1, (int) $cfg['retention_days']);

    global $wpdb;
    $table = $wpdb->prefix . self::TABLE_LOGS;

    $wpdb->query(
      $wpdb->prepare(
        "DELETE FROM {$table} WHERE created_at < (NOW() - INTERVAL %d DAY)",
        $days
      )
    );
  }

  public static function admin_menu()
  {
    add_menu_page(
      'DIA IP Guardian',
      'IP Guardian',
      'manage_options',
      'dia-ip-guardian',
      [__CLASS__, 'render_admin'],
      'dashicons-shield',
      81
    );
  }

  private static function admin_url_action($action, $ip = '')
  {
    $args = [
      'action' => 'dia_ipg_action',
      'do' => $action,
      'ip' => $ip,
      '_wpnonce' => wp_create_nonce('dia_ipg_nonce'),
    ];
    return admin_url('admin-post.php?' . http_build_query($args));
  }

  public static function handle_admin_action()
  {
    if (!current_user_can('manage_options'))
      wp_die('Forbidden');
    check_admin_referer('dia_ipg_nonce', '_wpnonce');
    $do = isset($_GET['do']) ? sanitize_text_field((string) $_GET['do']) : '';
    $ip = isset($_GET['ip']) ? sanitize_text_field((string) $_GET['ip']) : '';

    $cfg = self::cfg();

    if ($do === 'save_settings') {
      $new = [
        'ip_source' => isset($_POST['ip_source']) ? sanitize_text_field((string) $_POST['ip_source']) : 'auto',
        'retention_days' => isset($_POST['retention_days']) ? (int) $_POST['retention_days'] : 30,
        'ignore_admins' => !empty($_POST['ignore_admins']) ? 1 : 0,
        'track_logged_in' => !empty($_POST['track_logged_in']) ? 1 : 0,
      ];
      if (!in_array($new['ip_source'], ['auto', 'remote_addr', 'cf', 'xff'], true))
        $new['ip_source'] = 'auto';
      $new['retention_days'] = max(1, min(365, (int) $new['retention_days']));
      update_option(self::OPTION_CFG, $new);
    }

    if (($do === 'block' || $do === 'unblock') && filter_var($ip, FILTER_VALIDATE_IP)) {
      $blocked = get_option(self::OPTION_BLK, []);
      if (!is_array($blocked))
        $blocked = [];

      if ($do === 'block') {
        if (!in_array($ip, $blocked, true))
          $blocked[] = $ip;
      } else {
        $blocked = array_values(array_filter($blocked, fn($x) => $x !== $ip));
      }

      update_option(self::OPTION_BLK, $blocked);
    }

    if ($do === 'cleanup_now') {
      self::cleanup_logs();
    }

    wp_safe_redirect(admin_url('admin.php?page=dia-ip-guardian'));
    exit;
  }

  private static function top_ips($hours)
  {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE_LOGS;

    $hours = max(1, (int) $hours);

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT ip, COUNT(*) AS hits, MAX(created_at) AS last_seen
         FROM {$table}
         WHERE created_at >= (NOW() - INTERVAL %d HOUR)
         GROUP BY ip
         ORDER BY hits DESC
         LIMIT 50",
        $hours
      ),
      ARRAY_A
    );
    return is_array($rows) ? $rows : [];
  }

  private static function recent_visits($limit = 50)
  {
    global $wpdb;
    $table = $wpdb->prefix . self::TABLE_LOGS;

    $limit = max(1, min(200, (int) $limit));

    $rows = $wpdb->get_results(
      $wpdb->prepare(
        "SELECT ip, created_at, url, user_agent
         FROM {$table}
         ORDER BY created_at DESC
         LIMIT %d",
        $limit
      ),
      ARRAY_A
    );
    return is_array($rows) ? $rows : [];
  }

  public static function render_admin()
  {
    if (!current_user_can('manage_options'))
      return;

    $cfg = self::cfg();
    $blocked = get_option(self::OPTION_BLK, []);
    if (!is_array($blocked))
      $blocked = [];

    $top24 = self::top_ips(24);
    $top7d = self::top_ips(24 * 7);
    $recent = self::recent_visits(60);

    $nonce = wp_create_nonce('dia_ipg_nonce');
    ?>
    <div class="wrap">
      <h1>DIA IP Guardian</h1>
      <p style="max-width: 900px;">
        Tracks visitor IPs and lets you block them. <strong>Note:</strong> IP logging may be personal data — mention it in
        your privacy policy if needed.
      </p>

      <hr />

      <h2>Settings</h2>
      <form method="post" action="<?php echo esc_url(self::admin_url_action('save_settings')); ?>">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">IP Source</th>
            <td>
              <select name="ip_source">
                <option value="auto" <?php selected($cfg['ip_source'], 'auto'); ?>>Auto (CF → XFF → REMOTE_ADDR)</option>
                <option value="cf" <?php selected($cfg['ip_source'], 'cf'); ?>>Cloudflare (HTTP_CF_CONNECTING_IP)</option>
                <option value="xff" <?php selected($cfg['ip_source'], 'xff'); ?>>Proxy (X-Forwarded-For)</option>
                <option value="remote_addr" <?php selected($cfg['ip_source'], 'remote_addr'); ?>>Direct (REMOTE_ADDR)
                </option>
              </select>
              <p class="description">If your site is behind Cloudflare, choose <strong>Cloudflare</strong> for real visitor
                IPs.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Retention (days)</th>
            <td>
              <input type="number" name="retention_days" min="1" max="365"
                value="<?php echo esc_attr((int) $cfg['retention_days']); ?>" />
              <p class="description">Logs older than this are deleted daily.</p>
            </td>
          </tr>
          <tr>
            <th scope="row">Ignore admins</th>
            <td>
              <label><input type="checkbox" name="ignore_admins" value="1" <?php checked(!empty($cfg['ignore_admins'])); ?> /> Don’t log admins (manage_options)</label>
            </td>
          </tr>
          <tr>
            <th scope="row">Track logged-in users</th>
            <td>
              <label><input type="checkbox" name="track_logged_in" value="1" <?php checked(!empty($cfg['track_logged_in'])); ?> /> Track logged-in users too</label>
            </td>
          </tr>
        </table>
        <?php submit_button('Save settings'); ?>
      </form>

      <p>
        <a class="button" href="<?php echo esc_url(self::admin_url_action('cleanup_now')); ?>">Run cleanup now</a>
      </p>

      <hr />

      <h2>Blocked IPs</h2>
      <?php if (empty($blocked)): ?>
        <p>No blocked IPs yet.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($blocked as $ip): ?>
            <li>
              <code><?php echo esc_html($ip); ?></code>
              <a href="<?php echo esc_url(self::admin_url_action('unblock', $ip)); ?>">Unblock</a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <hr />

      <h2>Top IPs — last 24 hours</h2>
      <?php self::render_top_table($top24, $blocked); ?>

      <h2 style="margin-top: 28px;">Top IPs — last 7 days</h2>
      <?php self::render_top_table($top7d, $blocked); ?>

      <hr />

      <h2>Recent visits</h2>
      <table class="widefat striped">
        <thead>
          <tr>
            <th>Time</th>
            <th>IP</th>
            <th>URL</th>
            <th>User Agent</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td><?php echo esc_html($r['created_at']); ?></td>
              <td><code><?php echo esc_html($r['ip']); ?></code></td>
              <td style="max-width: 520px; overflow: hidden; text-overflow: ellipsis;">
                <a href="<?php echo esc_url($r['url']); ?>" target="_blank" rel="noopener noreferrer">
                  <?php echo esc_html($r['url']); ?>
                </a>
              </td>
              <td style="max-width: 320px; overflow: hidden; text-overflow: ellipsis;">
                <?php echo esc_html($r['user_agent']); ?>
              </td>
              <td>
                <?php if (in_array($r['ip'], $blocked, true)): ?>
                  <a class="button" href="<?php echo esc_url(self::admin_url_action('unblock', $r['ip'])); ?>">Unblock</a>
                <?php else: ?>
                  <a class="button button-primary"
                    href="<?php echo esc_url(self::admin_url_action('block', $r['ip'])); ?>">Block</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

    </div>
    <?php
  }

  private static function render_top_table($rows, $blocked)
  {
    ?>
    <table class="widefat striped">
      <thead>
        <tr>
          <th>IP</th>
          <th>Hits</th>
          <th>Last seen</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr>
            <td colspan="4">No data yet.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><code><?php echo esc_html($row['ip']); ?></code></td>
              <td><?php echo esc_html($row['hits']); ?></td>
              <td><?php echo esc_html($row['last_seen']); ?></td>
              <td>
                <?php if (in_array($row['ip'], $blocked, true)): ?>
                  <a class="button" href="<?php echo esc_url(self::admin_url_action('unblock', $row['ip'])); ?>">Unblock</a>
                <?php else: ?>
                  <a class="button button-primary"
                    href="<?php echo esc_url(self::admin_url_action('block', $row['ip'])); ?>">Block</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
    <?php
  }
}

DIA_IP_Guardian::init();