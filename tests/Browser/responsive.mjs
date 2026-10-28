// Opens every page of the application at phone, tablet-portrait and tablet-landscape widths and reports the ones that
// do not fit: node tests/Browser/responsive.mjs [email] [widths, e.g. 375,768,1024]
//
// A page fails when
//   - the page as a whole scrolls sideways (the content is wider than the window), or
//   - the sticky header or the main area is cut off on the right, or
//   - a control the person must press (button, link, field) is smaller than 24 x 24 CSS px on a phone or tablet
//     (WCAG 2.2 target size, minimum), counting only what is on the screen.
// Phone and tablet widths (up to 1024) are driven as touch devices (a coarse pointer, no hover), so the touch-only CSS is what is measured.
// It names the first few elements that stick out, so the fix can be made where it belongs.
//
// Needs the seeded demo data and a running server (BROWSER_CHECK_URL, default http://127.0.0.1:8731), like run.mjs.

import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Browser } from './driver.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const origin = (process.env.BROWSER_CHECK_URL ?? 'http://127.0.0.1:8731').replace(/\/$/, '');
const email = process.argv[2] ?? 'admin@formezy.test';
const widths = (process.argv[3] ?? '375,768,1024').split(',').map(Number);
const only = process.env.ONLY ? new RegExp(process.env.ONLY) : null;

function findPhp() {
    const tools = process.env.USERPROFILE ? join(process.env.USERPROFILE, 'tools') : null;
    const portable = tools && existsSync(tools)
        ? readdirSync(tools).filter((d) => /^php-8\.\d+/.test(d)).sort().reverse().map((d) => join(tools, d, process.platform === 'win32' ? 'php.exe' : 'php')).filter((f) => existsSync(f))
        : [];

    for (const candidate of [process.env.PHP_BIN, 'php', ...portable].filter(Boolean)) {
        try {
            if (Number(execFileSync(candidate, ['-r', 'echo PHP_VERSION_ID;'], { encoding: 'utf8' }).trim()) >= 80300) {
                return candidate;
            }
        } catch { /* try the next one */ }
    }

    console.error('PHP 8.3+ is needed to list the pages (set PHP_BIN).');
    process.exit(2);
}

const routes = JSON.parse(execFileSync(findPhp(), ['artisan', 'route:list', '--json'], { cwd: root, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }));
const pages = [...new Set(routes
    .filter((r) => /^GET/.test(r.method) && !r.uri.includes('{') && r.uri !== '/' && !/^(livewire|up$|storage|_|imports\/|login$|logout$|api\/|manifest|sw\.js|offline)/.test(r.uri) && !/lookup$/.test(r.uri))
    .map((r) => `/${r.uri}`))].filter((p) => !only || only.test(p)).sort();

const probe = String.raw`(() => {
    const doc = document.documentElement;
    const over = doc.scrollWidth - doc.clientWidth;
    const out = [...document.querySelectorAll('body *')]
        .filter((e) => e.offsetParent !== null || getComputedStyle(e).position === 'fixed')
        .filter((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.right > doc.clientWidth + 1 && !e.closest('.table-wrap, [class*="overflow-x"], .fc, aside, [x-cloak], .sr-only'); })
        .slice(0, 4).map((e) => e.tagName.toLowerCase() + '.' + String(e.className).trim().split(/\s+/).slice(0, 4).join('.') + ' right=' + Math.round(e.getBoundingClientRect().right));
    const small = [...document.querySelectorAll('main a[href], main button, main input:not([type=hidden]):not([type=checkbox]):not([type=radio]), main select, main textarea, header a, header button')]
        .filter((e) => e.offsetParent !== null && !e.closest('.sr-only, [aria-hidden=true]'))
        .filter((e) => { const r = e.getBoundingClientRect(); const inline = getComputedStyle(e).display === 'inline'; return !inline && r.width > 0 && (r.width < 24 || r.height < 24); })
        .slice(0, 4).map((e) => e.tagName.toLowerCase() + ':' + (e.getAttribute('aria-label') || e.textContent.trim().slice(0, 18) || e.className.toString().slice(0, 20)) + ' ' + Math.round(e.getBoundingClientRect().width) + 'x' + Math.round(e.getBoundingClientRect().height));
    return JSON.stringify({ over, out, small, h1: document.querySelector('h1')?.textContent.trim() ?? '' });
})()`;

const browser = await Browser.launch();
const bad = [];
let looked = 0;

try {
    const page = await browser.page(origin);
    await page.signIn(email);

    for (const width of widths) {
        await page.viewport(width, width < 600 ? 800 : 1000, width <= 1024);   // a phone or tablet is a finger, not a mouse

        for (const path of pages) {
            try {
                await page.goto(path, 250);
            } catch (error) {
                bad.push({ width, path, why: `did not load: ${error.message}` });
                continue;
            }

            looked++;
            const r = JSON.parse(await page.evaluate(probe));
            const why = [];

            if (r.over > 1) why.push(`page scrolls sideways by ${r.over}px: ${r.out.join(' | ')}`);
            if (r.small.length) why.push(`small targets: ${r.small.join(' | ')}`);
            if (why.length) bad.push({ width, path, why: why.join('  //  ') });
        }
    }
} finally {
    await browser.close();
}

for (const b of bad) {
    console.log(`${String(b.width).padStart(4)}  ${b.path}  ${b.why}`);
}

console.log(`\n${looked} page views (${pages.length} pages x ${widths.join('/')}), ${bad.length} with a problem.`);
process.exit(bad.length ? 1 : 0);
