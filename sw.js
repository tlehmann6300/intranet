// IBC Intranet – Service Worker
// Caches static assets (CSS, JS, images) for offline availability.

const CACHE_NAME = 'ibc-intranet-v3';

const STATIC_ASSETS = [
    '/assets/img/cropped_maskottchen_32x32.webp',
    '/assets/img/cropped_maskottchen_180x180.webp',
    '/assets/img/cropped_maskottchen_192x192.webp',
    '/assets/img/cropped_maskottchen_270x270.webp',
    '/assets/img/ibc_logo_original.webp',
    '/assets/img/ibc_logo_original_navbar.webp',
    '/assets/img/default_profil.png',
];

// Install: pre-cache static assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
    );
    self.skipWaiting();
});

// Activate: remove outdated caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            )
        )
    );
    self.clients.claim();
});

// Fetch: cache-first for same-origin static assets, skip everything else
self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Only handle GET requests
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // ── CRITICAL: skip all cross-origin requests (CDN, fonts, external APIs)
    // The CSP blocks the SW from fetching external URLs, so we must let the
    // browser handle those directly without SW interception.
    if (url.origin !== self.location.origin) return;

    // Cache-first strategy for same-origin static assets only
    const isStaticAsset =
        url.pathname.startsWith('/assets/') ||
        /\.(css|js|webp|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot)$/i.test(url.pathname);

    if (isStaticAsset) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) return cached;
                return fetch(request).then((response) => {
                    if (!response || response.status !== 200) return response;
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    return response;
                }).catch(() => Response.error());
            })
        );
        return;
    }

    // Network-first strategy for same-origin HTML pages
    event.respondWith(
        fetch(request).catch(() =>
            caches.match(request).then((cached) => cached || Response.error())
        )
    );
});
