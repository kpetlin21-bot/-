const CACHE_NAME = 'checklist-v20260603-1820';
const ASSETS = ['./', './index.html', './manifest.json', './icon-192.png', './icon-512.png', './history.html'];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_NAME).then((c) => c.addAll(ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  const url = new URL(e.request.url);
  if (url.pathname.includes('/api/checklist/')) {
    return;
  }
  if (e.request.method !== 'GET') {
    return;
  }
  e.respondWith(
    caches.match(e.request).then((cached) => {
      const net = fetch(e.request).then((res) => {
        if (res && res.status === 200 && url.origin === self.location.origin) {
          const clone = res.clone();
          caches.open(CACHE_NAME).then((c) => c.put(e.request, clone));
        }
        return res;
      }).catch(() => cached);
      return cached || net;
    })
  );
});

self.addEventListener('message', (e) => {
  if (e.data && e.data.type === 'SYNC_PENDING') {
    e.waitUntil(notifyClients('sync-pending'));
  }
});

function notifyClients(type) {
  return self.clients.matchAll({ type: 'window' }).then((clients) => {
    clients.forEach((c) => c.postMessage({ type }));
  });
}

self.addEventListener('sync', (e) => {
  if (e.tag === 'checklist-sync') {
    e.waitUntil(notifyClients('sync-pending'));
  }
});
