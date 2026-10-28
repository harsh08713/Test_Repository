{{-- Says so when the device is offline or a new version of the app is waiting. State comes from resources/js/pwa.js.
     A live region (aria-live) and deliberately NOT role="status": that role is what the page's own notices carry, and tests/Feature/NoticesTest.php
     and the browser checks tell "this page said something" from "nothing said" by it. --}}
<div x-data="pwaShell()" x-on:pwa-change.window="sync($event.detail)" aria-live="polite"
     class="pointer-events-none fixed inset-x-0 bottom-0 z-50 flex flex-col items-center gap-2 px-4 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
    <div x-show="! online" x-cloak data-pwa="offline"
         class="pointer-events-auto w-full max-w-md rounded-md bg-slate-900 px-4 py-3 text-sm text-white shadow-lg">
        <strong class="font-semibold">You are offline.</strong>
        Records need a connection, so nothing you change is saved until it is back.
    </div>
    <div x-show="updateReady" x-cloak data-pwa="update"
         class="pointer-events-auto flex w-full max-w-md items-center justify-between gap-3 rounded-md bg-brand-700 px-4 py-3 text-sm text-white shadow-lg">
        <span>A new version of the app is ready.</span>
        <button type="button" x-on:click="applyUpdate()" class="min-h-10 shrink-0 rounded-md bg-white px-3 py-1.5 text-sm font-semibold text-brand-700 hover:bg-brand-50">Update now</button>
    </div>
</div>
