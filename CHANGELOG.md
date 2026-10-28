# Changelog

All notable changes to this plugin. Versions follow [Semantic Versioning](https://semver.org/).

## [1.3.0] - 2026-10-28
### Security
- Link checks no longer follow redirects to internal addresses. Before, a link to an outside site that redirected to a private or loopback address (for example a cloud metadata service) was requested from the server, and the link was reported healthy. Every redirect is now validated (`wp_safe_remote_request()`), and such links are logged as blocked.
- "Fix with Archive" writes only the Wayback snapshot the scanner recorded for that link. Before, the replacement URL was taken from the browser request.
- Wayback snapshot URLs are accepted only when they point to `https://web.archive.org/`.
- Links in the admin pages open with `rel="noopener noreferrer"`, so the admin URL is not sent to the linked sites.
- Saved post types are limited to registered public post types.

### Fixed
- Links with more than one query parameter were reported broken. Post content stores `&` as `&amp;` inside links (the block editor always does), and the scanner requested the URL with `&amp;` in it. Entities are now decoded before a link is checked.
- Replacing a link stripped every backslash from the post, which damaged block attributes such as `"` escapes. Post content is now slashed before it is saved.
- Replacing a link only changes whole `href`/`src` values. Before, if the exact attribute was not found, every occurrence of the URL text was replaced, including inside longer URLs and plain text.
- Excluded domains match the host and its subdomains: `x.com` no longer excludes `dropbox.com`, and a domain that appears only in a query string no longer excludes the link. Entries containing `/` match as a URL prefix.
- A full scan saves broken links as it finds them, so a scan cut short by a timeout keeps its results. Before, all results were lost because the table was emptied first and only filled at the end.
- Cron batches, manual batches and full scans no longer run at the same time; a second run reports that one is already in progress.
- Manual scans keep running when a proxy (such as Cloudflare's 100-second limit) drops the browser request.
- Dismissing a link recounts the broken links instead of decrementing the stored count.
- "Time ago" in the activity log is no longer off by the site's UTC offset.
- WP-CLI `cron` on a site with no links printed PHP warnings.

### Changed
- The per-URL check registry (`lean_blc_url_timestamps`) is no longer autoloaded on every page.
- Updates installed by uploading a newer zip now run the plugin's upgrade routine (WordPress does not run activation hooks on updates).
- Uninstall also removes per-user notice dismissals, the stored version and the scan lock.
- The rate limit is capped at 60 requests per minute everywhere (the settings form already was).
- Requires PHP 7.4 or later. Tested with WordPress 7.1.

## [1.2.0] - 2026-10-25
### Added
- First version in this repository.
