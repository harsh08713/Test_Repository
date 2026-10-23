// Checks, in a real browser, the things the Pest suite cannot see: that every page opens without a script error, and
// that the behaviour which depends on JavaScript actually works when a person uses it.
//
//   php artisan migrate:fresh --seed        (the checks sign in as the demo accounts and use the demo data)
//   php artisan serve --port=8731           (or any server; say where with BROWSER_CHECK_URL)
//   npm run test:browser                    (or: node tests/Browser/run.mjs calendar   to run the checks whose name matches)
//
// Needs Node 22+ and Chrome or Edge (BROWSER_BIN to choose one), and PHP 8.3+ for the list of pages: it uses PHP_BIN, then `php` on the PATH, then a portable PHP under ~/tools; it says so if none is new enough.
// The checks add a few records (a lost reason, some imported leads, today's attendance), so seed again afterwards.
//
// Each of these was added because a screen passed every server-side test and did not work:
//   - the import wizard's "Upload and check" did nothing (its action was called `upload`, which Livewire reserves);
//   - a success message was shown twice after a reload, and not at all after an in-place save;
//   - the calendar's month view headed its columns with made-up dates.

import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { Browser } from './driver.mjs';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const origin = (process.env.BROWSER_CHECK_URL ?? 'http://127.0.0.1:8731').replace(/\/$/, '');
const php = findPhp();
const only = process.argv[2]?.toLowerCase();
const js = String.raw;

// The project needs PHP 8.3 or newer; on a machine with XAMPP, the `php` on the PATH is often an older one and `artisan` stops with a platform error. Look for a suitable one: PHP_BIN, then `php`,
// then a portable PHP 8.x under the user's `tools` folder (the layout the README describes). Say plainly when none is found, instead of letting artisan fail with a cryptic message.
function findPhp() {
    const tools = process.env.USERPROFILE ? join(process.env.USERPROFILE, 'tools') : null;
    const portable = tools && existsSync(tools)
        ? readdirSync(tools).filter((d) => /^php-8\.\d+/.test(d)).sort().reverse().map((d) => join(tools, d, process.platform === 'win32' ? 'php.exe' : 'php')).filter((f) => existsSync(f))
        : [];
    const candidates = [process.env.PHP_BIN, 'php', ...portable].filter(Boolean);
    const tried = [];

    for (const candidate of candidates) {
        try {
            const id = Number(execFileSync(candidate, ['-r', 'echo PHP_VERSION_ID;'], { encoding: 'utf8' }).trim());

            if (id >= 80300) {
                return candidate;
            }

            tried.push(`${candidate} is PHP ${Math.floor(id / 10000)}.${Math.floor((id % 10000) / 100)}.${id % 100}`);
        } catch {
            tried.push(`${candidate} could not be run`);
        }
    }

    console.error([
        `The browser checks need PHP 8.3 or newer to list the application's pages, and none was found (${tried.join('; ') || 'nothing tried'}).`,
        'Put a PHP 8.3 first on the PATH, or set PHP_BIN to its full path, for example:',
        '  PowerShell:  $env:PHP_BIN = "C:\\Users\\you\\tools\\php-8.3.35\\php.exe"; npm run test:browser',
        '  Git Bash:    PHP_BIN=/c/Users/you/tools/php-8.3.35/php.exe npm run test:browser',
    ].join('\n'));
    process.exit(2);
}

// what an action has just said, as a person sees it (the calendar's own message line is not one of these)
const notices = js`[...document.querySelectorAll('[role=status]')].filter(e => e.offsetParent !== null && !e.hasAttribute('aria-live')).map(e => e.textContent.trim().replace(/\s+/g, ' '))`;
const mainText = js`document.querySelector('main').innerText.replace(/\s+/g, ' ')`;

class Skipped extends Error {}

const checks = {
    async 'every page opens without a script or HTTP error'({ page, expect }) {
        await page.signIn('admin@formezy.test');

        const routes = JSON.parse(execFileSync(php, ['artisan', 'route:list', '--json'], { cwd: root, encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }));
        const pages = [...new Set(routes
            .filter((r) => /^GET/.test(r.method) && !r.uri.includes('{') && r.uri !== '/' && !/^(livewire|up$|storage|_|imports\/|login$|logout$|api\/)/.test(r.uri) && !/lookup$/.test(r.uri))
            .map((r) => `/${r.uri}`))].sort();

        expect(pages.length > 40, `only ${pages.length} pages were found to open`);
        page.problems();

        for (const path of pages) {
            try {
                await page.goto(path, 200);
            } catch (error) {
                throw new Error(`${path} did not finish loading (${error.message})`);
            }

            const heading = await page.evaluate(js`document.querySelector('h1')?.textContent.trim() ?? ''`);
            const problems = page.problems();

            expect(heading !== '', `${path} shows no heading`);
            expect(problems.length === 0, `${path}: ${problems.join('; ')}`);
        }

        return `${pages.length} pages`;
    },

    async 'a success message is said once: after an in-place save, and after a reload'({ page, expect }) {
        await page.signIn('companyadmin@formezy.test');

        // in place: a master list saves without loading the page again
        await page.goto('/crm/masters/lost-reasons');
        await page.click('button[wire\\:click=create]');
        expect(await page.until(js`!!document.querySelector('#f-code')`), 'the add form did not open');

        const code = `BC${Date.now().toString(36).toUpperCase().slice(-6)}`;
        await page.fill('#f-code', code);
        await page.fill('#f-name', `Browser check ${code}`);
        await page.click('form[wire\\:submit=save] button[type=submit]');

        const inPlace = await page.until(`${notices}.length ? ${notices} : false`);
        expect(Array.isArray(inPlace) && inPlace.length === 1 && inPlace[0].startsWith('Added'), `after an in-place save the page said: ${JSON.stringify(inPlace)}`);

        // after a reload: moving a lead to another stage loads its page again
        await page.goto('/crm/leads');
        const lead = await page.evaluate(js`document.querySelector('tbody tr[wire\\:key^=lead-] a')?.getAttribute('href')`);
        expect(Boolean(lead), 'there is no lead in the list to try (seed the demo data)');
        if (!lead) {
            return;
        }

        await page.goto(lead);
        expect(await page.click('button[wire\\:click^=moveTo]', 0), 'the lead has no stage button to press');

        const reloaded = await page.until(`${notices}.length ? ${notices} : false`);
        await page.until('false', 600);     // give a second copy the time to appear, if there is one
        const settled = await page.evaluate(notices);
        expect(Array.isArray(reloaded) && settled.length === 1 && settled[0].startsWith('The lead is now'), `after a reload the page said: ${JSON.stringify(settled)}`);
    },

    async 'the follow-up calendar draws, names its days properly, and moves a follow-up that is dragged'({ page, expect }) {
        await page.signIn('manager@formezy.test');
        await page.goto('/crm/follow-ups/calendar', 300);

        const title = js`document.querySelector('.fc-toolbar-title')?.textContent.trim()`;
        const headings = js`[...document.querySelectorAll('.fc-col-header-cell')].map(c => c.textContent.trim())`;

        expect(await page.until(title), 'the calendar did not draw');

        // week: "Mon, 12 Oct" … day before month
        const week = await page.evaluate(headings);
        expect(week.length === 7 && week.every((h) => /^[A-Z][a-z]{2}, \d{1,2} [A-Z][a-z]{2}$/.test(h)), `the week's columns are headed ${JSON.stringify(week)}`);

        // month: "October 2026", and columns that are weekdays, not dates
        await page.click('.fc-dayGridMonth-button', 500);
        const month = await page.evaluate(title);
        const monthHeadings = await page.evaluate(headings);
        expect(/^[A-Z][a-z]+ \d{4}$/.test(month), `the month is titled "${month}"`);
        expect(JSON.stringify(monthHeadings) === JSON.stringify(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun']), `the month's columns are headed ${JSON.stringify(monthHeadings)}`);

        // drag a planned follow-up to another day: the server moves it and says so
        const planned = '.fc-daygrid-event.fc-event-draggable';
        let found = await page.until(js`!!document.querySelector('.fc-daygrid-event.fc-event-draggable')`, 4000);
        if (!found) {
            await page.click('.fc-next-button', 600);
            found = await page.until(js`!!document.querySelector('.fc-daygrid-event.fc-event-draggable')`, 4000);
        }
        expect(Boolean(found), 'there is no planned follow-up on the calendar to drag (seed the demo data)');

        if (found) {
            const cell = await page.evaluate(js`(() => { const event = document.querySelector('.fc-daygrid-event.fc-event-draggable'); const cell = event.closest('td').getBoundingClientRect();
                const grid = document.querySelector('.fc-daygrid-body').getBoundingClientRect(); return { height: cell.height, roomBelow: cell.bottom + cell.height <= grid.bottom }; })()`);

            expect(await page.drag(planned, 0, cell.roomBelow ? cell.height : -cell.height), 'the follow-up could not be picked up');

            const said = await page.until(js`document.querySelector('[role=status][aria-live]')?.textContent.trim()`, 10000);
            expect(typeof said === 'string' && /^Moved to |must be in the future/.test(said), `after the drag the calendar said: ${JSON.stringify(said)}`);
        }

        // the list view is what a phone gets
        await page.click('.fc-listWeek-button', 500);
        expect(await page.until(js`!!document.querySelector('.fc-list-event, .fc-list-empty')`), 'the list view did not draw');
    },

    async 'the lead form warns about a duplicate, fills the address from the PIN code and finds items'({ page, expect }) {
        await page.signIn('bde@formezy.test');
        await page.goto('/crm/leads/create');

        await page.fill('#l-mobile', '90000 00104', 0);
        const matches = await page.until(js`document.querySelectorAll('[wire\\:key=dups] li').length`);
        expect(matches >= 1, 'typing a mobile number that is already a lead showed no warning (seed the demo data)');
        expect(await page.evaluate(js`!!document.querySelector('input[wire\\:model="lead.acknowledge_duplicate"]')`), 'the warning does not ask whether this is a separate inquiry');

        await page.fill('#l-pin', '380001', 0);
        const place = await page.until(js`(() => { const chosen = ['#l-state', '#l-district', '#l-block'].map(s => document.querySelector(s).selectedOptions[0]?.textContent.trim() ?? '');
            return chosen.every(text => text !== '' && !/^(Select|Choose)/.test(text)) ? chosen.join(' / ') : false; })()`);
        expect(place === 'Gujarat / Ahmedabad / Daskroi', `PIN 380001 filled in: ${JSON.stringify(place)}`);

        await page.fill('#i0-search', 'steel', 0);
        expect(await page.until(js`document.querySelectorAll('#i0-search ~ ul button').length`) >= 1, 'searching the items of interest for "steel" offered nothing');
    },

    async 'a spreadsheet of leads with its own headings is uploaded, mapped, checked and imported'({ page, expect }) {
        await page.signIn('manager@formezy.test');
        await page.goto('/crm/leads/import');
        await page.evaluate('window.confirm = () => true');

        // an upload left open by an earlier run is put aside first
        if (!(await page.evaluate(js`!!document.querySelector('#imp-file')`))) {
            (await page.click('button[wire\\:click=newImport]')) || (await page.click('button[wire\\:click=discard]'));
            expect(await page.until(js`!!document.querySelector('#imp-file')`), 'the upload form did not come back');
        }

        // two new leads, one whose number is already a lead in the demo data, one with no number
        const mobile = () => `9${String(Math.floor(Math.random() * 1e9)).padStart(9, '0')}`;
        const file = join(tmpdir(), `formezy-leads-${Date.now()}.csv`);
        writeFileSync(file, `Client,WhatsApp,Place,Temperature\r\nBrowser Check One,${mobile()},Ahmedabad,Hot\r\nBrowser Check Two,${mobile()},Surat,Warm\r\nKrishna Tools again,9000000104,Ahmedabad,Cold\r\nNo Number Traders,,Vadodara,Cold\r\n`);

        const branch = await page.evaluate(js`[...document.querySelector('#imp-branch').options].find(o => /Ahmedabad/.test(o.textContent))?.value`);
        await page.fill('#imp-branch', branch ?? '');

        // Wait for the upload itself to finish, not a fixed time: until it has, the button is disabled and a click on it does nothing.
        // (A fixed 2.5 s pause failed once the development server took ~0.9 s a request: the upload is three round-trips.)
        await page.evaluate(js`(() => { window.__uploaded = false; document.querySelector('#imp-file').addEventListener('livewire-upload-finish', () => { window.__uploaded = true; }); return true; })()`);
        expect(await page.choose('#imp-file', file, 300), 'the file input was not found');
        expect(await page.until(js`window.__uploaded && !document.querySelector('form[wire\\:submit=uploadAndCheck] button[type=submit]').disabled`, 20000), 'the upload did not finish');
        expect(await page.click('form[wire\\:submit=uploadAndCheck] button[type=submit]', 0), 'there is no "Upload and check" button');

        // none of these headings is one of ours: the file's own are offered for mapping
        const offered = await page.until(js`document.querySelectorAll('select[id^=map-]').length`, 20000);
        expect(offered === 4, `the upload was not taken, or its columns were not offered for mapping (${offered} offered) — ${await page.evaluate(mainText).then((t) => t.slice(0, 200))}`);

        await page.fill('#map-client', 'name');
        await page.fill('#map-whatsapp', 'mobile');
        await page.fill('#map-place', 'city');
        await page.click('form[wire\\:submit=applyMapping] button[type=submit]', 0);

        const counts = js`Object.fromEntries([...document.querySelectorAll('dl dt')].map(dt => [dt.textContent.trim(), Number(dt.nextElementSibling.textContent.trim())]))`;
        const checked = await page.until(`(() => { const c = ${counts}; return c['Rows read'] === 4 ? c : false; })()`, 20000);
        expect(checked && checked.New === 2 && checked['No change'] === 1 && checked.Rejected === 1, `the check found ${JSON.stringify(checked)}`);

        await page.evaluate('window.confirm = () => true');
        await page.click('button[wire\\:click=confirmImport]', 0);
        expect(await page.until(`${mainText}.includes('Import finished')`, 30000), 'the import did not finish');
    },

    async 'attendance is refused from too far away and accepted at the planned stop'({ page, expect }) {
        await page.signIn('bde@formezy.test');
        await page.viewport(420, 900);
        await page.position(23.0306, 72.5714, 12);                      // 900 m north of the first stop of the demo plan
        await page.goto('/field/today');

        const text = await page.evaluate(mainText);
        if (/Marked at \d\d:\d\d/.test(text)) {
            throw new Skipped('attendance is already marked for today (seed the demo data again to check it)');
        }
        if (!(await page.evaluate(js`!!document.querySelector('button[x-on\\:click="mark()"]')`))) {
            throw new Skipped('the demo salesperson has no published plan for today (the demo data is seeded relative to the day it was seeded)');
        }

        const said = js`[...document.querySelectorAll('[role=alert],[role=status]')].filter(e => e.offsetParent !== null).map(e => e.textContent.trim().replace(/\s+/g, ' ')).join(' | ')`;

        await page.click('button[x-on\\:click="mark()"]', 0);
        const refused = await page.until(`${said}.includes('from the nearest stop') ? ${said} : false`, 15000);
        expect(typeof refused === 'string' && /9\d\d m from the nearest stop/.test(refused), `from 900 m away the page said: ${JSON.stringify(refused)}`);

        await page.position(23.0226, 72.5713, 10);
        await page.goto('/field/today');
        await page.click('button[x-on\\:click="mark()"]', 0);
        const accepted = await page.until(`${said}.includes('Attendance marked at') ? ${said} : false`, 15000);
        expect(typeof accepted === 'string', `at the stop the page said: ${JSON.stringify(await page.evaluate(said))}`);
    },

    async 'the integration screens show the webhook address, ask before replacing it, and open a delivery'({ page, expect }) {
        await page.signIn('companyadmin@formezy.test');
        await page.goto('/admin/integrations');

        // the demo's Just Dial connection is on and push-only: its address is on the page, read-only, and shaped as documented
        const address = await page.evaluate(js`document.querySelector('#url-justdial')?.value ?? ''`);
        expect(/\/api\/webhooks\/justdial\/[A-Za-z0-9]{40}$/.test(address), `the Just Dial webhook address was ${JSON.stringify(address)}`);
        expect((await page.evaluate(mainText)).includes('Unsigned (the address is the only secret)'), 'the screen did not say that pushes are unsigned');

        // replacing the address asks first, and "keep the old one" changes nothing
        await page.click('button[wire\\:click="askForNewAddress(\'justdial\')"]');
        expect(await page.until(js`!!document.querySelector('[role=alertdialog]')`), 'no confirmation was asked for before replacing the address');
        await page.click('button[wire\\:click=cancelNewAddress]');
        expect(await page.until(js`!document.querySelector('[role=alertdialog]')`), 'the confirmation did not close');
        expect(address === (await page.evaluate(js`document.querySelector('#url-justdial')?.value ?? ''`)), 'the address changed although the replacement was cancelled');

        // the log: a delivery's payload opens in a dialog, with the secret removed, and closes again
        await page.goto('/admin/integrations/leads');
        await page.click('button[wire\\:click^=inspect]', 0);
        expect(await page.until(js`!!document.querySelector('[role=dialog] pre')`), 'the delivery did not open');
        const payload = await page.evaluate(js`document.querySelector('[role=dialog] pre').innerText`);
        expect(payload.includes('"leadid"') || payload.includes('"UNIQUE_QUERY_ID"'), `the payload shown was ${payload.slice(0, 80)}`);
        await page.click('button[aria-label=Close]');
        expect(await page.until(js`!document.querySelector('[role=dialog]')`), 'the dialog did not close');
    },

    async 'a quotation is priced while it is typed, saved from a deal, and an approved one is sent from its page'({ page, expect }) {
        await page.signIn('bde@formezy.test');

        // an approved deal (Patel Engineering Works) offers a quotation: the lines arrive priced from the rate master
        await page.goto('/crm/deals?q=Patel&stage=');
        const deal = await page.evaluate(js`document.querySelector('tbody tr[wire\\:key^=deal-] a')?.getAttribute('href')`);
        if (!deal) {
            throw new Skipped('the demo deal is not there (seed the demo data again to check it)');
        }

        await page.goto(deal);
        const make = await page.evaluate(js`document.querySelector('a[href*="/sales/quotations/make/deal/"]')?.getAttribute('href')`);
        if (!make) {
            throw new Skipped('the demo deal cannot be quoted (it was changed since the demo data was made)');
        }

        await page.goto(make);
        expect(await page.until(js`${mainText}.includes('Rate from the master')`, 15000), 'the lines were not priced from the rate master when the form opened');

        // the place of supply and the address come next; the totals appear once they are there, and move as the quantity is typed
        const gujarat = await page.evaluate(js`[...document.querySelectorAll('#q-pos option')].find(o => o.textContent.includes('Gujarat'))?.value ?? ''`);
        expect(gujarat !== '', 'Gujarat is not among the places of supply');
        await page.fill('#q-pos', gujarat, 0);
        await page.fill('#q-line1', 'Plot 14, Naroda GIDC', 0);
        expect(await page.until(js`!!document.querySelector('[aria-labelledby=sec-totals]')`, 15000), 'no totals were shown once the place of supply and the address were filled in');
        const before = await page.evaluate(js`document.querySelector('[aria-labelledby=sec-totals] dl').innerText`);
        await page.fill('#r0-qty', '1234', 0);
        expect(await page.until(js`document.querySelector('[aria-labelledby=sec-totals] dl')?.innerText !== ${JSON.stringify(before)}`, 15000), 'the totals did not move when the quantity was typed');
        expect((await page.evaluate(mainText)).includes('CGST'), 'a supply within Gujarat did not show CGST');

        // changing the place of supply to another state switches the tax to IGST without a save
        const other = await page.evaluate(js`[...document.querySelectorAll('#q-pos option')].find(o => o.textContent.includes('Maharashtra'))?.value ?? ''`);
        await page.fill('#q-pos', other, 0);
        expect(await page.until(js`document.querySelector('[aria-labelledby=sec-totals] dl')?.innerText.includes('IGST') ?? false`, 15000), 'a supply to another state did not show IGST');
        await page.fill('#q-pos', gujarat, 0);
        expect(await page.until(js`!(document.querySelector('[aria-labelledby=sec-totals] dl')?.innerText.includes('IGST') ?? true)`, 15000), 'going back to Gujarat did not bring CGST and SGST back');

        await page.click('form[wire\\:submit=save] button[type=submit]', 0);
        expect(await page.until(js`/\/sales\/quotations\/\d+$/.test(location.pathname)`, 20000), 'saving did not lead to the quotation');
        const text = await page.evaluate(mainText);
        expect(text.includes('Draft') && text.includes('CGST') && text.includes('Rev 0'), 'the saved quotation did not show as a draft, Rev 0, with its tax');

        // an approved quotation (the demo's Rev 1 of the Patel quotation) is sent from its page: recorded as handed over
        await page.goto('/sales/quotations?approval=approved');
        const approved = await page.evaluate(js`[...document.querySelectorAll('tbody tr[wire\\:key^=quo-]')].find(r => r.textContent.includes('Rev 1') && r.textContent.includes('Draft'))?.querySelector('a')?.getAttribute('href')`);
        if (!approved) {
            throw new Skipped('no approved, unsent quotation in the demo data (seed it again to check sending)');
        }

        await page.goto(approved);
        await page.click('button[wire\\:click="openDialog(\'send\')"]', 0);
        expect(await page.until(js`!!document.querySelector('[role=dialog] #m-to')`), 'the send dialog did not open');
        await page.click('[role=dialog] input[value=manual]', 0);
        expect(await page.until(js`!document.querySelector('[role=dialog] #m-to')`), 'choosing "handed over" did not hide the e-mail fields');
        await page.click('form[wire\\:submit=sendQuotation] button[type=submit]', 0);
        const sent = await page.until(js`${notices}.length ? ${notices} : false`, 20000);
        expect(Array.isArray(sent) && sent.some((n) => n.includes('recorded as sent')), `after sending the page said: ${JSON.stringify(sent)}`);
        expect((await page.evaluate(mainText)).includes('Sent'), 'the quotation did not show as sent');
    },

    async 'a sales order is priced while it is typed and saved from a deal, and the approved order has its invoice'({ page, expect }) {
        await page.signIn('bde@formezy.test');

        // an approved deal with no order yet (Kalupur Dies) offers a sales order
        await page.goto('/crm/deals?q=Kalupur&stage=');
        const deal = await page.evaluate(js`document.querySelector('tbody tr[wire\\:key^=deal-] a')?.getAttribute('href')`);
        if (!deal) {
            throw new Skipped('the demo deal is not there (seed the demo data again to check it)');
        }

        await page.goto(deal);
        const make = await page.evaluate(js`document.querySelector('a[href*="/sales/orders/make/deal/"]')?.getAttribute('href')`);
        if (!make) {
            throw new Skipped('the demo deal has already been ordered (seed the demo data again to check it)');
        }

        // a deal whose customer is already linked opens the form; one for a prospect asks for the customer first, found by typing
        await page.goto(make);
        expect(await page.until(js`!!document.querySelector('#so-cust, #o-delivery')`, 15000), 'neither the customer search nor the order form was shown');
        if (await page.evaluate(js`!!document.querySelector('#so-cust')`)) {
            await page.fill('#so-cust', 'Ganesh', 0);
            expect(await page.until(js`document.querySelectorAll('#so-cust ~ ul button').length`, 15000) >= 1, 'searching the customers for "Ganesh" offered nothing');
            await page.click('#so-cust ~ ul button', 0);
        }
        expect(await page.until(js`!!document.querySelector('#o-delivery')`, 15000), 'the order form did not open once the customer was chosen');

        // the lines are priced from the rate master; the place of supply and the address complete it, and the totals move as the quantity is typed
        const gujarat = await page.evaluate(js`[...document.querySelectorAll('#o-pos option')].find(o => o.textContent.includes('Gujarat'))?.value ?? ''`);
        expect(gujarat !== '', 'Gujarat is not among the places of supply');
        await page.fill('#o-pos', gujarat, 0);
        await page.fill('#o-bill1', 'Plot 7, Naroda GIDC', 0);
        expect(await page.until(js`!!document.querySelector('[aria-labelledby=sec-totals]')`, 15000), 'no totals were shown once the place of supply and the address were filled in');
        const before = await page.evaluate(js`document.querySelector('[aria-labelledby=sec-totals] dl').innerText`);
        await page.fill('#r0-qty', '1234', 0);
        expect(await page.until(js`document.querySelector('[aria-labelledby=sec-totals] dl')?.innerText !== ${JSON.stringify(before)}`, 15000), 'the totals did not move when the quantity was typed');

        await page.fill('#o-po', 'GE/PO/BROWSER/1', 0);
        await page.click('form[wire\\:submit=save] button[type=submit]', 0);
        expect(await page.until(js`/\/sales\/orders\/\d+$/.test(location.pathname)`, 20000), 'saving did not lead to the order');
        const text = await page.evaluate(mainText);
        expect(text.includes('Credit check') && text.includes('GE/PO/BROWSER/1') && text.includes('CGST'), 'the saved order did not show its credit check, the customer’s order number and its tax');

        // the demo’s approved order has its invoice, and the invoice can be downloaded
        await page.goto('/sales/invoices');
        const invoice = await page.evaluate(js`document.querySelector('tbody tr[wire\\:key^=inv-] a')?.getAttribute('href')`);
        if (!invoice) {
            throw new Skipped('the demo has no invoice (seed the demo data again to check it)');
        }

        await page.goto(invoice);
        expect(/\/sales\/invoices\/\d+\/pdf$/.test(await page.evaluate(js`document.querySelector('a[href$="/pdf"]')?.getAttribute('href') ?? ''`)), 'the invoice has no PDF link');
        expect((await page.evaluate(mainText)).includes('Issued'), 'the invoice did not show as issued');
    },

    async 'the executive dashboard draws its charts, filters without a reload, and the header search finds a screen'({ page, expect }) {
        await page.signIn('management@formezy.test');
        page.problems();

        await page.viewport(1600, 2900);
        await page.goto('/executive?range=last_12_months', 200);
        expect(await page.until(js`document.querySelectorAll('canvas').length >= 3`, 20000), 'the charts were not drawn (fewer than three canvases)');
        expect(await page.until(js`[...document.querySelectorAll('canvas')].every(c => c.width > 0 && c.height > 0)`, 10000), 'a chart has no size');
        const text = await page.evaluate(mainText);
        expect(text.includes('Total revenue') && text.includes('Industry performance overview') && text.includes('Profitability by brand'), 'the dashboard lacks a section');
        expect(text.includes('Demo data'), 'the demo-data badge is missing');
        expect(page.problems().length === 0, `script errors: ${page.problems().join('; ')}`);

        await page.fill('#flt-range', 'this_month', 0);
        expect(await page.until(js`/This month|\d{2} \w{3} \d{4} – /.test(document.querySelector('main').innerText) && location.search.includes('range=this_month')`, 15000), 'changing the period did not update the dashboard and the address');

        await page.evaluate(js`window.scrollTo(0, 0)`);
        await page.fill('#global-search', 'brand prof', 0);
        expect(await page.until(js`!!document.querySelector('[role=listbox] a[href*="/analytics/brand-profitability"]')`, 15000), 'the header search did not offer the Brand profitability page');

        await page.until(js`new Promise(r => setTimeout(() => r(true), 1500))`, 5000);
        await page.screenshot(join(tmpdir(), 'executive-dashboard.png'));
    },

    async 'the HR screens open, the employee page switches tabs and its job panel, and the org chart shows the tree'({ page, expect }) {
        await page.signIn('hr@formezy.test');
        page.problems();

        await page.goto('/hr', 300);
        expect(await page.until(js`document.querySelectorAll('[data-testid=hr-cards] .card').length === 4`, 15000), 'the HR dashboard showed no cards');
        expect((await page.evaluate(mainText)).includes('Headcount'), 'no headcount on the HR dashboard');

        await page.goto('/hr/employees?q=bhavin', 300);
        expect(await page.until(js`!!document.querySelector('td a[href*="/hr/employees/"]')`, 15000), 'the employee list found nobody called Bhavin');
        await page.click('td a[href*="/hr/employees/"]', 800);
        expect(await page.until(js`/Direct reports/.test(document.querySelector('main').innerText)`, 15000), 'the employee page did not open');

        await page.click(String.raw`button[wire\:click="$set('tab', 'history')"]`, 800);
        expect(await page.until(js`/Joined/.test(document.querySelector('main').innerText) && /recorded by/.test(document.querySelector('main').innerText)`, 15000), 'the history tab showed nothing');

        await page.click(String.raw`button[wire\:click="openPanel('job')"]`, 800);
        expect(await page.until(js`!!document.querySelector('#j-dept')`, 15000), 'the change-job panel did not open');

        await page.goto('/hr/org-chart', 300);
        expect(await page.until(js`/Meera Management/.test(document.querySelector('main').innerText) && /Sandeep Sales Manager/.test(document.querySelector('main').innerText)`, 15000), 'the org chart lacks the tree');
        expect(page.problems().length === 0, `script errors: ${page.problems().join('; ')}`);
    },

    async 'leave shows balances and counts the days as dates are typed, and attendance punches in and out'({ page, expect }) {
        await page.signIn('bde@formezy.test');
        page.problems();

        await page.goto('/hr/leave', 300);
        expect(await page.until(js`document.querySelectorAll('[data-testid=leave-balances] .card').length >= 3`, 15000), 'the leave balances did not show');
        await page.click('button.btn-primary', 600);
        expect(await page.until(js`!!document.querySelector('#lv-type')`, 15000), 'the leave form did not open');
        const typeId = await page.evaluate(js`document.querySelector('#lv-type option:nth-child(2)').value`);
        await page.fill('#lv-type', typeId, 0);
        await page.fill('#lv-from', '2027-02-01', 0);
        await page.fill('#lv-to', '2027-02-06', 0);
        expect(await page.until(js`/working day/.test(document.querySelector('[data-testid=leave-preview]')?.innerText ?? '')`, 15000), 'the working days were not counted as the dates were typed');

        await page.goto('/hr/attendance', 300);
        expect(await page.until(js`!!document.querySelector('[data-testid=punch-card]')`, 15000), 'the punch card did not show');
        await page.click(String.raw`button[wire\:click="punchIn"]`, 900);
        expect(await page.until(js`/Working since/.test(document.querySelector('[data-testid=punch-card]').innerText)`, 15000), 'punching in did not show the person as working');
        await page.click(String.raw`button[wire\:click="punchOut"]`, 900);
        expect(await page.until(js`/Punched out/.test(document.querySelector('[data-testid=punch-card]').innerText)`, 15000), 'punching out did not show');
        expect(page.problems().length === 0, `script errors: ${page.problems().join('; ')}`);
    },

    async 'a manager sees the waiting leave request with an Approve button, the team sheet and the corrections'({ page, expect }) {
        await page.signIn('manager@formezy.test');
        page.problems();

        await page.goto('/hr/leave/requests', 300);
        expect(await page.until(js`/Bhavin BDE/.test(document.querySelector('main').innerText) && !!document.querySelector('button.btn-primary.btn-sm')`, 15000), 'the manager does not see the waiting request with an Approve button');
        await page.goto('/hr/attendance/team', 300);
        expect(await page.until(js`/Bhavin BDE/.test(document.querySelector('main').innerText) && document.querySelectorAll('tbody tr').length >= 3`, 15000), 'the team sheet showed nobody');
        await page.goto('/hr/attendance/corrections', 300);
        expect((await page.evaluate(mainText)).includes('Attendance corrections'), 'the corrections page did not open');
        expect(page.problems().length === 0, `script errors: ${page.problems().join('; ')}`);
    },

    async 'recruitment shows the openings and an application with its interview and offer, and performance and training open for HR'({ page, expect }) {
        await page.signIn('hr@formezy.test');
        page.problems();

        await page.goto('/hr/recruitment/openings', 300);
        expect(await page.until(js`/Business Development Executive/.test(document.querySelector('main').innerText)`, 15000), 'the openings list did not show the sample opening');
        await page.click('td a[href*="/hr/recruitment/openings/"]', 800);
        expect(await page.until(js`/Applicants \(5\)/.test(document.querySelector('main').innerText)`, 15000), 'the opening did not list its five applicants');

        await page.click('td a[href*="/hr/recruitment/applications/"]', 800);
        expect(await page.until(js`/Interviews/.test(document.querySelector('main').innerText) && /History/.test(document.querySelector('main').innerText)`, 15000), 'the application page did not open');
        expect((await page.evaluate(mainText)).includes('Offer') && (await page.evaluate(mainText)).includes('Recommend') === false, 'the first application should be the one with the offer');
        await page.click(String.raw`button[wire\:click="openPanel('answer')"]`, 800);
        expect(await page.until(js`!!document.querySelector('#an-what') && !!document.querySelector('#an-on')`, 15000), 'the record-the-answer panel did not open');

        await page.goto('/hr/performance/cycles', 300);
        expect(await page.until(js`/Annual review/.test(document.querySelector('main').innerText)`, 15000), 'the review cycle did not show');
        await page.goto('/hr/training/programs', 300);
        expect(await page.until(js`/Product knowledge/.test(document.querySelector('main').innerText) && /Negotiation skills/.test(document.querySelector('main').innerText)`, 15000), 'the training programs did not show');
        await page.click('td a[href*="/hr/training/programs/"]', 800);
        expect(await page.until(js`/Enrolled \(/.test(document.querySelector('main').innerText)`, 15000), 'the program page did not show its enrolments');
        expect(page.problems().length === 0, `script errors: ${page.problems().join('; ')}`);
    },

    async 'a person sees their goals and the weighted score, and their training; a manager sees the team reviews'({ page, expect }) {
        await page.signIn('bde@formezy.test');
        page.problems();

        await page.goto('/hr/performance', 300);
        expect(await page.until(js`/Win 12 new industrial accounts/.test(document.querySelector('main').innerText)`, 15000), 'the goals did not show');
        expect(await page.until(js`document.querySelector('[data-testid=goal-score]')?.innerText.trim() === '78.0%'`, 15000), 'the weighted score is not 78.0% (60 x 70 + 40 x 90 over 100)');
        await page.goto('/hr/training', 300);
        expect(await page.until(js`/Product knowledge/.test(document.querySelector('main').innerText) && /Completed/.test(document.querySelector('main').innerText)`, 15000), 'my training did not show the completed program');
        await page.goto('/hr/recruitment/interviews', 300);
        expect((await page.evaluate(mainText)).includes('My interviews'), 'my interviews did not open');
        expect(page.problems().length === 0, `script errors: ${page.problems().join('; ')}`);
    },

    async 'a manager sees the team performance and the interview to take, and can open a person'({ page, expect }) {
        await page.signIn('manager@formezy.test');
        page.problems();

        await page.goto('/hr/performance/team', 300);
        expect(await page.until(js`/Bhavin BDE/.test(document.querySelector('main').innerText)`, 15000), 'the team performance did not list Bhavin');
        await page.click('tbody tr button', 800);
        expect(await page.until(js`/Goals of /.test(document.querySelector('main').innerText)`, 15000), 'opening a person did not show their goals');
        await page.goto('/hr/recruitment/interviews', 300);
        expect(await page.until(js`/Manav Trivedi/.test(document.querySelector('main').innerText)`, 15000), 'the interview to take was not listed');
        expect(page.problems().length === 0, `script errors: ${page.problems().join('; ')}`);
    },

    async 'the dashboard keeps results apart from forecasts, every report opens, and a filtered report is exported and downloaded'({ page, expect }) {
        await page.signIn('manager@formezy.test');

        await page.goto('/dashboard');
        expect(await page.until(js`!!document.querySelector('[data-kpi=sales]')`, 15000), 'the dashboard showed no approved-sales figure');
        const text = await page.evaluate(mainText);
        expect(text.includes('Actual') && text.includes('Forecast') && text.includes('Leads by stage') && text.includes('Branch performance'), 'the dashboard did not label actual and forecast figures, or lacks a section');
        expect(text.includes('Campaign attribution'), 'the dashboard has no campaign attribution section');

        const before = await page.evaluate(js`document.querySelector('#d-period + *, #d-period')?.value ?? ''`);
        await page.fill('#d-period', 'year', 0);
        expect(await page.until(js`/This year/.test(document.querySelector('main').innerText)`, 15000), `choosing "This year" (from "${before}") did not redraw the dashboard`);

        // every report the manager may run opens without a script or HTTP error, and shows its rows or says there are none
        await page.goto('/reports');
        const links = await page.evaluate(js`[...document.querySelectorAll('a[href*="/reports/"]')].map(a => a.getAttribute('href')).filter(h => !/exports$/.test(h))`);
        expect(Array.isArray(links) && links.length >= 12, `only ${links?.length} reports were offered to the manager`);
        page.problems();

        for (const link of links) {
            await page.goto(link, 200);
            const problems = page.problems();
            const heading = await page.evaluate(js`document.querySelector('h1')?.textContent.trim() ?? ''`);

            expect(heading !== '' && problems.length === 0, `${link}: ${heading || 'no heading'} ${problems.join('; ')}`);
        }

        // a filtered report: narrowing to a stage changes the rows, then it is exported and the file downloads
        await page.goto('/reports/lead-register?from=&to=');
        expect(await page.until(js`document.querySelectorAll('tbody tr[wire\\:key^=row-]').length > 0`, 15000), 'the lead register showed no rows');
        const all = await page.evaluate(js`document.querySelectorAll('tbody tr[wire\\:key^=row-]').length`);
        await page.fill('#f-choice', 'won', 0);
        expect(await page.until(js`document.querySelectorAll('tbody tr[wire\\:key^=row-]').length !== ${all}`, 15000), 'choosing the stage "Won" did not change the rows');

        await page.fill('#f-choice', '', 0);
        expect(await page.until(js`document.querySelectorAll('tbody tr[wire\\:key^=row-]').length === ${all}`, 15000), 'clearing the stage did not bring the rows back');
        await page.click('button[wire\\:click="requestExport(\'xlsx\')"]', 0);
        expect(await page.until(js`/\/reports\/exports$/.test(location.pathname)`, 20000), 'asking for an export did not lead to “My exports”');
        expect(await page.until(js`!!document.querySelector('a[href*="/download"]')`, 30000), 'the export did not become ready with a download link');

        const download = await page.evaluate(js`(async () => { const r = await fetch(document.querySelector('a[href*="/download"]').href, { credentials: 'same-origin' });
            return { status: r.status, type: r.headers.get('content-type') ?? '', size: (await r.arrayBuffer()).byteLength }; })()`);
        expect(download.status === 200 && /spreadsheetml/.test(download.type) && download.size > 1000, `the download answered ${JSON.stringify(download)}`);
    },

    async 'a task is made, appears on the board and the calendar, is finished, and the customer page opens from a deal'({ page, expect }) {
        await page.signIn('bde@formezy.test');

        await page.goto('/tasks/create');
        const title = `Browser check task ${Date.now().toString(36)}`;
        await page.fill('#t-title', title, 0);
        await page.click('form[wire\\:submit=save] button[type=submit]', 0);
        expect(await page.until(js`/\/tasks\/\d+$/.test(location.pathname)`, 20000), 'saving the task did not lead to its page');
        expect((await page.evaluate(mainText)).includes(title), 'the task page did not show the title');

        await page.goto('/tasks?view=board');
        expect(await page.until(js`document.querySelector('main').innerText.includes(${JSON.stringify(title)})`, 15000), 'the new task is not on the board');
        expect((await page.evaluate(mainText)).includes('In progress'), 'the board has no “In progress” column');

        await page.goto('/tasks?view=calendar');
        expect(await page.until(js`document.querySelectorAll('main .grid-cols-7').length >= 2`, 15000), 'the calendar did not draw its month');

        // finish it from its page
        await page.goto('/tasks?show=mine');
        const open = await page.evaluate(js`[...document.querySelectorAll('tbody a')].find(a => a.textContent.trim() === ${JSON.stringify(title)})?.getAttribute('href') ?? ''`);
        expect(open !== '', 'the new task is not in the list');
        await page.goto(open);
        await page.click('button[wire\\:click="openDialog(\'finish\')"]', 0);
        expect(await page.until(js`!!document.querySelector('[role=dialog] #d-note')`, 15000), 'the “done” box did not open');
        await page.fill('#d-note', 'Checked in a browser', 0);
        await page.click('[role=dialog] button[type=submit]', 0);
        expect(await page.until(js`/Task done\./.test(document.querySelector('main').innerText) && /Checked in a browser/.test(document.querySelector('main').innerText)`, 20000), 'the task was not shown as done with its note');

        // Customer 360 is one click from a deal whose customer is linked
        await page.goto('/crm/deals?q=Naroda&stage=');
        const deal = await page.evaluate(js`document.querySelector('tbody tr[wire\\:key^=deal-] a')?.getAttribute('href')`);
        if (!deal) {
            throw new Skipped('the demo deal is not there (seed the demo data again to check it)');
        }

        await page.goto(deal);
        const profile = await page.evaluate(js`[...document.querySelectorAll('a')].find(a => a.textContent.trim() === 'Customer 360')?.getAttribute('href') ?? ''`);
        expect(profile !== '', 'the deal page has no link to the customer’s page');
        await page.goto(profile);
        const text = await page.evaluate(mainText);
        expect(text.includes('Credit terms') && text.includes('Contacts') && text.includes('Not built yet') && text.includes('Sales orders'), 'the customer page lacks a section');
    },

    async 'the marketing screens open, show the demo campaign, template, workflow and opt-out'({ page, expect }) {
        await page.signIn('manager@formezy.test');

        await page.goto('/marketing/campaigns');
        const campaign = await page.evaluate(js`[...document.querySelectorAll('tbody a')].find(a => a.textContent.includes('Autumn fasteners offer'))?.getAttribute('href') ?? ''`);
        if (campaign === '') {
            throw new Skipped('the demo campaign is not there (seed the demo data again to check it)');
        }

        await page.goto(campaign);
        const text = await page.evaluate(mainText);
        expect(text.includes('Autumn fasteners offer') && /Running/.test(text) && text.includes("People in it"), 'the campaign page lacks its name, state or members');

        await page.goto('/marketing/templates');
        expect((await page.evaluate(mainText)).includes('Autumn offer'), 'the templates list does not show the demo template');

        await page.goto('/marketing/workflows');
        const flow = await page.evaluate(js`[...document.querySelectorAll('tbody a')].find(a => a.textContent.includes('Call every new lead'))?.getAttribute('href') ?? ''`);
        expect(flow !== '', 'the workflows list does not show the demo workflow');
        await page.goto(flow);
        expect(await page.until(js`/Create a task|create a task/.test(document.querySelector('main').innerText)`, 15000), 'the workflow page does not describe its action');

        await page.goto('/marketing/consent');
        const consent = await page.evaluate(mainText);
        expect(consent.includes('kalupur-dies.example') || consent.includes('Opted out'), 'the consent list does not show the demo opt-out');
    },
    async 'production: an operator issues material and reports output on the demo order'({ page, expect }) {
        await page.signIn('operator@formezy.test');

        await page.goto('/production/orders');
        const order = await page.evaluate(js`[...document.querySelectorAll('tbody tr[wire\\:key^=mo-]')].find(r => /In progress/.test(r.textContent))?.querySelector('a')?.getAttribute('href') ?? ''`);
        if (order === '') {
            throw new Skipped('the demo production order is not there (seed the demo data again to check it)');
        }

        await page.goto(order);
        const text = await page.evaluate(mainText);
        expect(text.includes('Issue material') && text.includes('Report output') && text.includes('Material cost per good unit'), 'the order page lacks its forms or figures');

        // issuing: choosing a component is a live update, the quantity is sent with the form
        const component = await page.evaluate(js`[...document.querySelectorAll('#is-comp option')].find(o => o.value)?.value ?? ''`);
        await page.fill('#is-qty', '1', 0);          // typed first: choosing the component is a live update, and its answer would otherwise wipe a quantity typed meanwhile
        await page.fill('#is-comp', component, 0);
        expect(await page.until(js`!document.querySelector('form[wire\\:submit=issueMaterial] button[type=submit]').disabled`, 15000), 'the Issue button stayed disabled after choosing the component');
        await page.click('form[wire\\:submit=issueMaterial] button[type=submit]', 0);
        const issued = await page.until(`${notices}.length ? ${notices} : false`, 15000);
        const shown = await page.evaluate(js`[...document.querySelectorAll('.error,[role=alert],[role=status]')].map(e => e.textContent.trim().replace(/\s+/g, ' '))`);
        expect(Array.isArray(issued) && issued.some((n) => n.startsWith('Material issued')), `after issuing material the page said: ${JSON.stringify(issued)} / ${JSON.stringify(shown)} / component=${component}`);

        // asking for more than the order allows is refused in the form, without a notice of success
        await page.fill('#is-qty', '99999', 0);
        await page.click('form[wire\\:submit=issueMaterial] button[type=submit]', 0);
        expect(await page.until(js`!!document.querySelector('form[wire\\:submit=issueMaterial] .error')`, 15000), 'an issue far beyond the allowance was not refused in the form');

        // reporting output
        await page.fill('#out-good', '5', 0);
        await page.click('form[wire\\:submit=reportOutput] button[type=submit]', 0);
        const reported = await page.until(js`${notices}.some(n => n.startsWith('Output recorded'))`, 15000);
        expect(reported, 'after reporting output the page did not say it was recorded');

    },
    async 'production: the planner sees the demo bill with its raw materials, and the new-order form offers it'({ page, expect }) {
        await page.signIn('production@formezy.test');
        await page.goto('/production/boms');
        const bom = await page.evaluate(js`[...document.querySelectorAll('tbody a')].find(a => a.textContent.includes('BOM-BOLT-01'))?.getAttribute('href') ?? ''`);
        expect(bom !== '', 'the bills list does not show the demo bill');
        await page.goto(bom);
        expect((await page.evaluate(mainText)).includes('Down to raw materials'), 'the bill page does not show its raw materials');
        await page.goto('/production/orders/create');
        expect(await page.until(js`!!document.querySelector('#mo-bom option[value]:not([value=""])')`), 'the new-order form offers no approved bill');
    },
    async 'quality: an inspector fills in the open demo inspection and completes it'({ page, expect }) {
        await page.signIn('inspector@formezy.test');

        await page.goto('/quality/inspections');
        const open = await page.evaluate(js`[...document.querySelectorAll('tbody tr[wire\\:key^=qi-]')].find(r => /In progress/.test(r.textContent))?.querySelector('a')?.getAttribute('href') ?? ''`);
        if (open === '') {
            throw new Skipped('the demo inspection in progress is not there (seed the demo data again to check it)');
        }

        await page.goto(open);
        expect((await page.evaluate(mainText)).includes('Complete the inspection'), 'the inspection page has no way to complete it');

        const ids = await page.evaluate(js`[...document.querySelectorAll('input[id^=ir][id$=-v]')].map(e => e.id)`);
        const checkId = await page.evaluate(js`document.querySelector('select[id^=ir][id$=-c]')?.id ?? ''`);
        expect(ids.length === 2 && checkId !== '', `the inspection page offers ${JSON.stringify(ids)} and ${checkId} to fill in`);
        await page.fill('#' + ids[0], '50.0', 0);
        await page.fill('#' + ids[1], '16.8', 0);
        await page.fill('#' + checkId, 'pass', 0);
        await page.click('button[wire\\:click=completeInspection]', 0);

        const done = await page.until(js`${notices}.some(n => n.startsWith('Inspection completed'))`, 15000);
        expect(done, `after completing the inspection the page said: ${JSON.stringify(await page.evaluate(notices))}`);
        expect((await page.evaluate(mainText)).includes('Accepted'), 'the inspection does not show as accepted');
    },
    async 'quality: the manager decides the failed demo report, and the lists open'({ page, expect }) {
        await page.signIn('quality@formezy.test');

        await page.goto('/quality/non-conformances');
        const report = await page.evaluate(js`[...document.querySelectorAll('tbody tr[wire\\:key^=ncr-]')].find(r => /Open/.test(r.textContent))?.querySelector('a')?.getAttribute('href') ?? ''`);
        if (report === '') {
            throw new Skipped('the demo non-conformance report is not there (seed the demo data again to check it)');
        }

        await page.goto(report);
        await page.fill('#nd-disp', 'rework', 0);
        await page.fill('#nd-note', 'Replace the crushed cartons and re-pack', 0);
        await page.click('form[wire\\:submit=decide] button[type=submit]', 0);
        const decided = await page.until(js`${notices}.some(n => n.startsWith('The decision is recorded'))`, 15000);
        expect(decided, `after recording the decision the page said: ${JSON.stringify(await page.evaluate(notices))}`);

        await page.goto('/quality/plans');
        expect((await page.evaluate(mainText)).includes('QP-BOLT-FINAL'), 'the plans list does not show the demo plan');
    },
    async 'maintenance: a technician ticks the tasks, logs hours and completes the breakdown assigned to them'({ page, expect }) {
        await page.signIn('technician@formezy.test');

        await page.goto('/maintenance/orders');
        const order = await page.evaluate(js`[...document.querySelectorAll('tbody tr[wire\\:key^=wo-]')].find(r => /Press not building pressure/.test(r.textContent))?.querySelector('a')?.getAttribute('href') ?? ''`);
        if (order === '') {
            throw new Skipped('the demo breakdown is not there (seed the demo data again to check it)');
        }

        await page.goto(order);
        const tasks = await page.evaluate(js`document.querySelectorAll('button[wire\\:click^=tickTask]').length`);
        expect(tasks === 2, `the work order shows ${tasks} tasks to tick, not 2`);

        for (let left = tasks; left > 0; left--) {
            await page.click('button[wire\\:click^=tickTask]', 0);
            expect(await page.until(js`document.querySelectorAll('button[wire\\:click^=tickTask]').length === ${left - 1}`, 15000), 'a task was not ticked');
        }

        await page.fill('#lh-hours', '2', 0);
        expect(await page.until(js`!document.querySelector('form[wire\\:submit=logWork] button[type=submit]').disabled`, 15000), 'the Log button stayed disabled');
        await page.click('form[wire\\:submit=logWork] button[type=submit]', 0);
        expect(await page.until(js`${notices}.some(n => n.startsWith('Hours logged'))`, 15000), 'after logging hours the page did not say so');

        await page.click('button[wire\\:click="openDialog(\'complete\')"]', 0);
        expect(await page.until(js`!!document.querySelector('[role=dialog] #d-note')`), 'the completion dialog did not open');
        await page.fill('#d-note', 'Relief valve cleaned and reset; pressure back to normal', 0);
        await page.click('form[wire\\:submit=finishDialog] button[type=submit]', 0);
        const done = await page.until(js`${notices}.some(n => n.startsWith('The work order is completed'))`, 15000);
        expect(done, `after completing the work order the page said: ${JSON.stringify(await page.evaluate(notices))}`);
        expect((await page.evaluate(mainText)).includes('Completed'), 'the work order does not show as completed');
    },
    async 'maintenance: the manager sees the asset with its figures, the schedule, and the report form'({ page, expect }) {
        await page.signIn('maintenance@formezy.test');

        await page.goto('/maintenance/assets');
        const asset = await page.evaluate(js`[...document.querySelectorAll('tbody tr[wire\\:key^=as-]')].find(r => /PRESS-01/.test(r.textContent))?.querySelector('a')?.getAttribute('href') ?? ''`);
        if (asset === '') {
            throw new Skipped('the demo asset is not there (seed the demo data again to check it)');
        }

        await page.goto(asset);
        const text = await page.evaluate(mainText);
        expect(text.includes('Mean time to repair') && text.includes('PM-PRESS-01') && text.includes('Report a breakdown'), 'the asset page lacks its figures, schedule or report button');

        await page.goto('/maintenance/schedules');
        expect((await page.evaluate(mainText)).includes('Grease and inspect the hydraulics'), 'the schedules list does not show the demo schedule');

        await page.goto('/maintenance/orders/create');
        expect(await page.until(js`!!document.querySelector('#wf-asset option[value]:not([value=""])')`), 'the report form offers no asset');
    },
    async 'loss: the radar lists findings with their evidence, and the savings ledger opens for the manager and for Finance'({ page, expect }) {
        await page.signIn('quality@formezy.test');

        await page.goto('/losses/radar');
        const radar = await page.evaluate(mainText);
        expect(radar.includes('Leakage radar') && radar.includes('estimates and proposals'), 'the radar page did not open');

        await page.goto('/losses/savings');
        const savings = await page.evaluate(mainText);
        expect(savings.includes('Verified savings') && savings.includes('Waiting for Finance'), 'the savings page lacks its heading or tabs');

        await page.signIn('finance@formezy.test');
        await page.goto('/losses/savings?show=awaiting');
        expect((await page.evaluate(mainText)).includes('To measure'), 'Finance cannot open the savings ledger');
    },
    async 'an inquiry finds its customer and item, converts to a deal, and the deal moves and is lost from its page'({ page, expect }) {
        await page.signIn('bde@formezy.test');

        // the inquiry form: the customer and the item are found by typing, and the lines are added up as they are typed
        await page.goto('/crm/leads/create-inquiry');
        await page.fill('#c-search', 'Ganesh', 0);
        expect(await page.until(js`document.querySelectorAll('#c-search ~ ul button').length`) >= 1, 'searching the customers for "Ganesh" offered nothing (seed the demo data)');
        await page.click('#c-search ~ ul button', 0);
        expect(await page.until(js`!!document.querySelector('button[wire\\:click=clearCustomer]')`), 'the customer was not chosen');

        await page.fill('#r0-search', 'bolt', 0);
        expect(await page.until(js`document.querySelectorAll('#r0-search ~ ul button').length`) >= 1, 'searching the items for "bolt" offered nothing');
        await page.click('#r0-search ~ ul button', 0);
        await page.fill('#r0-qty', '10', 0);
        await page.fill('#r0-rate', '125.50', 0);
        expect(await page.until(`${mainText}.includes('1,255.00')`), 'the lines were not added up while typing');

        // converting the demo's approved, qualified inquiry (Shree Ganesh Engineering): every one of its lines comes across, priced
        await page.goto('/crm/leads?q=Shree+Ganesh+Engineering&stage=qualified');
        const lead = await page.evaluate(js`document.querySelector('tbody tr[wire\\:key^=lead-] a')?.getAttribute('href')`);
        if (!lead) {
            throw new Skipped('the demo inquiry is not there (seed the demo data again to check it)');
        }

        await page.goto(lead);
        const convert = await page.evaluate(js`document.querySelector('a[href*="/crm/deals/convert/"]')?.getAttribute('href')`);
        if (!convert) {
            throw new Skipped('the demo inquiry has already been converted (seed the demo data again to check it)');
        }

        await page.goto(convert);
        const rates = await page.evaluate(js`[...document.querySelectorAll('input[id$=-rate]')].map(i => i.value)`);
        expect(rates.length === 2 && rates.every((r) => r !== ''), `the conversion form carried across ${JSON.stringify(rates)} for the inquiry's two priced lines`);
        expect((await page.evaluate(mainText)).includes('From lead'), 'the lines of the lead are not marked as coming from it');
        await page.click('form[wire\\:submit=save] button[type=submit]', 0);

        expect(await page.until(js`/\/crm\/deals\/\d+$/.test(location.pathname)`, 15000), 'converting did not lead to the deal');
        expect((await page.evaluate(js`document.querySelector('h1').textContent`)).includes('Shree Ganesh Engineering'), 'the deal is not for the inquiry’s customer');

        // moving it between stages loads the page again with one notice (the page already says it was made: wait for the new one)
        expect(await page.click('button[wire\\:click^=moveToStage]', 0), 'the deal has no stage button to press');
        const moved = await page.until(js`${notices}.some(n => n.startsWith('The deal is now in'))`, 15000);
        await page.until('false', 600);
        const settled = await page.evaluate(notices);
        expect(moved && settled.length === 1, `after moving the deal the page said: ${JSON.stringify(settled)}`);

        // losing it asks for a reason in a dialog, and says so when it is done
        await page.click('button[wire\\:click="openDialog(\'lost\')"]', 0);
        expect(await page.until(js`!!document.querySelector('[role=dialog] #x-reason')`), 'the lost dialog did not open');
        await page.click('form[wire\\:submit=markLost] button[type=submit]', 0);
        expect(await page.until(js`!!document.querySelector('[role=dialog] .error')`), 'a lost deal was accepted without a reason');
        await page.fill('#x-reason', await page.evaluate(js`[...document.querySelectorAll('#x-reason option')].find(o => o.value)?.value ?? ''`), 0);
        await page.click('form[wire\\:submit=markLost] button[type=submit]', 0);

        const lost = await page.until(`${notices}.length ? ${notices} : false`, 15000);
        expect(Array.isArray(lost) && lost.some((n) => n.startsWith('The deal is marked as lost')), `after losing the deal the page said: ${JSON.stringify(lost)}`);
    },
};

// ---- runner -----------------------------------------------------------------------------------------------------

const browser = await Browser.launch();
let failed = 0;

try {
    for (const [name, check] of Object.entries(checks)) {
        if (only && !name.toLowerCase().includes(only)) {
            continue;
        }

        const failures = [];
        const expect = (condition, what) => { if (!condition) { failures.push(what); } };
        const page = await browser.page(origin);
        const started = Date.now();
        let note = '';

        try {
            note = (await check({ page, expect })) ?? '';
            failures.push(...page.problems().map((problem) => `in the browser: ${problem}`));
        } catch (error) {
            if (error instanceof Skipped) {
                console.log(`  SKIP  ${name}\n        ${error.message}`);
                continue;
            }
            failures.push(`the check itself failed: ${error.message}`);
        }

        const seconds = ((Date.now() - started) / 1000).toFixed(1);
        if (failures.length === 0) {
            console.log(`  PASS  ${name}${note ? ` (${note})` : ''}  ${seconds}s`);
        } else {
            failed++;
            console.log(`  FAIL  ${name}  ${seconds}s\n        - ${failures.join('\n        - ')}`);
        }
    }
} finally {
    await browser.close();
}

console.log(failed === 0 ? '\nBrowser checks passed.' : `\n${failed} browser check(s) failed.`);
process.exit(failed === 0 ? 0 : 1);
