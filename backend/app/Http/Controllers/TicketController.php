<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListRequest;
use App\Http\Requests\SupportListRequest;
use App\Http\Requests\TicketActionRequest;
use App\Http\Requests\TicketMessageRequest;
use App\Http\Requests\TicketRequest;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\TicketService;
use App\Support\ListResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TicketController extends Controller
{
    public function __construct(private readonly TicketService $service) {}

    public function index(SupportListRequest $request)
    {
        $query = $this->service->visibleQuery($request->user())->with('assignee');
        foreach (['status', 'priority', 'category', 'channel'] as $field) {
            if ($request->filled($field)) {
                if ($field === 'status' && ! in_array($request->status, Ticket::STATUSES, true)) { throw ValidationException::withMessages(['status' => ['The selected status is invalid.']]); }
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('assignee')) {
            if ($request->assignee === 'unassigned') { $query->whereNull('assignee_id'); }
            elseif ($request->assignee === 'me') { $query->where('assignee_id', $request->user()->id); }
            elseif (ctype_digit($request->assignee)) { $query->where('assignee_id', (int) $request->assignee); }
            else { throw ValidationException::withMessages(['assignee' => ['Choose Me, Unassigned, a Support Agent or an Admin.']]); }
        }
        if ($request->filled('search')) {
            $search = '%'.mb_strtolower($request->search).'%';
            $customerIds = Customer::where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', [$search])->orWhereRaw('LOWER(email) LIKE ?', [$search]))->pluck('id');
            $sellerIds = Seller::where(fn ($q) => $q->whereRaw('LOWER(shop_name) LIKE ?', [$search])->orWhereRaw('LOWER(email) LIKE ?', [$search]))->pluck('id');
            $query->where(fn ($q) => $q->whereRaw('LOWER(reference) LIKE ?', [$search])->orWhereRaw('LOWER(subject) LIKE ?', [$search])->orWhere(fn ($p) => $p->where('requester_type', 'customer')->whereIn('requester_id', $customerIds))->orWhere(fn ($p) => $p->where('requester_type', 'seller')->whereIn('requester_id', $sellerIds)));
        }
        $query = ListResponse::dates($query, $request);
        if ($request->filled('sla_state')) { $ids = (clone $query)->get()->filter(fn ($ticket) => $ticket->sla_state === $request->sla_state)->pluck('id'); $query->whereIn('id', $ids); }
        $summaryRows = (clone $query)->get();
        $zone = Setting::valueFor('business_timezone', config('sfa.timezone')); $today = CarbonImmutable::now($zone)->startOfDay()->utc();
        $summary = ['total' => $summaryRows->count(), 'open' => $summaryRows->whereNotIn('status', ['resolved', 'closed'])->count(), 'unassigned' => $summaryRows->whereNull('assignee_id')->whereNotIn('status', ['resolved', 'closed'])->count(), 'at_risk' => $summaryRows->whereNotIn('status', ['resolved', 'closed'])->filter(fn ($ticket) => $ticket->sla_state === 'at_risk')->count(), 'breached' => $summaryRows->whereNotIn('status', ['resolved', 'closed'])->filter(fn ($ticket) => $ticket->sla_state === 'breached')->count(), 'resolved_today' => $summaryRows->filter(fn ($ticket) => $ticket->resolved_at?->gte($today))->count(), 'average_csat' => $summaryRows->whereNotNull('csat_score')->count() ? round($summaryRows->whereNotNull('csat_score')->avg('csat_score'), 2) : null];
        $query = ListResponse::sort($query, $request, ['created_at', 'reference', 'subject', 'priority']);
        if ($request->export === 'csv') { return ListResponse::csv($query, 'tickets.csv', ['Reference', 'Subject', 'Requester', 'Email', 'Status', 'Priority', 'Category', 'Channel', 'Assignee', 'SLA', 'Created at', 'CSAT'], fn ($ticket) => [$ticket->reference, $ticket->subject, $ticket->requester['name'] ?? '', $ticket->requester['email'] ?? '', $ticket->status, $ticket->priority, $ticket->category, $ticket->channel, $ticket->assignee?->name, $ticket->sla_state, $ticket->created_at->toIso8601String(), $ticket->csat_score]); }

        return ListResponse::paginate($query, $request, $summary);
    }

    public function store(TicketRequest $request)
    {
        $ticket = $this->service->create($request->validated(), $request->user());
        request()->attributes->set('audit.entity_type', 'Ticket'); request()->attributes->set('audit.entity_id', $ticket->id);

        return response()->json($ticket, 201);
    }

    public function show(Request $request, Ticket $ticket)
    {
        $this->service->assertView($ticket, $request->user());
        // Related records load only for roles that may read them (e.g. no payment data for logistics).
        $relations = ['assignee', 'order.items', 'messages.attachments'];
        if ($request->user()->can('view payments')) { $relations[] = 'payment.refunds'; }
        if ($request->user()->can('view shipments')) { $relations[] = 'shipment'; }
        $ticket->load($relations);
        $data = $ticket->toArray();
        $data['history'] = AuditLog::with('user:id,name')->where('entity_type', 'Ticket')->where('entity_id', (string) $ticket->id)->latest('id')->get();
        // The AI's labels and reading of the ticket, when it triaged it (what it changed is in history, automation ai_ticket_triage).
        $triage = app(\App\Services\AiService::class)->latest('ticket_triage', $ticket);
        // Older insights have no ground or checks (rulebook "triage").
        // Checks saved before they stopped naming payments may still name one: hidden from roles without view payments.
        $checks = $triage?->output['checks'] ?? [];
        if (! $request->user()->can('view payments')) { $checks = array_map(fn ($check) => array_replace($check, ['detail' => preg_replace('/\b(?:PAY|RFD)-\d{4}-\d{6}\b/', 'a payment', (string) ($check['detail'] ?? ''))]), $checks); }
        $data['ai_triage'] = $triage?->ok() ? [...\Illuminate\Support\Arr::only($triage->output, ['category', 'priority', 'sentiment', 'tags', 'summary', 'reason']), 'ground' => $triage->output['ground'] ?? null, 'checks' => $checks, 'created_at' => $triage->created_at->toIso8601String()] : null;

        return response()->json($data);
    }

    public function update(TicketRequest $request, Ticket $ticket)
    {
        return response()->json($this->service->update($ticket, $request->validated(), $request->user()));
    }

    public function destroy(Request $request, Ticket $ticket)
    {
        $this->service->assertWrite($ticket, $request->user(), 'delete');
        if (Refund::where('ticket_id', $ticket->id)->exists()) { throw ValidationException::withMessages(['ticket' => ['This ticket has refund requests and cannot be deleted.']]); }
        $ticket->delete();

        return response()->json(['message' => 'Ticket deleted successfully.']);
    }

    public function assign(TicketActionRequest $request, Ticket $ticket)
    {
        return response()->json($this->service->assign($ticket, $request->assignee_id, $request->user()));
    }

    public function status(TicketActionRequest $request, Ticket $ticket)
    {
        return response()->json($this->service->transition($ticket, $request->status, $request->user(), $request->note));
    }

    public function messages(ListRequest $request, Ticket $ticket)
    {
        $this->service->assertView($ticket, $request->user()); $query = $ticket->messages()->with('attachments')->getQuery();

        return ListResponse::paginate($query->orderBy('id'), $request, ['total' => (clone $query)->count(), 'public' => (clone $query)->where('is_internal_note', false)->count(), 'internal' => (clone $query)->where('is_internal_note', true)->count()]);
    }

    // An AI reply draft for the agent (nothing is sent). fresh=true asks again even when nothing changed.
    public function aiDraft(Request $request, Ticket $ticket)
    {
        $request->validate(['fresh' => 'sometimes|boolean']);
        // Drafting changes no record; the request is kept in ai_insights instead of the audit log.
        $request->attributes->set('audit.skip', true);

        return response()->json($this->service->draftReply($ticket, $request->user(), $request->boolean('fresh')));
    }

    public function message(TicketMessageRequest $request, Ticket $ticket)
    {
        $message = $this->service->message($ticket, $request->validated(), $request->file('attachments', []), $request->user());
        request()->attributes->set('audit.entity_type', 'Ticket'); request()->attributes->set('audit.entity_id', $ticket->id); request()->attributes->set('audit.new_values', ['message' => $message->toArray()] + $request->safe()->only(['status']));

        return response()->json($message, 201);
    }

    public function download(Request $request, Attachment $attachment, AttachmentService $files)
    {
        abort_unless($attachment->ticket, 404); $this->service->assertView($attachment->ticket, $request->user());
        if ($request->user() instanceof IntegrationClient) { abort_if($attachment->message?->is_internal_note, 404); }

        return $files->download($attachment);
    }

    public function options(Request $request)
    {
        $this->requirePermission('view support');
        $user = $request->user();
        // Admin and Finance see every order; other roles only the orders linked to tickets they can see.
        $orderIds = $user->hasAnyRole(['Admin', 'Finance']) ? null : $this->service->visibleQuery($user)->whereNotNull('order_id')->select('order_id');
        $scoped = fn ($query) => $orderIds ? $query->whereIn($query->getModel() instanceof Order ? 'id' : 'order_id', $orderIds) : $query;
        $requesters = $user->can('create tickets');

        return response()->json([
            'customers' => $requesters ? Customer::orderBy('name')->get(['id', 'name', 'email', 'source_system']) : [],
            'sellers' => $requesters ? Seller::orderBy('shop_name')->get(['id', 'shop_name', 'email', 'source_system']) : [],
            'orders' => $scoped(Order::with('items')->latest('id'))->limit(200)->get(),
            'payments' => $user->can('view payments') ? $scoped(Payment::latest('id'))->limit(200)->get(['id', 'reference', 'order_id', 'status']) : [],
            'shipments' => $user->can('view shipments') ? $scoped(Shipment::latest('id'))->limit(200)->get(['id', 'reference', 'order_id', 'seller_id', 'status']) : [],
            'agents' => User::role(['Admin', 'Support Agent'])->authorizedFor('view tickets')->authorizedFor('edit tickets')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    public function integrationShow(Request $request, string $reference)
    {
        $ticket = Ticket::where('reference', $reference)->firstOrFail(); $this->service->assertView($ticket, $request->user());

        return response()->json($ticket->load(['messages' => fn ($q) => $q->where('is_internal_note', false)->with('attachments')]));
    }

    public function integrationMessage(TicketMessageRequest $request, string $reference)
    {
        $ticket = Ticket::where('reference', $reference)->firstOrFail();
        $message = $this->service->message($ticket, $request->validated(), $request->file('attachments', []), $request->user());
        request()->attributes->set('audit.entity_type', 'Ticket'); request()->attributes->set('audit.entity_id', $ticket->id);

        return response()->json($message, 201);
    }

    public function csat(TicketActionRequest $request, string $reference)
    {
        $ticket = Ticket::where('reference', $reference)->firstOrFail(); request()->attributes->set('audit.entity_type', 'Ticket'); request()->attributes->set('audit.entity_id', $ticket->id); request()->attributes->set('audit.old_values', $ticket->toArray());

        return response()->json($this->service->csat($ticket, $request->validated(), $request->user()));
    }
}
