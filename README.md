DIA IP Guardian

DIA IP Guardian is a minimal, developer-focused WordPress plugin that logs visitor IP activity and helps you detect, investigate, and block suspicious traffic — without external analytics, SaaS dashboards, or bloated security suites.

Built for developers and site owners who want simple, local infrastructure visibility directly inside wp-admin.


## ✨ Features

Logs visitor IP + time + URL + user agent

Top IPs tables:

Last 24 hours

Last 3 days

Last 7 days

Recent visitor activity table (full URL + browser info)

Built-in IP search

Country dropdown filter (shows only countries you actually received traffic from)

Optional country detection + flags

Cloudflare header

MaxMind mmdb (local)

Remote vendor fallback (admin-only, cached)

Notes tab — attach comments to specific IPs (e.g. “Googlebot”, “Payment webhook”, “Suspicious scraper”)

One-click Block / Unblock

Export Top IPs as CSV

Print / Save as PDF

AJAX sorting / filtering / pagination (no full page reload)

Automatic log cleanup (configurable retention)

No frontend scripts, no trackers

----

## 🎯 Why this plugin exists

Most analytics plugins focus on marketing metrics and hide raw IP-level visibility.

DIA IP Guardian focuses on infrastructure visibility:

Identify suspicious crawlers & scrapers

Detect spam traffic patterns

Monitor unusual spikes

Track repeated hits from a single IP

Quickly block abusive IPs

Attach internal notes to IPs for investigation

All directly from the WordPress dashboard.

----

## 🧠 Traffic Behavior Guidance

The plugin includes a simple guideline table to help interpret traffic behavior:

Hits from 1 IP (24h)	Interpretation
1–50	Normal visitor activity
50–150	Probably a crawler or heavy user
150–500	Worth investigating
500–1000+	Likely automated bot
2000+	Very suspicious — possible attack

These numbers help you understand the nature of your traffic — not every high number is malicious (search engines can generate heavy traffic).

Always review:

IP

URL patterns

User agent

Country (if available)

before blocking.

----

## 🧭 Geo / Country Flags (optional)

Country flags are shown in admin tables when country can be resolved.

Supported modes:

Auto (recommended): Cloudflare → MaxMind → Remote (if enabled)

Cloudflare only: Uses HTTP_CF_IPCOUNTRY

MaxMind: Local GeoLite2 mmdb (fast + private)

Remote API (admin-only): Optional fallback, cached

Off: Disables country lookup

Remote geo lookups are admin-only to avoid slowing down visitors.

----

## 📝 Notes Tab

The Notes tab allows you to attach comments to specific IP addresses.

Example use cases:

Mark known bots (Googlebot, Bingbot)

Identify payment provider callbacks

Flag suspicious scrapers

Mark your office / home IP

Keep investigation context for recurring traffic

Notes are stored locally in WordPress and can be edited or deleted at any time.

----

## ⚡ Lightweight by design

No external analytics

No SaaS dependencies

Minimal database footprint (dedicated log table)

Notes stored in WordPress options

No frontend JS/CSS

Runs only inside wp-admin

Designed for performance and clarity

----

## 🛠 Use cases

Developers debugging suspicious traffic

WooCommerce stores monitoring bots & scraping

Sites behind Cloudflare needing real IP visibility

Minimal security setups without heavy plugins

Technical site owners who want raw access-level visibility

----

## 🧠 Technical Notes

Logs stored locally in a dedicated table:

ip

country

url

user_agent

created_at

Indexed for fast IP + date queries

Cleanup runs via WP Cron

Admin tables support AJAX pagination, sorting, filtering & searching

Export supports full-range CSV (not limited to visible page)

----

## 🔒 Privacy Note

IP addresses may be considered personal data in some regions (e.g., GDPR).

If you use this plugin in production, mention IP logging in your Privacy Policy:

What is collected (IP, URL, user agent, time)

Why it is collected (security, abuse prevention)

How long it is stored (retention setting)

You are responsible for compliance with applicable data protection laws.

----

## 📌 Status

Stable and actively evolving.
Originally built as a lightweight internal tool and now shared publicly for developers who want a clean, minimal IP monitoring solution.

----
## 📷 Screenshots

### Overview – Top IPs
![Overview](dia-ip-guardian/assets/screenshots/overview.png)

### Overview – Country Filter (All Countries)
![Overview All Countries](dia-ip-guardian/assets/screenshots/overview-all-countries.png)

### Recent Visitor Activity
![Visitor Activity](dia-ip-guardian/assets/screenshots/Visitor%20activity.png)

### Settings Screen
![Settings](dia-ip-guardian/assets/screenshots/settings.png)

---

## License
GPLv2 or later
