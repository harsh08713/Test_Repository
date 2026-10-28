/*
 * The installable-app side of FormEzy: registers the service worker (public/sw.js) and keeps four facts the screens show —
 * whether the device is online, whether the app can be installed, whether it already is, and whether a new version is waiting.
 *
 * Alpine components read them through `pwaShell()`:
 *
 *     <div x-data="pwaShell()" x-on:pwa-change.window="sync($event.detail)"> … x-show="!online" … </div>
 *
 * The facts live here, not in a component, because the layout is re-drawn on every page change (wire:navigate) and the
 * browser's "install" event comes only once.
 */

const standalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
const onApple = () => /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

const facts = {
    online: navigator.onLine,
    canInstall: false,                 // the browser offered an install prompt
    installed: standalone(),
    showIosHint: onApple() && !standalone(),   // Safari has no prompt: the person must use Share > Add to Home Screen
    updateReady: false,                // a new version of the app's files is waiting for the person's say-so
};

let installPrompt = null;
let waiting = null;
let reloading = false;

const publish = () => window.dispatchEvent(new CustomEvent('pwa-change', { detail: { ...facts } }));

window.addEventListener('online', () => { facts.online = true; publish(); });
window.addEventListener('offline', () => { facts.online = false; publish(); });

window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();            // keep it for the person's own "Install app" button
    installPrompt = event;
    facts.canInstall = true;
    publish();
});

window.addEventListener('appinstalled', () => {
    installPrompt = null;
    Object.assign(facts, { canInstall: false, installed: true, showIosHint: false });
    publish();
});

// (guarded: an exception here, at load, would stop every script on the page in a browser that lacks it, e.g. Safari before 14)
window.matchMedia?.('(display-mode: standalone)').addEventListener?.('change', () => {
    facts.installed = standalone();
    publish();
});

function watch(registration) {
    const found = (worker) => {
        // A first-ever install has no controller yet: nothing to "update". Only a worker that is replacing a running one waits.
        if (worker.state === 'installed' && navigator.serviceWorker.controller) {
            waiting = worker;
            facts.updateReady = true;
            publish();
        }
    };

    if (registration.waiting) {
        found(registration.waiting);
    }

    registration.addEventListener('updatefound', () => {
        const worker = registration.installing;
        worker?.addEventListener('statechange', () => found(worker));
    });

    // An installed app can stay open for days: look for a new version whenever it comes back to the front.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            registration.update().catch(() => {});
        }
    });
}

if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        if (!reloading && waiting) {       // only when the person asked for the update (see applyUpdate)
            reloading = true;
            window.location.reload();
        }
    });

    window.addEventListener('load', () => {
        const url = document.querySelector('meta[name="pwa-worker"]')?.content;

        if (url) {
            navigator.serviceWorker.register(url).then(watch).catch(() => { /* an app that cannot install still works as a website */ });
        }
    });
}

window.pwaShell = () => ({
    ...facts,

    sync(detail) {
        Object.assign(this, detail);
    },

    async install() {
        if (!installPrompt) {
            return;
        }

        installPrompt.prompt();
        await installPrompt.userChoice;
        installPrompt = null;
        facts.canInstall = false;
        publish();
    },

    applyUpdate() {
        if (waiting) {
            waiting.postMessage({ type: 'SKIP_WAITING' });   // the worker takes over; controllerchange above reloads the page once
        }
    },
});
