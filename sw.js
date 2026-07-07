'use strict';

var SW_CACHE = 'vg-pwa-v1';

self.addEventListener('install', function (e) {
  self.skipWaiting();
  e.waitUntil(
    caches.open(SW_CACHE).then(function (cache) {
      return cache.addAll(['/']).catch(function () { /* ignore cache failure */ });
    })
  );
});

self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys
          .filter(function (k) { return k !== SW_CACHE; })
          .map(function (k) { return caches.delete(k); })
      );
    }).then(function () { return self.clients.claim(); })
  );
});

self.addEventListener('fetch', function (e) {
  if (e.request.method !== 'GET' || e.request.mode !== 'navigate') return;
  e.respondWith(
    fetch(e.request).catch(function () {
      return caches.match('/').then(function (r) { return r || Response.error(); });
    })
  );
});
