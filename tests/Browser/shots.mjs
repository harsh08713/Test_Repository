// Screenshots of key screens at several widths, for looking at the design: node tests/Browser/shots.mjs <outDir>
// Also reports any screen where the page is wider than the window (unwanted horizontal scrolling).
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import { Browser } from './driver.mjs';

const out = process.argv[2] ?? 'shots';
mkdirSync(out, { recursive: true });

const screens = [
    ['login', null, '/login'],
    ['home', 'admin@formezy.test', '/dashboard'],
    ['leads', 'manager@formezy.test', '/crm/leads'],
    ['quotations', 'manager@formezy.test', '/sales/quotations'],
    ['stock', 'inventory@formezy.test', '/inventory/stock'],
];
const widths = [[320, 700], [375, 800], [768, 900], [1024, 800], [1440, 900], [1920, 1000]];

const browser = await Browser.launch();
const page = await browser.page(process.env.APP_URL ?? 'http://127.0.0.1:8731');

try {
    for (const [name, user, path] of screens) {
        if (user) {
            await page.signIn(user);
        }
        for (const [w, h] of widths) {
            await page.viewport(w, h);
            await page.goto(path, 900);
            const over = await page.evaluate('document.documentElement.scrollWidth - document.documentElement.clientWidth');
            await page.screenshot(join(out, `${name}-${w}.png`));
            console.log(`${name} @${w}: ${over > 1 ? 'HORIZONTAL OVERFLOW by ' + over + 'px' : 'ok'}`);
        }
    }
} finally {
    await browser.close();
}
