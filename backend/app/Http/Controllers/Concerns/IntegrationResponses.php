<?php

namespace App\Http\Controllers\Concerns;

use App\Models\IntegrationClient;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Support\ListResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait IntegrationResponses
{
    protected function authorizeIntegrations(Request $request): void
    {
        abort_unless($request->user() instanceof User && $request->user()->can('manage integrations'),
            403, 'This action requires manage integrations permission.');
    }

    protected function authorizeEndpoint(Request $request, WebhookEndpoint $endpoint): void
    {
        if ($request->user() instanceof IntegrationClient) {
            abort_unless($endpoint->client_id === $request->user()->id, 404);
        } else {
            $this->authorizeIntegrations($request);
        }
    }

    protected function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->input('per_page', 20)));
    }

    protected function auditRecord(Request $request, string $type, mixed $id, array $new, ?array $old = null): void
    {
        request()->attributes->set('audit.entity_type', $type);
        request()->attributes->set('audit.entity_id', $id);
        request()->attributes->set('audit.new_values', $new);
        request()->attributes->set('audit.old_values', $old);
    }

    protected function csv(string $name, array $headers, iterable $records, callable $row): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $records, $row) {
            $stream = fopen('php://output', 'wb');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, $headers);
            foreach ($records as $record) {
                fputcsv($stream, array_map(ListResponse::cell(...), $row($record)));
            }
            fclose($stream);
        }, $name.' '.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
