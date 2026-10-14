<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\IntegrationResponses;
use App\Http\Requests\ListRequest;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\WebhookService;
use App\Support\ListResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebhookDeliveryController extends Controller
{
    use IntegrationResponses;

    public function index(ListRequest $request)
    {
        $this->authorizeIntegrations($request);
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'status' => ['nullable', Rule::in(['pending', 'processing', 'delivered', 'failed'])],
            'endpoint_id' => ['nullable', 'integer'], 'event_type' => ['nullable', 'string', 'max:80'],
        ]);
        $query = WebhookDelivery::with('endpoint:id,url,client_id');
        if ($search = trim((string) $request->input('search'))) {
            // id is a uuid column on PostgreSQL, where "uuid LIKE text" has no operator and errors; it matches only a whole
            // UUID by equality (lowercase, as Str::uuid stores it on SQLite; PostgreSQL parses either case).
            $query->where(fn ($query) => $query->whereRaw('LOWER(event_type) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhere('event_id', 'like', '%'.$search.'%')
                ->when(Str::isUuid($search), fn ($query) => $query->orWhere('id', mb_strtolower($search))));
        }
        foreach (['status', 'endpoint_id', 'event_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->input($filter));
            }
        }
        ListResponse::dates($query, $request);
        ListResponse::sort($query, $request, ['id', 'event_type', 'status', 'attempts', 'next_attempt_at', 'created_at']);
        if ($request->input('export') === 'csv') {
            return $this->csv('Webhook deliveries', ['ID', 'Endpoint', 'Event', 'Status', 'Attempts', 'Response status', 'Next attempt', 'Created'],
                $query->get(), fn ($delivery) => [$delivery->id, $delivery->endpoint?->url, $delivery->event_type,
                    $delivery->status, $delivery->attempts, $delivery->response_status, $delivery->next_attempt_at?->toIso8601String(), $delivery->created_at->toIso8601String()]);
        }
        $summary = ['total' => (clone $query)->count(), 'pending' => (clone $query)->whereIn('status', ['pending', 'processing'])->count(),
            'delivered' => (clone $query)->where('status', 'delivered')->count(), 'failed' => (clone $query)->where('status', 'failed')->count()];

        return response()->json(array_merge($query->paginate($this->perPage($request))->withQueryString()->toArray(), ['summary' => $summary]));
    }

    public function export(ListRequest $request)
    {
        $request->merge(['export' => 'csv']);

        return $this->index($request);
    }

    // ADM-03: one click instead of resending each failed delivery. Only queues them; the scheduler sends them.
    public function resendFailed(Request $request, WebhookService $webhooks)
    {
        $this->authorizeIntegrations($request);
        $request->validate(['endpoint_id' => ['nullable', 'integer', 'exists:webhook_endpoints,id']]);
        $endpointId = $request->filled('endpoint_id') ? $request->integer('endpoint_id') : null;
        if ($endpointId) {
            $webhooks->ensureActive(WebhookEndpoint::findOrFail($endpointId));
        }
        $requeued = $webhooks->requeueFailed($endpointId);
        $this->auditRecord($request, 'WebhookEndpoint', $endpointId, ['resend_failed' => true, 'requeued' => $requeued]);
        $request->attributes->set('audit.action', 'updated');

        return response()->json(['requeued' => $requeued]);
    }

    public function resend(Request $request, WebhookDelivery $delivery, WebhookService $webhooks)
    {
        $this->authorizeIntegrations($request);
        $updated = $webhooks->resend($delivery);
        $this->auditRecord($request, 'WebhookDelivery', $delivery->id, $updated->toArray());

        return response()->json($updated);
    }
}
