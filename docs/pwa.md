# Phones, tablets and the installable app (Phase 17, first slice)

FormEzy is one web application that fits a phone, a tablet and a desktop, and that a person can **install** on the home screen like an app. This page says what was built, what it deliberately does not do yet, and how to check it.

## What a person gets

| | |
|---|---|
| **Phone (under 640 px)** | The menu is a drawer behind the menu button. A table of up to eight columns becomes one card per row, each value named by its column heading (`resources/js/ui.js`); wider tables scroll sideways inside their own panel. Fields are 16 px so iPhone Safari does not zoom the page when one is tapped. |
| **Tablet (640–1023 px)** | Same drawer, full-width tables, two-column forms. In landscape (1024 px and over) the menu stays open on the left, as on a desktop. |
| **Touch** | Small text buttons ("Remove", "Edit", "History") get a 30 px-high target on any touch screen or window under 1024 px, without moving the text. A control that only appeared on hover (the tree's Add / Edit / Move / Delete) is always shown where there is no mouse. No 300 ms tap delay. |
| **Notched phones, standalone window** | `viewport-fit=cover`, and the page and menu keep clear of the notch and the home bar (`env(safe-area-inset-*)`). |
| **Install** | The account menu shows **Install app** where the browser offers it (Chrome, Edge, Android) and, on iPhone and iPad, a line saying *Share → Add to Home Screen*. The app opens in its own window with the steel-blue icon and four shortcuts on its icon (Leads, Follow-ups, Attendance, Approvals). |
| **Offline** | A bar says **You are offline** and that nothing changed is saved. A page that cannot be fetched shows `offline.html` ("You are offline… Try again"); it opens the page the person wanted by itself when the connection returns. |
| **Updates** | When a new version of the app's files is ready, a bar says so and **Update now** reloads once. Nothing reloads under a person who is half way through a form; an installed app that stays open for days looks for an update each time it is brought to the front. |

Installing needs **HTTPS** (or `localhost`): browsers do not run a service worker on plain `http://` to another machine. Behind the production certificate it just works; on a LAN test machine use `localhost` or a certificate.

## The pieces

| File | Role |
|---|---|
| `GET /manifest.webmanifest` → `Shared\Http\Controllers\WebManifestController` | Public (a browser reads it without a session). Name from `config('app.name')`, start page `/dashboard?source=pwa`, `display: standalone`, three icons (192, 512, 512 maskable), four shortcuts. **Holds nothing about a user, company or record** — a test holds the signed-in and signed-out answers identical. |
| `public/sw.js` | The service worker (see below). |
| `public/offline.html` | Self-contained (inline style and script, no network needs): it must work when nothing else can load. |
| `public/icons/*.png` | `icon-192`, `icon-512`, `icon-maskable-512`, `apple-touch-icon` (180), `favicon-32`. Made by `php scripts/make-pwa-icons.php public/icons` (the sidebar's chart mark on the brand blue) — change the colours or the mark there and run it again. |
| `resources/js/pwa.js` | Registers the worker and keeps four facts (online, can install, installed, update ready); `pwaShell()` hands them to Alpine components, and the facts survive page changes (`wire:navigate`). |
| `resources/views/layouts/partials/pwa-head.blade.php`, `pwa-status.blade.php` | The tags in every page's head (manifest, theme colour, Apple icon and app tags) and the offline / update bars. Both layouts (signed in, sign-in) include them. |
| `resources/views/components/filters.blade.php` (`<x-filters>`) | A list's filters. Unchanged from 640 px up; on a phone only the search stays on the screen and the rest sits behind a **Filters** button that says how many are set, so the first rows are not pushed a screen down. Used on **Leads** so far; the other list screens (deals, quotations, orders, invoices, attendance, rates, employees…) still show all their filters and are the next to move over. |
| `tests/Feature/PwaTest.php`, `FiltersComponentTest.php` | The manifest, the icons at the sizes it names, every shortcut a real route, the tags on both layouts. |
| `tests/Browser/run.mjs` (*installable app*) | In a real browser: the worker takes control, **only build files and icons are ever stored**, the network is cut and the offline page and bar appear, the update and install buttons work. |
| `tests/Browser/responsive.mjs` | Opens **every page** at 375, 768 and 1024 px — driven as a touch device (a coarse pointer, no hover), because a narrow window with a mouse behaves like a desktop — and fails on sideways scroll or a control under 24 × 24 px. |

## What the service worker keeps — and never keeps

It stores only the application's own **build files** (`/build/assets/*`, named by their content, so a name never changes meaning) and the **icons**, plus `offline.html`. Everything else — every page, every Livewire update, every API answer, every export, every request that is not a GET — goes straight to the network, as if the worker were not there. A page is **never** served from storage, because a stored page is somebody's customers, employees or prices on a shared phone after they sign out. When the network fails, only a *navigation* gets `offline.html`.

Bump `VERSION` in `public/sw.js` if its behaviour changes; the old cache is removed when the new worker activates, and the app offers the update.

## What this slice does **not** do

- **Nothing can be saved offline.** The bar says so. Working without a connection (a lead entered in a lift, a follow-up logged underground) is the next slice: a queue of changes held in the browser, each with a client-made key so the server's existing idempotency makes a double submit harmless, shown to the person as *waiting / sent / refused*, and replayed against the same rules (permissions, geofence, approvals, rates) the screen enforces. The mobile API already takes `Idempotency-Key`; the batch endpoint and the conflict policy are being written on the API side first (see [api.md](api.md)).
- **No push notifications**, no background sync, no camera, no offline copy of lookups.
- **No native app.** If the owner chooses Flutter later, it uses the same `/api/v1`.
- **Not verified on a real iPhone or Android phone**: the browser checks drive Chrome/Edge with a phone-sized window and a cut network. Open the app on each device you will hand out before relying on it (install, sign in, Add to Home Screen, airplane mode).

## Rules for the next person touching a screen

1. A table with two to eight columns stacks on a phone **by itself**; give every `<th>` text, and a cell with only an icon or a button an empty heading (`<th></th>`) so the card has no stray label.
2. A control that must be pressed is at least 24 × 24 px (aim for 36+). A small text button written `text-xs … hover:underline` is padded for touch by `app.css`; a link that is a flex or grid item needs its own `py-*` with an equal negative `-my-*`.
3. Never show something **only** on hover; use `pointer-fine:` for the hover-only version (Tailwind's `hover:` does not apply on touch screens at all).
4. A list with more than two or three filters wraps them in `<x-filters :active="$n" id="…">` (search in its `primary` slot); give each field `w-full sm:w-48` so it fills its half of the phone's two-column panel.
5. A Chart.js canvas still sits in a `wire:ignore` wrapper; a dialog is `role="dialog" aria-modal="true"` (focus, Escape and scroll lock come from `ui.js`).
6. Run `node tests/Browser/responsive.mjs` after changing the layout or a shared building block; it names the element that sticks out.
