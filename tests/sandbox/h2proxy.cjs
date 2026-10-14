// Local HTTPS + HTTP/2 + gzip/brotli front for the sandbox (like a real host's edge). Port 8443 → 8899.
const http2 = require('http2'), http = require('http'), zlib = require('zlib'), fs = require('fs');
const server = http2.createSecureServer({ key: fs.readFileSync(process.argv[2] + '/h2.key'), cert: fs.readFileSync(process.argv[2] + '/h2.crt'), allowHTTP1: true });
server.on('request', (req, res) => {
  const headers = { ...req.headers, host: '127.0.0.1:8443', 'x-forwarded-proto': 'https' };
  for (const h of Object.keys(headers)) if (h.startsWith(':')) delete headers[h];
  const up = http.request({ host: '127.0.0.1', port: 8899, path: req.url, method: req.method, headers }, r => {
    const type = r.headers['content-type'] || ''; const out = { ...r.headers };
    delete out.connection; delete out['transfer-encoding']; delete out['keep-alive'];
    const ae = req.headers['accept-encoding'] || '';
    if (/text|javascript|json|css|svg|xml/.test(type) && !r.headers['content-encoding']) {
      const enc = /br/.test(ae) ? 'br' : /gzip/.test(ae) ? 'gzip' : null;
      if (enc) { out['content-encoding'] = enc; delete out['content-length']; res.writeHead(r.statusCode, out); return r.pipe(enc === 'br' ? zlib.createBrotliCompress() : zlib.createGzip()).pipe(res); }
    }
    res.writeHead(r.statusCode, out); r.pipe(res);
  });
  up.on('error', e => { res.writeHead(502); res.end(String(e)); });
  req.pipe(up);
});
server.listen(8443, '127.0.0.1', () => console.log('h2 proxy on https://127.0.0.1:8443'));
