# AI megaplan: Gemini assistant for SFA

Status: **approved 2026-10-12, being built** (see ai-todo.md for progress). Model change after live tests:
`gemini-3.5-flash-lite` is the main model and `gemini-3.1-flash-lite` the fallback, chosen for request quota (see "Changes
made during the build"). The model table below is the original draft. The checklist is in [ai-todo.md](ai-todo.md). The finished
automation megaplan stays in [plan.md](plan.md) and [todo.md](todo.md).

## Overview

Five AI features, one per module, plus an optional AI second opinion on payment risk. All of them run through one Gemini
client in the backend. **AI suggests and labels; people decide anything that moves money or reaches a customer.** Every
feature has its own switch under Settings > Automation, and all of them start off. Every AI output is stored, so it can be
shown again without calling Gemini twice and checked afterwards. If Gemini fails or is switched off, each screen keeps
working as it does today.

| # | Feature | Module | Mode |
|---|---|---|---|
| 1 | Ticket triage: category, priority, sentiment, tags | Customer Support | Automatic, labels only (staff can change them) |
| 2 | Reply draft from the knowledge base, with the source articles shown | Customer Support | Suggestion; the agent edits and sends |
| 3 | Finance review assistant: risk reviews and refund checks | Payments | Suggestion; Finance still clicks |
| 3b | AI second-opinion risk flag (optional) | Payments | Can only *add* a payment for review; never blocks, cancels or refunds |
| 4 | Address auto-fill on Create shipment, and the reason for a failed delivery | Logistics | Fills the form for review / automatic label |
| 5 | Ask your data in plain language | Analytics & Reports | Read-only; every number comes from SFA's own reports |

## Model choice

**`gemini-3.8-flash`**, pinned in `SFA_GEMINI_MODEL` (checked against Google's model pages on 2026-10-12).

| | gemini-3.8-flash (chosen) | gemini-3.1-pro-preview | `gemini-flash-latest` (from your curl) |
|---|---|---|---|
| Status | Stable, newest Flash (September 2026) | Preview. `gemini-3-pro-preview` has already been shut down | An alias: Google can move it to another model without notice |
| Input / output capacity | 1,048,576 / 65,536 tokens | 1,048,576 / 65,536 tokens | Depends on the model it points to |
| Structured JSON, function calling, thinking levels | Yes, yes, low/medium/high | Yes, yes, yes | Varies |
| Free tier | Yes | No | — |
| Paid price per 1M tokens, input / output | $0.75 / $3.75 until 2026-12-31, then $1.50 / $7.50 | $2.00 / $12.00 | — |

The Pro preview has the same capacity at about three times the price, and could be shut down before your defense. The
`-latest` alias can change which model it points to, and so how it behaves, without warning, which could break the JSON
answers SFA depends on. The fallback for rate limits and outages is `SFA_GEMINI_FALLBACK_MODEL=gemini-3.6-flash`, which
has the same price and also a free tier.

Thinking level per feature: `low` for triage, address and delivery reason; `medium` (the default) for reply drafts, the
finance review and questions about your data. Thinking tokens are billed as output.

**Rough cost** on 3.8 Flash at 2026 prices: about $0.004 per triage, $0.002 per address, $0.006 per finance review,
$0.01 per reply draft and $0.015 per question. A demo month of a few thousand calls costs about **$10–30**; prices double
from 2027-01-01.

## Architecture decisions

- **One client, plain HTTP.** `App\Services\AiService` calls
  `POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` with the `x-goog-api-key` header
  through Laravel's `Http` facade. This is the same endpoint as your curl and needs no new package (AGENTS.md). It reuses
  the existing `config('services.gemini')`, `SFA_GEMINI_API_KEY` and `SFA_GEMINI_MODEL`.
- **Exact JSON answers.** Labels, reviews and addresses use `generationConfig.responseMimeType = application/json` with
  a response schema. PHP still validates every value: enums against SFA's own lists, IDs against the database, lengths. An
  answer that fails validation is discarded and recorded as failed.
- **Numbers come from PHP.** This rule is already in `TECH-STACK.md` §5. Amounts, counts and thresholds are worked out in
  PHP and passed to the model; the model only explains them. For feature 5, the model can get figures only by calling SFA's
  report functions, and the screen shows the figures it used.
- **One table for cache, audit and budget.** `ai_insights` holds `feature`, `subject_type`/`subject_id`, `status`
  (`ok|failed|skipped`), `model`, `input_hash`, `output` (json), `error`, `prompt_tokens`, `output_tokens` (answer plus
  thinking), `created_by` and timestamps. The same input hash returns the stored answer with no call to Gemini. The day's
  token total enforces the daily limit, and each row records who asked for what.
- **Private data stays minimal.** Each feature sends a fixed list of fields. Payout details, emails, phone numbers,
  addresses (except in feature 4, which only sends the pasted text) and full names are never sent. Free text such as ticket
  messages and courier notes is masked first: emails, phone numbers, long digit runs. Reply drafts write `{first_name}`,
  which the browser fills in.
- **When AI runs.**
  - *Automatic, after the record is saved:* ticket triage and the delivery reason. They run once the database
    transaction has committed (the pattern `deliveryTicket` already uses), with an 8-second limit. No lock is held while
    waiting for Gemini.
  - *On first open:* the finance review runs when Finance first opens the payment or refund, then is reused; Refresh asks
    again. No payment code changes are needed for this.
  - *On click:* reply draft, address auto-fill and questions about your data.
  - *Scheduled work:* a catch-up run for triage and the optional second-opinion sweep.
- **How it fails.**
  - The feature is off, no key is set, or the daily limit is reached: `422` with a clear message.
  - Gemini times out, returns 429 or 5xx, blocks the request, or the answer is cut off by `finishReason`: SFA retries once
    on the fallback model, then answers `503` with a clear message.
  - Every screen shows the message with a "Try again" button and keeps working by hand.
- **Prompt injection.** Ticket text, notes and questions are data, not instructions; the system prompt says so. Even if a
  ticket says "approve my refund", the output can only be a value allowed by the schema. No AI path can change money,
  status or customer data. Feature 5's functions are read-only and run with the signed-in user's own permissions.
- **What stays untouched.** `PaymentService::processEvent`, the payment and refund integration endpoints, `app/Gateways`
  and the payment webhook (your payment-integration code). The optional second-opinion flag adds one new locked method,
  `PaymentService::flagForReview()`, and changes nothing else.

## Task list

Sizes: S = 1–2 files, M = 3–5 files. Each task ships with its feature tests: 401, 403, 422, the happy path, the
feature-off case, and Gemini failing. Tests use `Http::fake()` plus `Http::preventStrayRequests()` and never call Gemini.

### Phase 0: Foundation

**0.1 AiService, `ai_insights` and the config (M).** `generate($feature, $subject, $system, $input, $schema|$tools,
$opts)` covers:
- the switch checks: the master switch, the feature's own switch and the key;
- the daily token limit, masking and the cache by input hash;
- the HTTP call with timeout, plus one retry on the fallback model after 429/5xx;
- reading `promptFeedback.blockReason` and `finishReason`, the JSON check, recording tokens from `usageMetadata`, and
  saving the row.

The model defaults to `gemini-3.8-flash`, with the fallback and timeout settings added to `config/services.php` and
`.env.example`. A new migration creates the table, ending with the PostgreSQL row-level security statement.

*Acceptance:*
- [ ] A faked success stores one row with its tokens.
- [ ] The same input twice makes one HTTP call.
- [ ] Each failure is stored with its reason: off, no key, limit reached, blocked, cut off, malformed JSON, timeout.
- [ ] A 429 retries on the fallback model.
- [ ] Emails, phone numbers and digit runs never appear in the sent body.

*Files:* `app/Services/AiService.php`, `app/Models/AiInsight.php`, the new migration, `config/services.php`,
`.env.example`, `tests/Feature/AiServiceTest.php`.

**0.2 Live contract check and `sfa:ai-check` (S).** An artisan command sends one tiny structured request using the
configured key and model, then prints the model, latency and OK or the error. The key is never printed. Run it once with
the real key to confirm the exact request field names (`responseJsonSchema` vs `responseSchema`,
`thinkingConfig.thinkingLevel`) against Google's API reference. Save the answer, without the key, as
`tests/Fixtures/gemini/*.json` so the fakes copy the real shape.

*Acceptance:*
- [ ] The command works with the key and fails clearly without it.
- [ ] Fixtures are committed and contain no key.

**0.3 Settings > Automation, "AI assistant" section (M).**
- New settings in `config/sfa.php`: `ai_enabled`, `ai_ticket_triage`, `ai_reply_drafts`, `ai_finance_review`,
  `ai_risk_second_opinion`, `ai_address_fill`, `ai_delivery_reason` and `ai_ask_data`, all `false`; and
  `ai_daily_token_limit = 1000000`.
- `SettingsRequest` validates them.
- `GET /automation` gains an `ai` block: whether a key is set, the model, and today's calls, tokens and estimated cost.
- The tab shows the switches, the limit and usage. The key is never shown.

*Acceptance:*
- [ ] Only an Admin can change the settings.
- [ ] A Support Agent gets 403.
- [ ] Turning the master switch off stops every feature.

### Checkpoint 0
- [ ] `php artisan test --compact`, `npm run lint && npm run build`, scheduled work run twice, OpenAPI rebuilt.
- [ ] `sfa:ai-check` passes with the real key.

### Phase 1: Ticket triage (feature 1)

**1.1 Triage after a ticket is created (M).** After commit, `TicketService` classifies the subject and the first message
into:
- a category from SFA's ticket categories;
- a priority;
- a sentiment (`positive|neutral|negative|angry`);
- up to 5 tags;
- a one-line reason.

What triage may change:
- It sets the category only when none was given.
- It may **raise** the priority, never lower it, through `changePriorityLocked` so the SLA due times move with it.
- It adds the tags plus `ai-triaged`.
- It writes `AuditLog::system(..., 'ai_ticket_triage')`.

Scheduled catch-up triages at most 20 open, untriaged tickets from the last 24 hours, and is idempotent.

*Acceptance:*
- [ ] With the switch on, a new ticket is labelled.
- [ ] The switch off, Gemini failing, or a value not on SFA's lists each leave the ticket exactly as today.
- [ ] A ticket created as urgent stays urgent.
- [ ] Running the catch-up twice changes nothing the second time.

**1.2 Triage display (S).** `TicketDetail` shows an "AI triage" chip with the sentiment, the reason and "set by AI" next
to the fields AI set. The list shows the `ai-triaged` tag.

*Acceptance:*
- [ ] Staff can change any label by hand, and the chip then says "changed by staff".

### Phase 2: Reply drafts (feature 2)

**2.1 `POST /support/tickets/{id}/ai-draft` (M).** The request carries:
- the last 10 public messages, masked;
- the linked order, payment and shipment state, worked out in PHP (for example "out for delivery, attempt 1 of 3");
- the published knowledge-base articles and canned responses, sent first so Gemini's prompt caching can apply where it
  supports it.

The answer is `{reply, source_article_ids[], confidence, needs_human_reason}`. Source IDs must be published articles;
any others are dropped. Access follows `assertWrite` (a Support Agent only on their own or unassigned tickets). The route
is limited to 20 requests a minute per user.

*Acceptance:*
- [ ] A Support Agent gets 403 on someone else's ticket.
- [ ] The draft never contains an email or phone number from the conversation.
- [ ] Made-up article IDs are removed.

**2.2 "Draft with AI" in the reply box (M).** A shared `components/ui/AiSuggestion.jsx` card handles loading, the
label "AI suggestion: check before sending", the error with "Try again", and Refresh. A small `lib/ai.js` helper goes with
it. The draft fills the reply textarea with `{first_name}` replaced, and the source articles show as chips. Nothing is
sent automatically.

*Acceptance:*
- [ ] The button is disabled while drafting.
- [ ] The server's messages are shown.
- [ ] The layout is usable at 375 px.

### Checkpoint 1–2
- [ ] Full test, lint and build pass; OpenAPI rebuilt.
- [ ] One real triage and one real draft work on a **copy** of the database (`--no-reload`).
- [ ] Review with you before Phase 3.

### Phase 3: Finance review assistant (feature 3)

**3.1 Risk-review explanation (M).** `GET /payments/{id}/ai-review` returns the stored review, or generates one on first
open; `POST` refreshes it.

What PHP works out and sends:
- which rule flagged the payment: the amount against `risk_amount_threshold`, failed attempts against
  `risk_failed_attempts`;
- the customer's captured, failed, refunded and disputed counts;
- the order size;
- the subjects of linked tickets.

The answer is `{recommendation: clear|block|need_more_info, confidence, reasons[], note_draft}`, shown next to **Clear and
capture** / **Block and cancel** on the Risk reviews tab. "Use note" fills the review note. It is open to Finance and
Admins only.

*Acceptance:*
- [ ] A Support Agent gets 403.
- [ ] The review never captures, clears or blocks anything (the test checks the payment's state is unchanged).
- [ ] The second open makes no HTTP call.

**3.2 Refund check (M).** `GET`/`POST /refunds/{id}/ai-review` sends:
- the amount against `refundableAmount()`, worked out in PHP;
- the reason code and notes;
- the order and shipment state (delivered, returned, failed, proof of delivery);
- the customer's earlier refunds;
- the last 10 ticket messages, masked.

The answer is `{recommendation: approve|reject|need_more_info, reasons[], policy_flags[], rejection_note_draft}`, shown
next to **Approve** / **Reject**. The draft note fills the Reject dialog.

*Acceptance:*
- [ ] A refund over the refundable amount always raises a policy flag computed in PHP, whatever the model says.
- [ ] The check never approves or rejects anything.

**3.3 Optional: AI second-opinion flag (S).** With `ai_risk_second_opinion` on, scheduled work looks at payments from the
last 24 hours that the rules passed (`risk_level = none`).
- **Not yet captured** (COD before delivery, or authorized and waiting): if the AI finds a concrete reason, the new
  `PaymentService::flagForReview()` locks the payment, sets `risk_level = review`, records the reason, notifies Finance
  and writes `AuditLog::system(..., 'ai_risk_second_opinion')`. The payment then appears in Risk reviews, and the existing
  COD risk hold keeps its cash from being captured.
- **Already captured:** only a notice, "AI suggests a look at PAY-…". Nothing changes.

*Acceptance:*
- [ ] The flag never blocks, cancels or refunds.
- [ ] It never changes a captured payment.
- [ ] It stays idempotent: each payment is reviewed at most once.
- [ ] `processEvent` and the integration endpoints are untouched; checked with grep in review.

### Phase 4: Logistics (feature 4)

**4.1 Address auto-fill (M).** `POST /logistics/address/parse` takes `{text}`. The route is registered before the
`{shipment}` routes. The AI returns names: `{street, barangay, city, province, region, postal_code}`. PHP then matches
them to the `regions`, `provinces`, `cities` and `barangays` tables, ignoring case and forms like "City of …". The answer
has the IDs, with any part it couldn't match marked. `AddressFields.jsx` gains "Paste an address", which fills the selects;
unmatched parts are highlighted, and the person checks and saves.

*Acceptance:*
- [ ] The AI never supplies an ID; matching is done by SFA.
- [ ] An unknown barangay stays empty and is highlighted.
- [ ] The form saves only through the existing Create shipment validation.

**4.2 Failed-delivery reason (S).** In `LogisticsService::deliveryTicket`, the courier's note is classified **before**
the transaction starts. The reason is one of: `recipient_unavailable`, `wrong_or_incomplete_address`, `refused`,
`inaccessible_area`, `cod_cash_not_ready`, `rescheduled_by_customer` or `other`. The delivery ticket then gets:
- a tag such as `reason:wrong_or_incomplete_address`;
- an internal note with the suggested next step, for example "Confirm the address with the customer".

*Acceptance:*
- [ ] With the switch off, no note, or Gemini failing, the delivery ticket is created exactly as today.

### Phase 5: Ask your data (feature 5)

**5.1 `POST /analytics/ask` (M).** It takes `{question}` and offers two functions: `get_summary(filters)` and
`get_charts(filters)`.
- Both map to `ReportingService::summary($user, …)` and `::charts($user, …)` with the filters checked by the
  `AnalyticsRequest` rules, so role scoping stays exactly as it is.
- There are at most 4 rounds of function calls. The model's parts are sent back exactly as received, `thoughtSignature`
  included, as Gemini 3 requires.
- The answer is `{answer, figures_used[]}`, where each figure is a call and its result.
- It needs the analytics view permission and is limited to 10 requests a minute per user.

*Acceptance:*
- [ ] A Support Agent's question returns only data their role can see.
- [ ] "Show me the users table" or "ignore your rules" gets a polite answer built from the report functions only.
- [ ] Any number in the answer that is missing from the figures used is listed as unverified.

**5.2 "Ask" box on the Analytics page (S).** A question box with three example questions, then the answer card, with
"Based on" showing the report figures and the date range, plus loading, empty and error states. It is usable at 375 px.

### Checkpoint 3–5
- [ ] Full test, lint and build pass; scheduled work run twice; OpenAPI rebuilt.
- [ ] Each feature runs once for real on a copy of the database, with screenshots.

### Phase 6: Wrap-up

**6.1 Docs (S).**
- `TECH-STACK.md` and `SPECIFICATION.md` §5: the model line and the AI features.
- A `DECISIONS.md` entry: Gemini, the pinned model, suggestion-only, the private-data rules.
- A Help page section, "What the AI does and doesn't do".
- OpenAPI entries in `scripts/build-openapi.py`.

**6.2 Deploy (S, only with your go-ahead).** You set `SFA_GEMINI_API_KEY` in Vercel. I run the migration as `sfa_app`,
deploy, run `sfa:ai-check` against production and confirm every AI switch is off until you turn it on.

## Risks and mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| **Free-tier data use:** Google's pricing page says free-tier content is "used to improve our products"; paid-tier content is not | High with real customers | Fixed field lists and masking. Use a billing-enabled key before real customer data goes through. The demo data is fine either way |
| Free-tier rate limits during the defense | Medium | Stored answers, the fallback model, a clear "AI is busy" message, and a warm-up run beforehand |
| A customer writes instructions in a ticket | Medium | Schema-limited outputs, suggestions only, read-only functions, a system prompt that treats ticket text as data |
| Ticket creation slows down | Low | Triage runs after commit with an 8-second limit, and scheduled catch-up retries |
| The model invents numbers in feature 5 | Medium | Figures come only from function results; the screen shows them and lists any unverified number |
| The model alias or a preview model changes | Medium | The stable model is pinned; a different model is only an env change |
| Prices double from 2027-01-01 | Low | The daily token limit; usage and estimated cost on the Automation tab |
| **The API key was pasted into chat** | High | Create a new key in Google AI Studio and delete this one. Put keys only in `backend/.env` and Vercel |

## Open questions

1. **Second-opinion flag (3.3):** OK as planned? It adds uncaptured payments to Risk reviews and only sends a notice for
   captured ones. The alternative is checking inside checkout before capture, which adds 2–4 s to every online payment
   and puts Gemini in the money path. I don't recommend it.
2. **Billing on the key:** will you enable billing (paid tier) before production carries real customer data?
3. **Daily limit:** is 1,000,000 tokens a day (about $1–4 a day at 2026 prices) right?

## Changes made during the build

- **Compliance rulebooks (2026-10-12):** every AI feature now checks a numbered rulebook and returns `checks[]`; the
  outcome is computed in PHP from the rule results. See `tasks/ai-rules-plan.md` (spec) and DECISIONS.md "AI compliance
  rulebooks".
- **Model:** `gemini-3.5-flash-lite` is the main model and `gemini-3.1-flash-lite` the fallback. The project key is on
  the free tier, where every Flash model (3, 3.5, 3.6, 3.7, 3.8, 2.5) allows only 5 requests a minute and 20 a day; 3.6 and
  3.8 Flash ran past 20 a day during one morning of testing, and 3.8 also kept answering 503 "high demand". The two
  Flash-Lite models allow 15 a minute and 500 a day each, have the same 1M-token input and 65k output, and support
  structured JSON, function calling and the low/medium thinking levels (3.1's `medium` was confirmed with one request).
  Paid price for 3.5 Flash-Lite: $0.30 / $2.50 per million tokens. With billing on, a Flash model is an env change away.
- **Triage category:** the ticket form requires a category, so triage replaces only the category "other" (not an empty
  one). The tag that marks a triaged ticket is `ai_triaged`, matching the existing underscore tags.
- **Risk reviews:** Clear and Block take no note, so the risk review gives a summary and reasons but no note draft. Refund
  rejections need a reason, and the AI draft fills the Reject dialog.
- **Finance review timing:** the review runs when Finance first opens the record (then it is stored), not at arrival, so
  no payment code had to change.
- **Retries:** both models were busy at the same time several times, so a request someone is waiting for gets one more try
  on the main model after a 2-second pause. Triage, the delivery reason and the second opinion keep one attempt and catch
  up on their own schedule.
- **Ask your data:** Analytics is open only to Admins and Analysts (`view analytics`), so Support Agents and Finance get 403
  here. The figures check covers decimals, percentages and numbers of 1,000 or more; small counts are not checked.
- **Answered open questions:** the second-opinion design is as planned; the daily limit is 1,000,000 tokens; billing on the
  key is the owner's decision (see Risks).

