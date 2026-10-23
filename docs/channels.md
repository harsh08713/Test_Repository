# WhatsApp and SMS channels (Phase 16, first slice)

**Built against the providers' documented shapes and tested with faked HTTP. Not verified on a live account** — that needs a Meta WhatsApp Business account and a Twilio account (or a
substitute provider), which the owner arranges. Say so in every report until a real message has been sent.

`app/Modules/Marketing/Channels`. Campaigns, workflows and people still make a message in one place, `CommunicationService::queue()`; this adds the missing hand-over for two channels.

## How a message goes

1. **Connection is per company** (`ChannelRegistry`). Each of WhatsApp and SMS is `off` (the default: a message is kept as *not connected*, never "sent"), `sandbox` (rehearsal: marked as handed over with a
   `sandbox-…` reference, nothing leaves the system) or `live` (needs the credentials below). A live channel that lacks a credential behaves as off and the Channels page lists what is missing.
2. **Consent first, as before.** WhatsApp and SMS marketing always need a recorded opt-in; an opt-out always wins. Checked when the message is made and again just before it is handed over.
3. **`CommunicationService::deliver()`** hands the message to the provider adapter (`ChannelProvider`) and stores the provider's own reference in `communications.provider_ref`. `sent` still means
   *the provider accepted it* — the label says "Handed to WhatsApp (not yet reported delivered)".
4. **Delivery reports** come back to `POST /api/webhooks/channels/{company}/{channel}/{token}` and are applied by `ChannelDelivery`: `delivered_at` / `read_at` are each claimed by a conditional
   update (a repeated report changes nothing), nothing moves backwards, and a *failed* report only counts for a message not yet delivered or read and takes it out of the automatic retry.
5. **A reply of STOP** (STOP, STOPALL, UNSUBSCRIBE, CANCEL, END, QUIT — the first word) is recorded as an opt-out (`contact_consents`, source `reply`).

## Providers

| Channel | Provider | Send | Reports back | Checked by |
|---|---|---|---|---|
| WhatsApp | Meta WhatsApp Business Cloud API (`v21.0`) | `POST /{phone-number-id}/messages`, bearer token, a text message | `statuses` (sent, delivered, read, failed) and `messages` (replies) as JSON; the one-off `GET` check echoes `hub.challenge` for the verify token | `X-Hub-Signature-256` = HMAC-SHA256 of the body with the **app secret** |
| SMS | Twilio Programmable Messaging | `POST /2010-04-01/Accounts/{sid}/Messages.json`, basic auth, form fields | status callback (`MessageSid`, `MessageStatus`: delivered, undelivered, failed…) and inbound (`From`, `Body`) as form fields | `X-Twilio-Signature` = base64 HMAC-SHA1 of the full URL plus the sorted fields with the **auth token** |

Numbers are stored as ten digits; the company's country code (`channels.country_code`, 91 by default) is put in front when sending.

## Setting it up

* **Company settings → Channels** holds the credentials (secrets are encrypted and never shown again): WhatsApp phone number ID, access token, app secret, webhook verify token; SMS account SID, auth token, sender.
* **Marketing → Channels** (`marketing.channels`) shows each channel's state and what is missing, makes the secret webhook address (replacing it switches the old one off at once; the change is audited without
  the token), and shows the address to give each provider. Only people who may change settings see the address.
* **The webhook** answers **404** identically for a wrong token, an unknown channel, a company without that token, or a channel that is off. In **live** mode the provider's signature is required (**401** otherwise);
  in **sandbox** an unsigned call is accepted so a rehearsal can be driven by hand. It answers 200 straight away whatever was inside, so a provider never retries for days. Behind a proxy, the application must
  see the public URL (trusted proxies), or Twilio's signature, which covers the URL, will not match.

## Limits worth knowing (owner decisions)

* **WhatsApp templates.** Outside a customer's 24-hour reply window WhatsApp accepts only Meta-*approved templates*. This slice sends a text message, so a business-initiated message can be refused by Meta (it arrives as
  a *failed* report with Meta's reason). Mapping our templates to Meta-approved ones (name, language, variables) is not built.
* **India SMS needs DLT registration** (sender and template IDs with the operator). Not modelled; arrange it with the provider before going live.
* A rotated webhook token must be given to the providers again.

## Not built yet (rest of Phase 16)

Voice/telephony adapters and click-to-call logging, website lead capture (a form and an endpoint into `LeadService`), media/attachments, inbound WhatsApp conversations beyond STOP, per-message cost, other SMS providers.
