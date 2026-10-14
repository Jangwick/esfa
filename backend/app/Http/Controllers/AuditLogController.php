<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListRequest;
use App\Models\AuditLog;
use App\Support\ListResponse;

class AuditLogController extends Controller
{
    public function index(ListRequest $request)
    {
        $this->requirePermission('view audit logs');
        $query = AuditLog::with('user');
        foreach (['user_id', 'action'] as $field) {
            if ($request->filled($field)) { $query->where($field, $request->input($field)); }
        }
        // The entity filter is free text ("ticket" finds Ticket and TicketMessage), so match part of it, any case.
        if ($request->filled('entity_type')) { $query->whereRaw('LOWER(entity_type) LIKE ?', ['%'.mb_strtolower((string) $request->input('entity_type')).'%']); }
        if ($request->filled('search')) {
            $search = '%'.mb_strtolower($request->search).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(action) LIKE ?', [$search])->orWhereRaw('LOWER(entity_type) LIKE ?', [$search])->orWhereHas('user', fn ($u) => $u->whereRaw('LOWER(name) LIKE ?', [$search])->orWhereRaw('LOWER(email) LIKE ?', [$search])));
        }
        $query = ListResponse::sort(ListResponse::dates($query, $request), $request, ['created_at', 'action', 'entity_type']);
        $summary = ['total' => (clone $query)->count(), 'created' => (clone $query)->where('action', 'created')->count(), 'updated' => (clone $query)->where('action', 'updated')->count(), 'deleted' => (clone $query)->where('action', 'deleted')->count()];
        if ($request->export === 'csv') {
            return ListResponse::csv($query, 'audit-logs.csv', ['ID', 'Time (UTC)', 'User', 'Action', 'Entity', 'Entity ID', 'Old values', 'New values', 'IP address', 'User agent'], fn ($entry) => [$entry->id, $entry->created_at->toIso8601String(), $entry->user?->name ?? 'System / integration', $entry->action, $entry->entity_type, $entry->entity_id, json_encode($entry->old_values), json_encode($entry->new_values), $entry->ip_address, $entry->user_agent]);
        }

        return ListResponse::paginate($query, $request, $summary);
    }
}
