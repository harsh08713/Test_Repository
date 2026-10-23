// A small driver for a real (headless) Chrome or Edge, speaking the DevTools protocol over Node's own WebSocket.
// No packages to install: Node 22+ and a Chromium-based browser that is already on the machine.
//
// It exists because the Pest suite drives every screen on the server and so cannot see what only happens in a browser:
// a script error, a button that does nothing, a calendar that draws the wrong dates. See tests/Browser/run.mjs.

import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** The browser to drive: BROWSER_BIN, or the first Chrome / Edge found in the usual places. */
export function findBrowser() {
    const candidates = [
        process.env.BROWSER_BIN,
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files/Google/Chrome/Application/chrome.exe',
        'C:/Program Files (x86)/Google/Chrome/Application/chrome.exe',
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
        '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/microsoft-edge',
    ].filter(Boolean);

    const found = candidates.find((path) => existsSync(path));

    if (!found) {
        throw new Error('No Chrome or Edge found. Set BROWSER_BIN to the browser\'s executable.');
    }

    return found;
}

export class Browser {
    #process; #profile; #socket; #nextId = 0; #pending = new Map(); #listeners = new Map();

    static async launch(binary = findBrowser()) {
        const browser = new Browser();
        const port = 9300 + Math.floor(Math.random() * 500);

        browser.#profile = mkdtempSync(join(tmpdir(), 'formezy-browser-'));
        browser.#process = spawn(binary, [
            '--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${browser.#profile}`, '--disable-gpu',
            '--no-first-run', '--no-default-browser-check', '--disable-extensions', '--hide-scrollbars', 'about:blank',
        ], { stdio: 'ignore' });

        let url;
        for (let attempt = 0; attempt < 150 && !url; attempt++) {
            try {
                url = (await (await fetch(`http://127.0.0.1:${port}/json/version`)).json()).webSocketDebuggerUrl;
            } catch {
                await sleep(100);
            }
        }
        if (!url) {
            throw new Error(`The browser did not start (${binary}).`);
        }

        browser.#socket = new WebSocket(url);
        await new Promise((resolve, reject) => { browser.#socket.onopen = resolve; browser.#socket.onerror = reject; });

        browser.#socket.onmessage = ({ data }) => {
            const message = JSON.parse(data);

            if (message.id !== undefined) {
                const { resolve, reject } = browser.#pending.get(message.id);
                browser.#pending.delete(message.id);
                message.error ? reject(new Error(`${message.error.message} ${message.error.data ?? ''}`)) : resolve(message.result);
            } else {
                browser.#listeners.get(message.sessionId)?.(message.method, message.params);
            }
        };

        return browser;
    }

    send(method, params = {}, sessionId = undefined) {
        return new Promise((resolve, reject) => {
            const id = ++this.#nextId;
            this.#pending.set(id, { resolve, reject });
            this.#socket.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
        });
    }

    /** A page in a context of its own: its own cookies, so its own signed-in user. */
    async page(origin) {
        const { browserContextId } = await this.send('Target.createBrowserContext');
        const { targetId } = await this.send('Target.createTarget', { url: 'about:blank', browserContextId });
        const { sessionId } = await this.send('Target.attachToTarget', { targetId, flatten: true });

        const page = new Page(this, sessionId, browserContextId, origin);
        this.#listeners.set(sessionId, (method, params) => page.heard(method, params));
        await page.start();

        return page;
    }

    async close() {
        await this.send('Browser.close').catch(() => {});
        this.#process.kill();
        await sleep(300);
        try { rmSync(this.#profile, { recursive: true, force: true }); } catch { /* the browser may still hold a file */ }
    }
}

export class Page {
    #browser; #session; #context; #origin; #problems = []; #waiters = [];

    constructor(browser, session, context, origin) {
        this.#browser = browser; this.#session = session; this.#context = context; this.#origin = origin;
    }

    #send(method, params = {}) {
        return this.#browser.send(method, params, this.#session);
    }

    async start() {
        for (const domain of ['Page', 'Runtime', 'Log', 'Network', 'DOM']) {
            await this.#send(`${domain}.enable`);
        }
        await this.viewport(1440, 1000);
    }

    /** Everything that went wrong in the page: script errors, console errors, failed and 4xx/5xx requests. */
    heard(method, params) {
        if (method === 'Runtime.exceptionThrown') {
            this.#problems.push(`exception: ${params.exceptionDetails.exception?.description ?? params.exceptionDetails.text}`);
        } else if (method === 'Runtime.consoleAPICalled' && ['error', 'warning'].includes(params.type)) {
            this.#problems.push(`console.${params.type}: ${params.args.map((a) => a.value ?? a.description ?? '').join(' ')}`);
        } else if (method === 'Log.entryAdded' && params.entry.level === 'error') {
            this.#problems.push(`log: ${params.entry.text} ${params.entry.url ?? ''}`);
        } else if (method === 'Network.responseReceived' && params.response.status >= 400) {
            this.#problems.push(`HTTP ${params.response.status} ${params.response.url}`);
        } else if (method === 'Network.loadingFailed' && !params.canceled) {
            this.#problems.push(`request failed: ${params.errorText}`);
        }

        for (const waiter of [...this.#waiters]) {
            if (waiter.method === method) {
                this.#waiters.splice(this.#waiters.indexOf(waiter), 1);
                waiter.resolve(params);
            }
        }
    }

    /** What went wrong since this was last asked, each problem once. */
    problems() {
        const found = [...new Set(this.#problems)];
        this.#problems = [];

        return found;
    }

    #once(method, timeout = 30000) {
        return new Promise((resolve, reject) => {
            const waiter = { method, resolve };
            this.#waiters.push(waiter);
            setTimeout(() => {
                if (this.#waiters.includes(waiter)) {
                    this.#waiters.splice(this.#waiters.indexOf(waiter), 1);
                    reject(new Error(`Timed out waiting for ${method}`));
                }
            }, timeout);
        });
    }

    async viewport(width, height) {
        await this.#send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: width < 600 });
    }

    /** The position the device will report to the page. */
    async position(latitude, longitude, accuracy) {
        await this.#browser.send('Browser.grantPermissions', { permissions: ['geolocation'], origin: this.#origin, browserContextId: this.#context });
        await this.#send('Emulation.setGeolocationOverride', { latitude, longitude, accuracy });
    }

    async goto(path, settle = 500) {
        const loaded = this.#once('Page.loadEventFired');
        await this.#send('Page.navigate', { url: path.startsWith('http') ? path : this.#origin + path });
        await loaded;
        await sleep(settle);
    }

    async evaluate(expression) {
        const { result, exceptionDetails } = await this.#send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true });

        if (exceptionDetails) {
            throw new Error(exceptionDetails.exception?.description ?? exceptionDetails.text);
        }

        return result.value;
    }

    /**
     * Wait until an expression is truthy in the page, and hand back its value (or false when it never was).
     *
     * The value crosses to this process by value, so ask for text, numbers or `!!element` — never the element itself:
     * the frameworks hang their own objects on elements, and one that cannot be copied reads as "not there yet".
     */
    async until(expression, timeout = 10000) {
        const deadline = Date.now() + timeout;

        while (Date.now() < deadline) {
            const value = await this.evaluate(expression).catch(() => false);
            if (value) {
                return value;
            }
            await sleep(150);
        }

        return false;
    }

    async click(selector, settle = 600) {
        const clicked = await this.evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false; el.click(); return true; })()`);
        await sleep(settle);

        return clicked;
    }

    /** Put a value in a field the way typing or choosing does: the value, then the events Livewire and Alpine listen for. */
    async fill(selector, value, settle = 300) {
        const filled = await this.evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return false;
            const proto = el.tagName === 'SELECT' ? HTMLSelectElement.prototype : el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
            Object.getOwnPropertyDescriptor(proto, 'value').set.call(el, ${JSON.stringify(String(value))});
            el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); return true; })()`);
        await sleep(settle);

        return filled;
    }

    /** Choose a file in a file input, as the file dialog would. */
    async choose(selector, path, settle = 2500) {
        const { root } = await this.#send('DOM.getDocument', { depth: 0 });
        const { nodeId } = await this.#send('DOM.querySelector', { nodeId: root.nodeId, selector });

        if (!nodeId) {
            return false;
        }

        await this.#send('DOM.setFileInputFiles', { nodeId, files: [path] });
        await sleep(settle);

        return true;
    }

    /** Press on the middle of an element, move in small steps, release: what a hand on a mouse does. */
    async drag(selector, dx, dy, settle = 300) {
        const from = await this.evaluate(`(() => { const el = document.querySelector(${JSON.stringify(selector)}); if (!el) return null; el.scrollIntoView({ block: 'center' });
            const r = el.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; })()`);

        if (!from) {
            return false;
        }

        const mouse = (type, x, y, extra = {}) => this.#send('Input.dispatchMouseEvent', { type, x, y, ...extra });
        await mouse('mouseMoved', from.x, from.y);
        await mouse('mousePressed', from.x, from.y, { button: 'left', clickCount: 1 });
        for (let step = 1; step <= 12; step++) {
            await mouse('mouseMoved', from.x + (dx * step) / 12, from.y + (dy * step) / 12, { button: 'left', buttons: 1 });
            await sleep(30);
        }
        await mouse('mouseReleased', from.x + dx, from.y + dy, { button: 'left', clickCount: 1 });
        await sleep(settle);

        return true;
    }

    /** Sign in through the real sign-in form. */
    async signIn(email, password = 'password') {
        await this.goto('/login');
        await this.fill('#email', email, 100);
        await this.fill('#password', password, 100);

        const landed = this.#once('Page.loadEventFired', 20000).catch(() => null);
        await this.click('form[wire\\:submit=login] button[type=submit]', 0);
        await landed;

        return Boolean(await this.until(`location.pathname !== '/login' && document.readyState === 'complete'`, 15000));
    }

    async screenshot(path) {
        const { data } = await this.#send('Page.captureScreenshot', { format: 'png' });
        writeFileSync(path, Buffer.from(data, 'base64'));
    }
}
