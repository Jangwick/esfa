<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\City;
use App\Models\Courier;
use App\Models\IntegrationClient;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Province;
use App\Models\Rate;
use App\Models\Region;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Money;
use App\Support\Reference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LogisticsService
{
    public const DELIVERY_REASONS = ['recipient_unavailable', 'wrong_or_incomplete_address', 'refused', 'inaccessible_area', 'cod_cash_not_ready', 'rescheduled_by_customer', 'other'];
    private const REASON_PROMPT = 'You read a courier\'s note about a failed delivery attempt in the Philippines. The note may be in English, Filipino or courier shorthand '
        .'(for example NOT ARND, CNEE NOT AVAIL, INCOMPLETE ADDR, RTS). reason: the closest code. Use only the facts given.';
    private const REASON_SCHEMA = ['type' => 'object', 'properties' => ['reason' => ['type' => 'string', 'enum' => self::DELIVERY_REASONS]], 'required' => ['reason']];
    private const ADDRESS_PROMPT = 'You read a Philippine delivery address that someone pasted, in any order or format, and split it into parts. region and province: '
        .'copy the exact name from the lists given when the address names or implies one (for example Metro Manila or a Manila district means the National Capital '
        .'Region), otherwise leave them empty. city: the city or municipality as written, without "City of". barangay: as written, without "Barangay" or "Brgy". '
        .'line1: house or unit number, building and street. line2: subdivision, village or landmark. postal_code: the 4-digit ZIP code. '
        .'Leave any part you cannot find empty; never guess one.';
    private const ADDRESS_PARTS = ['line1', 'line2', 'barangay', 'city', 'province', 'region', 'postal_code'];
    private const PHONE = '/(?<!\d)(?:\+?63|0)9\d{2}[\s.-]?\d{3}[\s.-]?\d{4}(?!\d)/';
    // A digit or one of these words marks address text, not a recipient's name.
    private const NOT_A_NAME = '/\d|\b(?:st|street|rd|road|ave|avenue|blvd|brgy|barangay|bgy|city|purok|blk|block|lot|phase|subd|subdivision|village|tower|bldg|building|unit|floor|near|sitio|zone|metro|manila|province)\b/i';

    public const RELATIONS = ['order', 'seller', 'courier', 'originWarehouse.address.region', 'originWarehouse.address.province', 'originWarehouse.address.city', 'originWarehouse.address.barangay', 'destinationAddress.region', 'destinationAddress.province', 'destinationAddress.city', 'destinationAddress.barangay', 'proofAttachment', 'events'];
    public const TRANSITIONS = ['pending' => ['ready_for_pickup', 'cancelled'], 'ready_for_pickup' => ['picked_up', 'cancelled'], 'picked_up' => ['in_transit'], 'in_transit' => ['out_for_delivery'], 'out_for_delivery' => ['delivered', 'failed_attempt'], 'failed_attempt' => ['out_for_delivery'], 'returning' => ['returned_to_sender'], 'returned_to_sender' => [], 'delivered' => [], 'cancelled' => []];
    public const MAX_DELIVERY_ATTEMPTS = 3;
    // LOG-8: the forward path a courier may skip steps on (logistics_courier_fill_gaps). Delivered is never inferred.
    public const COURIER_FORWARD = ['pending', 'ready_for_pickup', 'picked_up', 'in_transit', 'out_for_delivery', 'failed_attempt'];
    // LOG-7: an order can be cancelled while each of its shipments has one of these statuses (pending and ready-for-pickup
    // parcels are cancelled with it).
    public const ORDER_CANCEL_ALLOWS = ['pending', 'ready_for_pickup', 'cancelled', 'returned_to_sender'];

    public function visibleQuery(User $user): Builder
    {
        abort_unless($user->can('view shipments'), 403, 'This action requires view shipments.');
        $query = Shipment::query();
        if ($user->hasRole('Administrative Assistant') && ! $user->hasAnyRole(['Admin', 'Accounting Staff', 'Warehouseman'])) {
            $tickets = app(TicketService::class)->visibleQuery($user);
            $query->where(fn ($q) => $q->whereIn('id', (clone $tickets)->whereNotNull('shipment_id')->select('shipment_id'))->orWhereIn('order_id', (clone $tickets)->whereNotNull('order_id')->select('order_id')));
        }
        return $query;
    }

    public function address(array $values): Address
    {
        $data = Validator::make($values, ['line1' => 'required|string|max:255', 'line2' => 'nullable|string|max:255', 'barangay_id' => 'required|integer|exists:barangays,id', 'city_id' => 'required|integer|exists:cities,id', 'province_id' => 'nullable|integer|exists:provinces,id', 'region_id' => 'required|integer|exists:regions,id', 'postal_code' => 'required|string|max:20', 'latitude' => 'nullable|numeric|between:-90,90', 'longitude' => 'nullable|numeric|between:-180,180', 'contact_name' => 'required|string|max:255', 'contact_phone' => 'required|string|max:30'])->validate();
        $city = City::findOrFail($data['city_id']); $barangay = Barangay::findOrFail($data['barangay_id']);
        if ($barangay->city_id !== $city->id || $city->region_id !== (int) $data['region_id'] || $city->province_id !== (isset($data['province_id']) ? (int) $data['province_id'] : null)) { throw ValidationException::withMessages(['address' => ['The region, province, city and barangay must belong to the same address hierarchy.']]); }
        return Address::create($data);
    }

    public function quotes(array $input): array
    {
        $origin = isset($input['origin_city_id']) ? City::findOrFail($input['origin_city_id']) : City::where('code', $input['origin_code'])->firstOrFail();
        $destination = isset($input['destination_city_id']) ? City::findOrFail($input['destination_city_id']) : City::where('code', $input['destination_code'])->firstOrFail();
        $rates = Rate::with(['courier', 'originZone', 'destinationZone'])->where('is_active', true)->whereHas('courier', fn ($query) => $query->where('is_active', true))->whereHas('originZone', fn ($query) => $query->where('is_active', true))->whereHas('destinationZone', fn ($query) => $query->where('is_active', true))->get();
        $quotes = []; $today = CarbonImmutable::now(Setting::valueFor('business_timezone', 'Asia/Manila'))->startOfDay();
        foreach ($rates as $rate) {
            if (! $rate->originZone->includesCity($origin) || ! $rate->destinationZone->includesCity($destination) || ! in_array($rate->service_type, $rate->courier->service_types, true)) { continue; }
            $divisor = $rate->courier->volumetric_divisor ?: (int) Setting::valueFor('volumetric_divisor', 3500);
            $volume = '1'; foreach (['length_cm', 'width_cm', 'height_cm'] as $dimension) { $volume = bcmul($volume, (string) $input['dimensions'][$dimension], 6); }
            $grams = bcdiv(bcmul($volume, '1000', 6), (string) $divisor, 6);
            $volumetric = (int) bcdiv($grams, '1', 0) + (bccomp($grams, bcdiv($grams, '1', 0), 6) > 0 ? 1 : 0);
            $chargeable = max((int) $input['weight_grams'], $volumetric);
            if ($chargeable > $rate->max_weight_grams) { continue; }
            $tiers = intdiv(max(0, $chargeable - $rate->base_weight_grams) + 499, 500);
            $additional = bcmul((string) $tiers, (string) $rate->additional_per_500g_amount, 0);
            $codFee = Money::halfUp((int) $input['cod_amount'], $rate->cod_fee_bp, 10000);
            $total = bcadd(bcadd((string) $rate->base_fee_amount, $additional, 0), (string) $codFee, 0);
            if (bccomp($total, '9000000000000000') > 0) { throw ValidationException::withMessages(['weight_grams' => ['The calculated shipping fee exceeds the supported amount.']]); }
            $quotes[] = ['rate_id' => $rate->id, 'courier_id' => $rate->courier_id, 'courier_name' => $rate->courier->name, 'service_type' => $rate->service_type, 'actual_weight_grams' => (int) $input['weight_grams'], 'volumetric_weight_grams' => $volumetric, 'chargeable_weight_grams' => $chargeable, 'volumetric_divisor' => $divisor, 'base_fee_amount' => $rate->base_fee_amount, 'additional_fee_amount' => (int) $additional, 'cod_fee_amount' => $codFee, 'total_amount' => (int) $total, 'currency' => 'PHP', 'eta_from' => $today->addDays($rate->destinationZone->min_days)->format('Y-m-d'), 'eta_to' => $today->addDays($rate->destinationZone->max_days)->format('Y-m-d')];
        }
        usort($quotes, fn ($left, $right) => $left['total_amount'] <=> $right['total_amount']);
        // The cheapest quote is recommended; create() uses it when no courier is chosen.
        return array_map(fn ($quote, $index) => $quote + ['recommended' => $index === 0], $quotes, array_keys($quotes));
    }

    /**
     * Order and seller pairs still waiting for a shipment: the order is not cancelled, has a captured (or partially
     * refunded) payment or a pending COD payment that is not held for risk review, and this seller has no shipment for it
     * (one per pair, even cancelled). Rows are orders with the pair's seller_id and seller_name.
     */
    public function awaitingBooking(?User $actor = null): Builder
    {
        $pairs = OrderItem::query()->join('sellers', 'sellers.id', '=', 'order_items.seller_id')->select('order_items.order_id', 'order_items.seller_id', 'sellers.shop_name as seller_name')->distinct();
        $query = Order::query()->joinSub($pairs, 'pairs', 'pairs.order_id', '=', 'orders.id')->select('orders.*', 'pairs.seller_id', 'pairs.seller_name')
            ->where('orders.status_snapshot', '!=', 'cancelled')
            ->where(fn ($query) => $query->whereHas('payments', fn ($payment) => $payment->whereIn('status', ['captured', 'partially_refunded']))->orWhereHas('payments', fn ($payment) => $payment->where('method', 'cod')->where('status', 'pending')->where('risk_level', 'none')))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('shipments')->whereColumn('shipments.order_id', 'orders.id')->whereColumn('shipments.seller_id', 'pairs.seller_id'));
        if ($actor && $actor->hasRole('Administrative Assistant') && ! $actor->hasAnyRole(['Admin', 'Accounting Staff', 'Warehouseman'])) {
            $query->whereIn('orders.id', app(TicketService::class)->visibleQuery($actor)->whereNotNull('order_id')->select('order_id'));
        }
        return $query;
    }

    public function create(array $values, User|IntegrationClient $actor): Shipment
    {
        return DB::transaction(function () use ($values, $actor) {
            $integration = $actor instanceof IntegrationClient;
            $orderQuery = Order::query(); $sellerQuery = Seller::query();
            if ($integration) { $orderQuery->where('source_system', $actor->system_code); $sellerQuery->where('source_system', $actor->system_code); }
            elseif ($actor->hasRole('Administrative Assistant') && ! $actor->hasAnyRole(['Admin', 'Accounting Staff', 'Warehouseman'])) {
                $orderQuery->whereIn('id', app(TicketService::class)->visibleQuery($actor)->whereNotNull('order_id')->select('order_id'));
            }
            $order = isset($values['order_id']) ? $orderQuery->whereKey($values['order_id'])->lockForUpdate()->firstOrFail() : $orderQuery->where('external_id', $values['order_external_id'])->lockForUpdate()->firstOrFail();
            $seller = isset($values['seller_id']) ? $sellerQuery->findOrFail($values['seller_id']) : $sellerQuery->where('external_id', $values['seller_external_id'])->firstOrFail();
            if ($order->status_snapshot === 'cancelled') { throw ValidationException::withMessages(['order_id' => ['A cancelled order cannot be shipped.']]); }
            if (! $order->items()->where('seller_id', $seller->id)->exists()) { throw ValidationException::withMessages(['seller_id' => ['This seller has no items in the selected order.']]); }
            if (Shipment::where('order_id', $order->id)->where('seller_id', $seller->id)->exists()) { throw ValidationException::withMessages(['order_id' => ['Only one shipment per order and seller is allowed.']]); }
            $warehouse = Warehouse::with('address')->findOrFail($values['origin_warehouse_id']);
            if (! $warehouse->is_active || ($warehouse->type === 'seller_pickup' && $warehouse->seller_id !== $seller->id)) { throw ValidationException::withMessages(['origin_warehouse_id' => ['Choose an active warehouse or a pickup point belonging to this seller.']]); }
            $destination = $this->address($values['destination']);
            $quote = collect($this->quotes(['origin_city_id' => $warehouse->address->city_id, 'destination_city_id' => $destination->city_id, 'weight_grams' => $values['weight_grams'], 'dimensions' => $values['dimensions'], 'cod_amount' => $values['cod_amount']]))->first(fn ($quote) => (! isset($values['courier_id']) || ($quote['courier_id'] === (int) $values['courier_id'] && $quote['service_type'] === $values['service_type'])) && (! isset($values['rate_id']) || $quote['rate_id'] === (int) $values['rate_id']));
            if (! $quote) { throw ValidationException::withMessages(['courier_id' => ['No active rate covers this route, service and chargeable weight.']]); }
            $codPayment = Payment::where('order_id', $order->id)->where('method', 'cod')->where('status', 'pending')->lockForUpdate()->first();
            if ($values['cod_amount'] > 0) {
                if (! $codPayment || $values['cod_amount'] + Shipment::where('order_id', $order->id)->sum('cod_amount') > $codPayment->total_amount) { throw ValidationException::withMessages(['cod_amount' => ['COD shipments cannot exceed the pending COD payment amount.']]); }
                if ($codPayment->risk_level !== 'none') { throw ValidationException::withMessages(['cod_amount' => ['This COD payment is held for risk review. Finance must clear it before the order ships.']]); }
            }
            // Every shipment of a COD order must carry cash collection, or the payment can never capture once the goods leave.
            elseif ($codPayment) { throw ValidationException::withMessages(['cod_amount' => ['This order is cash on delivery: enter this seller\'s COD amount.']]); }
            $shipment = Shipment::create(['order_id' => $order->id, 'seller_id' => $seller->id, 'courier_id' => $quote['courier_id'], 'service_type' => $quote['service_type'], 'tracking_number' => $values['tracking_number'] ?? null, 'origin_warehouse_id' => $warehouse->id, 'destination_address_id' => $destination->id, 'weight_grams' => $values['weight_grams'], 'dimensions' => $values['dimensions'], 'shipping_fee_amount' => $quote['total_amount'], 'cod_amount' => $values['cod_amount'], 'promised_from' => $quote['eta_from'], 'promised_to' => $quote['eta_to'], 'created_by' => $integration ? null : $actor->id]);
            Reference::assign($shipment, 'SHP');
            $shipment->events()->create(['status' => 'pending', 'note' => 'Shipment created.', 'occurred_at' => now(), 'source' => $integration ? 'integration' : 'manual', 'created_by' => $integration ? null : $actor->id]);
            $this->emit($shipment, 'shipment.created');
            return $shipment->fresh(self::RELATIONS);
        });
    }

    // Call in the surrounding transaction, before taking a shipment lock. Order cancellation and COD capture use the
    // same order -> payment -> shipment sequence, so concurrent delivery cannot form an inverted lock cycle.
    public function lockForMutation(Shipment $shipment): Shipment
    {
        Order::whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
        Payment::where('order_id', $shipment->order_id)->orderBy('id')->lockForUpdate()->get();
        return Shipment::whereKey($shipment->id)->where('order_id', $shipment->order_id)->lockForUpdate()->firstOrFail();
    }

    public function transition(Shipment $shipment, array $event, string $source = 'manual', ?User $actor = null): Shipment
    {
        return DB::transaction(function () use ($shipment, $event, $source, $actor) {
            $shipment = $this->lockForMutation($shipment); $status = $event['status'];
            if (! in_array($status, self::TRANSITIONS[$shipment->status] ?? [], true)) { throw ValidationException::withMessages(['status' => ["A {$shipment->status} shipment cannot transition to {$status}."]]); }
            if ($status === 'picked_up' && ! $shipment->tracking_number) { throw ValidationException::withMessages(['tracking_number' => ['Add the courier tracking number before the parcel is picked up.']]); }
            $occurred = isset($event['occurred_at']) ? CarbonImmutable::parse($event['occurred_at'])->utc() : CarbonImmutable::now('UTC');
            $latest = $shipment->events()->max('occurred_at');
            if ($latest && $occurred->lt(CarbonImmutable::parse($latest))) { throw ValidationException::withMessages(['occurred_at' => ['A shipment event cannot occur before its previous event.']]); }
            if ($status === 'out_for_delivery' && $shipment->attempt_count >= self::MAX_DELIVERY_ATTEMPTS) { throw ValidationException::withMessages(['status' => ['The delivery attempt limit has been reached.']]); }
            if ($status === 'delivered' && (! $shipment->recipient_name || ! $shipment->proof_attachment_id || ($shipment->cod_amount > 0 && ! $shipment->cash_collected))) { throw ValidationException::withMessages(['proof' => ['Recipient name, proof photo, and COD cash collection are required before delivery.']]); }
            // Any new event ends a quiet spell, so a stuck alert can fire again after it.
            $shipment->status = $status; $shipment->stuck_notified_at = null;
            if ($status === 'failed_attempt') { $shipment->attempt_count += 1; }
            if ($status === 'delivered') { $shipment->delivered_at = $occurred; }
            $shipment->save();
            $shipment->events()->create(['status' => $status, 'location' => $event['location'] ?? null, 'note' => $event['note'] ?? null, 'occurred_at' => $occurred, 'source' => $source, 'created_by' => $actor?->id]);
            if ($status === 'failed_attempt' && $shipment->attempt_count >= self::MAX_DELIVERY_ATTEMPTS) { $shipment->update(['status' => 'returning']); $shipment->events()->create(['status' => 'returning', 'note' => 'Three failed delivery attempts; returning to sender.', 'occurred_at' => $occurred, 'source' => $source, 'created_by' => $actor?->id]); }
            if ($status === 'delivered' && $shipment->cod_amount > 0) { $this->captureCodIfCollected($shipment); }
            if (in_array($status, ['failed_attempt', 'returned_to_sender'], true)) { $this->notify($shipment, $status); }
            if (in_array($status, ['returned_to_sender', 'cancelled'], true)) { $this->settleReturn($shipment, $status); }
            // After commit, so a ticket error is reported but never undoes the shipment update.
            if (in_array($status, ['failed_attempt', 'delivered'], true) && Setting::valueFor('logistics_auto_ticket')) { $id = $shipment->id; DB::afterCommit(fn () => rescue(fn () => $this->deliveryTicket($id))); }
            $this->emit($shipment, $status === 'delivered' ? 'shipment.delivered' : 'shipment.status_changed');
            return $shipment->fresh(self::RELATIONS);
        });
    }

    // The statuses a courier webhook would move this shipment to now, by the webhook's own rules: the courier names the
    // parcel by tracking number, then the state machine and attempt limit of transition() apply to each step, including the
    // forward steps SFA fills in while logistics_courier_fill_gaps is on. Delivered is left out: it also needs proof.
    public function courierSteps(Shipment $shipment): array
    {
        if (! $shipment->tracking_number) { return []; }
        $from = array_search($shipment->status, self::COURIER_FORWARD, true);
        $fill = Setting::valueFor('logistics_courier_fill_gaps');

        return array_values(array_filter(array_diff(Shipment::STATUSES, ['delivered']), function (string $to) use ($shipment, $from, $fill) {
            $index = array_search($to, self::COURIER_FORWARD, true);
            $status = $shipment->status;
            foreach ($fill && $from !== false && $index !== false && $index > $from + 1 ? array_slice(self::COURIER_FORWARD, $from + 1, $index - $from) : [$to] as $step) {
                if (! in_array($step, self::TRANSITIONS[$status] ?? [], true) || ($step === 'out_for_delivery' && $shipment->attempt_count >= self::MAX_DELIVERY_ATTEMPTS)) { return false; }
                $status = $step;
            }

            return true;
        }));
    }

    public function proof(Shipment $shipment, UploadedFile $photo, array $values, User $actor): Shipment
    {
        return DB::transaction(function () use ($shipment, $photo, $values, $actor) {
            $shipment = $this->lockForMutation($shipment);
            if ($shipment->status !== 'out_for_delivery') { throw ValidationException::withMessages(['status' => ['Only an out-for-delivery shipment can receive proof of delivery.']]); }
            if ($shipment->cod_amount > 0 && ! ($values['cash_collected'] ?? false)) { throw ValidationException::withMessages(['cash_collected' => ['Confirm cash collection for this COD shipment.']]); }
            $attachment = app(AttachmentService::class)->store($photo, $shipment, $actor);
            $shipment->update(['proof_attachment_id' => $attachment->id, 'recipient_name' => $values['recipient_name'], 'delivery_note' => $values['note'] ?? null, 'cash_collected' => (bool) ($values['cash_collected'] ?? false)]);
            return $this->transition($shipment, ['status' => 'delivered', 'note' => $values['note'] ?? 'Proof of delivery recorded.'], 'manual', $actor);
        });
    }

    // LOG-7: cancelling an order cancels its shipments that have not reached the courier (each with its webhook). One that
    // is already with the courier, or delivered, refuses the cancel with a 422 and changes nothing.
    public function cancelForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            Payment::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $shipments = Shipment::where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $away = $shipments->whereNotIn('status', self::ORDER_CANCEL_ALLOWS)->first();
            if ($away) { throw ValidationException::withMessages(['order' => ["Shipment {$away->reference} is already with the courier (".str_replace('_', ' ', $away->status).'), so the order cannot be cancelled.']]); }
            foreach ($shipments->whereIn('status', ['pending', 'ready_for_pickup']) as $shipment) { $this->transition($shipment, ['status' => 'cancelled', 'note' => 'The order was cancelled.'], 'integration'); }
        });
    }

    // LOG-5: flag each overdue shipment once, and each stuck one (no event for logistics_stuck_hours; 0 = off) once per
    // quiet spell. One digest goes to the active users who can edit shipments.
    public function runScheduled(): array
    {
        $today = CarbonImmutable::now(Setting::valueFor('business_timezone'))->toDateString(); $hours = (int) Setting::valueFor('logistics_stuck_hours');
        $checks = ['overdue_notified_at' => fn () => Shipment::overdue($today)];
        if ($hours > 0) { $checks['stuck_notified_at'] = fn () => Shipment::whereNotIn('status', Shipment::FINAL_STATUSES)->whereDoesntHave('events', fn ($query) => $query->where('occurred_at', '>', now()->subHours($hours))); }
        $flagged = ['overdue_notified_at' => [], 'stuck_notified_at' => []];
        foreach ($checks as $field => $query) {
            foreach ($query()->whereNull($field)->orderBy('id')->limit(100)->toBase()->pluck('id') as $id) {
                try {
                    DB::transaction(function () use ($query, $field, $id, &$flagged) {
                        // Locked and re-checked: an event or another run may have changed it since the scan.
                        $shipment = $query()->whereKey($id)->whereNull($field)->lockForUpdate()->first();
                        if ($shipment) { $shipment->update([$field => now()]); $flagged[$field][] = $shipment->reference; }
                    });
                } catch (\Throwable $exception) { report($exception); }
            }
        }
        [$overdue, $stuck] = array_values($flagged);
        if ($overdue || $stuck) {
            $list = fn (array $references) => implode(', ', array_slice($references, 0, 5)).(count($references) > 5 ? ' and '.(count($references) - 5).' more' : '');
            $message = implode(' ', array_filter([$overdue ? count($overdue).' past the promised delivery day: '.$list($overdue).'.' : null, $stuck ? count($stuck).' with no tracking update for '.$hours.' hours: '.$list($stuck).'.' : null]));
            foreach (User::authorizedFor('edit shipments')->where('is_active', true)->pluck('id') as $userId) { Notification::create(['user_id' => $userId, 'type' => 'shipments_attention', 'title' => 'Shipments need attention', 'message' => $message, 'data' => ['overdue' => count($overdue), 'stuck' => count($stuck)]]); }
            Cache::forever('sfa.api.version', (string) Str::uuid());
        }

        return ['shipments_overdue_flagged' => count($overdue), 'shipments_stuck_flagged' => count($stuck)];
    }

    // LOG-6: a failed attempt notes the shipment's open ticket (high priority from the third attempt) or opens a delivery
    // ticket for the customer; a delivery notes the open ticket. The shipment lock serializes calls, and a note already
    // written is not repeated, so a second call adds nothing.
    public function deliveryTicket(int $shipmentId): ?Ticket
    {
        // The AI reading of the courier's note is asked for before the shipment lock is taken (off unless ai_delivery_reason).
        // Nothing retries this call, so an AI error must never cost the ticket, its note or the third-attempt escalation.
        $reason = rescue(fn () => $this->deliveryReason($shipmentId), null, false);

        return DB::transaction(function () use ($shipmentId, $reason) {
            $shipment = Shipment::with('order')->whereKey($shipmentId)->lockForUpdate()->firstOrFail();
            $failed = in_array($shipment->status, ['failed_attempt', 'returning'], true) && $shipment->attempt_count > 0;
            if (! $failed && $shipment->status !== 'delivered') { return null; }
            $ticket = Ticket::where('shipment_id', $shipment->id)->whereNotIn('status', ['resolved', 'closed'])->latest('id')->lockForUpdate()->first();
            $note = $shipment->events()->reorder()->where('status', $failed ? 'failed_attempt' : 'delivered')->latest('id')->value('note');
            $body = $failed ? "Delivery attempt {$shipment->attempt_count} of 3 for {$shipment->reference} failed".($note ? ": {$note}" : '.').($shipment->status === 'returning' ? ' The parcel is returning to the sender.' : '')
                : "{$shipment->reference} was delivered to {$shipment->recipient_name}.";
            if (! $ticket) {
                if (! $failed) { return null; }
                $ticket = app(TicketService::class)->create(['subject' => "Failed delivery: {$shipment->reference}", 'description' => $body, 'requester_type' => 'customer', 'requester_id' => $shipment->order->customer_id, 'order_id' => $shipment->order_id, 'shipment_id' => $shipment->id, 'channel' => 'system', 'category' => 'delivery', 'priority' => $shipment->attempt_count >= 3 ? 'high' : 'normal', 'tags' => ['auto', 'delivery']], null);
                AuditLog::system($ticket, null, $ticket->only(['reference', 'shipment_id', 'priority']), 'logistics_auto_ticket');
                if ($reason) { $this->noteDeliveryReason($ticket, $reason); }
                return $ticket;
            }
            if (! $ticket->messages()->where('body', $body)->exists()) { TicketMessage::create(['ticket_id' => $ticket->id, 'author_type' => 'system', 'author_id' => null, 'body' => $body, 'is_internal_note' => true]); }
            if ($failed && $reason) { $this->noteDeliveryReason($ticket, $reason); }
            if ($failed && $shipment->attempt_count >= 3 && in_array($ticket->priority, ['low', 'normal'], true)) {
                $old = $ticket->priority;
                app(TicketService::class)->changePriorityLocked($ticket, 'high');
                AuditLog::system($ticket, ['priority' => $old], ['priority' => 'high'], 'logistics_auto_ticket');
            }
            return $ticket;
        });
    }

    /**
     * The AI reading of the courier's note on the latest failed attempt: one of DELIVERY_REASONS, or null (switch off, no
     * note, Gemini failing, an answer outside the list). The same note is never asked about twice.
     */
    private function deliveryReason(int $shipmentId): ?string
    {
        $ai = app(AiService::class);
        if ($ai->unavailable('delivery_reason')) { return null; }
        $shipment = Shipment::find($shipmentId);
        if (! $shipment || ! in_array($shipment->status, ['failed_attempt', 'returning'], true) || $shipment->attempt_count < 1) { return null; }
        $note = trim((string) $shipment->events()->reorder()->where('status', 'failed_attempt')->latest('id')->value('note'));
        if ($note === '') { return null; }
        // json() checks the daily limit again, and another call may have crossed it since unavailable().
        try {
            $insight = $ai->json('delivery_reason', $shipment, self::REASON_PROMPT, ['courier_note' => mb_substr($note, 0, 1000), 'attempt' => $shipment->attempt_count.' of '.self::MAX_DELIVERY_ATTEMPTS, 'cash_on_delivery' => $shipment->cod_amount > 0], self::REASON_SCHEMA, ['timeout' => 8, 'user' => null, 'patient' => false]);
        } catch (ValidationException) { return null; }
        if (! $insight->ok()) { return null; }
        $check = Validator::make($insight->output, ['reason' => ['required', \Illuminate\Validation\Rule::in(self::DELIVERY_REASONS)]]);
        if ($check->fails()) { $insight->reject('The answer did not fit SFA\'s list: '.$check->errors()->first()); return null; }

        return $insight->output['reason'];
    }

    // Under the ticket lock: a reason tag and one internal note per reading (a repeated call adds nothing). The next step
    // comes from SFA's playbook, never from the AI.
    private function noteDeliveryReason(Ticket $ticket, string $reason): void
    {
        $body = 'AI reading of the courier\'s note: '.Str::ucfirst(str_replace('_', ' ', $reason)).'. Next step (SFA playbook): '.AiRules::DELIVERY_PLAYBOOK[$reason];
        if ($ticket->messages()->where('body', $body)->exists()) { return; }
        TicketMessage::create(['ticket_id' => $ticket->id, 'author_type' => 'system', 'author_id' => null, 'body' => $body, 'is_internal_note' => true]);
        $old = $ticket->tags ?? [];
        $ticket->update(['tags' => array_values(array_unique([...$old, 'reason:'.$reason]))]);
        AuditLog::system($ticket, ['tags' => $old], ['tags' => $ticket->tags], 'ai_delivery_reason');
    }

    /**
     * Address auto-fill (off unless ai_address_fill): the AI splits pasted text into parts and SFA matches the names to its
     * own location tables, so every id comes from SFA. The city is matched first (the most specific name), and its
     * province or region must agree with it; the barangay is looked up inside that city. The phone number and the
     * recipient's name are read here and never sent to Gemini.
     */
    public function parseAddress(string $text, User $actor): array
    {
        $phone = preg_match(self::PHONE, $text, $match) ? '0'.substr(preg_replace('/\D/', '', $match[0]), -10) : null;
        // The name is the first comma- or line-separated part when it holds no digit and no address word.
        // ponytail: word-list guess; a paste opening with a bare town ("Makati, Metro Manila") reads it as the name.
        $name = ''; $parts = preg_split('/[,\n]/', $text, 2);
        if (count($parts) === 2) {
            $first = trim(preg_replace('/^(?:para kay|attn\b\.?|(?:to|name|recipient) ?:)\s*:?/i', '', trim(preg_replace([self::PHONE, '/\s+/'], ['', ' '], $parts[0]))));
            if ($first !== '' && ! preg_match(self::NOT_A_NAME, $first)) {
                $name = trim(mb_substr($first, 0, 255));
                // A phone on the name's line stays with the address (AiService masks it).
                $text = ($phone && str_contains($parts[0], $match[0]) ? $match[0].', ' : '').ltrim($parts[1]);
            }
        }
        $regions = Region::orderBy('name')->get(['id', 'name']);
        $provinces = Province::orderBy('name')->get(['id', 'name', 'region_id']);
        $insight = app(AiService::class)->json('address_fill', null, self::ADDRESS_PROMPT, ['regions' => $regions->pluck('name')->all(), 'provinces' => $provinces->pluck('name')->all(), 'pasted_address' => mb_substr($text, 0, 1000)],
            ['type' => 'object', 'properties' => array_fill_keys(self::ADDRESS_PARTS, ['type' => 'string']), 'required' => self::ADDRESS_PARTS], ['thinking' => 'low', 'user' => $actor->id]);
        abort_unless($insight->ok(), 503, $insight->error);
        $read = array_map(fn ($part) => is_string($part) ? trim(mb_substr($part, 0, 255)) : '', array_replace(array_fill_keys(self::ADDRESS_PARTS, ''), array_intersect_key($insight->output, array_flip(self::ADDRESS_PARTS))));
        $region = $regions->first(fn ($row) => self::placeKey($row->name) === self::placeKey($read['region']));
        $province = $provinces->first(fn ($row) => self::placeKey($row->name) === self::placeKey($read['province']));
        $cityKey = self::placeKey($read['city']);
        // ponytail: one scan of every city (about 1,650 in the PSGC list) per request; index a normalized name if it grows.
        $cities = $cityKey === '' ? collect() : City::get(['id', 'name', 'province_id', 'region_id'])->filter(fn ($row) => self::placeKey($row->name) === $cityKey)->values();
        // The only city with that name in the province (or region) the AI read, or anywhere when it read neither; a
        // contradicting or still shared name is left for the person. A city outside any province (NCR, highly urbanized
        // cities such as Cebu City) agrees with a province of its own region.
        $narrowed = $province ? $cities->filter(fn ($row) => $row->province_id === $province->id || (! $row->province_id && $row->region_id === $province->region_id))
            : ($region ? $cities->where('region_id', $region->id) : $cities);
        $city = $narrowed->count() === 1 ? $narrowed->first() : null;
        $barangayKey = self::placeKey($read['barangay']);
        $barangay = $city && $barangayKey !== '' ? Barangay::where('city_id', $city->id)->get(['id', 'name'])->first(fn ($row) => self::placeKey($row->name) === $barangayKey) : null;
        $regionId = $city?->region_id ?? $region?->id ?? $province?->region_id;
        $unmatched = array_keys(array_filter(['region' => ! $regionId && $read['region'] !== '', 'city' => ! $city && $read['city'] !== '', 'barangay' => ! $barangay && $read['barangay'] !== '']));
        // ADR-2 checks each part the AI read against SFA's tables; a region filled in from the city does not count.
        $notListed = array_keys(array_filter(['region' => ! $region && $read['region'] !== '', 'city' => ! $city && $read['city'] !== '', 'barangay' => ! $barangay && $read['barangay'] !== '']));
        $places = array_keys(array_filter(['region' => $read['region'], 'city' => $read['city'], 'barangay' => $read['barangay']], fn ($part) => $part !== ''));
        $kept = array_keys(array_filter(['the recipient\'s name' => $name !== '', 'the phone number' => (bool) $phone]));
        $where = $province?->name ?? $region?->name;
        $zip = $read['postal_code']; $zipOk = (bool) preg_match('/^\d{4}$/', $zip);
        $checks = AiRules::merge('address', [
            AiRules::result('ADR-1', 'pass', $kept ? 'Kept in SFA, not sent to the AI: '.implode(' and ', $kept).'.' : 'No name or phone number was found in the text.'),
            ! $places ? AiRules::result('ADR-2', 'not_applicable', 'No region, city or barangay was read.')
                : ($notListed ? AiRules::result('ADR-2', 'fail', 'Not in SFA\'s location list, left for you to pick: '.implode(', ', $notListed).'.') : AiRules::result('ADR-2', 'pass', 'Matched to SFA\'s location list: '.implode(', ', $places).'.')),
            match (true) {
                $read['city'] === '' => AiRules::result('ADR-3', 'not_applicable', 'No city was read.'),
                (bool) $city => AiRules::result('ADR-3', 'pass', "{$city->name} is the only city with that name in ".($where ?? 'SFA\'s list').'.'),
                $cities->isEmpty() => AiRules::result('ADR-3', 'fail', "SFA has no city named \"{$read['city']}\"; pick the city yourself."),
                $narrowed->isEmpty() => AiRules::result('ADR-3', 'fail', "No city named \"{$read['city']}\" is in {$where}; pick the city yourself."),
                default => AiRules::result('ADR-3', 'fail', $narrowed->count()." cities named \"{$read['city']}\" are in ".($where ?? 'SFA\'s list').'; pick the city yourself.'),
            },
            $zip === '' ? AiRules::result('ADR-4', 'not_applicable', 'No ZIP code was read.')
                : ($zipOk ? AiRules::result('ADR-4', 'pass', "{$zip} has 4 digits.") : AiRules::result('ADR-4', 'fail', "\"{$zip}\" is not a 4-digit ZIP code, so it was left empty.")),
        ]);

        return [
            'region_id' => $regionId, 'province_id' => $city ? $city->province_id : ($province?->region_id === $regionId ? $province?->id : null), 'city_id' => $city?->id, 'barangay_id' => $barangay?->id,
            'line1' => $read['line1'], 'line2' => $read['line2'], 'postal_code' => $zipOk ? $zip : '', 'contact_name' => $name, 'contact_phone' => $phone,
            'unmatched' => $unmatched, 'read' => array_intersect_key($read, array_flip(['region', 'province', 'city', 'barangay'])), 'checks' => $checks,
        ];
    }

    /**
     * Map search candidates for the pin picker: OpenStreetMap's geocoder finds the places, and when the embedding
     * model is up it re-ranks them by how close each candidate's description is to the typed text. Falls back to the
     * geocoder's own order whenever the AI is off or unreachable, so the search bar always answers.
     */
    public function addressSuggestions(string $text): array
    {
        $response = Http::withHeaders(['User-Agent' => 'ArchonNell-SFA/1.0 address search'])->timeout(10)->get('https://nominatim.openstreetmap.org/search', ['q' => $text, 'format' => 'json', 'limit' => 8, 'countrycodes' => 'ph', 'accept-language' => 'en']);
        $hits = collect($response->successful() ? $response->json() : [])->map(fn (array $hit) => ['label' => mb_substr((string) ($hit['display_name'] ?? ''), 0, 300), 'lat' => round((float) ($hit['lat'] ?? 0), 7), 'lng' => round((float) ($hit['lon'] ?? 0), 7)])->filter(fn (array $hit) => $hit['label'] !== '' && $hit['lat'] && $hit['lng'])->values()->all();
        if (count($hits) < 2 || ! $vectors = app(AiService::class)->embed([AiService::mask($text), ...array_column($hits, 'label')])) { return $hits; }
        $query = array_shift($vectors);
        if (! is_array($query)) { return $hits; }
        $candidates = $vectors;
        $norm = fn (array $vector) => sqrt(array_sum(array_map(fn ($x) => $x * $x, $vector)));
        $queryNorm = $norm($query) ?: 1;
        $scores = array_map(fn ($vector) => is_array($vector) ? array_sum(array_map(fn ($x, $y) => $x * $y, $query, $vector)) / ($queryNorm * ($norm($vector) ?: 1)) : 0, $candidates);
        array_multisort($scores, SORT_DESC, $hits);
        return $hits;
    }

    /**
     * A pinned map point becomes SFA location ids: the geocoder names the place, then the city name is matched to the
     * PSGC list (its region and province follow the city row, so differently-worded region names never matter) and the
     * barangay is matched inside that city. Anything unmatched is returned for the picker to leave alone.
     */
    public function reverseAddress(float $latitude, float $longitude): array
    {
        $hit = Http::withHeaders(['User-Agent' => 'ArchonNell-SFA/1.0 address search'])->timeout(10)->get('https://nominatim.openstreetmap.org/reverse', ['lat' => $latitude, 'lon' => $longitude, 'format' => 'json', 'zoom' => 18, 'addressdetails' => 1, 'accept-language' => 'en']);
        $address = $hit->successful() ? ($hit->json('address') ?? []) : [];
        $place = mb_substr((string) ($hit->json('display_name') ?? ''), 0, 300);

        $cityKey = self::placeKey($address['city'] ?? $address['town'] ?? $address['municipality'] ?? $address['county'] ?? '');
        $cities = $cityKey === '' ? collect() : City::get(['id', 'name', 'province_id', 'region_id'])->filter(fn ($row) => self::placeKey($row->name) === $cityKey)->values();
        // Same-name cities exist (seven San Fernandos): the geocoder's state narrows to its province or region when it can.
        $stateKey = self::placeKey($address['state'] ?? $address['province'] ?? '');
        if ($cities->count() > 1 && $stateKey !== '') {
            $narrowed = $cities->filter(fn ($city) => ($city->province_id && self::placeKey($city->province->name ?? '') === $stateKey) || ($city->region_id && self::placeKey($city->region->name ?? '') === $stateKey));
            if ($narrowed->count() === 1) { $cities = $narrowed->values(); }
        }
        $city = $cities->count() === 1 ? $cities->first() : null;

        $barangayKey = self::placeKey($address['village'] ?? $address['suburb'] ?? $address['quarter'] ?? $address['neighbourhood'] ?? '');
        $barangay = $city && $barangayKey !== '' ? Barangay::where('city_id', $city->id)->get(['id', 'name'])->first(fn ($row) => self::placeKey($row->name) === $barangayKey) : null;

        $road = trim(implode(' ', array_filter([(string) ($address['house_number'] ?? ''), (string) ($address['road'] ?? '')])));
        $zip = (string) ($address['postcode'] ?? '');

        return [
            'place' => $place,
            'region_id' => $city?->region_id, 'province_id' => $city?->province_id, 'city_id' => $city?->id, 'barangay_id' => $barangay?->id,
            'line1' => mb_substr($road, 0, 255), 'postal_code' => preg_match('/^\d{4}$/', $zip) ? $zip : '',
            'unmatched' => array_values(array_filter([$city ? null : ($address['city'] ?? $address['town'] ?? $address['municipality'] ?? 'unresolved'), ($city && ! $barangay && $barangayKey !== '') ? ($address['village'] ?? $address['suburb'] ?? 'barangay') : null])),
        ];
    }

    // "City of Las Piñas", "Las Pinas City" and "las piñas" compare equal; so do "Brgy. San Antonio" and "San Antonio".
    private static function placeKey(?string $name): string
    {
        $name = Str::lower(Str::ascii((string) $name));
        $name = preg_replace(['/\(.*?\)/', '/\b(city of|municipality of|city|municipality|barangay|brgy|bgy)\b\.?/'], ' ', $name);

        return trim(preg_replace('/[^a-z0-9]+/', ' ', $name));
    }

    private function captureCodIfCollected(Shipment $shipment): void
    {
        Order::whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
        $payment = Payment::where('order_id', $shipment->order_id)->where('method', 'cod')->where('status', 'pending')->lockForUpdate()->first();
        if ($payment) { app(PaymentService::class)->captureCodWhenReady($payment); }
    }

    // LOG-11: once no COD parcel of the order can still reach the customer and every seller has a shipment, the pending COD
    // payment is cancelled. A prepaid order's returned or cancelled parcel is flagged to Finance to decide on a refund.
    private function settleReturn(Shipment $shipment, string $status): void
    {
        $order = Order::whereKey($shipment->order_id)->lockForUpdate()->firstOrFail();
        if ($shipment->cod_amount > 0) {
            $open = Shipment::where('order_id', $order->id)->where('cod_amount', '>', 0)->whereNotIn('status', ['returned_to_sender', 'cancelled'])->exists();
            $unbooked = $order->items()->whereNotIn('seller_id', Shipment::where('order_id', $order->id)->select('seller_id'))->exists();
            $payment = $open || $unbooked ? null : Payment::where('order_id', $order->id)->where('method', 'cod')->where('status', 'pending')->lockForUpdate()->first();
            if ($payment) { app(PaymentService::class)->cancel($payment); AuditLog::system($payment, ['status' => 'pending'], ['status' => 'cancelled'], 'logistics_return_cod_cancel'); }
            return;
        }
        $payment = Payment::where('order_id', $order->id)->whereIn('status', ['captured', 'partially_refunded'])->first();
        if (! $payment) { return; }
        $what = $status === 'cancelled' ? 'was cancelled' : 'was returned to the sender';
        foreach (User::authorizedFor('edit payments')->where('is_active', true)->pluck('id') as $userId) { Notification::create(['user_id' => $userId, 'type' => 'parcel_returned', 'title' => $status === 'cancelled' ? 'Prepaid shipment cancelled' : 'Prepaid parcel returned', 'message' => "{$shipment->reference} {$what} for prepaid order {$order->external_id}. Decide whether to refund {$payment->reference}.", 'data' => ['payment_id' => $payment->id, 'reference' => $payment->reference, 'shipment_id' => $shipment->id]]); }
    }

    private function emit(Shipment $shipment, string $type): void
    {
        app(WebhookService::class)->emitFor($type, $shipment->fresh(['events', 'destinationAddress'])->toArray(), $shipment->order->source_system);
    }

    private function notify(Shipment $shipment, string $status): void
    {
        foreach (User::authorizedFor('edit shipments')->where('is_active', true)->get() as $user) { Notification::create(['user_id' => $user->id, 'type' => 'shipment_'.$status, 'title' => $status === 'failed_attempt' ? 'Delivery attempt failed' : 'Shipment returned', 'message' => $shipment->reference.($status === 'failed_attempt' ? ' had a failed delivery attempt.' : ' was returned to the sender.'), 'data' => ['shipment_id' => $shipment->id, 'path' => '/logistics', 'reference' => $shipment->reference]]); }
    }
}
