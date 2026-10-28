=== Lean Broken Link Checker ===
Tags: broken links, link checker, wayback machine, rate limit, activity log, zero db bloat
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A high-performance, ultra-lightweight link scanner built natively into WordPress with zero permanent database bloat, continuous cron link cycling, user-readable activity log, Wayback Machine 1-click repairs, and standard WordPress notifications.

== Description ==

Unlike heavy legacy link checkers that inject megabytes of database bloat and bog down your MySQL server, Lean Broken Link Checker is built for pure efficiency:

* **Clean Minimal Tables:** Stores active broken link records in `{$wpdb->prefix}lean_broken_links` and recent check events in `{$wpdb->prefix}lean_link_log`.
* **User-Readable Activity Log:** Displays a live, color-coded log of recent link checks with latency, HTTP status, and detailed responses. Automatically rotates out old entries based on your configured limit (default: 100 rows).
* **Continuous Cron Cycling:** Checks an incremental batch of links (default: 30 links every 24 hours), automatically prioritizing the oldest verified links so every URL on your site is continuously checked without server load spikes.
* **Server-Safe Rate Limiting:** Enforces a strict default rate ceiling of 10 requests per minute (6.0s delay between requests) and 15s connection timeout to protect your CPU/bandwidth and avoid bot bans.
* **Zero Residue on Uninstall:** Includes a native `uninstall.php` script that immediately drops both custom tables and removes all configuration options when deleted, leaving no residue behind.
* **Standard Built-in WordPress Notifications:** Uses standard WordPress dashboard alert notices and core `wp_mail` to the administrator.
* **Wayback Machine Integration:** Automatically queries the Internet Archive for snapshot URLs of dead links, offering 1-click in-place link replacement.
* **Domain Ignore List & Post Types:** Exclude social networks or aggressive bot-blocking domains, and choose exactly which post types to scan. A domain such as `x.com` excludes that host and its subdomains; an entry with a path such as `example.com/members/` excludes URLs starting with it.
* **SSRF Protection:** Links that point to, or redirect to, private and loopback addresses are never requested.
* **WP-CLI Support:** Run full scans, incremental cron batches, inspect reports/logs, or repair links directly from the shell (`wp link-check scan`, `wp link-check cron`, `wp link-check log`, `wp link-check report`, `wp link-check fix`). `fix` edits a post, so run it with `--user=<an editor or admin>`.

== Installation ==

1. Upload the `lean-broken-link-checker-<version>.zip` package via Plugins -> Add New -> Upload Plugin (or unzip it into `/wp-content/plugins/`). To update, upload the newer zip the same way and choose "Replace current with uploaded".
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Access the dashboard under **Tools -> Broken Links** or click **Settings** under the plugin name.
