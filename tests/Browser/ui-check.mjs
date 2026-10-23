// Checks the shared behaviours of resources/js/ui.js in a real browser: node tests/Browser/ui-check.mjs [outDir]
//  - a narrow table is stacked on a phone and the panel does not scroll sideways
//  - a dialog takes focus, keeps Tab inside, closes on Escape and hands focus back
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { Browser } from './driver.mjs';

const out = process.argv[2] ?? 'shots';
mkdirSync(out, { recursive: true });
const browser = await Browser.launch();
const origin = process.env.APP_URL ?? 'http://127.0.0.1:8731';
let page = await browser.page(origin);
let failed = 0;
const check = (name, ok, detail = '') => {
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' (' + detail + ')' : ''}`);
    failed += ok ? 0 : 1;
};
const key = (k, shift = false) => page.evaluate(`document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: '${k}', shiftKey: ${shift}, bubbles: true, cancelable: true })), true`);
const active = () => page.evaluate(`document.activeElement.tagName + '#' + document.activeElement.id`);

try {
    await page.signIn('manager@formezy.test');
    await page.viewport(375, 800);

    for (const path of ['/crm/leads', '/sales/quotations', '/crm/followups']) {
        await page.goto(path, 900);
        const r = JSON.parse(await page.evaluate(`JSON.stringify({
            tables: document.querySelectorAll('main table.data-table').length,
            stacked: document.querySelectorAll('main table.data-table.stacked').length,
            pageOver: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            panelOver: Math.max(0, ...[...document.querySelectorAll('main .table-wrap')].filter(w => w.querySelector('table.stacked')).map(w => w.scrollWidth - w.clientWidth)),
            labelled: document.querySelectorAll('main table.stacked td[data-label]').length
        })`));
        check(`${path}: narrow tables are stacked, no sideways scroll`, r.pageOver <= 1 && r.panelOver <= 1 && (r.tables === 0 || r.stacked > 0), JSON.stringify(r));
        await page.screenshot(join(out, `stacked${path.replaceAll('/', '-')}.png`));
    }

    page = await browser.page(origin);   // a fresh context: the manager above stays signed in on the old one
    await page.signIn('companyadmin@formezy.test');
    await page.viewport(1440, 900);
    await page.goto('/masters/departments', 900);
    await page.evaluate(`(() => { const b = [...document.querySelectorAll('main button')].find(x => x.textContent.trim() === 'Add'); b.setAttribute('data-add', ''); b.focus(); return true; })()`);
    await page.click('[data-add]', 900);

    check('dialog opens and takes focus inside it', await page.evaluate(`!!document.querySelector('[role=dialog]') && document.querySelector('[role=dialog]').contains(document.activeElement)`));
    check('the page behind does not scroll while it is open', await page.evaluate(`document.documentElement.classList.contains('overflow-hidden')`));

    await page.evaluate(`(() => { const d = document.querySelector('[role=dialog]'); const f = [...d.querySelectorAll('a[href], button:not([disabled]), input:not([type=hidden]):not([disabled]), select, textarea')].filter(e => e.offsetParent !== null); f[f.length - 1].focus(); window.__first = f[0]; return true; })()`);
    await key('Tab');
    check('Tab from the last control goes to the first (focus stays inside)', await page.evaluate(`document.activeElement === window.__first`));
    await key('Tab', true);
    check('Shift+Tab from the first goes to the last', await page.evaluate(`document.querySelector('[role=dialog]').contains(document.activeElement) && document.activeElement !== window.__first`));

    await page.evaluate(`document.querySelector('[role=dialog] input').focus(), true`);
    await key('Escape');
    await page.until(`!document.querySelector('[role=dialog]')`, 8000).catch(() => null);
    check('Escape closes it', await page.evaluate(`!document.querySelector('[role=dialog]')`));
    check('focus goes back to the Add button, and the page scrolls again', await page.evaluate(`document.activeElement.textContent.trim() === 'Add' && !document.documentElement.classList.contains('overflow-hidden')`), await active());
} finally {
    await browser.close();
}

console.log(failed ? `${failed} check(s) FAILED` : 'All UI checks passed.');
process.exit(failed ? 1 : 0);
