# Changelog

## 0.5.0 — 2026-11-03

### Added
- **Plugin files only where used** (Scripts, on by default): Contact Form 7, Fluent Forms, Formidable,
  Mailchimp for WP, Smash Balloon Instagram Feed and Slider Revolution keep their CSS and JS off pages
  that don't show them, decided from each page's finished HTML. One switch per plugin; custom rules as
  `handles | marker`.
- **WebP copies of all images** (Images & embeds, off by default: extra disk space). Media-library JPEG
  and PNG images, srcset included, switch to WebP once the copy exists. Copies are made for new uploads
  and for images pages show, a few seconds at a time by WP-Cron, with one cache purge per batch.
- **Size-matched fallback fonts** (Fonts, on): each self-hosted family gets Arial- and Times-based
  fallback faces sized from the font's own measurements (as Next.js does: Roboto 100.31%), added to the
  page's inline font stacks, so text doesn't jump when the web font arrives. Existing font sets get them
  automatically.
- **Vimeo: thumbnail until clicked** (Images & embeds, on), like YouTube. Thumbnails come from Vimeo's
  oEmbed API, once per video in the background.
- **Load the next page ahead** (Scripts): prefetch on hover by default, prerender or off. Uses WordPress's
  speculative loading (6.8+) and prints the same rules on older WordPress; the login page is excluded.
- **Weekly report email** (Hosting, off by default): real-user scores now / a week ago / first day and
  the week's security alerts, every Monday. "Send a test now"; `wp greenlight digest [--send]`.
- **Score history** on the Real visitors card: a small trend line per metric.
- **Settings presets**: Download preset / Import a preset on the Overview; `wp greenlight export
  [--with-key]` and `wp greenlight import <file> [--dry-run]`.

### Unchanged
- `wp greenlight security --format=json` (schema 1).

## 0.4.0 — 2026-11-03

### Added
- **Real-user scores on the Overview.** A "Real visitors" card with Core Web Vitals from the Chrome UX
  Report (LCP, INP, CLS, plus FCP and TTFB; 75th percentile of 28 days) for mobile and desktop: whether
  each passes, a rating per metric, and the value on the oldest day kept, so improvements since
  Greenlight show. Uses the PageSpeed Insights API once a day by WP-Cron (mobile, then desktop two
  minutes later), asking only for field data; page views never touch it. Needs a free API key (Google's
  keyless quota is now 0): Hosting → PageSpeed Insights API key. Optional page to measure, for staging.
  "Check now" button; `wp greenlight scores [--refresh] [--format=json]`.
- **Optional Claude (MCP) access**, off by default: Hosting → "Allow Claude (MCP) access"
  (`mcp_access`). When on (WordPress 6.9+), Greenlight registers four abilities: `greenlight/status`,
  `greenlight/security-report` (schema 1 JSON), `greenlight/compare` and `greenlight/purge`, public
  for the REST API and the MCP Adapter plugin (needed only if you use Claude). All need
  `manage_options`; only `purge` changes anything. When off, nothing is registered.
- `wp greenlight compare [<path>]`: a page with and without Greenlight, measured.

### Unchanged
- `wp greenlight security --format=json` (schema 1).

## 0.3.0 — 2026-10-22

### Added
- **Custom login URL** on the Security tab (off by default), the job WPS Hide Login does: the login page
  moves to `/<login_slug>/`; `wp-login.php` shows the theme's 404; `/login` and `/admin` no longer lead
  to it; logged-out visits to wp-admin go to `/404/`. Login, logout, lost-password and reset-email links
  all use the new address; admin-ajax.php, admin-post.php and password-protected posts keep working.
  The default address is `/backend-login/` (`login_slug`); an empty address, or a reserved word such as
  `login` or `admin`, falls back to it. WPS Hide Login is listed as an overlapping
  plugin while both are on. `wp greenlight set hide_login 0` undoes it from the command line.
- `wp greenlight status` shows the login page address.

### Changed
- The settings tabs are a little more compact, so all eight fit on a 1440px screen; on narrower screens
  the open tab scrolls into view.
- Baseline counts read "1 domain" / "no domains yet" instead of "domain(s)".
- README screenshots show 0.3.0 and the Security tab.

### Unchanged
- `wp greenlight security --format=json` (schema 1). The new `hide_login` setting is not in its
  `features`.

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
