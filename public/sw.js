/* Service worker for the Guidance Management System.
 *
 * What it does:  makes the app installable, caches the static files (CSS, JS, images, icons)
 *                so the app opens fast, and shows a friendly page when there is no connection.
 * What it does NOT do: it never caches pages (HTML/PHP) or API calls. Those hold personal data,
 *                login sessions and security tokens, so they always come from the server.
 *
 * To force every device to refresh its cached static files after you change CSS/JS/images,
 * bump VERSION below (e.g. 'v2') and deploy.
 */
const VERSION = 'v1';
const STATIC_CACHE = 'tcm-static-' + VERSION;
const scopeUrl = (p) => new URL(p, self.registration.scope).href;
const PRECACHE = [
  'offline.html',
  'assets/css/style.css',
  'assets/js/main.js',
  'assets/js/pwa.js',
  'assets/images/tcm_guidance_logo.png',
  'assets/icons/icon-192.png',
].map(scopeUrl);

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(STATIC_CACHE).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k.startsWith('tcm-static-') && k !== STATIC_CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;                       // forms / API writes always go to the server
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;        // CDN files (Bootstrap, fonts) use the browser's own cache

  // Page loads: always the network; if it fails (offline), show the offline page.
  if (req.mode === 'navigate') {
    event.respondWith(fetch(req).catch(() => caches.match(scopeUrl('offline.html'))));
    return;
  }

  // Static files under /assets/: serve the cached copy instantly, refresh it in the background.
  if (url.pathname.startsWith(new URL(self.registration.scope).pathname + 'assets/')) {
    event.respondWith(
      caches.open(STATIC_CACHE).then(async (cache) => {
        const cached = await cache.match(req);
        const refresh = fetch(req).then((res) => {
          if (res && res.ok && res.type === 'basic') cache.put(req, res.clone());
          return res;
        }).catch(() => cached);
        return cached || refresh;
      })
    );
  }
  // Anything else (api/*.php, etc.): not intercepted.
});
