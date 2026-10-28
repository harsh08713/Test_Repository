# Lead score, next steps, summary and deal forecast (Phase 18, first two slices)

Where: the **Score and advice** card on a lead's page. Settings: **Company settings → Lead scoring**. Brief section 6.2 ("AI-assisted lead scoring and sales recommendations").

**There is no AI in this slice.** The brief asks for scoring "based on explicit business signals", an explanation of the factors, suggested next actions and follow-up timing, and a summary "from available stored data" — all of which fixed rules do, repeatably and with every point explained. The part of 6.2 that *needs* an AI provider (drafting messages, free-text summaries, a model's probability) is **not built**: the owner has not named a provider or a data policy (see *Not built*). The card says so on the page.

It is **advice**. Looking at the card changes nothing; it never moves a lead, changes an owner, plans a follow-up, approves anything or sends a message.

## The score

Six signals, each earning up to its **weight** (Company settings). The score is the points earned out of the points available, shown out of 100 — so any weights make sense (they need not add to 100) and a weight of 0 switches a signal off.

| Signal (default weight) | Rule |
|---|---|
| **Where it came from** (20) | The company's percentage for the lead's source — `scoring.source_points`, e.g. `REFERENCE=100,FIELD=70,WEBSITE=60,INDIAMART=50,…`. A source not listed earns nothing; a figure outside 0–100 is brought to the nearest end; an unreadable pair is ignored |
| **Details filled in** (20) | Six details: mobile, e-mail, contact person, a real requirement (10+ characters), an address (PIN, street or city), an expected value. The points are the share present; the missing ones are named |
| **Conversations held** (20) | Follow-ups **done** whose outcome counts as reaching the person (the outcome's *counts as contact* flag): none 0 %, one 40 %, two 70 %, three or more 100 %. Follow-ups done that never reached anyone earn 10 % |
| **How recent the contact is** (20) | Days since the last contact: ≤ 3 → 100 %, ≤ 7 → 75 %, ≤ 14 → 50 %, ≤ 30 → 25 %, older 0. A lead **never contacted** counts from the day it was entered, at half |
| **How far it has progressed** (15) | New 0, Contacted ⅓, Qualified ⅔, Deal or Quotation all of it |
| **Expected value** (5) | At or above the threshold (`scoring.value_threshold`, default ₹1,00,000) all of it, at half the threshold half, below that none; no value none. A threshold of 0 makes any value earn it all |

**Hot / warm / cold:** from `scoring.hot_from` (default 70) the lead is hot, from `scoring.warm_from` (default 40) warm, below that cold — a score exactly on a line belongs to the warmer side.

A lead that is **won or lost is not scored** ("Closed — not scored"); its summary is still shown. Each company has its own settings; `scoring.enabled` (default on) switches the whole card off.

The card shows the score, the band, and a table of the signals with the points earned, the points available and one sentence each — the rows add up to the total, and the last row says so. Anyone who can open the lead sees it: it is made of what is on the page already.

## Worth doing next

Up to three suggestions, most pressing first, from rules over the lead's record and its follow-ups:

1. a planned follow-up whose time has passed ("the next was due 2 days ago — do it, or move it");
2. **nothing planned at all** — plan the next follow-up;
3. nobody has ever reached the lead (and it is more than a day old) — call it today;
4. no contact for 14 days or more;
5. no mobile number — the lead cannot be qualified without one;
6. a stage that no longer fits: spoken to but still **New** (move it to Contacted); **Qualified** with no expected value (estimate it), or with one (convert it to a deal, or quote it).

When nothing is planned it also says **when to be back in touch by**: a hot lead within a day, a warm one within three, a cold one within a week (from today, not from the last contact).

## The summary

A few sentences from stored facts only: when and how it was entered and from which source, how many follow-ups were done and how many reached the person, how many are planned, when it was last contacted, the stage and the expected value. It carries **no name and no phone number**, so it can be pasted anywhere.

## Deal forecast — an advisory chance of winning

On an **open deal's** page (Discovery, Negotiation or Quotation), *Forecast from history* shows a chance of winning worked out from how the company's **own closed deals** ended — beside the salesperson's own figure, which it never replaces. Settings: **Company settings → Deal forecast**.

For each open stage it looks at the closed deals (won or lost) that **ever reached that stage** (from the deal's stage history; a deal that went back and forth counts once; one re-opened and won counts as won) and how many were won:

> chance = (won + weight × usual) ÷ (deals + weight)

*usual* is the stage's usual chance (Discovery 10 %, Negotiation 50 %, Quotation 70 % — the probability a deal starts with there); *weight* (`forecast.prior_weight`, default 10) is how many deals' worth of belief that usual figure gets. So with **no history the forecast is the usual figure**, and each closed deal moves it towards what really happened — a few lucky deals cannot swing it, and a long record is believed. It is kept between 1 % and 99 %, like a deal's own probability.

Examples (Negotiation, weight 10): 4 of 6 won → 56 %; 10 of 10 → 75 %; 0 of 10 → 25 %; 0 of 200 → 2 %; 200 of 200 → 98 %.

The card says **how much history it rests on** — thin (fewer than `forecast.fair_from` closed deals, default 20), fair, or good (`forecast.good_from`, default 50) — states the counts in words ("Of 4 closed deals that reached Negotiation, 3 were won"), shows the deal's value weighted by the forecast, and warns when it differs from the salesperson's figure by 20 points or more. Other companies' deals never count; nor do deleted ones.

It **only advises**: the deal's own probability, the pipeline totals, the dashboards and the reports still use the figure a person set. Won and lost deals have no forecast; `forecast.enabled` (default on) switches it off.

What it is not: a prediction model. It sees only the stage, not the customer, the product, the size or the salesperson; it assumes the future resembles the past; and it counts a deal's *last* outcome, so deals still open say nothing about it. Whether it is more accurate than the salespeople is not measured — a report comparing the two once there is history would answer that.

## How it is built

`LeadScorer` reads the facts (one query over the lead's follow-ups, `facts()`) and works the score out (`score()`); `LeadAdvisor` gives the steps and the summary; `ScoringConfig` is the company's settings as a value object; `LeadScore`, `LeadFacts` and `LeadAdvice` carry the results. The scorer **looks the source up by id** and never trusts a loaded relation — a screen that loads `source:id,name` has no code, and the same lead scored 20 points lower on the page than in a test until a page test caught it. Add a signal by adding a method and a weight setting; do not score anywhere else.

## Not built (and the owner's decisions)

* **An AI provider.** The owner's answer (2026-10-22) was a self-hosted or other provider behind a provider-independent interface, **disabled until configured, provider and data policy not yet named**. Nothing in this slice calls one. The interface, the minimum-data rule ("do not send unnecessary personal data"), the activity log of every call (provider, model/config version, input data categories, result metadata — never secrets), the human review of any generated text and the opt-out belong to the slice that wires a provider in; they need that decision first.
* **A forecast beyond the stage.** The deal forecast uses the stage only; by lead source, product, deal size or salesperson, and a forecast of *when* a deal closes, are not built (the samples would be small). The forecast is on the deal's page only: not in the pipeline totals, the dashboards, the reports, the Deals list or the API.
* **A score column or filter on the Leads list**, a score in the mobile API, a *hot leads* report, scoring of deals or quotations, and **history of a lead's score** over time. The list's filters live in a shared component (`<x-filters>`); adding a column there needs a front-end build.
* **Learning.** The weights are the company's judgement, not fitted to past results. Whether the score predicts winning is not measured; the reports could compare the score of won and lost leads once there are enough.
