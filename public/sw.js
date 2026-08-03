/**
 * Service worker MINIMAL untuk Input Performa Mesin.
 *
 * PENTING: aplikasi ini SENGAJA bukan offline-first — sesuai NFR di PRD,
 * aplikasi butuh koneksi live ke server intranet perusahaan untuk baca &
 * simpan data. Service worker ini cuma untuk 2 hal:
 *   1. Syarat teknis supaya browser mengizinkan PWA di-install (Chrome/Android
 *      mewajibkan ada service worker dengan fetch handler aktif).
 *   2. Kalau koneksi putus saat pindah halaman, tampilkan halaman "Tidak Ada
 *      Koneksi" yang ramah (offline.html), bukan error bawaan browser.
 *
 * TIDAK melakukan caching data aplikasi (Dashboard, dsb) — itu harus selalu
 * fresh dari server, bukan dari cache.
 */

const CACHE_NAME = 'performa-mesin-shell-v1';
const OFFLINE_URL = '/offline.html';

const PRECACHE_ASSETS = [
    OFFLINE_URL,
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    // Navigasi antar halaman (klik link, buka app): coba jaringan dulu,
    // baru fallback ke halaman offline kalau gagal.
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    // Request lain (ikon, manifest, dsb): coba cache dulu (cepat), baru jaringan.
    event.respondWith(
        caches.match(event.request).then((cached) => cached || fetch(event.request))
    );
});
