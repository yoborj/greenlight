# Changelog

## 0.2.0 — 2026-10-22

### Added
- **Security tab**, one switch per feature. The Security tab ignores the master switch and `?greenlight=off`.
  - Security headers on front-end pages: `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`,
    `Permissions-Policy` (camera, microphone, geolocation off). Headers already sent by the host, theme or
    another plugin win.
  - HSTS (off by default, max-age 300 seconds to start) and a Content Security Policy in report-only mode
    (never enforced: Greenlight's delayed-script loader has no nonce).
  - Hide usernames from logged-out visitors: `?author=N` redirects home, no `/wp/v2/users` REST routes, no
    core users sitemap, no author in oEmbed. The block editor keeps working.
  - XML-RPC off (`xmlrpc.php` answers 403), with a warning when Jetpack is active.
  - File editor off (`DISALLOW_FILE_EDIT`, unless wp-config.php sets it).
  - Email addresses encoded with `antispambot()` in page text and `mailto:` links.
  - Application passwords off (off by default).
- **Alerts**, off by default, emailed to a configurable address (default: the site admin email), listed on
  the Security tab:
  - Admin changes: new or promoted administrators, plugins installed or activated, theme switched.
  - Script drift: a baseline of external script domains, then one alert per new domain, named and rated
    by Greenlight's script lists. Reset baseline button.
  - Daily core file check against the WordPress.org checksums (like `wp core verify-checksums`), plus PHP
    files in uploads. Runs in short batches.
  - Content check: flags only what an update added (scripts, embeds, inline code, external forms,
    redirects, links hidden on purpose); trusts the launch baseline and known embed domains; severity by
    whether the editor has `unfiltered_html`; direct database writes are always high; editable spam words
    raise severity. "Trust this domain" button.
- `wp greenlight security [--format=json]` with `--reset-baseline`, `--trust=<domain>`, `--run-checks`
  and `--clear-alerts`. The JSON shape (schema 1) is a contract for the QA app; see the README.
- Every Security key works with `wp greenlight set`.

### Changed
- `?greenlight=off` and pausing Greenlight turn off the speed features only; Security stays on.
- Release zips are built with `git archive` from the tag (docs and dotfiles excluded).

### Upgrading
- Switched on by default: security headers, hide usernames, XML-RPC off, file editor off, email
  obfuscation. On a site that uses Jetpack or the WordPress mobile app, switch off XML-RPC off.

## 0.1.1 — 2026-10-14

### Changed
- Image sizes Greenlight adds only give the browser the image's shape; they never stretch an image.
- Own wp-admin menu item, settings page with tabs per area, Overview, one row per service.
- Warns about WP-Optimize; hint for call tracking or consent loaded through Google Tag Manager.

## 0.1.0 — 2026-10-14

### Added
- First version: hero image priority, self-hosted Google Fonts, delayed third-party scripts, deferred head
  scripts, jQuery in the footer with a safety check, lazy-loading and missing image sizes, responsive theme
  images, YouTube thumbnails, embeds on interaction, cleanup, host cache purging, WP-CLI.
