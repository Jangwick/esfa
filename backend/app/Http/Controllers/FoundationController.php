<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Setting;

class FoundationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('view dashboard'), 403, 'This action requires view dashboard.');
        $summary = [
            'permissions_count' => $user->effectivePermissions()->count(),
            'unread_notifications' => DB::table('notifications')->where('user_id', $user->id)->where('is_read', false)->count(),
        ];
        $activity = [];
        if ($user->hasRole('Admin')) {
            $summary += [
                'active_users' => DB::table('users')->where('is_active', true)->count(),
                'active_clients' => DB::table('integration_clients')->where('is_active', true)->count(),
                'queued_deliveries' => DB::table('webhook_deliveries')->whereIn('status', ['pending', 'processing'])->count(),
            ];
            $activity = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                ->select(['audit_logs.id', 'audit_logs.action', 'audit_logs.entity_type', 'audit_logs.created_at', 'users.name as actor_name'])
                ->orderByDesc('audit_logs.id')->limit(6)->get()->map(fn ($row) => [
                    'id' => $row->id, 'action' => $row->action, 'entity_type' => $row->entity_type,
                    'created_at' => $row->created_at, 'user' => ['name' => $row->actor_name],
                ]);
        }
        return response()->json(['timezone' => Setting::valueFor('business_timezone', config('sfa.timezone', 'Asia/Manila')), 'summary' => $summary, 'activity' => $activity]);
    }
}
