const CACHE = 'bunny-learning-v10';
const ASSETS = [
  './',
  './index.html',
  './manifest.webmanifest',
  './icon-192.png',
  './icon-512.png'
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  const url = new URL(e.request.url);
  // Never intercept or cache API endpoints — they carry per-user auth state
  // and their query strings matter (e.g. progress.php?app=bunny-zh).
  if (url.origin === location.origin &&
      (url.pathname.includes('/api/') || url.pathname.endsWith('/admin.php'))) {
    return;
  }
  e.respondWith(
    caches.match(e.request).then(hit => {
      return hit || fetch(e.request).then(res => {
        const copy = res.clone();
        if (res.ok && url.origin === location.origin) {
          caches.open(CACHE).then(c => c.put(e.request, copy));
        }
        return res;
      }).catch(() => caches.match('./index.html'));
    })
  );
});
