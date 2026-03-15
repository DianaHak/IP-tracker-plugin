<?php
if (!defined('ABSPATH')) exit;

class DIA_IPG_Geo
{
  /**
   * Fast header-only country (no API, no DB).
   * Best: Cloudflare country header.
   */
  public static function country_from_fast_headers(): string
  {
    if (!empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
      $cc = strtoupper(trim((string) $_SERVER['HTTP_CF_IPCOUNTRY']));
      if (preg_match('/^[A-Z]{2}$/', $cc) && $cc !== 'XX') {
        return $cc;
      }
    }
    return '';
  }

  /**
   * Resolve a country for ANY IP (used in admin tables).
   * - For non-CF sites: relies on MaxMind and/or Remote (admin-only).
   * - On success in wp-admin: we backfill logs table so future tables always have country stored.
   */
  public static function resolve_country_for_ip(string $ip): string
  {
    $ip = trim($ip);
    if (!filter_var($ip, FILTER_VALIDATE_IP)) return '';

    $cfg  = DIA_IPG_Core::cfg();
    $mode = (string) ($cfg['geo_mode'] ?? 'auto');

    if ($mode === 'off') return '';

    // CF-only mode: only works for current visitor request context,
    // but in admin tables we can optionally fallback to remote.
    if ($mode === 'cf') {
      $cc = self::country_from_fast_headers();
      if ($cc) return $cc;

      if (!empty($cfg['remote_geo'])) {
        $cc = self::remote_country($ip, (string) ($cfg['remote_geo_vendor'] ?? 'ipapi_co'));
        if ($cc && is_admin()) self::backfill_country_for_ip($ip, $cc);
        return $cc;
      }
      return '';
    }

    // AUTO: try maxmind (if configured), else remote (if enabled)
    if ($mode === 'auto') {
      $cc = self::maxmind_country($ip, (string) ($cfg['maxmind_mmdb_path'] ?? ''));
      if ($cc) {
        if (is_admin()) self::backfill_country_for_ip($ip, $cc);
        return $cc;
      }

      if (!empty($cfg['remote_geo'])) {
        $cc = self::remote_country($ip, (string) ($cfg['remote_geo_vendor'] ?? 'ipapi_co'));
        if ($cc && is_admin()) self::backfill_country_for_ip($ip, $cc);
        return $cc;
      }

      return '';
    }

    if ($mode === 'maxmind') {
      $cc = self::maxmind_country($ip, (string) ($cfg['maxmind_mmdb_path'] ?? ''));
      if ($cc && is_admin()) self::backfill_country_for_ip($ip, $cc);
      return $cc;
    }

    if ($mode === 'remote') {
      if (empty($cfg['remote_geo'])) return '';
      $cc = self::remote_country($ip, (string) ($cfg['remote_geo_vendor'] ?? 'ipapi_co'));
      if ($cc && is_admin()) self::backfill_country_for_ip($ip, $cc);
      return $cc;
    }

    return '';
  }

  /**
   * Backfill country into logs table for this IP if missing.
   * This is what makes flags "always show" (after first admin view).
   *
   * - Only runs in wp-admin
   * - Only updates rows where country is NULL/empty
   * - Throttled per IP (60s) to prevent repeated writes on big tables
   */
  private static function backfill_country_for_ip(string $ip, string $cc): void
  {
    if (!is_admin()) return;

    $ip = trim($ip);
    $cc = strtoupper(trim($cc));

    if (!filter_var($ip, FILTER_VALIDATE_IP)) return;
    if (!preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'XX') return;

    // throttle DB writes per IP
    $throttle_key = 'dia_ipg_geo_fill_' . md5($ip);
    if (get_transient($throttle_key)) return;
    set_transient($throttle_key, 1, 60);

    // Avoid fatal if logger not loaded for some reason
    if (!class_exists('DIA_IPG_Logger') || !method_exists('DIA_IPG_Logger', 'table_name')) return;

    global $wpdb;
    $table = DIA_IPG_Logger::table_name();

    // Update only missing countries
    $wpdb->query(
      $wpdb->prepare(
        "UPDATE {$table}
         SET country = %s
         WHERE ip = %s
           AND (country IS NULL OR country = '')",
        $cc,
        $ip
      )
    );
  }

  /**
   * Remote country (admin-only recommended).
   * Cached for 7 days to reduce API calls.
   *
   * Key behavior:
   * - We DO NOT negative-cache rate limits (429/403), otherwise flags can "disappear".
   * - We only negative-cache invalid/broken responses briefly (3 minutes).
   * - Cache prefix bumped to v3 to ignore old cached failures from earlier versions.
   */
  public static function remote_country(string $ip, string $vendor): string
  {
    $vendor = $vendor ?: 'ipapi_co';

    // bump prefix so old "0" caches from previous versions won't affect results
    $cache_key = 'dia_ipg_geo_v3_' . md5($vendor . '|' . $ip);

    $cached = get_transient($cache_key);
    if (is_string($cached) && $cached !== '' && $cached !== '0') return $cached;
    if ($cached === '0') return '';

    // Only allow remote lookup in admin to avoid slowing visitors
    if (!is_admin()) return '';

    $cc = '';

    // short negative cache for truly broken responses (NOT for rate limiting)
    $neg = function (int $seconds = 180) use ($cache_key): string {
      set_transient($cache_key, '0', max(30, (int) $seconds));
      return '';
    };

    $ua = 'DIA-IP-Guardian/' . (defined('DIA_IPG_VERSION') ? DIA_IPG_VERSION : '1.0.0');

    if ($vendor === 'ip_api_com') {
      // ip-api.com (rate-limited). Use HTTPS.
      $url  = 'https://ip-api.com/json/' . rawurlencode($ip) . '?fields=status,countryCode,message';
      $resp = wp_remote_get($url, [
        'timeout' => 6,
        'headers' => [
          'Accept'     => 'application/json',
          'User-Agent' => $ua,
        ],
      ]);

      if (is_wp_error($resp)) return $neg(180);

      $code = (int) wp_remote_retrieve_response_code($resp);
      $body = (string) wp_remote_retrieve_body($resp);

      // Rate limited / blocked: do not poison cache (try again later)
      if ($code === 429 || $code === 403) return '';

      if ($code !== 200 || $body === '') return $neg(180);

      $j = json_decode($body, true);
      if (!is_array($j) || ($j['status'] ?? '') !== 'success') return $neg(180);

      $cc = strtoupper(trim((string) ($j['countryCode'] ?? '')));
    } else {
      // ipapi.co
      $url  = 'https://ipapi.co/' . rawurlencode($ip) . '/country/';
      $resp = wp_remote_get($url, [
        'timeout' => 6,
        'headers' => [
          'Accept'     => 'text/plain',
          'User-Agent' => $ua,
        ],
      ]);

      if (is_wp_error($resp)) return $neg(180);

      $code = (int) wp_remote_retrieve_response_code($resp);
      $body = trim((string) wp_remote_retrieve_body($resp));

      // Rate limited: do not poison cache
      if ($code === 429) return '';

      if ($code !== 200 || $body === '') return $neg(180);

      $cc = strtoupper(trim($body));
    }

    // Validate
    if (!preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'XX') {
      return $neg(180);
    }

    set_transient($cache_key, $cc, 7 * DAY_IN_SECONDS);
    return $cc;
  }

  /**
   * MaxMind support (optional)
   */
  public static function maxmind_country(string $ip, string $mmdb_path): string
  {
    $mmdb_path = trim((string) $mmdb_path);
    if ($mmdb_path === '' || !file_exists($mmdb_path)) return '';

    if (!class_exists('\GeoIp2\Database\Reader')) return '';

    try {
      $reader = new \GeoIp2\Database\Reader($mmdb_path);
      $record = $reader->country($ip);
      $cc = strtoupper((string) ($record->country->isoCode ?? ''));
      return preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '';
    } catch (\Throwable $e) {
      return '';
    }
  }

  // -----------------------
  // Emoji flag helpers
  // -----------------------

  public static function country_to_flag(string $cc): string
  {
    $cc = strtoupper(trim($cc));
    if (!preg_match('/^[A-Z]{2}$/', $cc)) return '';

    $a = ord($cc[0]) - 65 + 0x1F1E6;
    $b = ord($cc[1]) - 65 + 0x1F1E6;

    return mb_convert_encoding('&#' . $a . ';&#' . $b . ';', 'UTF-8', 'HTML-ENTITIES');
  }

  public static function render_flag(string $cc): string
  {
    $flag = self::country_to_flag($cc);
    if (!$flag) return '';
    return '<span style="margin-right:6px;">' . $flag . '</span>';
  }

  // -----------------------
  // Twemoji SVG flag helpers
  // -----------------------

  public static function country_to_twemoji_svg(string $cc): string
  {
    $cc = strtoupper(trim($cc));
    if (!preg_match('/^[A-Z]{2}$/', $cc)) return '';

    $a = dechex(0x1F1E6 + (ord($cc[0]) - 65));
    $b = dechex(0x1F1E6 + (ord($cc[1]) - 65));

    return strtolower($a . '-' . $b) . '.svg';
  }

  public static function render_flag_img(string $cc): string
  {
    $cc = strtoupper(trim($cc));
    if (!preg_match('/^[A-Z]{2}$/', $cc) || $cc === 'XX') return '';

    $file = self::country_to_twemoji_svg($cc);
    if (!$file) return '';

    $src = 'https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/svg/' . $file;

    return '<img src="' . esc_url($src) . '" alt="' . esc_attr($cc) . '" style="width:16px;height:16px;vertical-align:-3px;margin-right:6px;" loading="lazy" />';
  }
}