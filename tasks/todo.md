# Automation megaplan: task list

Details, acceptance criteria and tests for each task are in [plan.md](plan.md). Status: **Phases 0-6 built, verified and deployed.** Last full checkpoint green on 2026-10-12 after the UI dead-end sweeps and the simulator guides: 289 backend tests (4577 assertions), OpenAPI rebuilt from a fresh route manifest (140 paths, 172 operations), scheduled work twice (second run no new actions, `failed_jobs none`), lint and build. Deployed to https://esfa-eta.vercel.app the same night. Still the owner's: rotate secrets, enable scheduled work, clean production demo data, payment-integration items (see HANDOFF.md). See [HANDOFF.md](HANDOFF.md).

## Phase 0: Foundation
- [x] 0.1 Scheduler pause switch respected everywhere + settings fallback + system audit helper (S)
- [x] 0.2 Run state + `GET /automation`, `POST /automation/run` + failed-job alert (M)
- [x] 0.3 Settings > Automation tab (every rule, toggle/threshold, last result, Run now) (M)
- [x] 0.4 Bulk-action pattern (`useBulkAction`, `SelectCell`, `BulkBar`) + filters seeded from the URL (S)
- [x] 0.5 Dashboard "Needs attention" card with links, scoped per role (M)
- [x] Checkpoint 0: backend tests, lint + build, run scheduled work twice, OpenAPI rebuild, 375 px pass

## Phase 1: Support
- [x] 1.1 Ticket status follows replies, customer-reply alert, first reply claims the ticket (M)
- [x] 1.2 Reply and set status in one send (S)
- [x] 1.3 Bulk claim / assign / change status (S)
- [x] 1.4 Release a deactivated agent's tickets + auto-assign sweep (S)
- [x] 1.5 Breach alerts to Admins, low-CSAT alert, close clock respects late replies (S)
- [x] 1.6 Rules off by default: auto-resolve waiting tickets, auto-acknowledge (S)
- [x] 1.7 Auto-link the only payment/shipment on create (S)
- [x] 1.8 Suggested replies/KB chips + refund amount prefill (S)
- [x] Checkpoint 1 (backend tests, migrate, scheduled work twice, lint + build, OpenAPI rebuild; 375 px browser pass not run)

## Phase 2: Payments (staff side only)
- [x] 2.1 "Clear and capture" / "Block and cancel" (M)
- [x] 2.2 COD risk hold instead of delivery deadlock (S)
- [x] 2.3 Auto-capture later-authorized payments + invoice handoff (M)
- [x] 2.4 Finance reminders + refund outcome on the linked ticket (S)
- [x] 2.5 Approve selected refunds (S)
- [x] 2.6 Reconciliation auto-resolve + "Resolve all" (S)
- [x] 2.7 Risk auto-clear rule, off by default (S)
- [x] Checkpoint 2 (backend tests, migrate, scheduled work twice, lint + build, OpenAPI rebuild; 375 px browser pass not run)
- [x] Review 2.1-2.7 (2026-10-11): reconciliation import/resolve now need a finance role too, the risk queue applies its documented `risk_level` filter, finance reminders are all-or-nothing per record with try/catch (3 new PaymentsTest cases)

## Phase 3: Logistics
- [x] 3.1 Bulk status update for selected shipments (S)
- [x] 3.2 Printable shipping labels (M)
- [x] 3.3 Cheapest quote preselected, courier optional (M)
- [x] 3.4 "Awaiting booking" queue with prefilled form (M)
- [x] 3.5 Overdue / stuck shipment detection (M)
- [x] 3.6 Auto delivery ticket on failed attempt (M)
- [x] 3.7 Returns cancel pending COD + hygiene fixes (S)
- [x] 3.8 Order cancel cancels shipments (needs decision 5) (S)
- [x] 3.9 Courier gap-fill rule, off by default (S)
- [x] Checkpoint 3 (backend tests, migrate, scheduled work twice, lint + build, OpenAPI rebuild; 375 px browser pass not run)
- [x] Review of 3.1-3.9 and HANDOFF Task 5 (one lock order: order, payments, shipments; delivery escalation through `TicketService::changePriorityLocked`). Fixed: partially refunded orders stay in Awaiting booking; the ShippingQuote schema uses the real field names; the shipments list shows 50 a page so one bulk action can move 30 parcels (OpenAPI regeneration pending at the checkpoint)

## Phase 4: Billing
- [x] 4.1 Settlement run only for sellers with sales + one digest (S)
- [x] 4.2 Invoice and dunning hygiene (S)
- [x] 4.3 Finalize / record payouts for selected settlements (S)
- [x] 4.4 Auto-finalize rule, off by default (S)
- [x] 4.5 Catch-up settlement periods (S)
- [x] Checkpoint 4 (backend tests, migrate, scheduled work twice, lint + build, OpenAPI rebuild; 375 px browser pass not run)
- [x] Review of 4.1-4.5 (2026-10-11). Fixed: a seller whose scheduled settlement fails now counts in `billing_errors` (it used to be invisible); scheduled generation builds at most 100 drafts per period and run, and the rest follow on the next run (F9); the Payout and CommissionEntry schemas use the real values (`paid`, `capture`, `seller_net_amount`). 3 new BillingTest cases; the contract case passes once the checkpoint regenerates the OpenAPI document

## Phase 5: Platform
- [x] 5.1 "Resend failed (N)" (S)
- [x] 5.2 Webhook endpoint auto-recovery (M)
- [x] 5.3 Token expiry reminders (S)
- [x] 5.4 Sign-in lock alert + "locked" filter (S)
- [x] 5.5 Notification auto-clear + retention (M)
- [x] Checkpoint 5 (backend tests, migrate, scheduled work twice, lint + build, OpenAPI rebuild; 375 px browser pass not run)

## Phase 6: Policy rules (only if decided)
- [x] 6.1 Refund auto-approve under a cap (M)
- [x] 6.2 Courier proof of delivery for prepaid parcels (M) (`logistics_courier_pod`, off by default; LogisticsTest)
- [x] 6.3 Negative settlement carried forward (M) (`settlement_carry_forward`, off by default; migration `2026_10_11_000600_add_recovered_by_settlement_id_to_settlements`; BillingTest; demo STL-2026-000008 (-310) verified on a DB copy)
- [x] 6.4 Free-plan auto-subscribe for new sellers (M) (`default_plan_id`, null = off; SettingsRequest `after()`; `subscriptions_auto_created`; BillingTest, SchedulerTest key)
- [x] 6.5 Risk-review hold window (S)
- [x] Checkpoint 6 (2026-10-11 ~17:05: 278 backend tests, migrate, scheduled work twice, lint + build, OpenAPI rebuild; 375 px browser pass not run)

## Integration Simulator (HANDOFF.md Task 9)
- [x] Simulator frontend: tabbed page per connected system and submodule (`pages/Simulator.jsx`, `lib/simulator.js`, `components/simulator/*`), browser calls with each system's own token or signed webhooks, received-webhook panels, traffic log; "Read financial data of every storefront" checkbox and badge on Integrations. Checked with oxlint only; the browser pass at 375 px is still open
- [x] Simulator backend: `POST /simulator/connect` (12-hour `simulator` tokens for demo-storefront and demo-finance-gl, endpoint secret and courier secret kept, receiver push) and the `GET /simulator` pickers; the in-system storefront endpoints and `/simulator/partner/connect` removed and their coverage rewritten through the integration API; `reads_all_financials` tests (FinanceReadAccessTest); OpenAPI entries in build-openapi.py; INTEGRATION.md "Finance-wide read access"; DECISIONS.md entry. SimulatorTest and FinanceReadAccessTest pass; OpenApiTest passes once the checkpoint regenerates the document
- [x] Simulator review: frontend cross-checked against the integration requests, HMAC (matches PHP hash_hmac), centavo math and Idempotency-Key. Fixed: Connect stuck on "Connecting..." in dev (StrictMode), courier `occurred_at` now on SFA's clock, COD payments left out of gateway events, refund picker only lists refundable payments, order status update guarded, delivery attempts refresh the cached delivery log (the `_` cache-buster filled the database cache every 5 s), simulator tokens raise no expiry reminder, Help text. Browser pass at 375 px: no horizontal overflow on any of the 12 sub-tabs
- [x] Simulator audit: server-computed actions. `GET /simulator` gives every order, payment, ticket and courier-shipment row `actions` and `next_step`, worked out from the services' own rules (`LogisticsService::courierSteps`, `PaymentService::paymentBlock`/`refundableAmount`/`gatewayEvents`, `TicketService` reply/CSAT constants); every simulator form lists only records SFA would accept and offers only the steps SFA allows; "In SFA now" panels (ShipmentProgress, InSfaNow, gateway and settlement panels); Reconnect resends waiting and failed webhooks; signed sends use SFA's clock. SimulatorTest covers every gateway event against every payment status
- [x] Integrations page removed (frontend only). `pages/Integrations.jsx` and `components/admin/IntegrationForms.jsx` deleted (backups in `backups/removed-integrations/`), sidebar item and `/integrations` route removed, `SecretReveal` moved to `components/admin/SecretReveal.jsx`, notification links dropped. Backend endpoints, tests and `docs/openapi.yaml` unchanged; DECISIONS.md entry added
- [x] Simulator audit gap fixes (2026-10-11). Order upsert to `cancelled` follows the cancel rules (`MirrorController::closeOrder`), and a cancelled order stays cancelled (422). "Missing in report" and the simulator settlement file use the same gateway-only captures (`ReconciliationService::gatewayCaptures`, COD left out). Webhook delivery search matches the id only by whole UUID (PostgreSQL-safe). `/simulator/run-scheduled` is throttled. `GET /simulator` adds seller profiles, record links and `quote_cities`, and the storefront forms use them. Final checkpoint: 286 tests, scheduled work twice, lint and build, OpenAPI rebuilt. Still open (owner's payment code): dispute won/lost storefront webhooks and the payment webhook cache flush
