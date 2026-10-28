/*
 * FormEzy ERP service worker.
 *
 * What it does, and (just as important) what it does not:
 *
 *   - It keeps the application's own build files (/build/assets/*, hashed, so a name never changes meaning) and the icons,
 *     so the app opens quickly and installs as an app.
 *   - When a page cannot be fetched because the network is gone, it shows /offline.html instead of the browser's error.
 *   - It NEVER stores a page, a Livewire update, an API answer, an export or any other response that can hold a customer's
 *     or an employee's data, and it never replays a request. Everything that is not a build file or an icon goes straight to
 *     the network, exactly as if this file did not exist. (Brief 6.14: cache only permitted, minimal data.)
 *
 * A new version waits until the person agrees ("Update available" in the app), so a form half filled is never reloaded
 * under them. Bump VERSION when this file's behaviour changes; old caches are removed on activation.
 */

const VERSION = 'v1';
const STATIC = `formezy-static-${VERSION}`;
const ROOT = new URL('./', self.location).pathname;               // the folder this file is served from, e.g. "/"
const OFFLINE = `${ROOT}offline.html`;
const PRECACHE = [OFFLINE, `${ROOT}icons/icon-192.png`, `${ROOT}icons/icon-512.png`];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(STATIC).then((cache) => cache.addAll(PRECACHE)));
});

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const names = await caches.keys();
        await Promise.all(names.filter((n) => n.startsWith('formezy-') && n !== STATIC).map((n) => caches.delete(n)));
        await self.clients.claim();
    })());
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

/** Only these are ever kept: files the build names by content, and the icons. */
const keepable = (url) => url.origin === self.location.origin
    && (url.pathname.startsWith(`${ROOT}build/assets/`) || url.pathname.startsWith(`${ROOT}icons/`));

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') {
        return;                                                    // a save, a sign-out, a Livewire update: never touched
    }

    const url = new URL(request.url);

    if (request.mode === 'navigate' && url.origin === self.location.origin) {
        event.respondWith(fetch(request).catch(async () => (await caches.match(OFFLINE)) ?? Response.error()));

        return;
    }

    if (!keepable(url)) {
        return;                                                    // not ours to keep: the browser goes to the network as usual
    }

    event.respondWith((async () => {
        const cache = await caches.open(STATIC);
        const kept = await cache.match(request);

        if (kept) {
            return kept;
        }

        const fresh = await fetch(request);

        if (fresh.ok && fresh.type === 'basic') {
            cache.put(request, fresh.clone());
        }

        return fresh;
    })());
});
