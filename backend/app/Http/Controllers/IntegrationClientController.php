<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\IntegrationResponses;
use App\Http\Requests\IntegrationClientRequest;
use App\Http\Requests\IntegrationTokenRequest;
use App\Http\Requests\ListRequest;
use App\Models\IntegrationClient;
use App\Support\ListResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IntegrationClientController extends Controller
{
    use IntegrationResponses;

    public function index(ListRequest $request)
    {
        $this->authorizeIntegrations($request);
        $request->validate(['search' => ['nullable', 'string', 'max:255'], 'is_active' => ['nullable', 'boolean']]);
        $query = IntegrationClient::query();
        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($query) use ($search) {
                foreach (['name', 'system_code', 'contact_email'] as $column) {
                    $query->orWhereRaw("LOWER({$column}) LIKE ?", ['%'.mb_strtolower($search).'%']);
                }
            });
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        $query->withCount('tokens')->with(['tokens' => fn ($query) => $query
            ->select(['id', 'tokenable_id', 'tokenable_type', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at'])
            ->latest()]);
        ListResponse::dates($query, $request);
        ListResponse::sort($query, $request, ['id', 'name', 'system_code', 'contact_email', 'is_active', 'created_at']);
        if ($request->input('export') === 'csv') {
            return $this->csv('Integration clients', ['ID', 'Name', 'System code', 'Contact email', 'Active', 'Tokens'],
                $query->get(), fn ($client) => [$client->id, $client->name, $client->system_code, $client->contact_email, $client->is_active ? 'Yes' : 'No', $client->tokens_count]);
        }
        $summary = ['total' => (clone $query)->count(), 'active' => (clone $query)->where('is_active', true)->count(),
            'tokens' => (clone $query)->get()->sum('tokens_count')];

        return response()->json(array_merge($query->paginate($this->perPage($request))->withQueryString()->toArray(), ['summary' => $summary]));
    }

    public function export(ListRequest $request)
    {
        $request->merge(['export' => 'csv']);

        return $this->index($request);
    }

    public function store(IntegrationClientRequest $request)
    {
        $client = IntegrationClient::create($request->validated());
        $this->auditRecord($request, 'IntegrationClient', $client->id, $client->toArray());

        return response()->json($client->load('tokens')->loadCount('tokens'), 201);
    }

    public function update(IntegrationClientRequest $request, IntegrationClient $client)
    {
        $old = $client->toArray();
        $client->update($request->validated());
        $this->auditRecord($request, 'IntegrationClient', $client->id, $client->toArray(), $old);

        return response()->json($client->load('tokens')->loadCount('tokens'));
    }

    public function destroy(Request $request, IntegrationClient $client)
    {
        $this->authorizeIntegrations($request);
        $this->auditRecord($request, 'IntegrationClient', $client->id, [], $client->toArray());
        DB::transaction(function () use ($client) {
            $client->tokens()->delete();
            $client->delete();
        });

        return response()->json(['message' => 'Integration client deleted successfully.']);
    }

    public function issueToken(IntegrationTokenRequest $request, IntegrationClient $client)
    {
        $values = $request->validated();
        $token = $client->createToken($values['name'], $values['abilities'],
            empty($values['expires_at']) ? null : Carbon::parse($values['expires_at']));
        $record = $token->accessToken->only(['id', 'name', 'abilities', 'last_used_at', 'expires_at', 'created_at']);
        $this->auditRecord($request, 'IntegrationClient', $client->id, ['token_issued' => $record]);

        return response()->json(['token' => $token->plainTextToken, 'token_record' => $record], 201);
    }

    public function revokeToken(Request $request, IntegrationClient $client, string $token)
    {
        $this->authorizeIntegrations($request);
        $record = $client->tokens()->findOrFail($token);
        $this->auditRecord($request, 'IntegrationClient', $client->id, ['token_revoked' => $record->id]);
        $record->delete();

        return response()->json(['message' => 'Integration token revoked successfully.']);
    }
}
