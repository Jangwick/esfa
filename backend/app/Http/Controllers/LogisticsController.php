<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListRequest;
use App\Http\Requests\LogisticsRequest;
use App\Http\Requests\QuoteRequest;
use App\Models\Attachment;
use App\Models\Courier;
use App\Models\IntegrationClient;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\Warehouse;
use App\Services\AttachmentService;
use App\Services\LogisticsService;
use App\Services\TicketService;
use App\Support\ListResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LogisticsController extends Controller
{
    public function index(ListRequest $request)
    {
        $this->requirePermission('view shipments');
        $request->validate(['status' => ['nullable', Rule::in(Shipment::STATUSES)], 'courier_id' => 'nullable|integer|exists:couriers,id', 'seller_id' => 'nullable|integer|exists:sellers,id', 'overdue' => 'nullable|boolean']);
        $query = $this->visible($request)->with(['order', 'seller', 'courier', 'destinationAddress.city']);
        foreach (['status', 'courier_id', 'seller_id'] as $field) { if ($request->filled($field)) { $query->where($field, $request->input($field)); } }
        if ($request->boolean('overdue')) { $query->overdue(); }
        if ($request->filled('search')) { $term = '%'.mb_strtolower($request->search).'%'; $query->where(fn ($q) => $q->whereRaw('LOWER(reference) LIKE ?', [$term])->orWhereRaw('LOWER(tracking_number) LIKE ?', [$term])->orWhereHas('seller', fn ($s) => $s->whereRaw('LOWER(shop_name) LIKE ?', [$term]))); }
        $query = ListResponse::sort(ListResponse::dates($query, $request), $request, ['created_at', 'reference', 'status', 'promised_to']);
        $zone = Setting::valueFor('business_timezone', 'Asia/Manila'); $today = CarbonImmutable::now($zone)->startOfDay()->utc();
        $recent = (clone $query)->where('status', 'delivered')->where('delivered_at', '>=', CarbonImmutable::now($zone)->subDays(30)->startOfDay()->utc())->get();
        $summary = ['to_ship' => (clone $query)->whereIn('status', ['pending', 'ready_for_pickup'])->count(), 'in_transit' => (clone $query)->whereIn('status', ['picked_up', 'in_transit'])->count(), 'out_for_delivery' => (clone $query)->where('status', 'out_for_delivery')->count(), 'failed_attempts' => (clone $query)->where('status', 'failed_attempt')->count(), 'overdue' => (clone $query)->overdue()->count(), 'delivered_today' => (clone $query)->where('delivered_at', '>=', $today)->count(), 'on_time_rate' => $recent->count() ? round($recent->filter(fn ($shipment) => $shipment->on_time)->count() * 100 / $recent->count(), 1) : null];
        if ($request->export === 'csv') { return ListResponse::csv($query, 'shipments.csv', ['Reference', 'Order', 'Seller', 'Courier', 'Service', 'Tracking number', 'Status', 'Weight grams', 'Shipping fee centavos', 'COD centavos', 'Promised from', 'Promised to', 'Delivered at UTC', 'Failed attempts'], fn ($s) => [$s->reference, $s->order?->external_id, $s->seller?->shop_name, $s->courier?->name, $s->service_type, $s->tracking_number, $s->status, $s->weight_grams, $s->shipping_fee_amount, $s->cod_amount, $s->promised_from?->format('Y-m-d'), $s->promised_to?->format('Y-m-d'), $s->delivered_at?->toIso8601String(), $s->attempt_count]); }
        return ListResponse::paginate($query, $request, $summary);
    }

    /** Paid (or pending COD) order and seller pairs with no shipment yet, each with suggested booking fields. */
    public function awaitingBooking(ListRequest $request, LogisticsService $service)
    {
        $this->requirePermission('create shipments');
        $query = ListResponse::dates($service->awaitingBooking($request->user())->with('customer'), $request, 'orders.placed_at');
        if ($request->filled('search')) { $term = '%'.mb_strtolower($request->search).'%'; $query->where(fn ($q) => $q->whereRaw('LOWER(orders.external_id) LIKE ?', [$term])->orWhereRaw('LOWER(pairs.seller_name) LIKE ?', [$term])->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$term]))); }
        $summary = ['awaiting_booking' => (clone $query)->count()];
        $query = ListResponse::sort($query, $request, ['created_at', 'placed_at', 'external_id'])->orderBy('pairs.seller_id');
        if ($request->export === 'csv') { return ListResponse::csv($query, 'awaiting-booking.csv', ['Order', 'Source system', 'Customer', 'Seller', 'Placed at UTC', 'Order total centavos'], fn ($o) => [$o->external_id, $o->source_system, $o->customer?->name, $o->seller_name, $o->placed_at?->toIso8601String(), $o->total_amount]); }
        $page = $query->paginate($request->perPage())->withQueryString(); $rows = $page->getCollection();
        // Suggestions: the seller's pickup point (else the first active hub), the pending COD amount when the order has a
        // single seller (null when it must be split), and the customer's last delivery address.
        $warehouses = Warehouse::where('is_active', true)->orderBy('id')->get(['id', 'type', 'seller_id']);
        $cod = Payment::whereIn('order_id', $rows->pluck('id'))->where('method', 'cod')->where('status', 'pending')->get()->keyBy('order_id');
        $sellers = OrderItem::whereIn('order_id', $rows->pluck('id'))->groupBy('order_id')->selectRaw('order_id, COUNT(DISTINCT seller_id) as sellers')->pluck('sellers', 'order_id');
        $latest = Shipment::join('orders', 'orders.id', '=', 'shipments.order_id')->whereIn('orders.customer_id', $rows->pluck('customer_id'))->groupBy('orders.customer_id')->selectRaw('orders.customer_id, MAX(shipments.id) as shipment_id')->pluck('shipment_id', 'customer_id');
        $addresses = Shipment::with('destinationAddress')->whereIn('id', $latest->values())->get()->keyBy('id');
        $rows->each(function ($row) use ($warehouses, $cod, $sellers, $latest, $addresses) {
            $payment = $cod->get($row->id);
            $warehouse = $warehouses->first(fn ($w) => $w->type === 'seller_pickup' && (int) $w->seller_id === (int) $row->seller_id) ?? $warehouses->firstWhere('type', 'platform_hub');
            $address = $addresses->get($latest->get($row->customer_id, 0))?->destinationAddress;
            $row->setAttribute('suggested', ['origin_warehouse_id' => $warehouse?->id, 'cod_amount' => ! $payment ? 0 : ((int) $sellers->get($row->id) === 1 ? $payment->total_amount : null), 'destination' => $address?->only(['line1', 'line2', 'region_id', 'province_id', 'city_id', 'barangay_id', 'postal_code', 'latitude', 'longitude', 'contact_name', 'contact_phone'])]);
        });
        return response()->json(array_merge($page->toArray(), ['summary' => $summary]));
    }

    public function show(Request $request, Shipment $shipment)
    {
        $this->requirePermission('view shipments');
        abort_unless($this->visible($request)->whereKey($shipment->id)->exists(), 404);
        // The newest ticket about this shipment that this user may open, such as the automatic delivery ticket.
        $ticket = $request->user()->can('view tickets') ? rescue(fn () => app(TicketService::class)->visibleQuery($request->user())->where('shipment_id', $shipment->id)->latest('id')->first(), null, false) : null;
        return response()->json($shipment->load(LogisticsService::RELATIONS)->setAttribute('related_ticket', $ticket?->only(['id', 'reference', 'status'])));
    }

    public function store(LogisticsRequest $request, LogisticsService $service)
    {
        $this->requirePermission('create shipments');
        $shipment = $service->create($request->validated(), $request->user());
        $this->audit($request, $shipment);
        return response()->json($shipment, 201);
    }

    public function update(LogisticsRequest $request, Shipment $shipment, LogisticsService $service)
    {
        $this->requirePermission('edit shipments');
        abort_unless($service->visibleQuery($request->user())->whereKey($shipment->id)->exists(), 404);
        $values = $request->validated();
        return DB::transaction(function () use ($request, $shipment, $values, $service) {
            $shipment = $service->lockForMutation($shipment);
            if (! in_array($shipment->status, ['pending', 'ready_for_pickup'], true)) { throw ValidationException::withMessages(['shipment' => ['Only pending or ready-for-pickup shipments can be edited.']]); }
            if ((int) ($values['order_id'] ?? 0) !== $shipment->order_id || (int) ($values['seller_id'] ?? 0) !== $shipment->seller_id) { throw ValidationException::withMessages(['order_id' => ['The order and seller cannot be changed after a shipment is created.']]); }
            if ((int) $values['cod_amount'] !== $shipment->cod_amount) { throw ValidationException::withMessages(['cod_amount' => ['The COD amount cannot be changed after shipment creation.']]); }
            $values['courier_id'] ??= $shipment->courier_id; $values['service_type'] ??= $shipment->service_type;
            $destination = $shipment->destinationAddress;
            $addressUnchanged = collect(['line1', 'line2', 'region_id', 'province_id', 'city_id', 'barangay_id', 'postal_code', 'latitude', 'longitude', 'contact_name', 'contact_phone'])
                ->every(fn ($field) => (string) ($values['destination'][$field] ?? '') === (string) ($destination->$field ?? ''));
            $bookingUnchanged = (int) $values['origin_warehouse_id'] === $shipment->origin_warehouse_id && (int) $values['weight_grams'] === $shipment->weight_grams
                && $values['dimensions'] == $shipment->dimensions && (int) $values['courier_id'] === $shipment->courier_id
                && $values['service_type'] === $shipment->service_type && $addressUnchanged;
            // Updating only tracking keeps the booking's price and promises, even when its original rate is unavailable.
            if ($bookingUnchanged) {
                $request->attributes->set('audit.old_values', $shipment->toArray());
                $shipment->update(['tracking_number' => $values['tracking_number'] ?? null]);
                $this->audit($request, $shipment);
                return response()->json($shipment->fresh(LogisticsService::RELATIONS));
            }
            $warehouse = Warehouse::with('address')->findOrFail($values['origin_warehouse_id']);
            if (! $warehouse->is_active || ($warehouse->type === 'seller_pickup' && $warehouse->seller_id !== $shipment->seller_id)) { throw ValidationException::withMessages(['origin_warehouse_id' => ['Choose an active pickup point belonging to this seller.']]); }
            $address = $service->address($values['destination']);
            // Without a courier the shipment keeps its current courier and service.
            $quote = collect($service->quotes(['origin_city_id' => $warehouse->address->city_id, 'destination_city_id' => $address->city_id, 'weight_grams' => $values['weight_grams'], 'dimensions' => $values['dimensions'], 'cod_amount' => $values['cod_amount']]))->first(fn ($q) => $q['courier_id'] === (int) $values['courier_id'] && $q['service_type'] === $values['service_type'] && (! isset($values['rate_id']) || $q['rate_id'] === (int) $values['rate_id']));
            if (! $quote) { throw ValidationException::withMessages(['courier_id' => ['No active shipping rate covers this route.']]); }
            $request->attributes->set('audit.old_values', $shipment->toArray());
            $shipment->update(['courier_id' => $values['courier_id'], 'service_type' => $values['service_type'], 'origin_warehouse_id' => $warehouse->id, 'destination_address_id' => $address->id, 'weight_grams' => $values['weight_grams'], 'dimensions' => $values['dimensions'], 'tracking_number' => $values['tracking_number'] ?? null, 'shipping_fee_amount' => $quote['total_amount'], 'promised_from' => $quote['eta_from'], 'promised_to' => $quote['eta_to']]);
            $this->audit($request, $shipment);
            return response()->json($shipment->fresh(LogisticsService::RELATIONS));
        });
    }

    public function event(Request $request, Shipment $shipment, LogisticsService $service)
    {
        $this->requirePermission('edit shipments');
        abort_unless($service->visibleQuery($request->user())->whereKey($shipment->id)->exists(), 404);
        $values = $request->validate(['status' => ['required', Rule::in(Shipment::STATUSES)], 'location' => 'nullable|string|max:255', 'note' => 'nullable|string|max:10000', 'occurred_at' => 'nullable|date|before_or_equal:now']);
        $request->attributes->set('audit.old_values', $shipment->toArray());
        $shipment = $service->transition($shipment, array_filter($values, fn ($value) => $value !== null), 'manual', $request->user());
        $this->audit($request, $shipment);
        return response()->json($shipment);
    }

    public function proof(Request $request, Shipment $shipment, LogisticsService $service)
    {
        $this->requirePermission('edit shipments');
        abort_unless($service->visibleQuery($request->user())->whereKey($shipment->id)->exists(), 404);
        $values = $request->validate(['recipient_name' => 'required|string|max:255', 'photo' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:4096', 'note' => 'nullable|string|max:10000', 'cash_collected' => 'sometimes|boolean']);
        $request->attributes->set('audit.old_values', $shipment->toArray());
        $shipment = $service->proof($shipment, $request->file('photo'), $values, $request->user());
        $this->audit($request, $shipment);
        return response()->json($shipment);
    }

    public function proofDownload(Request $request, Shipment $shipment, AttachmentService $service)
    {
        $this->requirePermission('view shipments');
        abort_unless($shipment->proof_attachment_id && $this->visible($request)->whereKey($shipment->id)->exists(), 404);
        return $service->download(Attachment::findOrFail($shipment->proof_attachment_id));
    }

    // A quote is a calculation, not a change: POST only carries the parcel details, so it must not land in the audit log.
    public function quotes(QuoteRequest $request, LogisticsService $service) { $request->attributes->set('audit.skip', true); return response()->json(['data' => $service->quotes($request->validated())]); }

    // AI address fill for the shipment and warehouse forms: the AI reads the pasted text and SFA matches its own location
    // ids. Nothing is saved; the request is kept in ai_insights instead of the audit log.
    public function parseAddress(Request $request, LogisticsService $service)
    {
        abort_unless($request->user()->canAny(['create shipments', 'edit shipments', 'create warehouses', 'edit warehouses']), 403, 'This action requires create shipments.');
        $data = $request->validate(['text' => 'required|string|min:10|max:1000']);
        $request->attributes->set('audit.skip', true);

        return response()->json($service->parseAddress($data['text'], $request->user()));
    }

    public function options(Request $request)
    {
        $this->requirePermission('view shipments');
        $user = $request->user();
        if (($user->can('create shipments') || $user->can('edit shipments')) && (! $user->hasRole('Support Agent') || $user->hasAnyRole(['Admin', 'Finance', 'Logistics Coordinator']))) {
            $orders = Order::with('items')->where('status_snapshot', '!=', 'cancelled')->orderByDesc('id')->limit(200)->get(); $sellers = Seller::orderBy('shop_name')->get();
        } else {
            $visible = $this->visible($request);
            $orders = Order::with('items')->whereIn('id', (clone $visible)->select('order_id'))->orderByDesc('id')->get(); $sellers = Seller::whereIn('id', (clone $visible)->select('seller_id'))->orderBy('shop_name')->get();
        }
        return response()->json(['orders' => $orders, 'sellers' => $sellers, 'couriers' => Courier::orderBy('name')->get(), 'warehouses' => Warehouse::with(['address.region', 'address.province', 'address.city', 'address.barangay'])->orderBy('name')->get()]);
    }

    /** Support Agents read only shipments linked to their own or unassigned tickets (directly or through the order). */
    private function visible(Request $request)
    {
        return app(LogisticsService::class)->visibleQuery($request->user());
    }

    private function audit(Request $request, Shipment $shipment): void { $request->attributes->set('audit.entity_type', 'Shipment'); $request->attributes->set('audit.entity_id', $shipment->id); $request->attributes->set('audit.new_values', $shipment->toArray()); }
}
