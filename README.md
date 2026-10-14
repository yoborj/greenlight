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
| Delay third-party scripts | TBT, INP | Analytics, pixels, chat and widgets load on the first scroll, tap, click, key or mouse move (and after 10 seconds anyway, so analytics counts visitors who never interact; 0 turns that off). **The theme's and plugins' own scripts are never delayed.** Consent tools and call tracking are excluded by default. |
| Defer head scripts | FCP, Speed Index | Uses WordPress's own loading strategies: a `<head>` script is deferred only when everything that depends on it can wait. jQuery and scripts with inline code are left alone. |
| jQuery in the footer | FCP, LCP | Moves jQuery and the scripts that need it to the footer, where WordPress prints them in dependency order. Every page is checked before it's sent: if inline code (or an unknown blocking script) earlier on the page needs jQuery, jQuery is put back right before it. |
| Embeds on interaction | LCP, TBT | Map, Vimeo, review and booking iframes load on the first interaction instead of with the page (each one can be switched off). A Google Map alone is ~450 KB. |
| Lazy-loading | LCP, bandwidth | Native `loading="lazy"` below the fold; the first N images and the hero always load at once. Iframes (maps, embeds) lazy-load. |
| Missing width/height | CLS | Added from the file name (`-1024x683`) or the media library, so the page doesn't jump. |
| Responsive theme images | LCP, bandwidth | Media-library images printed without `srcset` get WordPress's smaller copies, with `sizes="auto"` on lazy images. |
| YouTube thumbnail | TBT, bandwidth | YouTube iframes become their thumbnail and a play button; the player loads on click. |
| Cleanup | small wins | Emoji script, embed discovery, header clutter, Dashicons for visitors, front-end Heartbeat. Optional: jQuery Migrate, block CSS on classic themes. |
| Host cache purge | — | WP Engine (memcached, page cache, CDN), SiteGround, Kinsta, Pantheon, object cache; on save, from the toolbar, or `wp greenlight purge`. |

## Settings

Settings → Greenlight opens on an **Overview**: how many optimizations are on, one tile per area
(with the PageSpeed metrics it helps), host cache and font status, overlapping plugins, Clear caches,
and **Compare a page** (opens any page with and without `?greenlight=off`). The header holds the
master switch. Tabs: **Hero image · Images & embeds · Fonts · Scripts · Cleanup · Hosting**.

Every feature has its own switch, and every entry in the lists (scripts to delay, never-delay
exceptions, embeds to hold back, images never treated as the hero, never-defer) has its own switch too,
with a readable name ("Meta (Facebook) Pixel", "Google Maps", "CallRail"…), a remove button and a box
to add your own. Switched-off entries stay in the list (saved with a leading `#`). Options that only
work under another switch say so when that switch is off. All tabs are one form: **Save changes** in
the bar at the bottom saves every tab, and the bar shows when there are unsaved changes.

## After installing

Activating or deactivating Greenlight clears the host's page cache, so pages rebuild with (or without)
it. Self-hosted fonts and the WebP hero copy are made in the background after the first page view or
two (about a minute), then the cache is cleared again. Behind Cloudflare, edge copies can take 10–20
minutes to refresh; compare with `?greenlight=off`, or test the origin directly.

## Not breaking the site

- Only plain front-end HTML pages are touched: never wp-admin, AJAX, REST, feeds, previews or the
  Customizer. Any error while optimizing serves the page unchanged.
- `?greenlight=off` on any URL shows the page with Greenlight fully off, for before/after checks.
- The end of every page's source has a summary, e.g.
  `<!-- Greenlight 0.1.0 (7 ms): fonts: 1 local, lcp: background hero-2048x1365.jpg, delayed: 4 … -->`.
- Settings → Greenlight warns when another plugin does the same job (WP Rocket, Autoptimize,
  Perfmatters, LiteSpeed Cache, W3 Total Cache, SiteGround Optimizer, Flying Scripts).

## Test results

Tested before release on a deliberately slow test site (Google Fonts, a 2400px CSS-background hero,
images without sizes, YouTube and Google Maps embeds, GA4, a Meta pixel, a chat widget, a consent
loader and call tracking), served over HTTP/2 with compression like a real host. Forms, menus, the
slider, consent, gtag click tracking and the delayed scripts all kept working on desktop and phone.

Lighthouse 12.8, median of 3, Oct 2026:

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
wp greenlight set delay_timeout 0
```

## Install

Zip the folder (named `greenlight`) and upload it under Plugins → Add New → Upload, or copy it to
`wp-content/plugins/greenlight/`. Settings are under Settings → Greenlight; the defaults are safe.
