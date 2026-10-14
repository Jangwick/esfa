# AI compliance rulebooks

Status: **requested 2026-10-12, being built.** The rulebooks are the base for every compliance check the AI features make.

## Why

The AI's refund review said "Need more info" with reasons the person could not trace to a policy. Each AI feature now
checks a numbered rulebook. SFA's own code checks what its records can prove (kind **SFA check**), and the AI judges only
what needs reading (kind **AI check**). **The outcome is worked out from the rule results in PHP, never by the model**, and
every panel shows the checklist it rests on.

## Contract (already in place)

- `backend/app/Services/AiRules.php`: the rulebooks (`refund`, `risk`, `reply`, `triage`, `address`, `delivery`, `ask`),
  `DELIVERY_PLAYBOOK`, `book()`, `books()`, `result()`, `aiRules()`, `aiSchema()`, `merge()`, `outcome()`, `failures()`.
  Tests: `tests/Feature/AiRulesTest.php`.
- New settings (config/sfa.php and SettingsRequest; no screen): `refund_window_days` 15, `refund_max_per_30_days` 2,
  `risk_amount_multiple` 5, `risk_new_customer_days` 7. The existing `risk_failed_attempts` is used too.
- A **check** in every response is `{id, kind: sfa|ai, severity: required|advisory, title, result:
  pass|fail|unclear|not_applicable, detail}`, in rulebook order, produced by `AiRules::merge()`.
- `AiRules::outcome($checks)` gives `fail` (a required rule failed), `review` (a required rule unclear, or an advisory
  rule failed or unclear) or `pass`.

## Feature changes

| Feature | Rulebook | What changes |
|---|---|---|
| Refund review (`FinanceReviewService::refundReview`) | `refund` | The book is REF-1..REF-9. SFA computes REF-1..REF-5 from the records; the model judges REF-6..REF-9 (verdicts in a `checks` array in its JSON answer) from the notes, the conversation, the order's items with their prices and up to 4 evidence photos (the refund's upload plus images from its linked tickets), and explains. The response adds `photos: [{id, filename, source}]`, the photos it was shown in order. `recommendation` = outcome mapped pass→approve, fail→reject, review→need_more_info. Response: `{recommendation, confidence, reasons[], checks[], rejection_note_draft, created_at}`; `rejection_note_draft` cites the failed rules and is null unless the recommendation is reject. `policy_flags` and `sfa_flags` are removed (the checks replace them). |
| Risk review (`riskReview`) | `risk` | SFA computes RSK-1..RSK-4; the model judges RSK-5. `recommendation` = pass→clear, fail→block, review→need_more_info. Response: `{recommendation, confidence, summary, reasons[], checks[], flagged_because[], created_at}`. |
| Second opinion (`secondOpinion`) | `risk` | Same checks; a payment is flagged when the outcome is `fail` or two or more rules fail. The model's job is RSK-5 plus a one-sentence reason; the audit row and notice name the failed rule ids. |
| Reply draft (`TicketService::draftReply`) | `reply` | The prompt lists the guidelines. After the answer, SFA checks RPL-1..RPL-5 on the draft text; the model judges RPL-6 and RPL-7 in its JSON answer. Response adds `checks[]` and `needs_correction` (true when a required rule fails). |
| Ticket triage (`TicketService::triage`) | `triage` | The model also returns a `ground` from `duplicate_charge, money_without_order, safety, account_security, delivery_problem, order_blocked, upset_requester, none`. SFA caps the priority: urgent only for a confirmed `duplicate_charge` (the order or customer has another captured payment of the same amount) or `money_without_order` (a captured payment on a cancelled order), or for `safety`/`account_security`; an unconfirmed money ground is capped at high. High only for the service grounds; `none` keeps normal. TRI-1..TRI-4 record what happened. `ai_triage` on the ticket adds `ground` and `checks[]`. |
| Address fill (`LogisticsService::parseAddress`) | `address` | Response adds `checks[]` (ADR-1 to ADR-4 from what SFA matched). |
| Failed-delivery reason (`deliveryReason`) | `delivery` | The model only picks the reason; the next step is `AiRules::DELIVERY_PLAYBOOK[$reason]`. The internal note reads `AI reading of the courier's note: <Reason>. Next step (SFA playbook): <step>`. |
| Ask your data (`AskDataService::ask`) | `ask` | Response adds `checks[]`: ASK-1 passes when `unverified` is empty, ASK-2 always passes (reports run with the asker's role), ASK-3 passes when the answer names a date, month or day count. |
| Rulebook page | all | `GET /api/v1/ai/rules` (any signed-in staff) returns `AiRules::books()`. The Help page shows every rulebook read-only under "Rules the AI follows". |

## Screens

- A shared `RuleChecks` list in `components/ui/Ai.jsx`: one row per rule with an icon (pass, fail, unclear, does not
  apply), the rule id and title, an "SFA check" or "AI check" tag, "required" or "advisory", and the detail.
- Shown in: the Finance AI review panel (refunds and risk reviews), the AI draft panel (with a red "Fix before sending"
  line when `needs_correction`), the AI triage panel (ground + checks), the address fill status, and the Ask your data
  answer.
- AI internal notes on tickets (delivery reason) are labelled "AI note", not "Status change".

## Refund compliance gate (decided 2026-10-12)

The owner chose: **Approve stays locked until the rules are met**, an **Admin can override with a written reason**, the
approver **ticks the person-judged checks**, and **automatic approval of small refunds only happens when every automatic
rule passes**. The gate is SFA's own check and works with the AI switched off; the AI only adds its opinion beside the
checks a person confirms.

### Backend

- `App\Services\RefundCompliance` (new) owns the automatic refund checks REF-1..REF-5 (moved out of
  `FinanceReviewService::refundReview`, which now calls it). Methods: `checks(Refund)` (automatic results, merged with
  `AiRules`), `manual(Refund)` (REF-6 required; REF-7 photo evidence, **required since 2026-10-12** (was advisory), applies
  only to `damaged`/`wrong_item`; REF-8 photo matches the order and REF-9 amount fits the affected items follow their
  rulebook severity),
  `blocked(array $checks)` (a required automatic rule failed), `automaticPass(Refund)` (every automatic rule passes or does
  not apply, advisory REF-5 included), `gate(Refund, array $confirmed, ?string $override, User)` (throws a 422 that names
  the unmet rules) and `snapshot(...)`.
- `GET /api/v1/refunds/{refund}/compliance` (approve refunds + payment scope) returns
  `{automatic: [check], manual: [{id, title, description, severity, applies}], blocked, required_confirmations: [ids],
  can_override, compliance}`; `compliance` is the stored snapshot once the refund was decided.
- `POST /api/v1/refunds/{refund}/approve` takes `{confirmed: [rule ids], override_reason}`:
  - every required manual check that applies must be in `confirmed` (422 `confirmed`);
  - when a required automatic rule fails, only an **Admin** with `override_reason` (10 to 1000 characters) may approve
    (422 `compliance` names the failed rules; a Finance user's override is refused);
  - on success the refund stores `compliance` = `{checks, confirmed, override_reason, overridden, by, at, auto: false}`
    (new nullable json column `refunds.compliance`, new migration), which the audit row and the refund detail show.
- `PaymentService::autoApprovable` also requires `RefundCompliance::automaticPass()`; an automatic approval stores the
  snapshot with `auto: true`. Service-level `PaymentService::approve()` is unchanged (the gate is in the HTTP action), and
  the owner's payment-integration code is not touched.
- A damaged or wrong-item refund also needs REF-7 confirmed before it can be approved, since REF-7 is now required.
- Existing tests that approve refunds through the API send `confirmed: ['REF-6']` (and, where a fixture breaks a rule on
  purpose for another reason, use an Admin override or a fixture that meets the rules), without weakening any assertion.

### Screen: the compliance checker

On a requested refund, for people who can approve it, a **Refund compliance** card replaces the separate AI review card:
- a status banner: **Ready to approve** (green), **Confirm N checks below** (amber) or **Blocked: N rules not met** (red);
- **Automatic checks (SFA)**: one row per rule with a pass/fail icon and what SFA found;
- **Checks you confirm**: a checkbox per applying manual rule, with the AI's opinion beside it (Met / Not met / Unclear and
  its reason) when the AI review is on;
- **Approve refund** unlocks only when nothing is blocked and every required box is ticked; when blocked, an Admin sees
  **Approve anyway** with a required reason box; **Reject** opens the reject dialog (pre-filled with the AI's note when there
  is one);
- the refund list's Approve icon opens this checker instead of approving straight away, and **Approve selected** states
  the confirmation in its confirm step, sends `confirmed: ['REF-6']`, and lists refunds the server refused as skipped with
  the reason;
- a decided refund shows the stored snapshot: which rules were met, what was confirmed, and any Admin override with its
  reason.

### Owner's screen changes (2026-10-12, later)

- **Nothing runs on open.** Opening a refund or a risk review (from its row, its AI icon or its reference) shows the
  rulebook and a **Run compliance check** button. No compliance call and no Gemini call happens until it is pressed, which
  also saves the free-tier Gemini quota.
- **The rules are shown as progress.** Before a run, every rule of the book is listed as a step marked "Not checked yet",
  with a progress bar at 0 of N. The rule text comes from `GET /ai/rules` (cached).
- **A run is a real two-stage progress:** stage 1 "Checking SFA's records" calls the compliance endpoint and fills in the
  automatic rules; stage 2 "Asking the AI" (only when the AI review is on for this user) fills in the AI's opinion on the
  person-judged rules. Each stage shows a spinner while running, then its rows turn into met / not met / does not apply,
  and the bar counts the rules met. A stage that fails shows its error with "Try again" without losing the other stage.
- **Approve unlocks only after a completed run** (stage 1 done, stage 2 done or not applicable), with nothing blocked and
  every required box ticked. "Run again" re-runs both stages.
- The shared `RuleChecks` list becomes this progress style everywhere it is used (reply draft guidelines, triage policy,
  address rules, reporting rules): a bar with "N of M rules met" and a step list with a status icon per rule.
- **Every step shows the rule itself, not only the result.** `AiRules::merge()` adds `description` (the rule text with
  its current threshold filled in) to each check, and every rule list shows two lines per rule: **Rule:** the
  requirement (e.g. "Requested within 15 days of delivery, or of payment when nothing was delivered.") and **Found:**
  what SFA or the AI found (the `detail`). Before a run, the step shows the Rule line and "Not checked yet".

### Refund photos (2026-10-12)

- The staff refund request form has "Photos of the problem (optional)": up to 3 PNG, JPEG or WebP images of at most 4 MB
  each, checked in the browser too. With photos the form posts multipart (`photos[]` plus the usual fields); without, it
  posts JSON as before.
- The refund detail shows a **Photos** strip (thumbnails loaded with the signed-in token from
  `GET /refunds/{id}/photos/{attachmentId}`; press one to see it full size).
- After a completed compliance check, the panel shows **Photos the AI looked at** (photo 1 first, with where each came
  from). With no photos nothing extra shows; the REF-7 row explains what is missing.
- A payment's risk review shows a **Risk check** instead (owner, 2026-10-12): the same manual run and rule list, worded as
  risk signals (`RuleChecks mode="risk"`: High / Some / Low risk, "Risk found" / "No risk", major / minor signal).
