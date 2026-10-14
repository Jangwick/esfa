<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\IntegrationResponses;
use App\Http\Requests\WebhookEndpointRequest;
use App\Http\Requests\ListRequest;
use App\Models\IntegrationClient;
use App\Models\WebhookEndpoint;
use App\Services\WebhookService;
use App\Support\ListResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookEndpointController extends Controller
{
    use IntegrationResponses;

    public function index(ListRequest $request)
    {
        if (! $request->user() instanceof IntegrationClient) {
            $this->authorizeIntegrations($request);
        }
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'], 'is_active' => ['nullable', 'boolean'],
            'client_id' => ['nullable', 'integer'],
        ]);
        $query = WebhookEndpoint::with('client:id,name,system_code');
        if ($request->user() instanceof IntegrationClient) {
            $query->where('client_id', $request->user()->id);
        } elseif ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        if ($search = trim((string) $request->input('search'))) {
            $query->whereRaw('LOWER(url) LIKE ?', ['%'.mb_strtolower($search).'%']);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        ListResponse::dates($query, $request);
        ListResponse::sort($query, $request, ['id', 'url', 'client_id', 'is_active', 'consecutive_failures', 'created_at']);
        if ($request->input('export') === 'csv') {
            return $this->csv('Webhook endpoints', ['ID', 'Client', 'URL', 'Events', 'Active', 'Consecutive failures'],
                $query->get(), fn ($endpoint) => [$endpoint->id, $endpoint->client?->name, $endpoint->url,
                    implode('; ', $endpoint->events), $endpoint->is_active ? 'Yes' : 'No', $endpoint->consecutive_failures]);
        }
        $summary = ['total' => (clone $query)->count(), 'active' => (clone $query)->where('is_active', true)->count(),
            'disabled' => (clone $query)->where('is_active', false)->count()];

        return response()->json(array_merge($query->paginate($this->perPage($request))->withQueryString()->toArray(), ['summary' => $summary]));
    }

    public function export(ListRequest $request)
    {
        $request->merge(['export' => 'csv']);

        return $this->index($request);
    }

    public function store(WebhookEndpointRequest $request)
    {
        $values = $request->safe()->except('rotate_secret');
        if ($request->user() instanceof IntegrationClient) {
            $values['client_id'] = $request->user()->id;
        }
        $secret = 'whsec_'.Str::random(64);
        $endpoint = WebhookEndpoint::create(array_merge($values, ['secret' => $secret]));
        $this->auditRecord($request, 'WebhookEndpoint', $endpoint->id, $endpoint->toArray());

        return response()->json(array_merge($endpoint->load('client:id,name,system_code')->toArray(), ['secret' => $secret]), 201);
    }

    public function update(WebhookEndpointRequest $request, WebhookEndpoint $endpoint)
    {
        $this->authorizeEndpoint($request, $endpoint);
        $old = $endpoint->toArray();
        $values = $request->safe()->except('rotate_secret');
        if ($request->user() instanceof IntegrationClient) {
            $values['client_id'] = $endpoint->client_id;
        }
        $secret = $request->boolean('rotate_secret') ? 'whsec_'.Str::random(64) : null;
        if ($secret) {
            $values['secret'] = $secret;
        }
        if (! $endpoint->is_active && ($values['is_active'] ?? false)) {
            $values['consecutive_failures'] = 0;
            $values['disabled_at'] = null;
        }
        // disabled_at marks only system disables; an explicit disable clears it so auto-recovery never probes it.
        if (array_key_exists('is_active', $values) && ! $values['is_active']) {
            $values['disabled_at'] = null;
        }
        $endpoint->update($values);
        $this->auditRecord($request, 'WebhookEndpoint', $endpoint->id, $endpoint->toArray(), $old);

        return response()->json(array_merge($endpoint->load('client:id,name,system_code')->toArray(), $secret ? ['secret' => $secret] : []));
    }

    public function destroy(Request $request, WebhookEndpoint $endpoint)
    {
        $this->authorizeEndpoint($request, $endpoint);
        $this->auditRecord($request, 'WebhookEndpoint', $endpoint->id, [], $endpoint->toArray());
        $endpoint->delete();

        return response()->json(['message' => 'Webhook endpoint deleted successfully.']);
    }

    public function test(Request $request, WebhookEndpoint $endpoint, WebhookService $webhooks)
    {
        $this->authorizeIntegrations($request);
        $delivery = $webhooks->test($endpoint);
        $this->auditRecord($request, 'WebhookDelivery', $delivery->id, $delivery->toArray());

        return response()->json($delivery, 201);
    }
}
