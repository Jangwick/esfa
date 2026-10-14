<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListRequest;
use App\Models\Notification;
use App\Support\ListResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(ListRequest $request)
    {
        $query = $request->user()->notifications();
        if ($request->has('is_read')) { $query->where('is_read', $request->boolean('is_read')); }
        if ($request->filled('search')) {
            $search = '%'.mb_strtolower($request->search).'%';
            $query->where(fn ($q) => $q->whereRaw('LOWER(title) LIKE ?', [$search])->orWhereRaw('LOWER(message) LIKE ?', [$search]));
        }
        $builder = ListResponse::sort(ListResponse::dates($query->getQuery(), $request), $request, ['created_at', 'title']);
        $summary = ['total' => (clone $builder)->count(), 'unread' => (clone $builder)->where('is_read', false)->count()];
        if ($request->export === 'csv') {
            return ListResponse::csv($builder, 'notifications.csv', ['Time (UTC)', 'Type', 'Title', 'Message', 'Read'], fn ($item) => [$item->created_at->toIso8601String(), $item->type, $item->title, $item->message, $item->is_read ? 'yes' : 'no']);
        }

        return ListResponse::paginate($builder, $request, $summary);
    }

    public function unreadCount(Request $request)
    {
        return response()->json(['count' => $request->user()->notifications()->where('is_read', false)->count()]);
    }

    public function markAllAsRead(Request $request)
    {
        $count = $request->user()->notifications()->where('is_read', false)->count();
        $request->user()->notifications()->where('is_read', false)->update(['is_read' => true, 'read_at' => now()]);
        $request->attributes->set('audit.entity_type', 'Notification');
        $request->attributes->set('audit.old_values', ['unread' => $count]);
        $request->attributes->set('audit.new_values', ['unread' => 0]);

        return response()->json(['message' => 'All notifications marked as read.', 'updated' => $count]);
    }

    public function read(Request $request, Notification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        if (! $notification->is_read) { $notification->update(['is_read' => true, 'read_at' => now()]); }

        return response()->json($notification->fresh());
    }

    public function destroy(Request $request, Notification $notification)
    {
        abort_unless($notification->user_id === $request->user()->id, 404);
        $notification->delete();

        return response()->json(['message' => 'Notification deleted successfully.']);
    }
}
