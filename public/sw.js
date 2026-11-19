// Service worker Altan Exam: tidak menyimpan apa pun (soal dan nilai tidak pernah masuk cache perangkat).
// Satu-satunya tugasnya: bila halaman tidak dapat dimuat karena tidak ada koneksi, tampilkan pesan yang rapi
// (bukan halaman galat bawaan peramban). Syarat pemasangan sebagai aplikasi juga mengharuskan adanya pengendali fetch.
var HALAMAN_OFFLINE = '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">' +
  '<title>Tidak ada koneksi</title><style>' +
  'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#101D63;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#111827}' +
  '.k{background:#fff;border-radius:20px;padding:28px 24px;margin:20px;max-width:360px;text-align:center}' +
  'h1{font-size:1.25rem;margin:0 0 8px}p{margin:0 0 18px;color:#4B5563;line-height:1.5}' +
  'button{background:#3960FB;color:#fff;border:0;border-radius:12px;padding:12px 22px;font-size:1rem;font-weight:600}' +
  '</style></head><body><div class="k"><h1>Tidak dapat terhubung</h1>' +
  '<p>Server ujian tidak dapat dijangkau. Periksa koneksi internet Anda, lalu coba lagi.</p>' +
  '<button onclick="location.reload()">Coba lagi</button></div></body></html>';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener('fetch', function (e) {
  if (e.request.mode !== 'navigate' || e.request.method !== 'GET') return; // selain halaman: biarkan jaringan yang menangani
  e.respondWith(fetch(e.request).catch(function () {
    return new Response(HALAMAN_OFFLINE, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } });
  }));
});
