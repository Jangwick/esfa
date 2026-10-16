<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\City;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Rate;
use App\Models\ReconciliationItem;
use App\Models\Refund;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\Ticket;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\LogisticsService;
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use App\Services\Scheduler;
use App\Services\TicketService;
use App\Services\WebhookService;
use Database\Seeders\IntegrationDemoSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

// The Integration Simulator: the browser plays the connected outside systems (storefront, finance ledger, courier,
// payment gateway) against the real integration API and signed webhooks. These endpoints only connect them, list
// pickers, and keep a few stand-ins (the customer's ticket answers on the staff ticket page, the scheduler clock and the
// gateway settlement file). Off in production unless SFA_SIMULATOR=true.
class SimulatorController extends Controller
{
    public const STOREFRONT = 'demo-storefront';
    public const FINANCE = 'demo-finance-gl';
    // What the simulated courier reports (Pickup and Trip progress). It never books, readies or cancels a parcel, and
    // delivered needs proof of delivery, which staff record.
    private const COURIER_REPORTS = ['picked_up', 'in_transit', 'out_for_delivery', 'failed_attempt', 'returned_to_sender'];
    // The storefront's own order states for its status update. SFA keeps the latest as status_snapshot; cancelled is added
    // per order only while SFA would accept it (MirrorController::closeOrder, the same rule as the cancel call).
    private const ORDER_STATUSES = ['placed', 'processing', 'packed', 'shipped', 'delivered', 'completed'];

    private function guard(Request $request): void
    {
        abort_unless(config('sfa.simulator'), 404);
        abort_unless($request->user()->hasRole('Admin'), 403, 'This action requires the Admin role.');
    }

    // Pickers for the simulator forms: the demo storefront's own records (newest 50 each, by external id), every
    // storefront's sellers for the finance system, and the parcels the first active courier has not finished.
    // Each order, payment, ticket and courier parcel row carries "actions": what the simulator may send for it right now,
    // worked out with the services' own rules so a form never offers what SFA refuses. When there is none, "next_step"
    // says what to do in SFA first (otherwise it is null).
    public function index(Request $request, PaymentService $payments, LogisticsService $logistics)
    {
        $this->guard($request);
        $own = fn ($query) => $query->where('source_system', self::STOREFRONT);
        $courier = Courier::where('is_active', true)->orderBy('id')->first();
        // id lets the simulator show the shipment's live progress from the staff API (GET /logistics/shipments/{id}); the
        // order and seller let a help-center ticket name the parcel it is about.
        $shipment = fn (Shipment $row) => $row->only(['id', 'reference', 'tracking_number', 'status']) + ['order_external_id' => $row->order->external_id, 'seller_external_id' => $row->seller->external_id];
        $offer = fn (array $actions, string $step) => ['actions' => $actions, 'next_step' => $actions ? null : $step];
        // The cities a shipping quote can be asked between: those in either zone of a rate LogisticsService::quotes would
        // use (active rate, courier and both zones, and a service the courier still offers).
        $zones = Rate::with(['courier', 'originZone', 'destinationZone'])->where('is_active', true)->get()
            ->filter(fn (Rate $rate) => $rate->courier->is_active && $rate->originZone->is_active && $rate->destinationZone->is_active && in_array($rate->service_type, $rate->courier->service_types, true))
            ->flatMap(fn (Rate $rate) => [$rate->originZone, $rate->destinationZone]);

        return response()->json([
            'partner_url' => config('sfa.partner_url'),
            'storefront' => [
                'customers' => Customer::where('source_system', self::STOREFRONT)->latest('id')->limit(50)->get()->map->only(['external_id', 'name', 'email', 'phone']),
                // The payout destination as staff see it (masked) and its bank or e-wallet, so a send shows which one SFA holds.
                'sellers' => Seller::where('source_system', self::STOREFRONT)->latest('id')->limit(50)->get()
                    ->map(fn (Seller $seller) => $seller->only(['external_id', 'shop_name', 'owner_name', 'email', 'phone', 'payout_destination_masked']) + ['payout_channel' => $seller->payout_destination['bank_name'] ?? null]),
                'categories' => $this->categories(),
                // Items come back in the PUT /integrations/orders shape, so a status update can resend the same order.
                'orders' => Order::where('source_system', self::STOREFRONT)->with(['customer', 'items.seller', 'items.category', 'payments', 'shipments'])->latest('id')->limit(50)->get()
                    ->map(function (Order $order) use ($payments, $offer) {
                        $open = $order->status_snapshot !== 'cancelled';
                        // MirrorController::closeOrder, for the cancel call and a status update to cancelled alike: no captured
                        // money, and no parcel already with the courier.
                        $cancellable = $open && ! $order->payments->contains(fn (Payment $payment) => $payment->captured_amount > 0)
                            && $order->shipments->every(fn (Shipment $row) => in_array($row->status, LogisticsService::ORDER_CANCEL_ALLOWS, true));
                        $actions = array_keys(array_filter([
                            // SFA refuses to reopen a cancelled order, so it takes no status update.
                            'status_update' => $open,
                            'cancel' => $cancellable,
                            // PaymentService::create, except while a charge is still open: SFA would add a second one beside it.
                            'payment' => ! $payments->paymentBlock($order) && ! $order->payments->contains(fn (Payment $payment) => in_array($payment->status, ['pending', 'authorized'], true)),
                        ]));

                        return $order->only(['external_id', 'status_snapshot', 'subtotal_amount', 'shipping_amount', 'discount_amount', 'total_amount', 'placed_at']) + [
                            'customer' => $order->customer->only(['external_id', 'name']),
                            'items' => $order->items->map(fn (OrderItem $item) => ['seller_external_id' => $item->seller->external_id, 'category_external_id' => $item->category?->external_id] + $item->only(['product_name', 'sku', 'quantity', 'unit_price_amount', 'line_total_amount'])),
                            'payments' => $order->payments->map->only(['reference', 'status', 'method']),
                            // The new statuses a status update may send now.
                            'statuses' => $open ? array_values(array_diff([...self::ORDER_STATUSES, ...($cancellable ? ['cancelled'] : [])], [$order->status_snapshot])) : [],
                        ] + $offer($actions, 'This order is cancelled, so SFA takes nothing more for it. Send a new order instead.');
                    }),
                'payments' => Payment::whereHas('order', $own)->with(['order.payments', 'refunds'])->latest('id')->limit(50)->get()
                    ->map(function (Payment $payment) use ($payments, $offer) {
                        $events = $payments->gatewayEvents($payment);
                        // SFA would still authorize or capture a payment of a cancelled order, but never does so itself.
                        if ($payment->order->status_snapshot === 'cancelled') { $events = array_values(array_diff($events, ['payment.authorized', 'payment.captured'])); }
                        $refundable = $payments->refundableAmount($payment);
                        $actions = [...$events, ...($refundable > 0 ? ['refund'] : [])];

                        return $payment->only(['id', 'reference', 'status', 'method', 'amount', 'total_amount', 'captured_amount', 'refunded_amount', 'risk_level', 'expires_at'])
                            + ['order_external_id' => $payment->order->external_id, 'refundable_amount' => $refundable] + $offer($actions, $this->paymentStep($payment));
                    }),
                'refunds' => Refund::whereHas('payment.order', $own)->with('payment')->latest('id')->limit(50)->get()
                    ->map(fn (Refund $refund) => $refund->only(['id', 'reference', 'status', 'total_amount']) + ['payment_reference' => $refund->payment->reference]),
                // id and csat_score let the simulator show the ticket as SFA holds it (GET /support/tickets/{id}); the linked
                // order and payment let a refund request name its ticket.
                'tickets' => Ticket::where('source_system', self::STOREFRONT)->with(['order', 'payment'])->latest('id')->limit(50)->get()
                    ->map(fn (Ticket $ticket) => $ticket->only(['id', 'reference', 'subject', 'status', 'csat_score', 'csat_comment'])
                        + ['order_external_id' => $ticket->order?->external_id, 'payment_reference' => $ticket->payment?->reference] + $offer(array_keys(array_filter([
                        'reply' => ! in_array($ticket->status, TicketService::REPLY_BLOCKED_STATUSES, true),
                        'csat' => in_array($ticket->status, TicketService::CSAT_STATUSES, true),
                    ])), 'Change the ticket status in Support first.')),
                'shipments' => Shipment::whereHas('order', $own)->with(['order', 'seller'])->latest('id')->limit(50)->get()->map($shipment),
            ],
            'quote_cities' => City::whereIn('id', $zones->pluck('city_ids')->flatten()->unique()->values())->orWhereIn('province_id', $zones->pluck('province_ids')->flatten()->unique()->values())
                ->orderBy('name')->get()->map->only(['code', 'name']),
            'finance_sellers' => Seller::latest('id')->limit(100)->get()->map->only(['external_id', 'shop_name', 'source_system']),
            'courier' => $courier ? ['code' => $courier->code] : null,
            // Every parcel the courier has not finished, so one still waiting in SFA shows with its next step.
            'courier_shipments' => $courier ? Shipment::where('courier_id', $courier->id)->whereNotIn('status', Shipment::FINAL_STATUSES)->latest('id')->limit(50)->get()
                ->map(fn (Shipment $row) => $row->only(['id', 'reference', 'tracking_number', 'status', 'attempt_count'])
                    + $offer(array_values(array_intersect($logistics->courierSteps($row), self::COURIER_REPORTS)), $this->courierStep($row))) : [],
        ]);
    }

    // The newest 50 storefront categories, each with its parent and the listed categories SFA takes as its new parent:
    // MirrorController::category refuses the category itself and anything below it (a loop).
    // ponytail: reads every storefront category to walk the tree; a recursive query if a catalog grows to thousands.
    private function categories(): array
    {
        $all = Category::where('source_system', self::STOREFRONT)->get(['id', 'external_id', 'name', 'parent_id']);
        $children = $all->groupBy('parent_id');
        $listed = $all->sortByDesc('id')->take(50)->values();

        return $listed->map(function (Category $category) use ($all, $children, $listed) {
            $blocked = [$category->id];
            for ($i = 0; $i < count($blocked); $i++) {
                array_push($blocked, ...($children[$blocked[$i]] ?? collect())->pluck('id'));
            }

            return $category->only(['external_id', 'name']) + [
                'parent_external_id' => $all->firstWhere('id', $category->parent_id)?->external_id,
                'parent_choices' => $listed->whereNotIn('id', $blocked)->pluck('external_id')->values()->all(),
            ];
        })->all();
    }

    private function paymentStep(Payment $payment): string
    {
        return match (true) {
            $payment->gateway === 'cod' && $payment->status === 'pending' => 'Cash on delivery: SFA captures it when Logistics & Locations records the delivery with proof and the cash collected.',
            in_array($payment->status, PaymentService::REFUNDABLE_STATUSES, true) => 'Refunds already requested hold the rest. Finance approves or rejects them in Payments & Transactions > Refunds.',
            default => 'This payment is '.str_replace('_', ' ', $payment->status).', so SFA takes no gateway result or refund for it.',
        };
    }

    private function courierStep(Shipment $shipment): string
    {
        if (! $shipment->tracking_number) {
            return 'Add the courier tracking number in Logistics & Locations (shipment details, Edit shipment)'.($shipment->status === 'pending' ? ', then mark it Ready for pickup.' : '.');
        }

        return $shipment->status === 'pending' ? 'Mark it Ready for pickup in Logistics & Locations.' : 'SFA takes no courier update for this parcel right now.';
    }

    // The ticket's storefront, standing in for the integration client that would deliver what the customer types there.
    // It is never saved; it only lets the customer-side ticket paths run as they do for a connected storefront.
    private function storefront(Ticket $ticket): IntegrationClient
    {
        return new IntegrationClient(['name' => 'Process simulator', 'system_code' => $ticket->source_system]);
    }

    // The customer answers a ticket from the storefront.
    public function ticketReply(Request $request, Ticket $ticket, TicketService $tickets)
    {
        $this->guard($request);
        $values = $request->validate(['body' => 'required|string|max:20000']);

        return response()->json($tickets->message($ticket, $values, [], $this->storefront($ticket)), 201);
    }

    // The customer rates the help they got (only after the ticket is resolved or closed).
    public function ticketRating(Request $request, Ticket $ticket, TicketService $tickets)
    {
        $this->guard($request);
        $values = $request->validate(['score' => 'required|integer|min:1|max:5', 'comment' => 'nullable|string|max:2000']);

        return response()->json($tickets->csat($ticket, $values, $this->storefront($ticket)));
    }

    // Connects the simulated systems. The demo storefront and the demo finance ledger (read-only, every storefront's
    // financials) each get a fresh 12-hour "simulator" token with their seeded scopes, the storefront's webhook endpoint
    // at the partner receiver is (re)activated with its existing secret, and the first active courier keeps or gets a
    // signing secret. The tokens and secrets go to the Admin's browser, which calls the real integration API and signs
    // the courier and gateway webhooks with them; this route is 404 unless simulator mode is on, so that happens only on
    // test servers. The receiver (tools/partner-simulator) gets the same storefront configuration, best effort. When it
    // answers, the webhooks that waited for it go out again.
    public function connect(Request $request, WebhookService $webhooks)
    {
        $this->guard($request);
        $partner = rtrim((string) config('sfa.partner_url'), '/');
        $expiresAt = now()->addHours(12);
        [$systems, $endpoint, $courier] = DB::transaction(function () use ($partner, $expiresAt) {
            $systems = $clients = [];
            foreach (['storefront' => self::STOREFRONT, 'finance' => self::FINANCE] as $key => $code) {
                [$name, $scopes] = IntegrationDemoSeeder::CLIENTS[$code];
                $client = IntegrationClient::firstOrCreate(['system_code' => $code], ['name' => $name, 'contact_email' => $code.'@example.com']);
                $client->update(['is_active' => true] + ($code === self::FINANCE ? ['reads_all_financials' => true] : []));
                $client->tokens()->where('name', 'simulator')->delete();
                $systems[$key] = ['client_code' => $code, 'token' => $client->createToken('simulator', $scopes, $expiresAt)->plainTextToken, 'scopes' => $scopes];
                $clients[$key] = $client;
            }
            $endpoint = WebhookEndpoint::firstOrNew(['client_id' => $clients['storefront']->id, 'url' => $partner.'/webhooks']);
            $endpoint->fill(['events' => WebhookEndpoint::EVENTS, 'is_active' => true, 'consecutive_failures' => 0, 'disabled_at' => null]);
            if (! $endpoint->secret) {
                $endpoint->secret = 'whsec_'.Str::random(64);
            }
            $endpoint->save();
            $courier = Courier::where('is_active', true)->orderBy('id')->lockForUpdate()->first();
            if ($courier && ! $courier->webhook_secret) {
                $courier->update(['webhook_secret' => 'courier_'.Str::random(64)]);
            }

            return [$systems, $endpoint, $courier];
        });
        $gatewaySecret = config('payments.sandbox_webhook_secret') ?: null;
        $payload = array_filter(['sfa_api_url' => url('/api/v1'), 'client_code' => self::STOREFRONT, 'token' => $systems['storefront']['token'], 'webhook_secret' => $endpoint->secret, 'courier_code' => $courier?->code, 'courier_secret' => $courier?->webhook_secret, 'gateway_secret' => $gatewaySecret]);
        // Free this request's pooler slot while the receiver answers: the self-hosted one (/api/v1/simrx) needs its own
        // connection. The next query reconnects. PostgreSQL only (an in-memory SQLite database would be lost).
        if (DB::getDriverName() === 'pgsql' && DB::transactionLevel() === 0) {
            DB::disconnect();
        }
        try {
            $online = Http::timeout(10)->acceptJson()->post($partner.'/api/setup', $payload)->successful();
        } catch (Throwable) {
            $online = false;
        }
        if ($online) {
            // Deliveries backing off become due and failed ones are queued again; the first 20 go once this response is
            // done (as after any event in simulator mode), the rest with the next scheduled run.
            WebhookDelivery::where('endpoint_id', $endpoint->id)->where('status', 'pending')->update(['next_attempt_at' => now()]);
            $webhooks->requeueFailed($endpoint->id);
            $due = WebhookDelivery::where('endpoint_id', $endpoint->id)->where('status', 'pending')->orderBy('created_at')->limit(20)->get();
            app()->terminating(function () use ($due, $webhooks) { foreach ($due as $delivery) { $webhooks->deliver($delivery); } });
        }

        return response()->json([
            'expires_at' => $expiresAt,
            'receiver' => ['url' => $partner, 'online' => $online],
            'webhook_endpoint_id' => $endpoint->id,
            'systems' => $systems,
            'courier' => $courier ? ['code' => $courier->code, 'secret' => $courier->webhook_secret] : null,
            'gateway' => $gatewaySecret ? ['secret' => $gatewaySecret] : null,
        ]);
    }

    // Runs the scheduled work now (renewals and retries, settlement generation, SLA alerts, auto-close, webhook retries)
    // instead of waiting for the next automatic run.
    public function runScheduled(Request $request, Scheduler $scheduler)
    {
        $this->guard($request);

        return response()->json($scheduler->run());
    }

    // A gateway settlement report for the Reconciliation tab, built from the payments the gateway captured in the period
    // (cash on delivery is collected by the courier, so the gateway never reports it). It is deliberately imperfect so
    // every reconciliation outcome shows up: the second payment is reported ₱1.00 short (half short when that would
    // leave nothing, so the amount stays positive), the third is left out (missing in report) and one unknown reference
    // is added (missing in SFA).
    public function settlementFile(Request $request)
    {
        $this->guard($request);
        $values = $request->validate(['date_from' => 'required|date_format:Y-m-d', 'date_to' => 'required|date_format:Y-m-d|after_or_equal:date_from']);
        $zone = Setting::valueFor('business_timezone', 'Asia/Manila');
        // The same payments the import expects, so the file and "missing in report" never disagree.
        $captured = app(ReconciliationService::class)->gatewayCaptures($values['date_from'], $values['date_to'])->orderBy('captured_at')->get();
        if ($captured->isEmpty()) {
            throw ValidationException::withMessages(['date_from' => ['No gateway payments were captured in this period.']]);
        }
        $pesos = fn (int $amount) => sprintf('%d.%02d', intdiv($amount, 100), $amount % 100);
        $rows = [['date', 'gateway_reference', 'amount', 'fee']];
        // The omitted payment is reported late: once an import has listed it as missing, later files include it, so
        // importing an overlapping file resolves that row automatically.
        $reportedMissing = ReconciliationItem::where('status', 'missing_in_report')->whereIn('payment_id', $captured->pluck('id'))->pluck('payment_id')->all();
        foreach ($captured as $index => $payment) {
            if ($index === 2 && ! in_array($payment->id, $reportedMissing)) {
                continue;
            }
            $short = $index === 1 ? min(100, intdiv($payment->captured_amount, 2)) : 0;
            $rows[] = [$payment->captured_at->timezone($zone)->toDateString(), $payment->gateway_reference, $pesos($payment->captured_amount - $short), $pesos((int) $payment->fee_amount)];
        }
        $rows[] = [$values['date_to'], 'sandbox_UNKNOWN-'.Str::upper(Str::random(6)), '150.00', '3.75'];
        $csv = implode("\n", array_map(fn (array $row) => implode(',', $row), $rows))."\n";

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="sandbox-settlement-'.$values['date_from'].'-to-'.$values['date_to'].'.csv"']);
    }

    // Stand-in partner receiver (unauthenticated, signature-verified): a hosted SFA can point SFA_PARTNER_URL at its
    // own /api/v1/simrx so the Connection bar and webhook demo work where tools/partner-simulator cannot run. The
    // storefront browser flow still prefers the real partner tool when SFA_PARTNER_URL is left alone.
    public function simrxSetup(Request $request)
    {
        abort_unless(config('sfa.simulator'), 404);
        Setting::updateOrCreate(['key' => 'simrx.config'], ['value' => $request->validate([
            'sfa_api_url' => 'required|string|max:200', 'client_code' => 'required|string|max:50', 'token' => 'required|string|max:200',
            'webhook_secret' => 'required|string|max:120', 'courier_code' => 'nullable|string|max:50', 'courier_secret' => 'nullable|string|max:120', 'gateway_secret' => 'nullable|string|max:120',
        ])]);

        return response()->json(['ok' => true]);
    }

    public function simrxWebhook(Request $request, WebhookService $webhooks)
    {
        abort_unless(config('sfa.simulator'), 404);
        $secret = Setting::valueFor('simrx.config')['webhook_secret'] ?? null;
        abort_unless($secret, 409, 'Connect the simulator first so the receiver has a signing secret.');
        $signature = (string) $request->header('X-SFA-Signature');
        preg_match('/t=(\d+),v1=([a-f0-9]+)/', $signature, $m);
        abort_unless($m && abs(now()->timestamp - (int) $m[1]) <= 300, 401, 'Bad or stale webhook signature.');
        $expected = $webhooks->signature((int) $m[1], $request->getContent(), $secret);
        abort_unless(hash_equals(substr($expected, strpos($expected, 'v1=') + 3), $m[2]), 401, 'Bad webhook signature.');
        // Ring of the last deliveries so the receiver page can show what arrived.
        $events = Setting::valueFor('simrx.events', []);
        array_unshift($events, ['at' => now()->toISOString(), 'event' => $request->header('X-SFA-Event'), 'delivery' => $request->header('X-SFA-Delivery'), 'payload' => $request->json()->all()]);
        Setting::updateOrCreate(['key' => 'simrx.events'], ['value' => array_slice($events, 0, 30)]);

        return response()->json(['ok' => true]);
    }

    public function simrxIndex(Request $request)
    {
        $this->guard($request);

        return response()->json(['configured' => (bool) Setting::valueFor('simrx.config'), 'events' => Setting::valueFor('simrx.events', [])]);
    }

    // What "Open the partner app" links to on hosted setups: a read-only page over the stand-in receiver's ring.
    public function simrxPage()
    {
        abort_unless(config('sfa.simulator'), 404);
        $configured = (bool) Setting::valueFor('simrx.config');
        $rows = collect(Setting::valueFor('simrx.events', []))->map(fn ($e) => '<tr><td>'.e($e['at'] ?? '').'</td><td>'.e($e['event'] ?? '').'</td><td><code>'.e(mb_substr(json_encode($e['payload'] ?? ''), 0, 300)).'</code></td></tr>')->implode('');
        $body = $configured ? ($rows ?: '<tr><td colspan="3">Connected — nothing received yet.</td></tr>') : '<tr><td colspan="3">Not connected yet. Connect on the Process Simulator page first.</td></tr>';

        return response('<!doctype html><html><head><meta charset="utf-8"><title>SFA partner receiver</title><style>body{font-family:system-ui,sans-serif;margin:2rem;color:#1e293b}table{border-collapse:collapse;width:100%}td,th{border:1px solid #cbd5e1;padding:.4rem .6rem;font-size:.85rem;text-align:left;vertical-align:top}code{font-size:.78rem;word-break:break-all}</style></head><body><h1>Partner webhook receiver</h1><p>Stand-in receiver built into SFA for hosted demos (tools/partner-simulator is the fuller local version). Newest first.</p><table><thead><tr><th>Received</th><th>Event</th><th>Payload</th></tr></thead><tbody>'.$body.'</tbody></table></body></html>');
    }
}
