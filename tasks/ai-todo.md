# AI megaplan: task list

Details, acceptance criteria and tests for each task are in [ai-plan.md](ai-plan.md). Status: **approved 2026-10-12; building phase by phase.** Model: `gemini-3.5-flash-lite` (fallback `gemini-3.1-flash-lite`): the
largest free-tier request quota (15 a minute, 500 a day each); every Flash model allows only 5 and 20. Every AI switch starts off.

## Phase 0: Foundation
- [x] 0.1 AiService + `ai_insights` table (cache, audit, daily token limit) + masking + fallback model + config (M)
- [x] 0.2 `sfa:ai-check` command + one live contract check + recorded fixtures (S)
- [x] 0.3 Settings > Automation "AI assistant": switches, daily limit, usage today (M)
- [x] Checkpoint 0: backend tests, lint + build, scheduled work twice, OpenAPI rebuild, `sfa:ai-check` with the real key

## Phase 1: Ticket triage
- [x] 1.1 Triage after create (replaces category "other", raise-only priority, sentiment, tags) + scheduled catch-up (M)
- [x] 1.2 "AI triage" chip on the ticket (S)

## Phase 2: Reply drafts
- [x] 2.1 `POST /support/tickets/{id}/ai-draft` from the knowledge base and linked records (M)
- [x] 2.2 Shared `AiSuggestion` card + "Draft with AI" in the reply box (M)
- [x] Checkpoint 1–2 (2026-10-12): 307 backend tests, lint + build, OpenAPI rebuilt; real triage (other→delivery, normal→high, ~377 tokens) and real drafts (Filipino, grounded on the shipment) on a DB copy; ticket page and Automation tab checked in the browser, no overflow at 375 px

## Phase 3: Finance review assistant
- [x] 3.1 Risk-review explanation + Clear/Block recommendation + note draft (M)
- [x] 3.2 Refund check + Approve/Reject recommendation + rejection note draft (M)
- [x] 3.3 Optional: AI second-opinion flag (adds uncaptured payments to Risk reviews; notice for captured ones) (S)
- [x] Phase 3 live on the DB copy: risk review (Block, cites PHP 75,000 vs a PHP 172 history), refund check (flags damaged-while-out-for-delivery, note pre-fills Reject), second opinion moved a PHP 48,000 COD to Risk reviews. Risk reviews have no note field, so they get no note draft. On-screen requests now get one more try on the main model after a 2 s pause (Gemini 503 spikes)

## Phase 4: Logistics
- [x] 4.1 Address auto-fill on Create shipment (AI reads, SFA matches the location tables) (M)
- [x] 4.2 Failed-delivery reason on the automatic delivery ticket (S)
- [x] Phase 4 live on the DB copy: a pasted Taglish address filled region, city, barangay, lines, ZIP, name and phone (phone read by SFA, never sent); a courier note "lumipat na daw" became reason:wrong_or_incomplete_address with a next step on the open ticket

## Phase 5: Ask your data
- [x] 5.1 `POST /analytics/ask` with read-only report functions, role-scoped (M)
- [x] 5.2 "Ask" box on the Analytics page with the figures used (S)
- [ ] Checkpoint 3–5: tests, lint + build, scheduled work twice, OpenAPI; every feature once on a DB copy

## Phase 6: Wrap-up
- [x] 6.1 Docs: TECH-STACK, SPECIFICATION §5, DECISIONS, Help page, OpenAPI entries (S)
- [ ] 6.2 Deploy with approval; key set in Vercel by the owner; AI switches off until turned on (S)

## Phase 7: Compliance rulebooks (tasks/ai-rules-plan.md)
- [x] 7.1 `AiRules` contract: seven rulebooks, delivery playbook, `merge()`, `outcome()`, `failures()` + AiRulesTest (M)
- [x] 7.2 Settings `refund_window_days`, `refund_max_per_30_days`, `risk_amount_multiple`, `risk_new_customer_days` (no screen) (S)
- [x] 7.3 `GET /ai/rules` for any signed-in staff + AiRulesApiTest + OpenAPI (AiRulebooks, AiCheck, checks on every AI schema) (S)
- [x] 7.4 Docs: DECISIONS, TECH-STACK, SPECIFICATION §5, ai-plan pointer (S)
- [ ] 7.5 Refund review on REF-1..REF-7 (recommendation from the outcome; `policy_flags`/`sfa_flags` removed) (M)
- [ ] 7.6 Risk review and second opinion on RSK-1..RSK-5 (M)
- [ ] 7.7 Reply drafts on RPL-1..RPL-7 with `needs_correction` (M)
- [ ] 7.8 Triage ground + priority caps (TRI-1..TRI-4), `ground` and `checks` in `ai_triage` (M)
- [ ] 7.9 Address fill ADR-1..ADR-4, failed-delivery playbook step, Ask your data ASK-1..ASK-3 (S)
- [ ] 7.10 `RuleChecks` list in the AI panels, "Fix before sending", "AI note" label, Help page "Rules the AI follows" (M)
- [ ] Checkpoint 7: backend tests, lint + build, OpenAPI; each feature once on a DB copy
