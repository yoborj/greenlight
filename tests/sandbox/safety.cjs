// "Doesn't break the site" checks for Speed Kit, run with the plugin off (?speedkit=off) and on.
const p = require('puppeteer-core');
const S = __dirname + '/';
const wait = ms => new Promise(r => setTimeout(r, ms));
const FLAGS = ['themeMainRan', 'themeDomReady', 'sliderReady', 'sliderInlineRan', 'consentLoaderRan', 'gaInlineRan', 'pixelInlineRan', 'chatInlineRan'];

(async () => {
  const b = await p.launch({ executablePath: process.env.CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome', headless: 'new' });
  const out = {};
  for (const [dev, vp] of [['desktop', { width: 1440, height: 900 }], ['phone', { width: 390, height: 844, isMobile: true, hasTouch: true, deviceScaleFactor: 2 }]]) {
    for (const mode of ['off', 'on']) {
      const ctx = await b.createBrowserContext(); const pg = await ctx.newPage(); await pg.setViewport(vp);
      const errors = []; const requests = [];
      pg.on('pageerror', e => errors.push('JS: ' + e.message.slice(0, 140)));
      pg.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|ERR_|net::/.test(m.text())) errors.push('console: ' + m.text().slice(0, 140)); });
      pg.on('request', r => requests.push(r.url()));
      const url = 'http://127.0.0.1:8899/' + (mode === 'off' ? '?speedkit=off' : '');
      await pg.goto(url, { waitUntil: 'load', timeout: 60000 });
      await wait(3500); // no interaction yet
      const before = await pg.evaluate(f => Object.fromEntries(f.map(k => [k, !!window[k]]).concat([['gtagFn', typeof window.gtag === 'function'], ['delayedLeft', document.querySelectorAll('script[type="text/speedkit-delayed"]').length]])), FLAGS);
      const shot = `${S}shot-${dev}-${mode}.png`; await pg.screenshot({ path: shot });
      const thirdPartyBefore = [...new Set(requests.filter(u => /googletagmanager|facebook|tawk|youtube\.com\/(embed|s\/)|ytimg|fonts\.g/.test(u)).map(u => new URL(u).hostname))];
      // gtag() click tracking must work even before Google's script has loaded.
      const cta = await pg.evaluate(() => { const a = document.querySelector('.cta'); a.addEventListener('click', e => e.preventDefault(), { once: true }); a.click(); return !!window.ctaTracked; });
      // Phone menu.
      let menu = null;
      if (dev === 'phone') menu = await pg.evaluate(async () => { const t = document.querySelector('.menu-toggle'); t.click(); await new Promise(r => setTimeout(r, 300)); return document.querySelector('nav').classList.contains('open'); });
      // First real interaction: delayed scripts should now load, in order.
      await pg.mouse.move(200, 300); await pg.mouse.move(260, 340); await wait(4000);
      const after = await pg.evaluate(f => Object.fromEntries(f.map(k => [k, !!window[k]]).concat([['delayedLeft', document.querySelectorAll('script[type="text/speedkit-delayed"]').length], ['fbqFn', typeof window.fbq === 'function']])), FLAGS);
      // YouTube: thumbnail until clicked, then the player.
      const yt = await pg.evaluate(async () => { const f = document.querySelector('.speedkit-yt'); if (!f) return document.querySelector('iframe[src*="youtube"]') ? 'iframe (no facade)' : 'missing'; f.scrollIntoView(); f.click(); await new Promise(r => setTimeout(r, 800)); return f.querySelector('iframe') ? 'facade → player on click' : 'click did nothing'; });
      out[`${dev}/${mode}`] = { before, after, cta, menu, yt, thirdPartyBeforeInteraction: thirdPartyBefore, errors };
      await ctx.close();
    }
  }
  await b.close();
  require('fs').writeFileSync(S + 'safety.json', JSON.stringify(out, null, 1));
  for (const [k, v] of Object.entries(out)) {
    console.log(`\n== ${k}`);
    console.log('  before interaction:', Object.entries(v.before).map(([a, b]) => `${a}=${b}`).join(' '));
    console.log('  after interaction: ', Object.entries(v.after).map(([a, b]) => `${a}=${b}`).join(' '));
    console.log(`  gtag click tracking before load: ${v.cta} · phone menu: ${v.menu} · YouTube: ${v.yt}`);
    console.log('  third-party hosts requested before any interaction:', v.thirdPartyBeforeInteraction.join(', ') || 'none');
    console.log('  errors:', v.errors.length ? v.errors : 'none');
  }
})();
