// IBC Intranet – Service Worker
// Caches static assets for offline availability and adds background sync for forms.

const CACHE_NAME      = 'ibc-intranet-v3';
const OFFLINE_URL     = '/offline.html';

const STATIC_ASSETS = [
    '/assets/img/cropped_maskottchen_32x32.webp',
    '/assets/img/cropped_maskottchen_180x180.webp',
    '/assets/img/cropped_maskottchen_192x192.webp',
    '/assets/img/cropped_maskottchen_270x270.webp',
    '/assets/img/ibc_logo_original.webp',
    '/assets/img/ibc_logo_original_navbar.webp',
    '/assets/img/default_profil.png',
    OFFLINE_URL,
];

// Pages cached for offline access (inventory list for field use)
const CACHED_PAGES = [
    '/inventory',
    '/inventory/my-checkouts',
    '/inventory/my-rentals',
];

// Install: pre-cache static assets + offline page
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

// Background Sync: replay queued form submissions when back online
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-inventory-requests') {
        event.waitUntil(replayQueuedRequests());
    }
});

async function replayQueuedRequests() {
    // Queued requests are stored in IndexedDB as { url, method, body } objects.
    // The service worker stores the serialised form body as a plain string
    // rather than a cached Response (which can only be read once).
    try {
        const db = await openQueueDB();
        const tx = db.transaction('queue', 'readwrite');
        const store = tx.objectStore('queue');
        const all = await promisifyRequest(store.getAll());
        await Promise.all(
            (all || []).map(async (entry) => {
                try {
                    await fetch(entry.url, {
                        method:  entry.method || 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body:    entry.body || '',
                    });
                    const delTx    = db.transaction('queue', 'readwrite');
                    const delStore = delTx.objectStore('queue');
                    delStore.delete(entry.id);
                } catch {
                    // Will retry on next sync
                }
            })
        );
    } catch {
        // IndexedDB unavailable – skip
    }
}

function openQueueDB() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open('ibc-offline-queue', 1);
        req.onupgradeneeded = (e) => e.target.result.createObjectStore('queue', { keyPath: 'id', autoIncrement: true });
        req.onsuccess       = (e) => resolve(e.target.result);
        req.onerror         = (e) => reject(e.target.error);
    });
}

function promisifyRequest(req) {
    return new Promise((resolve, reject) => {
        req.onsuccess = (e) => resolve(e.target.result);
        req.onerror   = (e) => reject(e.target.error);
    });
}

// Fetch: routing strategy
self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Only handle GET requests for caching; let POST requests through
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Cache-first strategy for static assets (CSS, JS, images, fonts)
    const isStaticAsset =
        url.pathname.startsWith('/assets/') ||
        /\.(css|js|webp|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot)$/i.test(url.pathname);

    if (isStaticAsset) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) return cached;
                return fetch(request).then((response) => {
                    if (!response || response.status !== 200 || response.type !== 'basic') {
                        return response;
                    }
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    return response;
                });
            })
        );
        return;
    }

    // Stale-while-revalidate for inventory pages (useful offline in the field)
    const isInventoryPage = CACHED_PAGES.some((p) => url.pathname.startsWith(p));

    if (isInventoryPage) {
        event.respondWith(
            caches.open(CACHE_NAME).then(async (cache) => {
                const cached = await cache.match(request);
                const networkFetch = fetch(request).then((response) => {
                    if (response && response.status === 200) {
                        cache.put(request, response.clone());
                    }
                    return response;
                }).catch(() => cached || caches.match(OFFLINE_URL));

                // Return cached immediately, update in background
                return cached || networkFetch;
            })
        );
        return;
    }

    // Network-first with offline fallback for all other HTML pages
    event.respondWith(
        fetch(request).catch(async () => {
            const cached = await caches.match(request);
            return cached || caches.match(OFFLINE_URL) || Response.error();
        })
    );
});
