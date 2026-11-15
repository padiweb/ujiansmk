// Pengganti service worker aplikasi lama: bersihkan cache lama lalu mendaftarkan diri keluar.
// Ada karena sebagian HP siswa masih memasang aplikasi lama di alamat yang sama.
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) {
  e.waitUntil(
    caches.keys()
      .then(function (k) { return Promise.all(k.map(function (n) { return caches.delete(n); })); })
      .then(function () { return self.registration.unregister(); })
      .then(function () { return self.clients.matchAll(); })
      .then(function (c) { c.forEach(function (w) { if (w.navigate) w.navigate('/'); }); })
  );
});
