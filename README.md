# Lean Broken Link Checker

A lightweight broken link checker for WordPress. It checks links and images in published content in small rate-limited batches from WP-Cron, keeps a short activity log, finds Wayback Machine snapshots for dead links with one-click replacement, and removes all of its data on uninstall.

Features, settings and WP-CLI commands are described in [`readme.txt`](readme.txt) (the standard WordPress plugin readme).

## Install

1. Download `lean-broken-link-checker-<version>.zip` from the [latest release](../../releases/latest).
2. In WordPress, go to **Plugins → Add New → Upload Plugin**, choose the zip and activate it.
3. Settings are under **Tools → Broken Links → Settings**.

To update, upload the newer zip the same way and choose **Replace current with uploaded**. The plugin runs its upgrade steps on the next page load.

Each release also has a `.sha256` file. To check a download: `sha256sum -c lean-broken-link-checker-<version>.zip.sha256`.

Requires WordPress 5.8+ and PHP 7.4+.

## Repository layout

| Path | Purpose |
|------|---------|
| `lean-broken-link-checker.php` | Plugin bootstrap: header, constants, cron, activation and upgrade |
| `includes/` | Scanner, database, Wayback Machine, admin screens, WP-CLI commands |
| `assets/` | Admin JavaScript and CSS |
| `uninstall.php` | Removes tables, options, user meta and the cron event |
| `VERSION` | The plugin version (source of truth) |
| `CHANGELOG.md` | Changes per version; becomes the release notes |
| `bin/version.sh` | Print, check or set the version |
| `bin/build.sh` | Build the installable zip into `dist/` |
| `.github/workflows/` | CI (lint, version check, build) and tag-triggered releases |

Only the plugin bootstrap, `uninstall.php`, `readme.txt`, `LICENSE`, `includes/` and `assets/` go into the zip.

## Development

```bash
composer install       # PHPCS with the WordPress Coding Standards and PHPCompatibility
composer lint          # security sniffs and PHP 7.4+ compatibility (phpcs.xml.dist)
bin/version.sh check   # plugin header, constant, readme.txt and CHANGELOG.md match VERSION
bin/build.sh           # dist/lean-broken-link-checker-<version>.zip and .sha256
```

`bin/build.sh` needs Bash and Python 3. The same commit always builds a byte-identical zip.

## Versioning and releases

Versions follow [Semantic Versioning](https://semver.org/): patch for fixes, minor for new features or settings, major for changes that break existing settings, data or WP-CLI usage.

The version appears in four places, which must agree: `VERSION`, the plugin header `Version:`, the `LEAN_BLC_VERSION` constant and the `Stable tag:` in `readme.txt`. WordPress reads the header; the constant also busts the admin asset cache and drives the upgrade routine. Never edit them by hand:

1. `bin/version.sh set 1.4.0` writes the version everywhere and adds a dated stub to `CHANGELOG.md`.
2. Fill in the changelog entry and commit.
3. Tag the commit and push the tag:
   ```bash
   git tag -a v1.4.0 -m "v1.4.0"
   git push origin v1.4.0
   ```
4. The **Release** workflow checks that the tag matches `VERSION`, builds the zip and publishes a GitHub release with the zip, its checksum and the changelog entry as notes.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
