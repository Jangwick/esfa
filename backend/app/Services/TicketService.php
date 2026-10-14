<?php

namespace App\Services;

use App\Models\AiInsight;
use App\Models\AuditLog;
use App\Models\CannedResponse;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\KbArticle;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Reference;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketService
{
    public const TRANSITIONS = ['open' => ['in_progress'], 'in_progress' => ['waiting_on_customer', 'resolved'], 'waiting_on_customer' => ['in_progress'], 'resolved' => ['closed', 'reopened'], 'closed' => ['reopened'], 'reopened' => ['in_progress']];
    // Nobody replies on a closed ticket (it is reopened first); the requester rates a ticket once it is resolved or closed.
    public const REPLY_BLOCKED_STATUSES = ['closed'];
    public const CSAT_STATUSES = ['resolved', 'closed'];
    public const SENTIMENTS = ['positive', 'neutral', 'negative', 'angry'];
    // Triage grounds (rulebook "triage") as staff read them in the checks.
    private const GROUNDS = ['duplicate_charge' => 'a duplicate charge', 'money_without_order' => 'money taken for a cancelled order', 'safety' => 'a safety problem',
        'account_security' => 'an account-security problem', 'delivery_problem' => 'a delivery problem', 'order_blocked' => 'a blocked order', 'upset_requester' => 'a very upset requester', 'none' => 'no ground'];
    private const TRIAGE_PROMPT = 'You triage customer-support tickets for Archon Nell, a Philippine online marketplace (customers, sellers, payments in PHP, courier deliveries). '
        .'category: the closest one. ground: why the ticket needs attention: duplicate_charge (charged more than once for one purchase), money_without_order (paid, but the '
        .'order was cancelled), safety (a risk to someone\'s health or safety), account_security (an account taken over or used by someone else), delivery_problem (a late, '
        .'lost or failed delivery), order_blocked (the order cannot go ahead), upset_requester (the requester is very upset), or none. priority from the ground: urgent for '
        .'duplicate_charge, money_without_order, safety or account_security; high for delivery_problem, order_blocked or upset_requester; normal for none. SFA checks money grounds against its records and keeps the priority within the ground. sentiment: from the requester\'s wording. '
        .'tags: up to 5 short lowercase tags such as late_delivery, refund_status, wrong_item. summary: one sentence on what the requester needs. reason: one sentence on why that priority. '
        .'Write in English even when the ticket is in Filipino or Taglish.';
    private const TRIAGE_SCHEMA = ['type' => 'object', 'properties' => [
        'category' => ['type' => 'string', 'enum' => Ticket::CATEGORIES], 'priority' => ['type' => 'string', 'enum' => Ticket::PRIORITIES],
        'ground' => ['type' => 'string', 'enum' => ['duplicate_charge', 'money_without_order', 'safety', 'account_security', 'delivery_problem', 'order_blocked', 'upset_requester', 'none']],
        'sentiment' => ['type' => 'string', 'enum' => self::SENTIMENTS], 'tags' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 5],
        'summary' => ['type' => 'string'], 'reason' => ['type' => 'string'],
    ], 'required' => ['category', 'priority', 'ground', 'sentiment', 'tags', 'summary', 'reason']];
    private const DRAFT_PROMPT = 'You draft a reply for a customer-support agent of Archon Nell, a Philippine online marketplace. Use only facts from the ticket, '
        .'the linked records and the knowledge base; never invent a policy, date, amount, tracking detail or promise. When the knowledge base and the records do not '
        .'answer the request, write a short, polite holding reply and say in needs_human_reason what the agent must check (otherwise leave it empty). Greet the '
        .'requester as {first_name}. Answer in the language the requester used (English, Filipino or Taglish), warm and concise, under 150 words, with no '
        .'signature (the agent adds it). Never mention or ask for a password, one-time code (OTP), PIN, CVV or card number, and use only amounts '
        .'and references found in the linked records. source_article_ids: the ids of the knowledge-base articles you '
        .'used. confidence: high when the reply fully answers the request from the facts given, medium when partly, low when the agent must add facts.';
    private const DRAFT_SCHEMA = ['type' => 'object', 'properties' => [
        'reply' => ['type' => 'string'], 'source_article_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
        'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']], 'needs_human_reason' => ['type' => 'string'],
    ], 'required' => ['reply', 'source_article_ids', 'confidence', 'needs_human_reason']];

    public function visibleQuery(User $user)
    {
        abort_unless($user->can('view tickets'), 403, 'This action requires view tickets.');
        $query = Ticket::query();
        if ($user->hasRole('Admin') || $user->hasRole('Finance')) { return $query; }
        if ($user->hasRole('Support Agent')) { return $query->where(fn ($q) => $q->whereNull('assignee_id')->orWhere('assignee_id', $user->id)); }
        if ($user->hasRole('Logistics Coordinator')) { return $query->whereNotNull('shipment_id'); }
        abort(403, 'This action requires access to support tickets.');
    }

    public function assertView(Ticket $ticket, User|IntegrationClient $actor): void
    {
        if ($actor instanceof IntegrationClient) { abort_unless($ticket->source_system === $actor->system_code, 404); return; }
        abort_unless($this->visibleQuery($actor)->whereKey($ticket->id)->exists(), 404);
    }

    public function assertWrite(Ticket $ticket, User $actor, string $action = 'edit'): void
    {
        abort_unless($actor->can($action.' tickets'), 403, 'This action requires '.$action.' tickets.');
        $this->assertView($ticket, $actor);
    }

    // A null actor is SFA itself (channel system, e.g. the delivery ticket after a failed attempt): its first message is a
    // system message rather than the requester's.
    public function create(array $values, User|IntegrationClient|null $actor): Ticket
    {
        if ($actor instanceof User) { abort_unless($actor->can('create tickets'), 403, 'This action requires create tickets.'); }

        return DB::transaction(function () use ($values, $actor) {
            $values = $this->resolveIntegration($values, $actor);
            $requester = ($values['requester_type'] === 'seller' ? Seller::class : Customer::class)::find($values['requester_id']);
            if (! $requester) { throw ValidationException::withMessages(['requester_id' => ['The requester mirror record does not exist.']]); }
            if ($actor instanceof IntegrationClient && $requester->source_system !== $actor->system_code) { abort(404); }
            $values['source_system'] = $requester->source_system;
            $values['priority'] ??= 'normal'; $values['tags'] ??= []; $values['status'] = 'open';
            $values = $this->autoLink($values, $requester);
            $this->validateLinks($values, $requester);
            $hours = SlaPolicy::hoursFor($values['priority']);
            $values['first_response_due_at'] = now()->addHours($hours['first_response_hours']); $values['resolution_due_at'] = now()->addHours($hours['resolution_hours']);
            if (Setting::valueFor('auto_assign', false) && ($agent = $this->leastLoadedAgent())) { $values['assignee_id'] = $agent->id; }
            $ticket = Ticket::create($values); Reference::assign($ticket, 'TCK');
            TicketMessage::create(['ticket_id' => $ticket->id, 'author_type' => $actor ? $ticket->requester_type : 'system', 'author_id' => $actor ? $ticket->requester_id : null, 'body' => $ticket->description, 'is_internal_note' => false]);
            // Optional acknowledgement of a request someone sent: public, so the requester sees it, but it leaves
            // first_responded_at alone and the first-response SLA keeps running until a person answers.
            $first = $hours['first_response_hours'];
            $ack = $actor && Setting::valueFor('ticket_auto_acknowledge') ? TicketMessage::create(['ticket_id' => $ticket->id, 'author_type' => 'system', 'author_id' => null, 'body' => 'Thank you for contacting us. We received your request '.$ticket->reference.' and will reply within '.$first.' '.Str::plural('hour', $first).'.', 'is_internal_note' => false]) : null;
            if ($ticket->assignee_id) { $this->notifyAssignment($ticket); }
            $this->emit('ticket.created', $ticket);
            // AI triage once the ticket is saved (it does nothing while ai_ticket_triage is off); failures leave it as is.
            $id = $ticket->id; DB::afterCommit(fn () => rescue(fn () => $this->triage($id)));
            if ($ack) { $this->emit('ticket.replied', $ticket, ['message' => $ack->toArray()]); }

            return $ticket->fresh(['assignee', 'messages.attachments']);
        });
    }

    public function update(Ticket $ticket, array $values, User $actor): Ticket
    {
        $this->assertWrite($ticket, $actor);

        return DB::transaction(function () use ($ticket, $values, $actor) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $this->assertWrite($ticket, $actor);
            $links = array_replace($ticket->getAttributes(), $values);
            $requester = ($ticket->requester_type === 'seller' ? Seller::class : Customer::class)::findOrFail($ticket->requester_id);
            $this->validateLinks($links, $requester);
            $priority = $values['priority'] ?? $ticket->priority;
            unset($values['priority']);
            $ticket->fill($values);
            $this->changePriorityLocked($ticket, $priority);
            $ticket->save();

            return $ticket->fresh('assignee');
        });
    }

    // Used by staff edits and delivery escalation after the caller has locked this ticket.
    public function changePriorityLocked(Ticket $ticket, string $priority): void
    {
        if (! in_array($priority, Ticket::PRIORITIES, true)) { throw ValidationException::withMessages(['priority' => ['The selected priority is invalid.']]); }
        if ($ticket->priority === $priority) { return; }
        $hours = SlaPolicy::hoursFor($priority);
        $ticket->priority = $priority;
        $ticket->first_response_due_at = $ticket->created_at->copy()->addHours($hours['first_response_hours']);
        $ticket->resolution_due_at = $ticket->created_at->copy()->addHours($hours['resolution_hours'])->addSeconds($ticket->resolution_paused_seconds);
        $ticket->sla_at_risk_notified_at = null; $ticket->sla_breached_notified_at = null;
        $ticket->save();
    }

    /**
     * AI triage of a ticket a person or partner opened: replaces only the category "other", raises only the default
     * priority "normal" (the SLA follows), and adds tags plus ai_triaged; staff can change all of it. Gemini is asked
     * before the lock is taken. Returns whether the ticket was labelled.
     */
    public function triage(int $id, int $timeout = 8): bool
    {
        $ai = app(AiService::class);
        if ($ai->unavailable('ticket_triage')) { return false; }
        $ticket = Ticket::with(['order:id,status_snapshot', 'payment:id,status', 'shipment:id,status,attempt_count'])->find($id);
        if (! $ticket || ! $this->triageable($ticket)) { return false; }
        $insight = $ai->json('ticket_triage', $ticket, self::TRIAGE_PROMPT, [
            'subject' => $ticket->subject, 'message' => mb_substr((string) $ticket->description, 0, 4000), 'channel' => $ticket->channel,
            'requester' => $ticket->requester_type, 'chosen_category' => $ticket->category,
            'linked' => array_filter(['order_status' => $ticket->order?->status_snapshot, 'payment_status' => $ticket->payment?->status, 'shipment_status' => $ticket->shipment?->status, 'delivery_attempts' => $ticket->shipment?->attempt_count]),
        ], self::TRIAGE_SCHEMA, ['timeout' => $timeout, 'user' => null, 'patient' => false]);
        if (! $insight->ok()) { return false; }
        $answer = $insight->output;
        $check = Validator::make($answer, ['category' => ['required', Rule::in(Ticket::CATEGORIES)], 'priority' => ['required', Rule::in(Ticket::PRIORITIES)], 'ground' => ['required', Rule::in(array_keys(self::GROUNDS))], 'sentiment' => ['required', Rule::in(self::SENTIMENTS)], 'tags' => 'present|array|max:5', 'tags.*' => 'string|max:40', 'summary' => 'required|string|max:400', 'reason' => 'required|string|max:400']);
        if ($check->fails()) { $insight->reject('The answer did not fit SFA\'s lists: '.$check->errors()->first()); return false; }

        $checks = DB::transaction(function () use ($id, $answer) {
            $ticket = Ticket::with(['order', 'payment'])->whereKey($id)->lockForUpdate()->first();
            if (! $ticket || ! $this->triageable($ticket)) { return null; }
            $old = $ticket->only(['category', 'priority', 'tags']);
            [$priority, $results] = $this->triageRules($ticket, $answer);
            if ($ticket->category === 'other') { $ticket->category = $answer['category']; }
            $tags = collect($answer['tags'])->map(fn ($tag) => Str::slug($tag, '_'))->filter()->take(5)->all();
            $ticket->tags = array_values(array_unique([...($ticket->tags ?? []), ...$tags, 'ai_triaged']));
            if ($ticket->priority === 'normal' && in_array($priority, ['high', 'urgent'], true)) { $this->changePriorityLocked($ticket, $priority); }
            $ticket->save();
            AuditLog::system($ticket, $old, $ticket->only(['category', 'priority', 'tags']), 'ai_ticket_triage');

            return AiRules::merge('triage', $results);
        });
        if ($checks === null) { return false; }
        $insight->update(['output' => [...$answer, 'checks' => $checks]]);

        return true;
    }

    /**
     * The priority the AI's ground allows (urgent for a money ground only when SFA's records confirm it, otherwise at most
     * high) capped to what the AI asked for, and TRI-1..TRI-4 on what was decided. Called before the ticket is changed.
     */
    private function triageRules(Ticket $ticket, array $answer): array
    {
        $rank = fn (string $priority) => array_search($priority, Ticket::PRIORITIES, true);
        $label = fn (string $value) => Str::ucfirst(str_replace('_', ' ', $value));
        ['ground' => $ground, 'priority' => $asked] = $answer;
        $money = in_array($ground, ['duplicate_charge', 'money_without_order'], true);
        $proof = $money ? $this->moneyProof($ticket, $ground) : null;
        $allowed = match (true) { in_array($ground, ['safety', 'account_security'], true) => 'urgent', $money => $proof ? 'urgent' : 'high', $ground === 'none' => 'normal', default => 'high' };
        $granted = $rank($asked) > $rank($allowed) ? $allowed : $asked;
        $why = self::GROUNDS[$ground];
        $missing = $ground === 'duplicate_charge' ? 'SFA\'s records show no second captured payment of the same amount' : 'SFA\'s records show no captured payment on a cancelled order';
        // A person's priority is never changed, so TRI-2 and TRI-3 have nothing to judge.
        $chosen = $ticket->priority !== 'normal' ? 'A person chose the priority; nothing was applied.' : null;

        // SFA enforces TRI-1 itself (it only ever raises Normal), so the rule always passes; a Low suggestion is noted.
        return [$granted, [
            AiRules::result('TRI-1', 'pass', match (true) {
                $chosen !== null && $rank($asked) < $rank($ticket->priority) => 'Suggested '.$label($asked).'; SFA never lowers a priority, kept the priority '.$label($ticket->priority).' a person chose.',
                $chosen !== null => 'Kept the priority '.$label($ticket->priority).' a person chose.',
                $rank($asked) < $rank('normal') => 'Suggested '.$label($asked).'; SFA never lowers a priority, kept at Normal.',
                $rank($granted) > $rank('normal') => 'Raised the default priority Normal to '.$label($granted).'.',
                default => 'Kept the default priority Normal.',
            }),
            $chosen !== null ? AiRules::result('TRI-2', 'not_applicable', $chosen) : ($asked !== 'urgent' ? AiRules::result('TRI-2', 'not_applicable', 'The AI did not ask for Urgent.')
                : ($granted === 'urgent' ? AiRules::result('TRI-2', 'pass', 'Urgent for '.$why.($proof ? ': '.$proof : '').'.')
                    : AiRules::result('TRI-2', 'fail', 'Asked for Urgent for '.$why.($money ? ', but '.$missing : '').'; capped at '.$label($granted).'.'))),
            $chosen !== null ? AiRules::result('TRI-3', 'not_applicable', $chosen) : ($asked !== 'high' && $granted !== 'high' ? AiRules::result('TRI-3', 'not_applicable', 'The AI did not ask for High.')
                : ($granted === 'high' ? AiRules::result('TRI-3', 'pass', 'High for '.$why.'.') : AiRules::result('TRI-3', 'fail', 'Asked for High for '.$why.'; capped at '.$label($granted).'.'))),
            AiRules::result('TRI-4', 'pass', $ticket->category !== 'other' ? 'Kept the category '.$label($ticket->category).' a person chose.'
                : ($answer['category'] === 'other' ? 'Kept the category Other.' : 'Replaced the category Other with '.$label($answer['category']).'.')),
        ]];
    }

    // What in SFA's records confirms a money ground, in words, or null. Captured means captured or partially refunded.
    // The words name no payment reference or amount: every viewer of the ticket sees the checks, including roles without
    // view payments, and the twin may be a payment from another order.
    private function moneyProof(Ticket $ticket, string $ground): ?string
    {
        $orderIds = array_values(array_filter([$ticket->order_id, $ticket->payment?->order_id]));
        $captured = Payment::whereIn('status', ['captured', 'partially_refunded'])->orderBy('id');
        if ($ground === 'money_without_order') {
            return $captured->whereIn('order_id', $orderIds)->whereHas('order', fn ($query) => $query->where('status_snapshot', 'cancelled'))->exists()
                ? 'SFA\'s records show a captured payment on this cancelled order' : null;
        }
        // Duplicate charge: the ticket's payment (or its order's payments) has another captured payment of the same amount for
        // the same order or customer, or a second captured payment on the same order.
        $customerIds = array_values(array_filter([$ticket->requester_type === 'customer' ? $ticket->requester_id : null, $ticket->order?->customer_id, $ticket->payment?->customer_id]));
        $payments = $captured->where(fn ($query) => $query->whereIn('order_id', $orderIds)->orWhereIn('customer_id', $customerIds))->get(['id', 'order_id', 'total_amount']);
        foreach ($payments->filter(fn ($payment) => $payment->id == $ticket->payment_id || in_array($payment->order_id, $orderIds)) as $payment) {
            $twin = $payments->first(fn ($other) => $other->id !== $payment->id && ($other->total_amount === $payment->total_amount || ($payment->order_id && $other->order_id == $payment->order_id)));
            if ($twin) { return 'SFA\'s records show a second captured payment '.($twin->total_amount === $payment->total_amount ? 'of the same amount for this customer' : 'on the same order'); }
        }

        return null;
    }

    /**
     * An AI reply draft for the agent to edit and send (it is never sent here). Built from the published knowledge base,
     * the shared canned responses, the last 10 public messages (masked by AiService) and the linked order, payment and
     * shipment the agent may view. Amounts and states are SFA's; the model only words them.
     */
    public function draftReply(Ticket $ticket, User $actor, bool $fresh = false): array
    {
        $this->assertWrite($ticket, $actor);
        if ($ticket->status === 'closed') { throw ValidationException::withMessages(['ticket' => ['This ticket is closed. Reopen it before drafting a reply.']]); }
        $articles = KbArticle::where('status', 'published')->orderBy('id')->limit(40)->get(['id', 'title', 'body']);
        $canned = CannedResponse::where(fn ($query) => $query->where('is_shared', true)->orWhere('user_id', $actor->id))->orderBy('id')->limit(40)->get(['title', 'body']);
        $ticket->loadMissing(['order.items', 'payment.refunds', 'shipment.courier']);
        $payment = $actor->can('view payments') ? $ticket->payment : null;
        $shipment = $actor->can('view shipments') ? $ticket->shipment : null;
        $linked = array_filter([
            'order' => $ticket->order ? ['status' => $ticket->order->status_snapshot, 'total' => AiService::peso($ticket->order->total_amount), 'items' => $ticket->order->items->map(fn ($item) => $item->quantity.' x '.$item->product_name)->all(), 'placed_at' => $ticket->order->placed_at?->toIso8601String()] : null,
            'payment' => $payment ? ['reference' => $payment->reference, 'method' => $payment->method, 'status' => $payment->status, 'paid' => AiService::peso($payment->captured_amount), 'refunded' => AiService::peso($payment->refunded_amount), 'refunds' => $payment->refunds->map(fn ($refund) => ['reference' => $refund->reference, 'status' => $refund->status, 'amount' => AiService::peso($refund->total_amount)])->all()] : null,
            'shipment' => $shipment ? ['reference' => $shipment->reference, 'status' => $shipment->status, 'courier' => $shipment->courier?->name, 'tracking_number' => $shipment->tracking_number, 'promised_between' => $shipment->promised_from?->toDateString().' and '.$shipment->promised_to?->toDateString(), 'delivery_attempts' => $shipment->attempt_count, 'delivered_at' => $shipment->delivered_at?->toIso8601String()] : null,
        ]);
        // The centavo amounts the model was given; RPL-2 allows only these in the draft.
        $amounts = [...($ticket->order ? [$ticket->order->total_amount] : []), ...($payment ? [$payment->captured_amount, $payment->refunded_amount, ...$payment->refunds->pluck('total_amount')->all()] : [])];
        $messages = $ticket->messages()->where('is_internal_note', false)->reorder()->latest('id')->limit(10)->get(['author_type', 'body', 'created_at'])->reverse()
            ->map(fn ($message) => ['from' => $message->author_type === 'user' ? 'agent' : $message->author_type, 'at' => $message->created_at->toIso8601String(), 'text' => mb_substr($message->body, 0, 2000)])->values()->all();
        $guidelines = collect(AiRules::book('reply')['rules'])->map(fn ($rule) => '- '.$rule['id'].' '.$rule['title'].': '.$rule['description'])->implode("\n");
        $schema = self::DRAFT_SCHEMA;
        $schema['properties']['checks'] = AiRules::aiSchema('reply');
        $schema['required'][] = 'checks';
        $insight = app(AiService::class)->json('reply_drafts', $ticket, self::DRAFT_PROMPT."\n\nReply guidelines (SFA checks the others on your draft). checks: your verdict on "
            .implode(' and ', array_column(AiRules::aiRules('reply'), 'id'))." about your own draft (pass, fail, unclear or not_applicable), each with a one-sentence reason.\n".$guidelines, [
            'knowledge_base' => $articles->map(fn ($article) => ['id' => $article->id, 'title' => $article->title, 'body' => mb_substr($article->body, 0, 3000)])->all(),
            'canned_responses' => $canned->map(fn ($response) => ['title' => $response->title, 'body' => mb_substr($response->body, 0, 1500)])->all(),
            'ticket' => ['subject' => $ticket->subject, 'status' => $ticket->status, 'category' => $ticket->category, 'requester' => $ticket->requester_type],
            'linked_records' => $linked, 'conversation' => $messages,
        ], $schema, ['thinking' => 'medium', 'fresh' => $fresh, 'user' => $actor->id]);
        abort_unless($insight->ok(), 503, $insight->error);
        $answer = $insight->output;
        $check = Validator::make($answer, ['reply' => 'required|string|max:20000', 'source_article_ids' => 'present|array', 'source_article_ids.*' => 'integer', 'confidence' => 'required|in:high,medium,low', 'needs_human_reason' => 'present|nullable|string|max:1000', 'checks' => 'array']);
        if ($check->fails()) { $insight->reject('The draft did not have the expected parts: '.$check->errors()->first()); abort(503, 'The AI draft could not be used. Try again.'); }
        // Only articles that were actually sent and are still published can be cited.
        $sources = $articles->whereIn('id', $answer['source_article_ids'])->map(fn ($article) => ['id' => $article->id, 'title' => $article->title])->values()->all();
        $checks = AiRules::merge('reply', $this->replyRules($ticket, $answer['reply'], $amounts), $answer['checks'] ?? []);

        return ['reply' => $answer['reply'], 'sources' => $sources, 'confidence' => $answer['confidence'], 'needs_human_reason' => $answer['needs_human_reason'] ?: null,
            'checks' => $checks, 'needs_correction' => AiRules::failures($checks, 'required') > 0, 'created_at' => $insight->created_at->toIso8601String()];
    }

    // RPL-1..RPL-5: what SFA checks on the draft text itself. $amounts are the centavo amounts the model was given.
    private function replyRules(Ticket $ticket, string $reply, array $amounts): array
    {
        // PIN only in capitals (or MPIN / "pin code"): "paki-pin" and "pin your location" mean a map pin.
        preg_match('/\b(?:pass(?:word|code)|otp|one[- ]time (?:code|password|pin)|verification code|(?-i:PIN)|mpin|pin (?:code|number)|cvv|cvc|card (?:number|no)|numero ng (?:iyong |inyong )?card)\b/iu', $reply, $secret);
        // PHP 1,234.00, php 1,234, ₱1,234 or P1,234.00, as centavos (a lone P only in capitals).
        preg_match_all('/(?:\b(?i:PHP)|\bP|₱)\s?(\d[\d,]*)(?:\.(\d{1,2}))?/u', $reply, $found, PREG_SET_ORDER);
        $written = array_map(fn ($match) => (int) str_replace(',', '', $match[1]) * 100 + (int) str_pad($match[2] ?? '', 2, '0'), $found);
        $unknown = array_unique(array_map(AiService::peso(...), array_diff($written, $amounts)));
        preg_match_all('/\b(?:PAY|RFD|SHP|TCK)-\d{4}-\d{6}\b|\bTCK-\d{6}\b/', $reply, $references);
        $own = array_filter([$ticket->reference, $ticket->payment?->reference, $ticket->shipment?->reference, ...($ticket->payment?->refunds->pluck('reference')->all() ?? [])]);
        $foreign = array_unique(array_diff($references[0], $own));
        $words = count(preg_split('/\s+/u', trim($reply), -1, PREG_SPLIT_NO_EMPTY));

        return [
            AiRules::result('RPL-1', $secret ? 'fail' : 'pass', $secret ? 'The draft mentions "'.$secret[0].'"; a reply never asks for a password, one-time code, PIN or card number.' : 'Asks for no password, one-time code, PIN or card number.'),
            AiRules::result('RPL-2', $unknown ? 'fail' : 'pass', $unknown ? 'Not in the ticket\'s order, payment or refund records: '.implode(', ', $unknown).'.' : ($written ? 'Every amount matches the ticket\'s records.' : 'The draft names no amount.')),
            AiRules::result('RPL-3', $foreign ? 'fail' : 'pass', $foreign ? 'Not this ticket\'s records: '.implode(', ', $foreign).'.' : ($references[0] ? 'Every reference belongs to this ticket.' : 'The draft names no reference.')),
            AiRules::result('RPL-4', $words <= 150 ? 'pass' : 'fail', $words.' words.'),
            AiRules::result('RPL-5', str_contains($reply, '{first_name}') ? 'pass' : 'fail', str_contains($reply, '{first_name}') ? 'Greets the requester as {first_name}.' : 'The draft does not use {first_name}.'),
        ];
    }

    // SFA's own tickets (delivery tickets) are not triaged, and nor is a ticket that is finished or already labelled.
    private function triageable(Ticket $ticket): bool
    {
        return $ticket->channel !== 'system' && ! in_array($ticket->status, ['resolved', 'closed'], true) && ! in_array('ai_triaged', $ticket->tags ?? [], true);
    }

    public function assign(Ticket $ticket, int|string $assignee, User $actor): Ticket
    {
        $this->assertWrite($ticket, $actor);
        $id = $assignee === 'me' ? $actor->id : (int) $assignee;
        if (! $actor->hasRole('Admin') && $id !== $actor->id) { abort(403, 'Support agents may only assign tickets to themselves.'); }
        $agent = User::role(['Admin', 'Support Agent'])->authorizedFor('view tickets')->authorizedFor('edit tickets')->where('is_active', true)->find($id);
        if (! $agent) { throw ValidationException::withMessages(['assignee_id' => ['Choose an active Support Agent or Admin.']]); }

        return DB::transaction(function () use ($ticket, $id, $actor) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $this->assertWrite($ticket, $actor);
            if ($ticket->assignee_id !== $id) { $ticket->update(['assignee_id' => $id]); $this->notifyAssignment($ticket); }

            return $ticket->fresh('assignee');
        });
    }

    public function transition(Ticket $ticket, string $status, ?User $actor, ?string $note = null): Ticket
    {
        if ($actor) { $this->assertWrite($ticket, $actor); }

        return DB::transaction(function () use ($ticket, $status, $actor, $note) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($actor) { $this->assertWrite($ticket, $actor); }
            $this->applyTransition($ticket, $status, $actor, $note);

            return $ticket->fresh('assignee');
        });
    }

    // Moves a ticket the caller has already locked; writes the status note and the webhook.
    private function applyTransition(Ticket $locked, string $status, ?User $actor, ?string $note): void
    {
        $this->assertTransition($locked->status, $status);
        $from = $locked->status;
        if ($from === 'waiting_on_customer' && $locked->resolution_paused_at) {
            $seconds = max(0, (int) $locked->resolution_paused_at->diffInSeconds(now()));
            $locked->resolution_due_at = $locked->resolution_due_at->copy()->addSeconds($seconds); $locked->resolution_paused_seconds += $seconds; $locked->resolution_paused_at = null;
        }
        if ($status === 'waiting_on_customer') { $locked->resolution_paused_at = now(); }
        if ($status === 'resolved') { $locked->resolved_at = now(); }
        if ($status === 'closed') { $locked->closed_at = now(); }
        if ($status === 'reopened') { $locked->resolved_at = null; $locked->closed_at = null; $locked->csat_score = null; $locked->csat_comment = null; }
        $locked->status = $status; $locked->save();
        TicketMessage::create(['ticket_id' => $locked->id, 'author_type' => 'system', 'author_id' => $actor?->id, 'body' => 'Status changed from '.str_replace('_', ' ', $from).' to '.str_replace('_', ' ', $status).'.'.($note ? ' '.$note : ''), 'is_internal_note' => true]);
        $this->emit('ticket.status_changed', $locked);
    }

    private function assertTransition(string $from, string $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) { throw ValidationException::withMessages(['status' => ['This ticket cannot move from '.$from.' to '.$to.'.']]); }
    }

    public function message(Ticket $ticket, array $values, array $files, User|IntegrationClient $actor): TicketMessage
    {
        if ($actor instanceof User) { $this->assertWrite($ticket, $actor); } else { $this->assertView($ticket, $actor); }

        return DB::transaction(function () use ($ticket, $values, $files, $actor) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if (in_array($ticket->status, self::REPLY_BLOCKED_STATUSES, true)) { throw ValidationException::withMessages(['body' => ['Reopen the ticket before adding a reply.']]); }
            if ($actor instanceof User) { $this->assertWrite($ticket, $actor); } else { $this->assertView($ticket, $actor); }
            $staff = $actor instanceof User;
            $internal = $staff && (bool) ($values['is_internal_note'] ?? false);
            // The status follows the conversation: a public staff reply starts the work, a requester reply on a waiting
            // ticket hands it back to staff. A status chosen with the reply is checked first, so a 422 saves nothing.
            $follow = $staff ? ! $internal && in_array($ticket->status, ['open', 'reopened'], true) : $ticket->status === 'waiting_on_customer';
            $chosen = $staff && ! $internal ? ($values['status'] ?? null) : null;
            if ($chosen) { $this->assertTransition($follow ? 'in_progress' : $ticket->status, $chosen); }
            $message = TicketMessage::create(['ticket_id' => $ticket->id, 'author_type' => $staff ? 'user' : $ticket->requester_type, 'author_id' => $staff ? $actor->id : $ticket->requester_id, 'body' => $values['body'], 'is_internal_note' => $internal]);
            foreach ($files as $file) { app(AttachmentService::class)->store($file, $message, $staff ? $actor : null); }
            $old = $ticket->only(['status', 'assignee_id']);
            if ($staff && ! $internal) {
                $ticket->first_responded_at ??= now();
                if (! $ticket->assignee_id && $actor->hasRole('Support Agent')) { $ticket->assignee_id = $actor->id; }
                $ticket->save();
            }
            if ($follow) { $this->applyTransition($ticket, 'in_progress', $staff ? $actor : null, $staff ? 'Moved automatically by the reply.' : 'Moved automatically: the '.$ticket->requester_type.' replied.'); }
            if ($old !== $ticket->only(['status', 'assignee_id'])) { AuditLog::system($ticket, $old, $ticket->only(['status', 'assignee_id']), 'ticket_reply_follow_up'); }
            if ($chosen) { $this->applyTransition($ticket, $chosen, $actor, null); }
            if (! $staff && $this->activeAssigneeIds($ticket)) {
                Notification::create(['user_id' => $ticket->assignee_id, 'type' => 'ticket_customer_replied', 'title' => ucfirst($ticket->requester_type).' replied', 'message' => $ticket->reference.': '.$ticket->subject, 'data' => ['ticket_id' => $ticket->id]]);
            }
            if (! $internal) { $this->emit('ticket.replied', $ticket, ['message' => $message->load('attachments')->toArray()]); }

            return $message->fresh('attachments');
        });
    }

    public function csat(Ticket $ticket, array $values, IntegrationClient $actor): Ticket
    {
        $this->assertView($ticket, $actor);

        return DB::transaction(function () use ($ticket, $values) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if (! in_array($ticket->status, self::CSAT_STATUSES, true)) { throw ValidationException::withMessages(['score' => ['A satisfaction score can only be submitted after resolution.']]); }
            $wasLow = $ticket->csat_score !== null && $ticket->csat_score <= 2;
            $ticket->update(['csat_score' => $values['score'], 'csat_comment' => $values['comment'] ?? null]);
            // A score of 2 or below alerts the Admins and the assignee once; re-submitting a low score does not repeat it.
            if ($ticket->csat_score <= 2 && ! $wasLow) {
                $ids = $this->activeAdminIds();
                $ids = [...$ids, ...$this->activeAssigneeIds($ticket)];
                foreach (array_unique($ids) as $id) { Notification::create(['user_id' => $id, 'type' => 'ticket_low_csat', 'title' => 'Low satisfaction score', 'message' => $ticket->reference.': rated '.$ticket->csat_score.' out of 5', 'data' => ['ticket_id' => $ticket->id]]); }
            }

            return $ticket->fresh();
        });
    }

    private function resolveIntegration(array $values, User|IntegrationClient|null $actor): array
    {
        if (! ($actor instanceof IntegrationClient)) { return $values; }
        $model = $values['requester_type'] === 'seller' ? Seller::class : Customer::class;
        $values['requester_id'] = $model::where('source_system', $actor->system_code)->where('external_id', $values['requester_external_id'])->firstOrFail()->id;
        foreach (['order_external_id' => ['order_id', Order::class, 'external_id'], 'payment_reference' => ['payment_id', Payment::class, 'reference'], 'shipment_reference' => ['shipment_id', Shipment::class, 'reference']] as $field => [$target, $class, $column]) {
            if (! empty($values[$field])) {
                $query = $class::where($column, $values[$field]);
                if ($class === Order::class) { $query->where('source_system', $actor->system_code); }
                else { $query->whereHas('order', fn ($q) => $q->where('source_system', $actor->system_code)); }
                $values[$target] = $query->firstOrFail()->id;
            }
            unset($values[$field]);
        }
        unset($values['requester_external_id']);

        return $values;
    }

    // A payment or delivery question about an order with exactly one payment or shipment is about that record, so link it
    // when the creator left it empty. validateLinks still checks the order belongs to the requester.
    private function autoLink(array $values, Customer|Seller $requester): array
    {
        if (empty($values['order_id'])) { return $values; }
        $only = fn ($query) => ($ids = $query->limit(2)->pluck('id'))->count() === 1 ? $ids->first() : null;
        $category = $values['category'] ?? null;
        if (empty($values['payment_id']) && in_array($category, ['payment', 'refund_return'], true)) { $values['payment_id'] = $only(Payment::where('order_id', $values['order_id'])); }
        if (empty($values['shipment_id']) && in_array($category, ['delivery', 'refund_return'], true)) { $values['shipment_id'] = $only(Shipment::where('order_id', $values['order_id'])->when($requester instanceof Seller, fn ($q) => $q->where('seller_id', $requester->id))); }

        return $values;
    }

    private function validateLinks(array $values, Customer|Seller $requester): void
    {
        $order = ! empty($values['order_id']) ? Order::with('items')->findOrFail($values['order_id']) : null;
        if ($order && ($order->source_system !== $requester->source_system || ($requester instanceof Customer ? $order->customer_id !== $requester->id : ! $order->items->contains('seller_id', $requester->id)))) { throw ValidationException::withMessages(['order_id' => ['The order must belong to this requester and source system.']]); }
        foreach (['payment_id' => Payment::class, 'shipment_id' => Shipment::class] as $field => $class) {
            if (! empty($values[$field])) {
                $record = $class::findOrFail($values[$field]);
                if (! $order || $record->order_id !== $order->id) { throw ValidationException::withMessages([$field => ['Link the corresponding order before selecting this record.']]); }
                if ($field === 'shipment_id' && $requester instanceof Seller && $record->seller_id !== $requester->id) { throw ValidationException::withMessages([$field => ['This shipment belongs to another seller.']]); }
            }
        }
    }

    private function activeAdminIds(): array
    {
        return User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', 'Admin'))->pluck('id')->all();
    }

    private function activeAssigneeIds(Ticket $ticket): array
    {
        return $ticket->assignee_id ? User::authorizedFor('view tickets')->where('is_active', true)->whereKey($ticket->assignee_id)->pluck('id')->all() : [];
    }

    // Call inside a transaction: the agent rows are locked so concurrent assignments see each other's counts.
    private function leastLoadedAgent(): ?User
    {
        User::role('Support Agent')->authorizedFor('view tickets')->authorizedFor('edit tickets')->where('is_active', true)->orderBy('id')->lockForUpdate()->get(['id']);

        return User::role('Support Agent')->authorizedFor('view tickets')->authorizedFor('edit tickets')->where('is_active', true)->withCount(['assignedTickets as active_tickets_count' => fn ($q) => $q->whereNotIn('status', ['resolved', 'closed'])])->orderBy('active_tickets_count')->orderBy('id')->first();
    }

    // A deactivated agent (or one who lost the Admin and Support Agent roles) cannot work their open tickets, and Support
    // Agents only see unassigned or their own, so the tickets go back to the unassigned queue. Run inside the caller's
    // transaction; resolved and closed tickets keep their assignee for the record.
    public function releaseAssignments(User $user): int
    {
        return Ticket::where('assignee_id', $user->id)->whereNotIn('status', ['resolved', 'closed'])->update(['assignee_id' => null]);
    }

    private function notifyAssignment(Ticket $ticket): void
    {
        if (! $this->activeAssigneeIds($ticket)) { return; }
        Notification::create(['user_id' => $ticket->assignee_id, 'type' => 'ticket_assigned', 'title' => 'Ticket assigned to you', 'message' => $ticket->reference.': '.$ticket->subject, 'data' => ['ticket_id' => $ticket->id]]);
    }

    private function emit(string $event, Ticket $ticket, array $extra = []): void
    {
        app(WebhookService::class)->emitFor($event, array_merge(['reference' => $ticket->reference, 'status' => $ticket->status, 'subject' => $ticket->subject, 'requester_type' => $ticket->requester_type, 'requester_external_id' => $ticket->requester['external_id'] ?? null], $extra), $ticket->source_system);
    }

    public function runScheduled(): array
    {
        $result = ['tickets_auto_assigned' => 0, 'ticket_sla_notifications' => 0, 'tickets_auto_closed' => 0, 'tickets_auto_resolved' => 0, 'tickets_ai_triaged' => 0];
        // Off by default: a ticket that has waited on the customer this many days with no reply from them is resolved
        // (through in_progress, so the paused SLA time is credited) and tagged auto_resolved. Runs first so the tickets it
        // resolves are neither assigned nor alerted below.
        $days = (int) Setting::valueFor('ticket_waiting_auto_resolve_days');
        if ($days > 0) {
            $cutoff = now()->subDays($days);
            $requesterReplied = fn ($q) => $q->whereIn('author_type', ['customer', 'seller'])->whereColumn('ticket_messages.created_at', '>', 'tickets.resolution_paused_at');
            foreach (Ticket::where('status', 'waiting_on_customer')->where('resolution_paused_at', '<=', $cutoff)->whereDoesntHave('messages', $requesterReplied)->orderBy('id')->limit(100)->toBase()->pluck('id') as $id) {
                try {
                    DB::transaction(function () use ($id, $cutoff, $days, &$result) {
                        $ticket = Ticket::whereKey($id)->lockForUpdate()->firstOrFail();
                        if ($ticket->status !== 'waiting_on_customer' || ! $ticket->resolution_paused_at || $ticket->resolution_paused_at->gt($cutoff)
                            || $ticket->messages()->whereIn('author_type', ['customer', 'seller'])->where('created_at', '>', $ticket->resolution_paused_at)->exists()) { return; }
                        $old = $ticket->only(['status', 'tags']);
                        $this->applyTransition($ticket, 'in_progress', null, null);
                        $ticket->tags = array_values(array_unique([...($ticket->tags ?? []), 'auto_resolved']));
                        $this->applyTransition($ticket, 'resolved', null, 'Automatically resolved after '.$days.' '.Str::plural('day', $days).' without a reply from the '.$ticket->requester_type.'.');
                        AuditLog::system($ticket, $old, $ticket->only(['status', 'tags']), 'ticket_auto_resolve'); $result['tickets_auto_resolved']++;
                    });
                } catch (\Throwable $exception) { report($exception); }
            }
        }
        // AI triage catch-up, before the SLA alerts since it may raise a priority: open tickets from the last day it has not
        // labelled (Gemini was busy, or the switch was turned on later), at most 3 tries each and 5 tickets per run.
        // ponytail: 5 per run keeps a run short (each try may wait up to 8 s); raise it if tickets arrive faster.
        if (! app(AiService::class)->unavailable('ticket_triage')) {
            $tries = AiInsight::where('feature', 'ticket_triage')->where('subject_type', 'Ticket')->where('created_at', '>=', now()->subDays(2))->selectRaw('subject_id, count(*) as tries')->groupBy('subject_id')->pluck('tries', 'subject_id');
            $candidates = Ticket::whereNotIn('status', ['resolved', 'closed'])->where('channel', '!=', 'system')->where('created_at', '>=', now()->subDay())->orderBy('id')->limit(200)->get(['id', 'status', 'channel', 'tags'])
                ->filter(fn ($ticket) => $this->triageable($ticket) && ($tries[$ticket->id] ?? 0) < 3)->take(5);
            foreach ($candidates as $ticket) {
                try { $result['tickets_ai_triaged'] += (int) $this->triage($ticket->id); } catch (\Throwable $exception) { report($exception); }
            }
        }
        // With auto_assign on, the unassigned queue heals itself (released tickets, tickets created while no agent was
        // active). It runs before the SLA alerts so they go to the new assignee.
        if (Setting::valueFor('auto_assign')) {
            foreach (Ticket::whereNull('assignee_id')->whereNotIn('status', ['resolved', 'closed'])->orderBy('id')->limit(100)->toBase()->pluck('id') as $id) {
                try {
                    DB::transaction(function () use ($id, &$result) {
                        // User updates lock the agent before releasing their tickets. Take these locks in that order.
                        $agent = $this->leastLoadedAgent();
                        if (! $agent) { return; }
                        $ticket = Ticket::whereKey($id)->lockForUpdate()->firstOrFail();
                        if ($ticket->assignee_id || in_array($ticket->status, ['resolved', 'closed'], true)) { return; }
                        $ticket->update(['assignee_id' => $agent->id]); $this->notifyAssignment($ticket);
                        AuditLog::system($ticket, ['assignee_id' => null], ['assignee_id' => $agent->id], 'auto_assign'); $result['tickets_auto_assigned']++;
                    });
                } catch (\Throwable $exception) { report($exception); }
            }
        }
        foreach (Ticket::whereNotIn('status', ['resolved', 'closed'])->orderBy('id')->toBase()->pluck('id') as $id) {
            try {
                $result['ticket_sla_notifications'] += DB::transaction(function () use ($id) {
                    $ticket = Ticket::whereKey($id)->lockForUpdate()->firstOrFail();
                    if (in_array($ticket->status, ['resolved', 'closed'], true)) { return 0; }
                    $state = $ticket->sla_state;
                    $field = $state === 'at_risk' ? 'sla_at_risk_notified_at' : ($state === 'breached' ? 'sla_breached_notified_at' : null);
                    if (! $field || $ticket->$field) { return 0; }
                    $ids = $ticket->assignee_id ? $this->activeAssigneeIds($ticket) : User::role(['Admin', 'Support Agent'])->authorizedFor('view tickets')->where('is_active', true)->pluck('id')->all();
                    // A breach is escalated: the active Admins hear about it too, once, alongside the assignee.
                    if ($state === 'breached') { $ids = array_unique([...$ids, ...$this->activeAdminIds()]); }
                    foreach ($ids as $userId) { Notification::create(['user_id' => $userId, 'type' => 'ticket_sla_'.$state, 'title' => $state === 'breached' ? 'Ticket SLA breached' : 'Ticket SLA at risk', 'message' => $ticket->reference.': '.$ticket->subject, 'data' => ['ticket_id' => $ticket->id]]); }
                    $ticket->update([$field => now()]);
                    return count($ids);
                });
            } catch (\Throwable $exception) { report($exception); }
        }
        // Seven days after resolution, unless the requester wrote in the last seven days: then the clock restarts from
        // their latest message, so a late "it still doesn't work" is not closed under them.
        $recentReply = fn ($q) => $q->whereIn('author_type', ['customer', 'seller'])->where('created_at', '>', now()->subDays(7));
        foreach (Ticket::where('status', 'resolved')->where('resolved_at', '<=', now()->subDays(7))->whereDoesntHave('messages', $recentReply)->orderBy('id')->limit(100)->toBase()->pluck('id') as $id) {
            try {
                DB::transaction(function () use ($id, $recentReply, &$result) {
                    // Re-checked under the lock (message() writes replies under the same lock): a reply that landed after
                    // the query above holds the close.
                    $ticket = Ticket::whereKey($id)->lockForUpdate()->firstOrFail();
                    if ($ticket->status !== 'resolved' || ! $ticket->resolved_at || $ticket->resolved_at->gt(now()->subDays(7)) || $ticket->messages()->where($recentReply)->exists()) { return; }
                    $old = $ticket->toArray(); $this->applyTransition($ticket, 'closed', null, 'Automatically closed seven days after resolution.');
                    AuditLog::system($ticket, $old, $ticket->toArray(), 'ticket_auto_close'); $result['tickets_auto_closed']++;
                });
            } catch (\Throwable $exception) { report($exception); }
        }

        return $result;
    }
}
