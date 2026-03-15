<?php
if (!defined('ABSPATH'))
  exit;

class DIA_IPG_Table
{
  public static function export_fmt_dt($mysql_dt): string
  {
    return (string) self::fmt_dt($mysql_dt);
  }
  private static function sanitize_ip_search(string $s): string
  {
    $s = trim($s);
    // allow partial typing: digits, hex, dot, colon
    $s = preg_replace('/[^0-9a-fA-F\.\:\s]/', '', $s);
    return trim($s);
  }
  /**
   * Format a MySQL DATETIME string for display in WP timezone.
   */
  private static function fmt_dt($mysql_dt)
  {
    $s = trim((string) $mysql_dt);
    if ($s === '' || $s === '0000-00-00 00:00:00')
      return '';

    $mode = apply_filters('dia_ipg_dt_storage_mode', 'auto');
    $mode = is_string($mode) ? strtolower($mode) : 'auto';
    if (!in_array($mode, ['auto', 'utc', 'local'], true))
      $mode = 'auto';

    try {
      $wp_tz = wp_timezone();

      if ($mode === 'utc') {
        $dt = new DateTimeImmutable($s, new DateTimeZone('UTC'));
        return $dt->setTimezone($wp_tz)->format('H:i d.m.y');
      }
      if ($mode === 'local') {
        $dt = new DateTimeImmutable($s, $wp_tz);
        return $dt->format('H:i d.m.y');
      }

      // AUTO: choose the interpretation closer to "now"
      $dt_utc = new DateTimeImmutable($s, new DateTimeZone('UTC'));
      $as_wp_1 = $dt_utc->setTimezone($wp_tz);

      $dt_local = new DateTimeImmutable($s, $wp_tz);
      $as_wp_2 = $dt_local;

      $now = time();
      $t1 = $as_wp_1->getTimestamp();
      $t2 = $as_wp_2->getTimestamp();

      $d1 = abs($now - $t1);
      $d2 = abs($now - $t2);

      $very_old = 365 * DAY_IN_SECONDS;
      $chosen = ($d1 < $d2 && $d1 < $very_old) ? $as_wp_1 : $as_wp_2;

      return $chosen->format('H:i d.m.y');
    } catch (Exception $e) {
      return '';
    }
  }

  private static function per_page_select($table_key, $per_page)
  {
    $opts = [20, 50, 100, 500];

    echo '<label style="display:inline-flex;gap:8px;align-items:center;">';
    echo '<span style="opacity:.85;">Rows:</span>';
    echo '<select class="ipg-per-page" data-table="' . esc_attr($table_key) . '">';
    foreach ($opts as $o) {
      printf(
        '<option value="%d"%s>%d</option>',
        (int) $o,
        selected((int) $per_page, (int) $o, false),
        (int) $o
      );
    }
    echo '</select>';
    echo '</label>';
  }

  /**
   * Trustworthy IP info source (ASN, org, prefix, RPKI, etc)
   */
  // private static function whois_url(string $ip): string
  // {
  //   return 'https://stat.ripe.net/' . rawurlencode($ip);
  // }

  // private static function whois_button(string $ip): string
  // {
  //   if (!$ip)
  //     return '';
  //   $url = self::whois_url($ip);
  //   return '<a class="button" href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">WHOIS</a>';
  // }

  private static function pagination($table_key, $page, $per_page, $total)
  {
    $total_pages = (int) ceil(max(1, $total) / max(1, $per_page));
    if ($total_pages <= 1)
      return;

    $prev = max(1, $page - 1);
    $next = min($total_pages, $page + 1);

    $prev_disabled = ($page <= 1);
    $next_disabled = ($page >= $total_pages);

    $cls_prev = $prev_disabled ? ' button disabled' : ' button';
    $cls_next = $next_disabled ? ' button disabled' : ' button';

    $attrs_prev = $prev_disabled ? ' aria-disabled="true" tabindex="-1"' : '';
    $attrs_next = $next_disabled ? ' aria-disabled="true" tabindex="-1"' : '';

    echo '<div class="tablenav bottom" style="margin-top:10px;">';
    echo '<div class="tablenav-pages">';
    echo '<span class="displaying-num">' . esc_html($total) . ' items</span> ';

    echo '<a href="#" class="ipg-page' . esc_attr($cls_prev) . '" data-table="' . esc_attr($table_key) . '" data-page="1"' . $attrs_prev . '>&laquo;</a> ';
    echo '<a href="#" class="ipg-page' . esc_attr($cls_prev) . '" data-table="' . esc_attr($table_key) . '" data-page="' . esc_attr($prev) . '"' . $attrs_prev . '>&lsaquo;</a> ';

    echo '<span style="margin:0 8px;">Page <strong>' . esc_html($page) . '</strong> of <strong>' . esc_html($total_pages) . '</strong></span>';

    echo '<a href="#" class="ipg-page' . esc_attr($cls_next) . '" data-table="' . esc_attr($table_key) . '" data-page="' . esc_attr($next) . '"' . $attrs_next . '>&rsaquo;</a> ';
    echo '<a href="#" class="ipg-page' . esc_attr($cls_next) . '" data-table="' . esc_attr($table_key) . '" data-page="' . esc_attr($total_pages) . '"' . $attrs_next . '>&raquo;</a>';

    echo '</div>';
    echo '</div>';
  }

  private static function sort_link($table_key, $label, $orderby_key, $current_orderby, $current_order)
  {
    $is = ($current_orderby === $orderby_key);
    $current_order = strtoupper((string) $current_order);
    if (!in_array($current_order, ['ASC', 'DESC'], true))
      $current_order = 'DESC';

    $next_order = ($is && $current_order === 'DESC') ? 'ASC' : 'DESC';
    $arrow = $is ? (($current_order === 'DESC') ? ' ▼' : ' ▲') : '';

    printf(
      '<a href="#" class="ipg-sort" data-table="%s" data-orderby="%s" data-order="%s">%s%s</a>',
      esc_attr($table_key),
      esc_attr($orderby_key),
      esc_attr($next_order),
      esc_html($label),
      esc_html($arrow)
    );
  }

  private static function normalize_cc(string $cc): string
  {
    $cc = strtoupper(trim($cc));
    // normalize common alias
    if ($cc === 'UK')
      $cc = 'GB';
    return $cc;
  }

  /**
   * Country name (full), uses WooCommerce if available, else fallback map.
   */
  private static function country_name(string $cc): string
  {
    $cc = self::normalize_cc($cc);
    if (!preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'XX')
      return $cc;

    if (class_exists('WC_Countries')) {
      $wc = new WC_Countries();
      $countries = (array) $wc->get_countries();
      if (!empty($countries[$cc])) {
        $name = (string) $countries[$cc];

        // WooCommerce shows "United Kingdom (UK)" for GB — remove the alias part
        if ($cc === 'GB') {
          $name = preg_replace('/\s*\(UK\)\s*$/u', '', $name);
          $name = trim((string) $name);
        }

        return $name;
      }
    }

    static $map = [
    'AF' => 'Afghanistan',
    'AL' => 'Albania',
    'DZ' => 'Algeria',
    'AS' => 'American Samoa',
    'AD' => 'Andorra',
    'AO' => 'Angola',
    'AI' => 'Anguilla',
    'AQ' => 'Antarctica',
    'AG' => 'Antigua and Barbuda',
    'AR' => 'Argentina',
    'AM' => 'Armenia',
    'AW' => 'Aruba',
    'AU' => 'Australia',
    'AT' => 'Austria',
    'AZ' => 'Azerbaijan',
    'BS' => 'Bahamas',
    'BH' => 'Bahrain',
    'BD' => 'Bangladesh',
    'BB' => 'Barbados',
    'BY' => 'Belarus',
    'BE' => 'Belgium',
    'BZ' => 'Belize',
    'BJ' => 'Benin',
    'BM' => 'Bermuda',
    'BT' => 'Bhutan',
    'BO' => 'Bolivia',
    'BA' => 'Bosnia and Herzegovina',
    'BW' => 'Botswana',
    'BR' => 'Brazil',
    'BN' => 'Brunei',
    'BG' => 'Bulgaria',
    'BF' => 'Burkina Faso',
    'BI' => 'Burundi',
    'KH' => 'Cambodia',
    'CM' => 'Cameroon',
    'CA' => 'Canada',
    'CV' => 'Cape Verde',
    'KY' => 'Cayman Islands',
    'CF' => 'Central African Republic',
    'TD' => 'Chad',
    'CL' => 'Chile',
    'CN' => 'China',
    'CO' => 'Colombia',
    'KM' => 'Comoros',
    'CG' => 'Congo',
    'CD' => 'Congo (Democratic Republic)',
    'CR' => 'Costa Rica',
    'CI' => 'Côte d’Ivoire',
    'HR' => 'Croatia',
    'CU' => 'Cuba',
    'CY' => 'Cyprus',
    'CZ' => 'Czech Republic',
    'DK' => 'Denmark',
    'DJ' => 'Djibouti',
    'DM' => 'Dominica',
    'DO' => 'Dominican Republic',
    'EC' => 'Ecuador',
    'EG' => 'Egypt',
    'SV' => 'El Salvador',
    'GQ' => 'Equatorial Guinea',
    'ER' => 'Eritrea',
    'EE' => 'Estonia',
    'ET' => 'Ethiopia',
    'FJ' => 'Fiji',
    'FI' => 'Finland',
    'FR' => 'France',
    'GF' => 'French Guiana',
    'GA' => 'Gabon',
    'GM' => 'Gambia',
    'GE' => 'Georgia',
    'DE' => 'Germany',
    'GH' => 'Ghana',
    'GI' => 'Gibraltar',
    'GR' => 'Greece',
    'GL' => 'Greenland',
    'GD' => 'Grenada',
    'GP' => 'Guadeloupe',
    'GU' => 'Guam',
    'GT' => 'Guatemala',
    'GN' => 'Guinea',
    'GW' => 'Guinea-Bissau',
    'GY' => 'Guyana',
    'HT' => 'Haiti',
    'HN' => 'Honduras',
    'HK' => 'Hong Kong',
    'HU' => 'Hungary',
    'IS' => 'Iceland',
    'IN' => 'India',
    'ID' => 'Indonesia',
    'IR' => 'Iran',
    'IQ' => 'Iraq',
    'IE' => 'Ireland',
    'IL' => 'Israel',
    'IT' => 'Italy',
    'JM' => 'Jamaica',
    'JP' => 'Japan',
    'JO' => 'Jordan',
    'KZ' => 'Kazakhstan',
    'KE' => 'Kenya',
    'KI' => 'Kiribati',
    'KW' => 'Kuwait',
    'KG' => 'Kyrgyzstan',
    'LA' => 'Laos',
    'LV' => 'Latvia',
    'LB' => 'Lebanon',
    'LS' => 'Lesotho',
    'LR' => 'Liberia',
    'LY' => 'Libya',
    'LI' => 'Liechtenstein',
    'LT' => 'Lithuania',
    'LU' => 'Luxembourg',
    'MO' => 'Macau',
    'MK' => 'North Macedonia',
    'MG' => 'Madagascar',
    'MW' => 'Malawi',
    'MY' => 'Malaysia',
    'MV' => 'Maldives',
    'ML' => 'Mali',
    'MT' => 'Malta',
    'MH' => 'Marshall Islands',
    'MQ' => 'Martinique',
    'MR' => 'Mauritania',
    'MU' => 'Mauritius',
    'MX' => 'Mexico',
    'FM' => 'Micronesia',
    'MD' => 'Moldova',
    'MC' => 'Monaco',
    'MN' => 'Mongolia',
    'ME' => 'Montenegro',
    'MA' => 'Morocco',
    'MZ' => 'Mozambique',
    'MM' => 'Myanmar',
    'NA' => 'Namibia',
    'NR' => 'Nauru',
    'NP' => 'Nepal',
    'NL' => 'Netherlands',
    'NZ' => 'New Zealand',
    'NI' => 'Nicaragua',
    'NE' => 'Niger',
    'NG' => 'Nigeria',
    'KP' => 'North Korea',
    'NO' => 'Norway',
    'OM' => 'Oman',
    'PK' => 'Pakistan',
    'PA' => 'Panama',
    'PG' => 'Papua New Guinea',
    'PY' => 'Paraguay',
    'PE' => 'Peru',
    'PH' => 'Philippines',
    'PL' => 'Poland',
    'PT' => 'Portugal',
    'PR' => 'Puerto Rico',
    'QA' => 'Qatar',
    'RO' => 'Romania',
    'RU' => 'Russia',
    'RW' => 'Rwanda',
    'KN' => 'Saint Kitts and Nevis',
    'LC' => 'Saint Lucia',
    'VC' => 'Saint Vincent and the Grenadines',
    'WS' => 'Samoa',
    'SM' => 'San Marino',
    'ST' => 'Sao Tome and Principe',
    'SA' => 'Saudi Arabia',
    'SN' => 'Senegal',
    'RS' => 'Serbia',
    'SC' => 'Seychelles',
    'SL' => 'Sierra Leone',
    'SG' => 'Singapore',
    'SK' => 'Slovakia',
    'SI' => 'Slovenia',
    'SB' => 'Solomon Islands',
    'SO' => 'Somalia',
    'ZA' => 'South Africa',
    'KR' => 'South Korea',
    'ES' => 'Spain',
    'LK' => 'Sri Lanka',
    'SD' => 'Sudan',
    'SR' => 'Suriname',
    'SE' => 'Sweden',
    'CH' => 'Switzerland',
    'SY' => 'Syria',
    'TW' => 'Taiwan',
    'TJ' => 'Tajikistan',
    'TZ' => 'Tanzania',
    'TH' => 'Thailand',
    'TL' => 'Timor-Leste',
    'TG' => 'Togo',
    'TO' => 'Tonga',
    'TT' => 'Trinidad and Tobago',
    'TN' => 'Tunisia',
    'TR' => 'Turkey',
    'TM' => 'Turkmenistan',
    'UG' => 'Uganda',
    'UA' => 'Ukraine',
    'AE' => 'United Arab Emirates',
    'GB' => 'United Kingdom',
    'US' => 'United States',
    'UY' => 'Uruguay',
    'UZ' => 'Uzbekistan',
    'VU' => 'Vanuatu',
    'VA' => 'Vatican City',
    'VE' => 'Venezuela',
    'VN' => 'Vietnam',
    'YE' => 'Yemen',
    'ZM' => 'Zambia',
    'ZW' => 'Zimbabwe',
    ];

    return $map[$cc] ?? $cc;
  }

  /**
   * Always show a flag-like UI:
   * - Try Twemoji SVG <img>
   * - If image blocked (CSP/adblock/CDN), fallback to emoji automatically
   * - If no country, show neutral placeholder
   */
  private static function safe_flag_html($cc)
  {
    $cc = self::normalize_cc((string) $cc);

    if ($cc && preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX') {
      $img = (string) DIA_IPG_Geo::render_flag_img($cc);
      $emoji = (string) DIA_IPG_Geo::render_flag($cc);

      if ($img === '') {
        return $emoji !== ''
          ? $emoji
          : '<span style="display:inline-block;width:16px;height:16px;margin-right:6px;opacity:.35;vertical-align:-3px;">🏳️</span>';
      }

      // onerror fallback -> emoji
      $img = str_replace(
        '<img ',
        '<img onerror="this.outerHTML=' . esc_attr(json_encode($emoji)) . ';" ',
        $img
      );

      return $img;
    }

    return '<span style="display:inline-block;width:16px;height:16px;margin-right:6px;opacity:.35;vertical-align:-3px;">🏳️</span>';
  }

  public static function render_top_ips_table(array $args)
  {
    $table_key = (string) ($args['table_key'] ?? '');
    $rows = (array) ($args['rows'] ?? []);
    $total = (int) ($args['total'] ?? 0);
    $page = (int) ($args['page'] ?? 1);
    $per_page = (int) ($args['per_page'] ?? 50);
    $orderby = (string) ($args['orderby'] ?? 'hits');
    $order = (string) ($args['order'] ?? 'DESC');
    $blocked = (array) ($args['blocked'] ?? []);
    $title = (string) ($args['title'] ?? '');
    $ip_search = self::sanitize_ip_search((string) ($args['ip_search'] ?? ''));
    // ✅ normalize filter (fixes UK/GB selection)
    $country_filter = self::normalize_cc((string) ($args['country'] ?? ''));
    if ($country_filter !== '' && !preg_match('/^[A-Z]{2}$/', $country_filter))
      $country_filter = '';

    $countries = (array) ($args['countries'] ?? []);

    echo '<div class="ipg-table-wrap"'
      . ' data-ipg-table="' . esc_attr($table_key) . '"'
      . ' data-country="' . esc_attr($country_filter) . '"'
      . ' data-orderby="' . esc_attr($orderby) . '"'
      . ' data-order="' . esc_attr($order) . '"'
      . ' data-ip-search="' . esc_attr($ip_search) . '"'
      . ' data-page="' . esc_attr($page) . '"'
      . ' data-per-page="' . esc_attr($per_page) . '"'
      . '>';

    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;margin:10px 0;">';

    // Left: Country dropdown
    echo '<div style="display:flex;align-items:center;gap:10px;">';
    echo '<strong style="font-size:14px;">country:</strong>';

    echo '<select class="ipg-country-filter" data-table="' . esc_attr($table_key) . '" style="min-width:190px;">';
    echo '<option value="">' . esc_html__('All countries', 'dia-ipg') . '</option>';

    // normalize + de-dupe (fixes UK/GB duplicates)
    $norm = [];
    foreach ($countries as $x) {
      $x = self::normalize_cc((string) $x);
      if (preg_match('/^[A-Z]{2}$/', $x) && $x !== 'XX')
        $norm[] = $x;
    }
    $countries = array_values(array_unique($norm));
    sort($countries);

    foreach ($countries as $cc) {
      $name = self::country_name($cc);

      // If name already ends with "(CC)", don't add it again (fixes US/US)
      $ends_with_code = (bool) preg_match('/\(\s*' . preg_quote($cc, '/') . '\s*\)\s*$/u', $name);
      $label = $ends_with_code ? $name : ($name . " ({$cc})");

      printf(
        '<option value="%s"%s>%s</option>',
        esc_attr($cc),
        selected($country_filter, $cc, false),
        esc_html($label)
      );
    }

    echo '</select>';
    echo '</div>';

    // Center: refresh + exports
    echo '<div style="display:flex;align-items:center;gap:16px;flex:1;justify-content:center;flex-wrap:wrap;">';

    echo '<button type="button" style="border:1px solid black;color:black" class="button ipg-refresh" data-table="' . esc_attr($table_key) . '" title="Refresh table">refresh</button>';

    echo '<button type="button" class="button ipg-export-csv" data-table="' . esc_attr($table_key) . '" title="Export this range to CSV">export CSV</button>';

    echo '<button type="button" class="button ipg-export-pdf" data-table="' . esc_attr($table_key) . '" title="Open printable view (Save as PDF)">save as PDF</button>';

    echo '<span class="ipg-refresh-msg" style="display:none;font-size:12px;opacity:.75;">Refreshing…</span>';

    echo '</div>';

    // Right: per page
    self::per_page_select($table_key, $per_page);

    echo '</div>';

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th style="width:34px;text-align:center;padding-left:8px;padding-right:8px;">';
echo '<input type="checkbox" class="ipg-select-all-page" title="select all rows on this page" style="margin:0;" />';
    echo '</th>';
    echo '<th>IP</th>';
    echo '<th>';
    self::sort_link($table_key, 'Hits', 'hits', $orderby, $order);
    echo '</th>';
    echo '<th>';
    self::sort_link($table_key, 'Last seen', 'last_seen', $orderby, $order);
    echo ' (WordPress time)</th>';
    echo '<th>Action</th>';
    echo '</tr></thead>';

    echo '<tbody>';

    if (empty($rows)) {
      echo '<tr><td colspan="6">No data yet.</td></tr>';
    } else {
      $cc_cache = [];

      foreach ($rows as $r) {
        $ip = (string) ($r['ip'] ?? '');
        $hits = (int) ($r['hits'] ?? 0);
        $last_seen = (string) ($r['last_seen'] ?? '');

        $cc = (string) ($r['country'] ?? '');
        if ($ip !== '' && ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc))) {
          if (!isset($cc_cache[$ip]))
            $cc_cache[$ip] = (string) DIA_IPG_Geo::resolve_country_for_ip($ip);
          $cc = (string) $cc_cache[$ip];
        }

        // ✅ normalize final (fixes suffix showing UK)
        $cc = self::normalize_cc((string) $cc);

        $flag = self::safe_flag_html($cc);
        $suffix = ($cc && preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX')
          ? '<span style="opacity:.7;margin-left:6px;">' . esc_html($cc) . '</span>'
          : '';

        $hits_html = esc_html((string) $hits);
        if ($hits >= 1000) {
          $hits_html = '<strong style="color:#d63638;">' . esc_html((string) $hits) . '</strong>';
        } elseif ($hits >= 100) {
          $hits_html = '<strong style="color:#dba617;">' . esc_html((string) $hits) . '</strong>';
        }

        $is_blocked = ($ip && in_array($ip, $blocked, true));
        $act = $is_blocked ? 'unblock' : 'block';
        $label = $is_blocked ? 'unblock' : 'block';
        $class = $is_blocked ? 'button' : 'button button-primary';

        $action_url = admin_url('admin-post.php?' . http_build_query([
          'action' => 'dia_ipg_action',
          'do' => $act,
          'ip' => $ip,
          '_wpnonce' => wp_create_nonce('dia_ipg_nonce'),
        ]));

        echo '<tr>';
        echo '<td style="width:34px;text-align:center;padding:6px 8px;vertical-align:middle;">';
        echo '<input type="checkbox" class="ipg-row-select" value="' . esc_attr($ip) . '" style="margin:0;" />';
        echo '</td>';
        echo '<td>' . $flag . '<code>' . esc_html($ip) . '</code>' . $suffix . '</td>';
        echo '<td>' . $hits_html . '</td>';
        echo '<td>' . esc_html(self::fmt_dt($last_seen)) . '</td>';
        echo '<td>';
        if ($ip)
          echo '<a class="' . esc_attr($class) . '" href="' . esc_url($action_url) . '">' . esc_html($label) . '</a>';
        echo '</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';

    self::pagination($table_key, $page, $per_page, $total);
    echo '</div>';
  }

  public static function render_recent_table(array $args)
  {
    $rows = (array) ($args['rows'] ?? []);
    $total = (int) ($args['total'] ?? 0);
    $page = (int) ($args['page'] ?? 1);
    $per_page = (int) ($args['per_page'] ?? 50);
    $order = strtoupper((string) ($args['order'] ?? 'DESC'));
    $recent_hours = (int) ($args['recent_hours'] ?? 24);
    $blocked = (array) ($args['blocked'] ?? []);
    $title = (string) ($args['title'] ?? '');

    if (!in_array($order, ['ASC', 'DESC'], true))
      $order = 'DESC';

    $arrow = ($order === 'DESC') ? ' ▼' : ' ▲';
    $next_order = ($order === 'DESC') ? 'ASC' : 'DESC';

    echo '<div class="ipg-table-wrap" style="max-width:980px;" data-ipg-table="recent" data-orderby="created_at" data-order="' . esc_attr($order) . '" data-page="' . esc_attr($page) . '" data-per-page="' . esc_attr($per_page) . '" data-recent-hours="' . esc_attr($recent_hours) . '">';

    echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:16px;margin:10px 0;">';
    echo '<strong style="font-size:14px;">' . esc_html($title) . '</strong>';
    self::per_page_select('recent', $per_page);
    echo '</div>';

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>';
    printf(
      '<a href="#" class="ipg-sort" data-table="recent" data-orderby="created_at" data-order="%s">Time%s</a>',
      esc_attr($next_order),
      esc_html($arrow)
    );
    echo '</th>';
    echo '<th>IP</th>';
    echo '<th>URL</th>';
    echo '<th>User Agent</th>';
    echo '<th>Action</th>';
    echo '</tr></thead>';

    echo '<tbody>';

    if (empty($rows)) {
      echo '<tr><td colspan="6">No data.</td></tr>';
    } else {
      $cc_cache = [];

      foreach ($rows as $r) {
        $ip = (string) ($r['ip'] ?? '');

        $cc = (string) ($r['country'] ?? '');
        if ($ip !== '' && ($cc === '' || !preg_match('/^[A-Z]{2}$/', $cc))) {
          if (!isset($cc_cache[$ip]))
            $cc_cache[$ip] = (string) DIA_IPG_Geo::resolve_country_for_ip($ip);
          $cc = (string) $cc_cache[$ip];
        }

        // ✅ normalize final (fixes suffix showing UK)
        $cc = self::normalize_cc((string) $cc);

        $flag = self::safe_flag_html($cc);
        $suffix = ($cc && preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX')
          ? '<span style="opacity:.7;margin-left:6px;">' . esc_html($cc) . '</span>'
          : '';

        $is_blocked = ($ip && in_array($ip, $blocked, true));
        $act = $is_blocked ? 'unblock' : 'block';
        $label = $is_blocked ? 'unblock' : 'block';
        $class = $is_blocked ? 'button' : 'button button-primary';

        $action_url = admin_url('admin-post.php?' . http_build_query([
          'action' => 'dia_ipg_action',
          'do' => $act,
          'ip' => $ip,
          '_wpnonce' => wp_create_nonce('dia_ipg_nonce'),
        ]));

        $u = (string) ($r['url'] ?? '');
        $ua = (string) ($r['user_agent'] ?? '');

        echo '<tr>';
        echo '<td>' . esc_html(self::fmt_dt((string) ($r['created_at'] ?? ''))) . '</td>';
        echo '<td>' . $flag . '<code>' . esc_html($ip) . '</code>' . $suffix . '</td>';
        echo '<td style="max-width:520px;overflow:hidden;text-overflow:ellipsis;">';
        echo '<a href="' . esc_url($u) . '" target="_blank" rel="noopener noreferrer">' . esc_html($u) . '</a>';
        echo '</td>';
        echo '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;">' . esc_html($ua) . '</td>';
        echo '<td>';
        if ($ip)
          echo '<a class="' . esc_attr($class) . '" href="' . esc_url($action_url) . '">' . esc_html($label) . '</a>';
        echo '</td>';
        echo '</tr>';
      }
    }

    echo '</tbody></table>';

    self::pagination('recent', $page, $per_page, $total);
    echo '</div>';
  }
}