// Names the elements that stick out past the window: node tests/Browser/overflow.mjs <email> <path> <width>
import { Browser } from './driver.mjs';

const [email, path, width = '375'] = process.argv.slice(2);
const browser = await Browser.launch();
const page = await browser.page(process.env.APP_URL ?? 'http://127.0.0.1:8731');

try {
    await page.signIn(email);
    await page.viewport(Number(width), 800);
    await page.goto(path, 900);
    console.log(await page.evaluate(`JSON.stringify([...document.querySelectorAll('main *')].filter(e => e.getBoundingClientRect().right > document.documentElement.clientWidth + 1 && e.offsetParent !== null).slice(0, 8).map(e => e.tagName + '.' + String(e.className).slice(0, 90) + ' right=' + Math.round(e.getBoundingClientRect().right)))`));
} finally {
    await browser.close();
}
