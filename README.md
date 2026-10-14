# Greenlight

A WordPress plugin for the front-end fixes that actually move Core Web Vitals (LCP, CLS, TBT, FCP,
Speed Index), built from what worked on real WP Engine sites. **No page cache**: WP Engine, Kinsta,
SiteGround, Flywheel and Pantheon already cache pages at the server (WP Rocket turns its own page cache
off on WP Engine too). Greenlight clears the host's cache when content changes instead.

Requires WordPress 6.3+ and PHP 7.4+.

## What it does

| Feature | Metric | What it does |
|---|---|---|
| Hero image | LCP | Finds the first big image near the top (an `<img>` or an inline CSS `background-image`), removes lazy-loading from it, adds `fetchpriority="high"` and preloads it (placed after the viewport tag, so phones don't also fetch the desktop copy). Originals wider than 2048px are swapped for WordPress's 2048px copy; phones get the 1536px copy; a WebP copy is made in the background and served once ready (the original file is untouched). |
| Self-hosted Google Fonts | FCP, CLS | Downloads the Google Fonts CSS and woff2 files in the background (WP-Cron), serves them from `uploads/greenlight/fonts/`, preloads the Latin files and removes the connections to Google. Nobody waits for the download. |
| Delay third-party scripts | TBT, INP | Analytics, pixels, chat and widgets load on the first scroll, tap, click, key or mouse move (optionally after N seconds). **The theme's and plugins' own scripts are never delayed.** Consent tools and call tracking are excluded by default. |
| Defer head scripts | FCP, Speed Index | Uses WordPress's own loading strategies: a `<head>` script is deferred only when everything that depends on it can wait. jQuery and scripts with inline code are left alone. |
| jQuery in the footer | FCP, LCP | Moves jQuery and the scripts that need it to the footer, where WordPress prints them in dependency order. Every page is checked before it's sent: if inline code (or an unknown blocking script) earlier on the page needs jQuery, jQuery is put back right before it. |
| Embeds on interaction | LCP, TBT | Map, Vimeo, review and booking iframes (editable list) load on the first interaction instead of with the page. A Google Map alone is ~450 KB. |
| Lazy-loading | LCP, bandwidth | Native `loading="lazy"` below the fold; the first N images and the hero always load at once. Iframes (maps, embeds) lazy-load. |
| Missing width/height | CLS | Added from the file name (`-1024x683`) or the media library, so the page doesn't jump. |
| Responsive theme images | LCP, bandwidth | Media-library images printed without `srcset` get WordPress's smaller copies, with `sizes="auto"` on lazy images. |
| YouTube thumbnail | TBT, bandwidth | YouTube iframes become their thumbnail and a play button; the player loads on click. |
| Cleanup | small wins | Emoji script, embed discovery, header clutter, Dashicons for visitors, front-end Heartbeat. Optional: jQuery Migrate, block CSS on classic themes. |
| Host cache purge | — | WP Engine (memcached, page cache, CDN), SiteGround, Kinsta, Pantheon, object cache; on save, from the toolbar, or `wp greenlight purge`. |

## Not breaking the site

- Only plain front-end HTML pages are touched: never wp-admin, AJAX, REST, feeds, previews or the
  Customizer. Any error while optimizing serves the page unchanged.
- `?greenlight=off` on any URL shows the page with Greenlight fully off, for before/after checks.
- The end of every page's source has a summary, e.g.
  `<!-- Greenlight 0.1.0 (7 ms): fonts: 1 local, lcp: background hero-2048x1365.jpg, delayed: 4 … -->`.
- Settings → Greenlight warns when another plugin does the same job (WP Rocket, Autoptimize,
  Perfmatters, LiteSpeed Cache, W3 Total Cache, SiteGround Optimizer, Flying Scripts).

## Testing

`tests/sandbox/` builds a throwaway WordPress (SQLite, PHP's built-in server) with a deliberately slow
theme: Google Fonts, a 2400px CSS-background hero, images without sizes, YouTube and Google Maps
embeds, GA4, a Meta pixel, a chat widget, a consent loader and call tracking.

```
PHP=/path/to/php tests/sandbox/setup.sh        # once
PHP=/path/to/php tests/sandbox/serve.sh        # http://127.0.0.1:8899
tests/sandbox/serve-h2.sh                      # https://127.0.0.1:8443, HTTP/2 + compression like a real host
node tests/sandbox/safety.cjs                  # "doesn't break the site" checks, plugin off vs on
```

Measure with Lighthouse against `https://127.0.0.1:8443/` and `…/?greenlight=off`. Measure through
`serve-h2.sh`: PHP's built-in server alone is HTTP/1.1 without compression, which no real host is, and
it makes phone LCP look about 2.5 s worse than it is.

Results on the sandbox (Lighthouse 12.8, median of 3, Oct 2026):

| | Phone off → on | Desktop off → on |
|---|---|---|
| Performance score | 72 → 99 | 77 → 100 |
| First Contentful Paint | 2.33 s → 0.79 s | 0.56 s → 0.21 s |
| Largest Contentful Paint | 29.9 s → 2.25 s | 4.89 s → 0.60 s |
| Total Blocking Time | 51 ms → 0 ms | 0 ms → 0 ms |
| Cumulative Layout Shift | 0.003 → 0 | 0.015 → 0 |
| Speed Index | 2.33 s → 0.79 s | 0.63 s → 0.21 s |
| Page weight | 8.2 MB → 0.8 MB | 8.2 MB → 1.0 MB |

## WP-CLI

```
wp greenlight status
wp greenlight purge
wp greenlight fonts [--clear]
wp greenlight set delay_timeout 6
```

## Install

Zip the folder (named `greenlight`) and upload it under Plugins → Add New → Upload, or copy it to
`wp-content/plugins/greenlight/`. Settings are under Settings → Greenlight; the defaults are safe.
