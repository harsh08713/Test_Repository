# Lead marketplaces — IndiaMART and Just Dial

Screens: **Administration → Integrations** (status and set-up) and **Integrations → Inbound lead log** (what each provider delivered).
Tables: [schema.md](schema.md). The API a phone uses is separate: [api.md](api.md).

Nothing here can be tested against a live account until you have one. Everything below is built and tested against the shapes
the providers **document** (IndiaMART) or are **known to send** (Just Dial). **Live verification is pending** — see the end.

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

`POST /api/webhooks/{provider}/{key}` — `provider` is `indiamart` or `justdial`; `key` is the connection's 40-character key.

* **Body:** JSON — one lead, a list, or the provider's wrapper (`{"RESPONSE": …}`, `{"leads": […]}`) — or, for Just Dial, form fields.
  At most 64 KB and 200 leads.
* **Answers:** `202 {"data": {"status": "accepted", "received": n, "new": n, "already_known": n}}` as soon as it is stored.

| Status | Code | When |
|---|---|---|
| 202 | — | Accepted (also for a signed request that has already been seen: `replayed: true`) |
| 400 | `bad_request` | Not JSON, or no lead in it |
| 401 | `invalid_signature` | A signing secret is set and the signature does not match the exact body |
| 401 | `stale_timestamp` | A signing secret is set and the timestamp is missing or more than 5 minutes off |
| 404 | `not_found` | Unknown provider, wrong key, switched off, or not open to pushes — the **same** answer for all, so nothing is confirmed to a guesser |
| 413 | `payload_too_large` | Over 64 KB or 200 leads |
| 429 | `too_many_requests` | More than 120 a minute to one address, or 600 a minute from one IP address |

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
pull, the webhook, the screens and the retries are shared.

## Known limits and what is pending

* **Pending live verification**: IndiaMART against a real key (field names beyond the documented ones; the push payload layout) and
  Just Dial's actual payload and delivery behaviour. The contract tests pin what we believe; reality may differ in small ways.
* IndiaMART's push cannot be signed by IndiaMART; Just Dial's cannot either. Signing needs a relay you run.
* A buyer's second enquiry from a number that is only on a **closed** lead makes a **new** lead (a lost enquiry coming back is a new chance).
* Leads from a marketplace are given to one person or left in the pool; there is no round-robin or rule-based routing yet.
* There is no export of the log, and no per-lead “re-pull”: use *Retry* for a failed delivery.
