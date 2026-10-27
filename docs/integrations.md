# Where leads come from outside — IndiaMART, Just Dial and the website form

Screens: **Administration → Integrations** (status and set-up) and **Integrations → Inbound lead log** (what each provider delivered).
Tables: [schema.md](schema.md). The API a phone uses is separate: [api.md](api.md).

Nothing here can be tested against a live account until you have one. IndiaMART and Just Dial are built and tested against the shapes
the providers **document** (IndiaMART) or are **known to send** (Just Dial); **live verification is pending** — see the end. The
**website form** (Phase 16) is different: its format is ours, so it is documented below rather than guessed, and its cross-origin behaviour
is checked in a real browser (`npm run test:browser`).

## How a lead gets in

```
IndiaMART  ── we pull every 15 min ──┐
IndiaMART  ── it pushes to our URL ──┼─►  Inbound lead log  ─►  intake  ─►  a new lead   (Created)
Just Dial  ── it pushes to our URL ──┘     (stored first,         │         or a note on the open lead
                                            nothing is lost)       │         that has the same mobile (Linked)
                                                                   └─►  Failed — kept, with its reason, for a retry
```

1. **Stored first.** A delivery is written to the log — with anything secret removed — before anything else happens to it. It is
   unique per company, provider and the provider's own lead id, so the same enquiry arriving twice (a retry, the pull's overlap, a
   push that was also pulled) is **one** row; the log only counts how often it came.
2. **Same mobile number as an open lead?** Then no second lead is made: a note is left on that lead (“Another IndiaMART enquiry from
   this number…”), its owner is told, and the delivery is **Linked**. Two enquiries from one buyer arriving at the same moment make
   one lead (the intake takes a lock on the number — proven by a test that races separate processes).
3. **Otherwise a lead is created** through `LeadService` — the same door as every other channel, so every lead rule holds — in the
   branch you chose, under the provider's standard source (*IndiaMART* / *Just Dial*), owned by the person you chose or **left in the
   pool**, and entered *as* the person who set the connection up. The managers who hand leads out get an in-app notice for a pool lead.
4. **Trouble goes to the log, not to the provider.** A push is answered `202` as soon as it is stored; if the lead cannot be made the
   delivery stays **Failed** with a reason a person can act on, and the provider is not made to retry something only you can fix.

### What is kept, left off, and marked incomplete

| The provider sends | What the lead gets |
|---|---|
| A name and/or a company | Name = the company, else the person; the person is the contact |
| **No name** (IndiaMART's placeholder “IndiaMART Buyer”) | Name “IndiaMART enquiry <mobile>”, marked **incomplete** |
| **No mobile number** | Kept, marked **incomplete** (it cannot be qualified until someone finds the number); the remarks say so |
| A number that is not an Indian mobile (a foreign number, a landline) | The number is **left off** and written into the remarks as given (with its country); marked incomplete |
| An invalid e-mail address / PIN code | Left off, and written into the remarks as given |
| A valid PIN code | Fills state, district and block from the geography master, as everywhere |
| A product, a subject, a message | The message is the requirement; the product becomes the lead's item of interest (in words) |
| A time in the future, or unreadable | The lead is dated today — never in the future |
| Do-not-call flag (Just Dial `dncmobile` / `dncphone`) | Written in the remarks: *do not phone this number* — a flag for whoever works it, not a consent record |
| Very long text | Cut to what a lead can hold |

An **incomplete** lead shows an *Incomplete* badge in the Leads list and on its page.

## IndiaMART

**What you need.** A **paid seller** account with the Leads API add-on (the Pull API is not available without an active base
subscription); a key from *seller.indiamart.com → Lead Manager → Import/Export Leads → Pull API → Generate Key*. IndiaMART does not
help with the integration: it is yours to run. A key **expires** if unused for 7 days or when a newer one is generated.

**Pull (the default).** We call `GET https://mapi.indiamart.com/wservce/crm/crmListing/v2/` with the key and an IST time window.

| IndiaMART's rule | What we do |
|---|---|
| At least **5 minutes** between calls with one key; more than 5 a minute blocks the key for 15 minutes | One call per connection per pull interval (default 15 min, minimum 5), claimed with one conditional UPDATE so two workers cannot both go. A 429 backs off 16 minutes |
| At most **7 days** per call, **365 days** back | Longer gaps are caught up one 7-day window per call; never asks further back than 360 days |
| Leads can be published late | Each pull starts **10 minutes before** the last one ended; the overlap's duplicates cost nothing (unique log) |
| Errors by `CODE` — 204 none, 400 bad range, 401 wrong/expired key, 429 too often, 500 theirs | 204 is success. 401 waits an hour and shows *“IndiaMART did not accept the key…”*; 400 is a bug to look at; 500 and network failures back off 15 min doubling to 4 hours |
| The key is in the address of the call | It is never written anywhere: not into an error message, a log line, the audit trail or the stored payload |

The first pull looks back as far as the connection says (default 24 hours, at most 7 days; IndiaMART only serves data from 24 hours
after a key was generated). The **watermark** moves only after every record of the period has been written down, so a failure asks for
the same period again.

**Push.** IndiaMART can also be told an address to push leads to; the connection's *Webhook address* is that address. Use *Both* to pull
as well and let the log's uniqueness make the two agree.

Fields read (`UNIQUE_QUERY_ID` is the id we de-duplicate on): `QUERY_TYPE` (W direct, B buy-lead, P phone, BIZ catalogue view, WA
WhatsApp), `QUERY_TIME`, `SENDER_NAME`, `SENDER_MOBILE`, `SENDER_EMAIL`, `SENDER_COUNTRY_ISO`, `QUERY_MESSAGE` — documented — and
`SENDER_COMPANY`, `SENDER_ADDRESS`, `SENDER_CITY`, `SENDER_STATE`, `SENDER_PINCODE`, `SUBJECT`, `QUERY_PRODUCT_NAME`,
`QUERY_MCAT_NAME` — from the commonly published layout, **to be confirmed on a real account**. All but the id are optional.

Source: [IndiaMART's Lead Manager Pull API guide](https://help.indiamart.com/knowledge-base/lms-crm-integration-v2).

## Just Dial

**Push only.** Just Dial publishes no self-service developer API. Your **Just Dial account manager** switches on the push of your leads
to an address you give them (the other CRMs' Just Dial integrations all work this way), or gives you credentials for a pull API. Send
them the connection's *Webhook address*. Nothing is pulled from Just Dial.

The record layout is the one such integrations commonly receive and is read tolerantly (only `leadid` is needed): `leadid`,
`leadtype`, `prefix`, `name`, `mobile`, `phone`, `email`, `date`, `time`, `category`, `area`, `city`, `brancharea`, `pincode`,
`company`, `parentid`, `dncmobile`, `dncphone`. **Ask your account manager for a sample payload** and compare; if a name differs only
`JustDialProvider` changes.

## The website form

The company's own enquiry form (on its site, a landing page, a campaign page) posts straight to the CRM and becomes a lead through the **same
log, de-duplication and intake** as the marketplaces. It is the one provider whose sender is a **visitor's browser**, so it adds what a
browser needs and what a stranger must not be able to abuse.

**Set-up.** *Administration → Integrations → Website form → Set up* (branch, owner or pool, on); then **Company settings → Integrations →
Websites allowed to send leads from a visitor's browser** — your own site's address, e.g. `https://www.example.com` (several: separate with
commas or spaces). The card shows the **form address** and a **form to paste** into the page (change the fields as you like).

**What the browser may send** (`POST` to the form address; one flat JSON object, or ordinary form fields). Only a way to reach the person is
required — `mobile` (or `phone`, `whatsapp`) **or** `email`; anything else is optional.

| Field | Becomes |
|---|---|
| `name` (or `first_name` + `last_name`), `company` | the lead's name (company over person, as for Just Dial); neither = “Website form enquiry <mobile>”, **incomplete** |
| `mobile` / `phone`, `email`, `city`, `state`, `pincode`, `address` | the lead's contact details (invalid ones are left off and written into the remarks, as for the marketplaces) |
| `product`, `message` | the item of interest and the requirement |
| `form` | what the form is called (“Contact form”) — shown in the remarks |
| `page_url` | the page it was sent from, **without its query string or fragment** (a link may carry a token or a person's details); only `http`/`https` |
| `utm_source` `utm_medium` `utm_campaign` `utm_term` `utm_content` | kept on the lead as plain text (≤ 100 characters each, nothing but letters, digits, space, `_` `.` `-`) |
| `campaign` | a campaign code (`CP/…`): the lead is credited to it as **link** attribution; an unknown code is ignored (the UTM values are still kept) |
| `submission_id` | the page's own id for this submission (`A–Z a–z 0–9 _ -`, 8–64 characters), so a double click is one lead. Without one, a submission is identified by its **content and the day**: the same person sending the same words twice in a day is one lead |
| `consent`, `consent_text`, `consent_channels` | see *What the visitor agreed to* below |
| `_gotcha` | a hidden trap field (see below): it must be **empty** |
| `thanks_url` | for a plain HTML form (no script): the page to send the visitor to afterwards — only on a listed site, never elsewhere |

**What protects it.** The form address sits in the page's source, so it is **not a secret** — say so plainly. What stands between it and abuse:

| Layer | What it does | What it does not do |
|---|---|---|
| **Allowed websites** (`WebsiteFormCors`) | A browser says which page it is on (`Origin`). Only sites you list may post; any other gets **403 `origin_not_allowed`**, and its browser will not hand the answer to the page. The answer to a listed site carries `Access-Control-Allow-Origin` — also a refusal and a throttle, so the page's script can say why | It cannot stop a **script or a server**, which sends no `Origin`: such a request is judged by the layers below. Nothing listed = no browser at all. Origins compare exactly (scheme + host + port; no wildcards; `http` ≠ `https`) |
| **Trap field** `_gotcha` | Hidden from people, filled in by robots. A filled trap is answered **202 as if it worked** and nothing is written down, so the robot learns nothing to adapt to | A robot that skips the field, or a person who types something into it |
| **Throttle** | **10 posts a minute from one visitor** (address + form), 120 a minute to the whole form, 600 from one address; over it: 429 with `Retry-After` | A slow, patient flood |
| **Size and shape** | 64 KB, one submission, JSON or form fields; a body with no way to reach the person is 400 | — |
| **Signing** | A **relay or server** that posts the form can sign it (same headers as the marketplaces; secret: *Company settings → Website form signing secret*). **A browser cannot sign**, so while a secret is set browser posts are refused (401) — leave it empty for a form on a page | — |

No CAPTCHA is built in (the provider — reCAPTCHA, hCaptcha, Turnstile — is a choice for the owner; it would be one more layer in front of the same address).

**What the visitor agreed to.** A lead from a form is **not** consent to market to the person, just like a marketplace lead. A tick box on the form can
be: send `consent` (`on`/`true`/`yes`/`1`), the **exact wording beside the box** in `consent_text`, and the channels it covered in `consent_channels`
(`email`, `whatsapp`, `sms`; an array or a comma-separated list). Then, **and only then**, an **opt-in** is recorded for each named channel
(`contact_consents`, source `website`, the submission id and the wording in the note) and the lead's remarks say what was agreed. Without the wording or
without a channel nothing is recorded. Three more rules:

* **An opt-out is never undone by a form.** Nobody has proved the number or address is the visitor's, so if the address has opted out the tick is **not**
  recorded and the lead's remarks tell whoever works it to ask the person first.
* A ticked channel with no usable address (an invalid e-mail, no mobile) records nothing and says so on the lead.
* The consent is recorded **after** the lead is made, and a failure to record it never undoes the lead.

Because there is no OTP, a form can still be used to opt **somebody else's** number in (the history shows where it came from and what it said). Treat the
website opt-in as *claimed*, not *verified*; double opt-in (a confirmation message the person answers) is the strong answer and is not built.

**A second enquiry from a number already on an open lead** becomes a note on that lead (as for the marketplaces), now also carrying what was agreed.

**Plain HTML form, no script.** A form whose `action` is the address and which posts ordinary fields works too: add a hidden `thanks_url` on your site and
the visitor is sent there (`303`) after the lead is accepted. Without it, or with a page that is not on a listed site, the browser shows the JSON answer.

## Setting a connection up

1. **Company settings → Integrations**: save the IndiaMART **API key** (pull) and/or a **signing secret** (see below). Secrets are
   encrypted and can never be read back, only replaced.
2. **Administration → Integrations → Set up**: choose how leads arrive, the **branch** new leads go to, and who they are **given to**
   (or leave them in the pool). Leads are entered *as the person who saves this*, so that person must be able to create and hand out leads.
3. Switch it on. IndiaMART: **Test connection** calls the provider once for the last hour's leads and shows what came back, saving nothing.
4. Push providers: copy the **Webhook address** to the provider.

The address contains a 40-character random key — anyone holding it can send leads — so it is shown only to people who may set the
connection up, is never written to a log or the audit trail, and **New address…** replaces it at once if it leaks.

**Status** shown on the screen: *Disabled*; *Needs setup* (on, but no branch or person); *Configured* (ready, nothing received yet);
*Connected* (heard from recently — a pull connection must have succeeded within three intervals); *Degraded* (failures in a row, with
the last error and when it will try again).

## The webhook contract

`POST /api/webhooks/{provider}/{key}` — `provider` is `indiamart`, `justdial` or `website`; `key` is the connection's 40-character key.
(The website form also answers the browser's `OPTIONS` preflight on the same address; every other provider answers it 404.)

* **Body:** JSON — one lead, a list, or the provider's wrapper (`{"RESPONSE": …}`, `{"leads": […]}`) — or, for Just Dial, form fields.
  At most 64 KB and 200 leads.
* **Answers:** `202 {"data": {"status": "accepted", "received": n, "new": n, "already_known": n}}` as soon as it is stored.

| Status | Code | When |
|---|---|---|
| 202 | — | Accepted (also for a signed request that has already been seen: `replayed: true`) |
| 400 | `bad_request` | Not JSON, or no lead in it |
| 401 | `invalid_signature` | A signing secret is set and the signature does not match the exact body |
| 401 | `stale_timestamp` | A signing secret is set and the timestamp is missing or more than 5 minutes off |
| 403 | `origin_not_allowed` | **Website form only:** a browser on a site the company has not listed (also for its preflight). Only a switched-on form with a right key says this; a wrong key is 404 |
| 404 | `not_found` | Unknown provider, wrong key, switched off, or not open to pushes — the **same** answer for all, so nothing is confirmed to a guesser |
| 413 | `payload_too_large` | Over 64 KB or 200 leads (the website form takes one) |
| 429 | `too_many_requests` | More than 120 a minute to one address, or 600 a minute from one IP address; the website form also 10 a minute from one visitor |

**Signing (optional but recommended).** The providers cannot sign, so by default **the address is the only secret** (the screen says
*Unsigned*). To harden it, put a small relay you control in front (or use a gateway that can add headers), save a **signing secret**
under Company settings, and have the relay send:

```
X-FormEzy-Timestamp: <unix seconds>
X-FormEzy-Signature: sha256=<hex HMAC-SHA256 of "<timestamp>.<raw body>", keyed with the secret>
```
```php
$timestamp = time();
$signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);   // rawBody: the exact bytes you POST
```
A request more than five minutes old, or whose body was changed by one byte, is refused; an identical signed request seen twice is
accepted once.

## When something fails

Open **Inbound lead log**: every delivery with its status, the lead it made, how many times it was sent and, for a failure, why in plain
words (for example *“The standard IndiaMART lead source is missing or switched off. Switch it on under CRM → Lead masters.”*). **View**
shows the payload as stored. Put right what the message says, then **Retry**. A delivery stored but never processed (the queue was
down) is picked up by `integrations:retry-stuck` within ten minutes; one **failed** waits for you.

| Failure on the connection | What it means | What happens next |
|---|---|---|
| `auth` | Key refused or expired, or none saved | Tried again in an hour; fix the key in Company settings |
| `rate_limited` | Asked too often | Waits the time the provider asked for (16 min) |
| `invalid` | The provider refused the period | A bug to look at; tried again in an hour |
| `unavailable` | Provider or network down | 15 min, then 30, 60, 120, 240 |

## What it needs to run

* **A queue worker** for production (`QUEUE_CONNECTION=database`): each delivery is processed by a queued job (five tries with growing
  waits; a problem only a person can fix is not retried). `QUEUE_CONNECTION=sync` processes inline, which is fine for local use.
* **The scheduler** (`* * * * * php artisan schedule:run`): pulls every five minutes (each connection's own interval decides), retries
  stragglers every ten, prunes the log daily.
* **A cache shared by every process** (`database` or `redis`, not `array`): the lock that keeps one buyer to one lead, and the
  replay check on signed requests, live in the cache. One web server with `file` also works.
* Commands: `integrations:pull [--connection=ID]`, `integrations:retry-stuck`, `integrations:prune`.

## Privacy and retention

A stored delivery holds the buyer's phone number and e-mail address, so the log is kept for **90 days** (Company settings →
*Keep the inbound-lead log for this many days*, at least 7); failed deliveries twice as long. The leads made from them are not touched.
No secret is ever stored: payload fields named like a key, token, secret, password or signature are replaced, and the company's own
key and secrets are scrubbed from text wherever they appear. Receiving a lead from a marketplace is not consent to market to the buyer
by WhatsApp, SMS or e-mail; the do-not-call flag is kept in the remarks, and consent handling arrives with communications (Phase 15–16).
Whether keeping marketplace buyers' data is compliant for your business is for the owner to review.

## Adding another provider

Implement `LeadProvider` (its key, source code, channel, what it can do, `fetch`, `extractRecords`, `normalise` — it never touches the
database), register it in `IntegrationsServiceProvider`, and add its standard source to `SeedCrmDefaults`. The log, the intake, the
pull, the webhook, the screens and the retries are shared. A provider that a visitor's browser posts to also implements `BrowserForm`
(its robot test and the form it hands out); the origin rules, the preflight and the per-visitor throttle are then the website form's
and are applied by provider key — telephony, when it comes, is a different kind of provider (a call, not a form).

Two framework facts the website form taught: Laravel sorts the `throttle` middleware **ahead of any middleware it does not know**, so a
middleware that must see a throttled answer has to be put before it in the priority list (`bootstrap/app.php`,
`prependToPriorityList`); and the CORS settings in `config/cors.php` cover the mobile API only (`api/v1/*`) — the website form answers
its own browsers, site by site.

## Known limits and what is pending

* **Pending live verification**: IndiaMART against a real key (field names beyond the documented ones; the push payload layout) and
  Just Dial's actual payload and delivery behaviour. The contract tests pin what we believe; reality may differ in small ways.
* IndiaMART's push cannot be signed by IndiaMART; Just Dial's cannot either. Signing needs a relay you run.
* **Website form:** no CAPTCHA, no double opt-in, and the form address is public by nature (see *What protects it*). Not tried on a live website yet — the
  browser check proves the cross-origin behaviour between two origins on one machine, and the tests prove the rest; a first real page is the next proof.
  Several websites per company share one form address (list them all); one form per site or per campaign is not built.
* A buyer's second enquiry from a number that is only on a **closed** lead makes a **new** lead (a lost enquiry coming back is a new chance).
* Leads from a marketplace are given to one person or left in the pool; there is no round-robin or rule-based routing yet.
* There is no export of the log, and no per-lead “re-pull”: use *Retry* for a failed delivery.
