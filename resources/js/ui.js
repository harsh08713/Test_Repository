/*
 * Two behaviours every screen shares, kept here so the 180-odd views need no change:
 *
 *  1. Dialogs (role="dialog" aria-modal="true"): focus moves in when one opens, Tab stays inside it, Escape presses its
 *     Cancel / Close button, the page behind does not scroll, and focus goes back to what opened it.
 *  2. Tables (.data-table) with up to eight columns show as one card per row on a phone, each value labelled with its
 *     column heading. Wider tables keep their sideways scroll: a card of twelve lines is no easier to read.
 *
 * Livewire re-renders replace parts of the page, so both are applied by an observer and are safe to apply again.
 */

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
const MAX_STACKED_COLUMNS = 8;

const visible = (el) => el.offsetParent !== null || el.getClientRects().length > 0;

/* ---- dialogs ---- */

let openDialog = null;
let opener = null;

function closeButton(dialog) {
    return [...dialog.querySelectorAll('button')].find((b) => /^(cancel|close|back)$/i.test(b.textContent.trim()) || /^(close|cancel)/i.test(b.getAttribute('wire:click') ?? ''));
}

function onDialogKey(event) {
    if (!openDialog) {
        return;
    }

    if (event.key === 'Escape') {
        const button = closeButton(openDialog);

        if (button) {
            event.preventDefault();
            event.stopPropagation();
            button.click();
        }

        return;
    }

    if (event.key !== 'Tab') {
        return;
    }

    const items = [...openDialog.querySelectorAll(FOCUSABLE)].filter(visible);

    if (items.length === 0) {
        event.preventDefault();

        return;
    }

    const first = items[0];
    const last = items[items.length - 1];

    if (!openDialog.contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
    } else if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

function syncDialogs() {
    const dialog = [...document.querySelectorAll('[role=dialog][aria-modal=true]')].filter(visible).pop() ?? null;

    if (dialog === openDialog) {
        return;
    }

    if (dialog && !openDialog) {
        opener = document.activeElement instanceof HTMLElement && document.activeElement !== document.body ? document.activeElement : null;
        document.documentElement.classList.add('overflow-hidden');
    }

    if (!dialog) {
        document.documentElement.classList.remove('overflow-hidden');

        if (opener && opener.isConnected) {
            opener.focus();
        }

        opener = null;
    }

    openDialog = dialog;

    if (dialog && !dialog.contains(document.activeElement)) {
        const target = dialog.querySelector('[autofocus]') ?? [...dialog.querySelectorAll('input:not([type=hidden]), select, textarea')].find(visible) ?? [...dialog.querySelectorAll(FOCUSABLE)].filter(visible)[0];

        target?.focus();
    }
}

/* ---- tables ---- */

function stackTables() {
    document.querySelectorAll('table.data-table').forEach((table) => {
        const headRow = table.tHead?.rows[table.tHead.rows.length - 1];
        const labels = headRow ? [...headRow.cells].map((th) => th.textContent.trim().replace(/\s+/g, ' ')) : [];
        const stack = labels.length >= 2 && labels.length <= MAX_STACKED_COLUMNS;

        if (table.classList.contains('stacked') !== stack) {
            table.classList.toggle('stacked', stack);
        }

        if (!stack) {
            return;
        }

        [...table.tBodies].forEach((body) => [...body.rows].forEach((row) => {
            if (row.cells.length !== labels.length) {
                return;
            }

            [...row.cells].forEach((cell, i) => {
                if (cell.getAttribute('data-label') !== labels[i]) {
                    cell.setAttribute('data-label', labels[i]);
                }
            });
        }));
    });
}

/* ---- wiring ---- */

let queued = false;

function apply() {
    queued = false;
    syncDialogs();
    stackTables();
}

function schedule() {
    if (!queued) {
        queued = true;
        requestAnimationFrame(apply);
    }
}

document.addEventListener('keydown', onDialogKey, true);
document.addEventListener('DOMContentLoaded', () => {
    new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'data-label'] });
    apply();
});
document.addEventListener('livewire:navigated', apply);
