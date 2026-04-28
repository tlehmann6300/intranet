// IBC Intranet – Service Worker
// Cached AUSSCHLIESSLICH statische Assets (CSS, JS, Bilder, Fonts).
// HTML-Seiten / PHP-Routen werden BEWUSST nicht abgefangen — der Browser
// behandelt sie nativ, damit transiente Netzwerkfehler nicht in
// `Response.error()` münden und die Seite tatsächlich aufrufbar bleibt.

const CACHE_NAME = 'ibc-intranet-v4';

const STATIC_ASSETS = [
    '/assets/img/cropped_maskottchen_32x32.webp',
    '/assets/img/cropped_maskottchen_180x180.webp',
    '/assets/img/cropped_maskottchen_192x192.webp',
    '/assets/img/cropped_maskottchen_270x270.webp',
    '/assets/img/ibc_logo_original.webp',
    '/assets/img/ibc_logo_original_navbar.webp',
    '/assets/img/default_profil.png',
];

// Install: pre-cache static assets — Fehler einzelner Assets dürfen die
// Installation nicht abbrechen (sonst landet der SW im "redundant"-State).
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) =>
            Promise.allSettled(STATIC_ASSETS.map((url) => cache.add(url)))
        )
    );
    self.skipWaiting();
});

// Activate: alte Caches aufräumen.
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

// Fetch: Cache-first NUR für same-origin Static-Assets. Alles andere
// (HTML, PHP-Routen, Cross-Origin) wird NICHT abgefangen — der Browser
// holt sich die Antwort direkt vom Netzwerk.
self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Nur GET cachen.
    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    // Cross-origin (CDN, Fonts, externe APIs): nicht eingreifen.
    if (url.origin !== self.location.origin) return;

    // Navigationen (HTML / PHP-Seiten): NICHT eingreifen — der Browser
    // soll selbst entscheiden, ob er die Seite holen kann. Das verhindert
    // den `FetchEvent ... resolved with an error response object`-Bug,
    // wenn ein einzelner fetch() im SW transient fehlschlägt.
    if (
        request.mode === 'navigate' ||
        (request.destination === '' && request.headers.get('accept')?.includes('text/html'))
    ) {
        return;
    }

    // Static-Asset-Erkennung: Pfad oder Endung.
    const isStaticAsset =
        url.pathname.startsWith('/assets/') ||
        /\.(css|js|mjs|webp|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot)$/i.test(url.pathname);

    if (!isStaticAsset) return; // alles andere lassen wir nativ durch.

    event.respondWith(
        caches.match(request).then((cached) => {
            if (cached) return cached;
            return fetch(request)
                .then((response) => {
                    // Nur "echte" 200-Antworten cachen (keine Opaque-Responses).
                    if (response && response.status === 200 && response.type === 'basic') {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                })
                .catch(() => {
                    // Netzwerkfehler: lieber nichts respond'en als Response.error()
                    // — der Browser zeigt dann seinen eigenen netzten/offline-State.
                    return new Response('', { status: 504, statusText: 'Gateway Timeout' });
                });
        })
    );
});
