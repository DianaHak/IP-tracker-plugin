DIA IP Guardian

DIA IP Guardian is a minimal, developer-friendly WordPress plugin for tracking visitor IPs and blocking suspicious traffic.

It was built for developers and site owners who want simple, local IP monitoring without relying on external analytics or bloated security plugins.

✨ Features

Logs visitor IPs with timestamp, URL, and user agent

View Top IPs (last 24 hours and 7 days)

Recent visit log inside WordPress admin

One-click Block / Unblock IP

Works behind Cloudflare and proxies

Automatic log cleanup (configurable retention)

No external APIs, no tracking services

🎯 Why this plugin exists

Most WordPress analytics plugins focus on marketing data and hide raw IP access.
DIA IP Guardian focuses on infrastructure visibility:

Identify suspicious crawlers

Detect spam or scraping

Monitor unusual traffic spikes

Quickly block abusive IPs

All directly from the dashboard.

⚡ Lightweight by design

No external services

No SaaS dependencies

Minimal database footprint

No frontend scripts

Everything runs locally inside WordPress.

🔒 Privacy note

IP addresses may be considered personal data in some regions.
If you use this plugin in production, make sure to mention IP logging in your privacy policy where applicable.

🛠 Use cases

Developers debugging suspicious traffic

WooCommerce store owners monitoring bots

Sites behind Cloudflare needing real IP visibility

Minimal security setups without heavy plugins

🧠 Technical notes

Supports Cloudflare (HTTP_CF_CONNECTING_IP)

Supports proxy headers (X-Forwarded-For)

Logs stored locally in a dedicated table

Automatic cleanup via WP Cron

📌 Status

Early but stable.
Built as a simple internal tool and shared publicly for developers who want a clean, minimal solution.
