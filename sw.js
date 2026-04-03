// IBC Intranet – Service Worker v3
// Strategies:
//   • Stale-while-revalidate  → static assets (CSS, JS, fonts, images)
//   • Network-first           → pages/ routes and API calls
//   • Offline fallback        → pages/errors/offline.html for navigation failures

const CACHE_VERSION = 'v3';
const STATIC_CACHE  = `ibc-static-${CACHE_VERSION}`;
const PAGE_CACHE    = `ibc-pages-${CACHE_VERSION}`;
const ALL_CACHES    = [STATIC_CACHE, PAGE_CACHE];

const OFFLINE_URL = '/pages/errors/offline.html';

// Assets to pre-cache on install (images + offline page)
const PRECACHE_ASSETS = [
    OFFLINE_URL,
    '/assets/img/cropped_maskottchen_32x32.webp',
    '/assets/img/cropped_maskottchen_180x180.webp',
    '/assets/img/cropped_maskottchen_192x192.webp',
    '/assets/img/cropped_maskottchen_270x270.webp',
    '/assets/img/ibc_logo_original.webp',
    '/assets/img/ibc_logo_original_navbar.webp',
    '/assets/img/default_profil.png',
];

// ── Install: pre-cache essential assets ──────────────────────────────────────
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE_ASSETS))
    );
    self.skipWaiting();
});

// ── Activate: remove outdated caches ─────────────────────────────────────────
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => !ALL_CACHES.includes(key))
                    .map((key) => caches.delete(key))
            )
        )
    );
    self.clients.claim();
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function isStaticAsset(url) {
    return (
        url.pathname.startsWith('/assets/') ||
        /\.(css|js|woff2?|ttf|eot|webp|png|jpg|jpeg|gif|svg|ico)$/i.test(url.pathname)
    );
}

function isNavigationOrPage(request, url) {
    return (
        request.mode === 'navigate' ||
        url.pathname.startsWith('/pages/') ||
        url.pathname === '/' ||
        url.pathname.endsWith('.php')
    );
}

function isApiCall(url) {
    return url.pathname.startsWith('/api/');
}

// ── Stale-while-revalidate for static assets ──────────────────────────────────
function staleWhileRevalidate(request) {
    return caches.open(STATIC_CACHE).then((cache) =>
        cache.match(request).then((cached) => {
            const networkFetch = fetch(request).then((response) => {
                if (response && response.status === 200) {
                    cache.put(request, response.clone());
                }
                return response;
            });
            // Return the cached version immediately; update in background
            return cached || networkFetch;
        })
    );
}

// ── Network-first with page-cache fallback ────────────────────────────────────
function networkFirst(request) {
    return fetch(request)
        .then((response) => {
            if (response && response.status === 200 && request.mode === 'navigate') {
                caches.open(PAGE_CACHE).then((cache) => cache.put(request, response.clone()));
            }
            return response;
        })
        .catch(() =>
            caches.match(request).then(
                (cached) => cached || caches.match(OFFLINE_URL)
            )
        );
}

// ── Fetch handler ─────────────────────────────────────────────────────────────
self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Only handle same-origin GET requests
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (isStaticAsset(url)) {
        event.respondWith(staleWhileRevalidate(request));
        return;
    }

    if (isNavigationOrPage(request, url) || isApiCall(url)) {
        event.respondWith(networkFirst(request));
        return;
    }

    // Default: network-first for everything else
    event.respondWith(networkFirst(request));
});
