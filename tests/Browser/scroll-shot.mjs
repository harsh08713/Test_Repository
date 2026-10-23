// A screenshot of a page scrolled to its first table row: node tests/Browser/scroll-shot.mjs <email> <path> <width> <file>
import { Browser } from './driver.mjs';

const [email, path, width, file] = process.argv.slice(2);
const browser = await Browser.launch();
const page = await browser.page(process.env.APP_URL ?? 'http://127.0.0.1:8731');

try {
    await page.signIn(email);
    await page.viewport(Number(width), 800);
    await page.goto(path, 900);
    await page.evaluate(`(() => { const r = document.querySelector('main table tbody tr'); if (r) window.scrollTo(0, r.getBoundingClientRect().top + window.scrollY - 90); return true; })()`);
    await new Promise((r) => setTimeout(r, 300));
    await page.screenshot(file);
} finally {
    await browser.close();
}
