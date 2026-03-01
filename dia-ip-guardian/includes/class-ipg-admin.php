<?php
if (!defined('ABSPATH')) exit;

class DIA_IPG_Admin
{

  public static function init()
  {
    add_action('admin_menu', [__CLASS__, 'menu']);
    add_action('admin_post_dia_ipg_action', [__CLASS__, 'handle_action']);
  }

  public static function menu()
  {
    add_menu_page(
      'IP Guardian',
      'IP Guardian',
      'manage_options',
      'dia-ip-guardian',
      [__CLASS__, 'render'],
      'dashicons-shield',
      81
    );
  }

  private static function current_tab(): string
  {
    $tab = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'overview';
    $allowed = ['overview', 'recent', 'blocked', 'settings', 'info'];
    return in_array($tab, $allowed, true) ? $tab : 'overview';
  }

  private static function action_url(string $do, string $ip = '', ?string $tab = null): string
  {
    if ($tab === null) $tab = self::current_tab();

    return admin_url('admin-post.php?' . http_build_query([
      'action'   => 'dia_ipg_action',
      'do'       => $do,
      'ip'       => $ip,
      'tab'      => $tab,
      '_wpnonce' => wp_create_nonce('dia_ipg_nonce'),
    ]));
  }

  public static function handle_action()
  {
    if (!current_user_can('manage_options')) wp_die('Forbidden');
    check_admin_referer('dia_ipg_nonce', '_wpnonce');

    $do = isset($_GET['do']) ? sanitize_text_field((string)$_GET['do']) : '';
    $ip = isset($_GET['ip']) ? sanitize_text_field((string)$_GET['ip']) : '';

    // where to return after action
    $return_tab = '';
    if (!empty($_POST['return_tab'])) {
      $return_tab = sanitize_key((string)$_POST['return_tab']);
    } elseif (!empty($_GET['tab'])) {
      $return_tab = sanitize_key((string)$_GET['tab']);
    }
    if (!in_array($return_tab, ['overview', 'recent', 'blocked', 'settings', 'info'], true)) {
      $return_tab = 'overview';
    }

    if ($do === 'save_settings') {
      $new = [
        'ip_source' => isset($_POST['ip_source']) ? sanitize_text_field((string)$_POST['ip_source']) : 'auto',
        'retention_days' => isset($_POST['retention_days']) ? (int)$_POST['retention_days'] : 30,
        'ignore_admins' => !empty($_POST['ignore_admins']) ? 1 : 0,
        'track_logged_in' => !empty($_POST['track_logged_in']) ? 1 : 0,

        'geo_mode' => isset($_POST['geo_mode']) ? sanitize_text_field((string)$_POST['geo_mode']) : 'auto',
        'remote_geo' => !empty($_POST['remote_geo']) ? 1 : 0,
        'remote_geo_vendor' => isset($_POST['remote_geo_vendor']) ? sanitize_text_field((string)$_POST['remote_geo_vendor']) : 'ipapi_co',
        'maxmind_mmdb_path' => isset($_POST['maxmind_mmdb_path']) ? sanitize_text_field((string)$_POST['maxmind_mmdb_path']) : '',
      ];

      if (!in_array($new['ip_source'], ['auto', 'remote_addr', 'cf', 'xff'], true)) $new['ip_source'] = 'auto';
      $new['retention_days'] = max(1, min(365, (int)$new['retention_days']));

      if (!in_array($new['geo_mode'], ['auto', 'off', 'cf', 'maxmind', 'remote'], true)) $new['geo_mode'] = 'auto';
      if (!in_array($new['remote_geo_vendor'], ['ipapi_co', 'ip_api_com'], true)) $new['remote_geo_vendor'] = 'ipapi_co';

      DIA_IPG_Core::set_cfg($new);
    }

    if (($do === 'block' || $do === 'unblock') && filter_var($ip, FILTER_VALIDATE_IP)) {
      $blocked = DIA_IPG_Core::blocked_list();

      if ($do === 'block') {
        if (!in_array($ip, $blocked, true)) $blocked[] = $ip;
      } else {
        $blocked = array_values(array_filter($blocked, fn($x) => $x !== $ip));
      }

      DIA_IPG_Core::set_blocked_list($blocked);
    }

    if ($do === 'cleanup_now') {
      DIA_IPG_Logger::cleanup_logs();
    }

    wp_safe_redirect(admin_url('admin.php?' . http_build_query([
      'page' => 'dia-ip-guardian',
      'tab'  => $return_tab,
    ])));
    exit;
  }

  public static function render()
  {
    if (!current_user_can('manage_options')) return;

    $tab     = self::current_tab();
    $cfg     = DIA_IPG_Core::cfg();
    $blocked = DIA_IPG_Core::blocked_list();

    $nonce = wp_create_nonce('dia_ipg_nonce');
?>
    <div class="wrap">
      <h1>IP Guardian</h1>

      <?php self::render_tabs($tab); ?>

      <div style="margin-top: 14px;">
        <?php
        if ($tab === 'overview') {
          self::render_tab_overview($blocked);
        } elseif ($tab === 'recent') {
          self::render_tab_recent($blocked);
        } elseif ($tab === 'blocked') {
          self::render_tab_blocked($blocked);
        } elseif ($tab === 'settings') {
          self::render_tab_settings($cfg, $nonce);
        } else {
          self::render_tab_info();
        }
        ?>
      </div>
    </div>
  <?php
  }

  private static function render_tabs(string $active_tab): void
  {
    $tabs = [
      'overview' => 'Overview',
      'recent'   => 'Visitor activity ',
      'blocked'  => 'Blocked',
      'settings' => 'Settings',
      'info'     => 'Info',
    ];

    echo '<nav class="nav-tab-wrapper" style="margin-top: 10px;">';
    foreach ($tabs as $key => $label) {
      $url = admin_url('admin.php?' . http_build_query([
        'page' => 'dia-ip-guardian',
        'tab'  => $key,
      ]));
      $class = 'nav-tab' . ($active_tab === $key ? ' nav-tab-active' : '');
      echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';
  }

  /* -------------------- TAB: OVERVIEW -------------------- */

  private static function render_tab_overview(array $blocked): void
  {
?>
    <p style="max-width: 800px;">
      Overview of top visitor IPs. Use sorting, pagination, and rows-per-page without reloading the whole plugin page.
    </p>

    <hr />

    <div style="max-width: 900px; margin-top: 6px;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
        <label for="ipg_top_range"><strong>Top IPs:</strong></label>
        <select id="ipg_top_range">
          <option value="top24" selected>last 24 hours</option>
          <option value="top3d">last 3 days</option>
          <option value="top7d">last 7 days</option>
        </select>

        <span id="ipg-top-loading" style="display:none;margin-left:10px;font-size:12px;opacity:.85;">
          Loading… this may take a few moments
        </span>
      </div>

      <style>
        @keyframes ipgBlink { 0%,100% { opacity: .2; } 50% { opacity: 1; } }
        #ipg-top-loading.ipg-blink { animation: ipgBlink 0.9s infinite; }
      </style>

      <div id="ipg-top-container" data-country="">
        <?php
        $page = 1;
        $per_page = 50;
        $orderby = 'hits';
        $order = 'DESC';

        // ✅ FIX: populate dropdown on first render
        $country = '';
        $countries = DIA_IPG_Logger::top_countries(24);

        $data = DIA_IPG_Logger::top_ips_paged(24, $page, $per_page, $orderby, $order, $country);

        DIA_IPG_Table::render_top_ips_table([
          'table_key' => 'top24',
          'title'     => 'Top IPs — last 24 hours',
          'rows'      => $data['rows'],
          'total'     => $data['total'],
          'page'      => $page,
          'per_page'  => $per_page,
          'orderby'   => $orderby,
          'order'     => $order,
          'blocked'   => $blocked,

          // ✅ dropdown data
          'countries' => $countries,
          'country'   => $country,
        ]);
        ?>
      </div>
    </div>
<?php
  }

  /* -------------------- TAB: RECENT -------------------- */

  private static function render_tab_recent(array $blocked): void
  {
    $recent_hours = isset($_GET['recent_hours']) ? (int)$_GET['recent_hours'] : 24;
    $allowed_hours = [1, 6, 24, 168, 720, 0];
    if (!in_array($recent_hours, $allowed_hours, true)) $recent_hours = 24;

?>
    <p style="max-width: 900px;">
      Recent Visitor Activity shows exact URLs + browser (user agent). You can sort by time and paginate without reloading the whole plugin page.
    </p>

    <hr />

    <h2>Recent Visitor Activity — Full URLs & Browser Info</h2>

    <form method="get" style="margin: 10px 0 14px;">
      <input type="hidden" name="page" value="dia-ip-guardian" />
      <input type="hidden" name="tab" value="recent" />

      <label for="recent_hours" style="margin-right:8px;"><strong>Show:</strong></label>
      <select name="recent_hours" id="recent_hours" onchange="this.form.submit()">
        <option value="1" <?php selected($recent_hours, 1); ?>>Last 1 hour</option>
        <option value="6" <?php selected($recent_hours, 6); ?>>Last 6 hours</option>
        <option value="24" <?php selected($recent_hours, 24); ?>>Last 24 hours</option>
        <option value="168" <?php selected($recent_hours, 168); ?>>Last 7 days</option>
        <option value="720" <?php selected($recent_hours, 720); ?>>Last 30 days</option>
        <option value="0" <?php selected($recent_hours, 0); ?>>All time</option>
      </select>

      <noscript><?php submit_button('Filter', 'secondary', '', false); ?></noscript>
    </form>

<?php
    $page = 1;
    $per_page = 50;
    $order = 'DESC';

    $data = DIA_IPG_Logger::recent_visits_paged($page, $per_page, $recent_hours, $order);

    DIA_IPG_Table::render_recent_table([
      'title'        => 'Recent Visitor Activity — Full URLs & Browser Info',
      'rows'         => $data['rows'],
      'total'        => $data['total'],
      'page'         => $page,
      'per_page'     => $per_page,
      'order'        => $order,
      'recent_hours' => $recent_hours,
      'blocked'      => $blocked,
    ]);
  }

  /* -------------------- TAB: BLOCKED -------------------- */

  private static function render_tab_blocked(array $blocked): void
  {
?>
    <p>All IPs you blocked using the plugin.</p>
    <hr />
    <?php if (empty($blocked)): ?>
      <p>No blocked IPs yet.</p>
    <?php else: ?>
      <ul style="margin-top: 10px;">
        <?php foreach ($blocked as $ip): ?>
          <li style="margin: 6px 0;">
            <code><?php echo esc_html($ip); ?></code>
            <a class="button" style="margin-left:10px;"
              href="<?php echo esc_url(self::action_url('unblock', (string)$ip, 'blocked')); ?>">
              Unblock
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
<?php
  }

  /* -------------------- TAB: SETTINGS -------------------- */

  private static function render_tab_settings(array $cfg, string $nonce): void
  {
?>
    <div style="max-width: 900px;">
      <p>
        Here you can control how IP Guardian detects visitor IPs, how long logs are stored, and how country flags are detected.
        If you’re not sure, the “Auto” options are safe defaults.
      </p>

      <hr />

      <form method="post" action="<?php echo esc_url(self::action_url('save_settings', '', 'settings')); ?>">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>" />
        <input type="hidden" name="return_tab" value="settings" />

        <table class="form-table" role="presentation">
          <tr>
            <th scope="row">IP Source</th>
            <td>
              <select name="ip_source">
                <option value="auto" <?php selected($cfg['ip_source'], 'auto'); ?>>Auto (CF → XFF → REMOTE_ADDR)</option>
                <option value="cf" <?php selected($cfg['ip_source'], 'cf'); ?>>Cloudflare (HTTP_CF_CONNECTING_IP)</option>
                <option value="xff" <?php selected($cfg['ip_source'], 'xff'); ?>>Proxy (X-Forwarded-For)</option>
                <option value="remote_addr" <?php selected($cfg['ip_source'], 'remote_addr'); ?>>Direct (REMOTE_ADDR)</option>
              </select>

              <p class="description" style="margin-top:6px;">
                <strong>What this means:</strong> This controls where the plugin gets the visitor IP from.
                If your site is behind Cloudflare or a proxy, picking the wrong source can show the proxy IP instead of the real visitor.
              </p>

              <ul style="margin:8px 0 0 18px; list-style:disc;">
                <li><strong>Auto:</strong> Best choice for most sites. Tries Cloudflare first, then proxy headers, then direct.</li>
                <li><strong>Cloudflare:</strong> Use this if your site is on Cloudflare and you want the most accurate real visitor IP.</li>
                <li><strong>Proxy (X-Forwarded-For):</strong> Use only if you’re behind a trusted proxy/load balancer that sets XFF correctly.</li>
                <li><strong>Direct (REMOTE_ADDR):</strong> Use if you are not behind Cloudflare/proxies. (Otherwise you may log the proxy IP.)</li>
              </ul>
            </td>
          </tr>

          <tr>
            <th scope="row">Retention (days)</th>
            <td>
              <input type="number" name="retention_days" min="1" max="365"
                value="<?php echo esc_attr((int)$cfg['retention_days']); ?>" />

              <p class="description" style="margin-top:6px;">
                <strong>What this means:</strong> How long visit logs are kept before old entries are deleted automatically.
                Smaller values keep your database lighter.
                A good default is <strong>7–30 days</strong>.
              </p>
            </td>
          </tr>

          <tr>
            <th scope="row">Ignore admins</th>
            <td>
              <label>
                <input type="checkbox" name="ignore_admins" value="1" <?php checked(!empty($cfg['ignore_admins'])); ?> />
                Don’t log admins
              </label>

              <p class="description" style="margin-top:6px;">
                <strong>If enabled:</strong> visits made by Administrator users won’t be logged.
                This is useful if you don’t want your own activity to pollute the logs.
              </p>
            </td>
          </tr>

          <tr>
            <th scope="row">Track logged-in users</th>
            <td>
              <label>
                <input type="checkbox" name="track_logged_in" value="1" <?php checked(!empty($cfg['track_logged_in'])); ?> />
                Track logged-in users too
              </label>

              <p class="description" style="margin-top:6px;">
                <strong>If enabled:</strong> visits from logged-in users (customers, editors, subscribers, etc.) will be logged.
                <br />
                <strong>If disabled:</strong> only visitors who are not logged in will be tracked.
                This can reduce noise and is better for privacy on membership sites.
              </p>
            </td>
          </tr>

          <tr>
            <th colspan="2"><hr /></th>
          </tr>

          <tr>
            <th scope="row">Geo detection</th>
            <td>
              <select name="geo_mode">
                <option value="auto" <?php selected($cfg['geo_mode'], 'auto'); ?>>Auto (CF → MaxMind → Remote)</option>
                <option value="off" <?php selected($cfg['geo_mode'], 'off'); ?>>Off</option>
                <option value="cf" <?php selected($cfg['geo_mode'], 'cf'); ?>>Cloudflare header only</option>
                <option value="maxmind" <?php selected($cfg['geo_mode'], 'maxmind'); ?>>MaxMind (mmdb + library)</option>
                <option value="remote" <?php selected($cfg['geo_mode'], 'remote'); ?>>Remote API (admin only)</option>
              </select>

              <p class="description" style="margin-top:6px;">
                <strong>What this means:</strong> Country detection is used only to show flags / country codes in the admin tables.
                If you don’t need flags, you can turn this off.
              </p>

              <ul style="margin:8px 0 0 18px; list-style:disc;">
                <li><strong>Auto:</strong> Best effort and easiest. Uses Cloudflare if available, otherwise tries MaxMind, then remote API.</li>
                <li><strong>Off:</strong> No country lookup (fastest + most privacy-friendly).</li>
                <li><strong>Cloudflare header only:</strong> Works only if you use Cloudflare. No external lookups.</li>
                <li><strong>MaxMind:</strong> Uses a local database file (fast + private), but needs setup.</li>
                <li><strong>Remote API:</strong> Uses an external service to detect the country (works anywhere, but depends on the vendor and network).</li>
              </ul>

              <label style="display:block;margin-top:10px;">
                <input type="checkbox" name="remote_geo" value="1" <?php checked(!empty($cfg['remote_geo'])); ?> />
                Allow Remote Geo lookup in admin (cached)
              </label>

              <p class="description" style="margin-top:6px;">
                <strong>If enabled:</strong> the admin screen is allowed to request country info from the selected remote vendor,
                and results are cached so you don’t call the API again for the same IP.
                <br />
                <strong>If disabled:</strong> no remote lookups will be made from wp-admin.
              </p>

              <label style="display:block;margin-top:10px;">
                Remote vendor:
                <select name="remote_geo_vendor">
                  <option value="ipapi_co" <?php selected($cfg['remote_geo_vendor'], 'ipapi_co'); ?>>ipapi.co</option>
                  <option value="ip_api_com" <?php selected($cfg['remote_geo_vendor'], 'ip_api_com'); ?>>ip-api.com</option>
                </select>
              </label>

              <p class="description" style="margin-top:6px;">
                Choose which external service is used for remote country detection.
                This is only used if Geo detection is set to <strong>Auto</strong> (fallback) or <strong>Remote API</strong>.
              </p>

              <label style="display:block;margin-top:10px;">
                MaxMind mmdb path (optional):
                <input type="text" name="maxmind_mmdb_path" style="width:420px;"
                  value="<?php echo esc_attr((string)$cfg['maxmind_mmdb_path']); ?>"
                  placeholder="/full/path/to/GeoLite2-Country.mmdb" />
              </label>

              <p class="description" style="margin-top:6px;">
                If you want fast and private geo lookup, use MaxMind.
                You’ll need the <strong>GeoLite2 Country</strong> mmdb file on your server, and the PHP library installed.
                <br />
                Requires <code>GeoIp2\Database\Reader</code> (geoip2/geoip2).
              </p>
            </td>
          </tr>
        </table>

        <?php submit_button('Save settings'); ?>
      </form>

      <p style="margin-top: 12px;">
        <a class="button" href="<?php echo esc_url(self::action_url('cleanup_now', '', 'settings')); ?>">
          Run cleanup now
        </a>
      </p>

      <p class="description" style="margin-top:6px;">
        This removes old log records based on your retention setting. Normally cleanup runs automatically, but you can run it manually anytime.
      </p>
    </div>
<?php
  }

  /* -------------------- TAB: INFO -------------------- */

  private static function render_tab_info(): void
  {
?>
    <h2>What this plugin does</h2>
    <p> This plugin helps you see who is visiting your website and gives you simple tools to protect it. </p>
    <ul style="list-style: disc; padding-left: 18px;">
      <li>Logs each visitor’s IP address, the page they visited, and their browser info</li>
      <li>Shows the most active IPs in the last 24 hours and last 7 days</li>
      <li>Displays recent visits with full URLs and user agents</li>
      <li>Lets you block or unblock any IP with one click</li>
      <li>Optionally detects visitor country (via Cloudflare, MaxMind, or remote API) and shows country flags</li>
    </ul>
    <p> It’s especially useful if you: </p>
    <ul style="list-style: disc; padding-left: 18px;">
      <li>Want to monitor suspicious activity or spam</li>
      <li>Run an online shop and need to track unusual behavior</li>
      <li>Are getting too many fake logins or bot traffic</li>
      <li>Simply want more visibility and control over your website traffic</li>
    </ul>
    <hr />
    <h2>Tips</h2>
    <ul style="list-style: disc; padding-left: 18px;">
      <li>If you use Cloudflare, set <strong>IP Source = Cloudflare</strong> to correctly detect real visitor IPs.</li>
      <li>Choose a reasonable log retention period (for example, 7–30 days) to keep your database clean and fast.</li>
      <li>Use Geo detection set to “Auto” for the best balance between accuracy and simplicity.</li>
    </ul>
    <h2>Note</h2>

    <div style="margin-top:10px;max-width:900px;border:1px solid #e2e4e7;background:#f6f7f7;padding:14px 16px;border-radius:4px;">

      <p style="margin-top:0;">
        The number of hits from a single IP address can help you understand whether traffic is normal or potentially suspicious.
        The table below gives a general guideline for typical WordPress stores.
      </p>

      <table class="widefat striped" style="margin-top:10px;max-width:600px;">
        <thead>
          <tr>
            <th>Hits from 1 IP (24h)</th>
            <th>Interpretation</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>1–50</td>
            <td>Normal visitor activity</td>
          </tr>
          <tr>
            <td>50–150</td>
            <td>Probably a crawler or heavy user</td>
          </tr>
          <tr>
            <td>150–500</td>
            <td>Worth investigating</td>
          </tr>
          <tr>
            <td>500–1000+</td>
            <td>Likely automated bot</td>
          </tr>
          <tr>
            <td>2000+</td>
            <td><strong>Very suspicious — possible attack</strong></td>
          </tr>
        </tbody>
      </table>

      <p style="margin-top:12px;opacity:.85;">
        Keep in mind that search engine bots (Google, Bing, etc.) may generate high traffic and are not always malicious.
        Always review the IP, URL patterns, and user agent before blocking.
      </p>

    </div>

    <div style="color: darkgrey; font-size: 11px;margin-top: 20px; border:1px solid #ddd; padding:12px; border-radius:4px;">
      <h2 style="color: darkgrey;">Privacy note</h2>
      <p style="max-width: 900px;">
        This plugin stores visitor IP addresses and related visit information. In many countries (including the EU and UK), IP addresses may be considered personal data under privacy laws like GDPR.
      </p>
      <p style="max-width: 900px;">
        If you use IP logging, you should mention it in your website’s Privacy Policy. Explain what data is collected (IP address, visited URL, browser information), why it is collected (security, analytics, abuse prevention), and how long it is stored.
      </p>
      <p style="max-width: 900px;">
        You are responsible for ensuring your website complies with applicable data protection laws.
      </p>
    </div>
<?php
  }
}