<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListRequest;
use App\Http\Requests\UserRequest;
use App\Models\LoginLockout;
use App\Models\User;
use App\Services\TicketService;
use App\Support\ListResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    // Only an Admin may grant or revoke the Admin role itself; any other change follows the users permissions.
    private function assertAdminRoleUntouched(array $roles, User $user): void
    {
        if ($user->hasRole('Admin') !== in_array('Admin', $roles, true) && ! request()->user()->hasRole('Admin')) {
            throw ValidationException::withMessages(['roles' => ['Only an Admin can grant or remove the Admin role.']]);
        }
    }

    public function index(ListRequest $request)
    {
        $this->requirePermission('view users');
        $query = User::with(['roles.permissions', 'permissions']);
        if ($request->filled('search')) {
            $search = mb_strtolower($request->search);
            $query->where(fn ($q) => $q->whereRaw('LOWER(name) LIKE ?', ['%'.$search.'%'])->orWhereRaw('LOWER(email) LIKE ?', ['%'.$search.'%']));
        }
        if ($request->filled('role')) {
            if (! Role::where('name', $request->role)->where('guard_name', 'web')->exists()) { throw ValidationException::withMessages(['role' => ['The selected role is invalid.']]); }
            $query->role($request->role);
        }
        if ($request->filled('status')) {
            if (! in_array($request->status, ['active', 'inactive', 'locked'], true)) { throw ValidationException::withMessages(['status' => ['The selected status is invalid.']]); }
            $request->status === 'locked'
                ? $query->whereIn('email', LoginLockout::where('locked_until', '>', now())->select('email'))
                : $query->where('is_active', $request->status === 'active');
        }
        $query = ListResponse::sort(ListResponse::dates($query, $request), $request, ['created_at', 'name', 'email']);
        $summary = [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('is_active', true)->count(),
            'inactive' => (clone $query)->where('is_active', false)->count(),
            'locked' => (clone $query)->whereIn('email', LoginLockout::where('locked_until', '>', now())->select('email'))->count(),
        ];
        if ($request->export === 'csv') {
            return ListResponse::csv($query, 'users.csv', ['ID', 'Name', 'Email', 'Roles', 'Status', 'Sign-in code email', 'Created at'], fn ($user) => [$user->id, $user->name, $user->email, $user->getRoleNames()->implode('; '), $user->is_active ? 'active' : 'inactive', $user->otp_email, $user->created_at->toIso8601String()]);
        }

        return ListResponse::paginate($query, $request, $summary);
    }

    public function store(UserRequest $request)
    {
        $this->requirePermission('create users');
        $user = DB::transaction(function () use ($request) {
            $values = $request->validated();
            $roles = $values['roles'];
            $this->assertAdminRoleUntouched($roles, new User());
            unset($values['roles']);
            $user = User::create($values);
            $user->syncRoles($roles);

            return $user;
        });
        request()->attributes->set('audit.entity_type', 'User');
        request()->attributes->set('audit.entity_id', $user->id);

        return response()->json($user->fresh(), 201);
    }

    public function update(UserRequest $request, User $user)
    {
        $this->requirePermission('edit users');
        DB::transaction(function () use ($request, $user) {
            $adminIds = User::role('Admin')->where('is_active', true)->orderBy('id')->pluck('id');
            // Take the target and active Admin locks in one id-ordered set. A dual Admin/Administrative Assistant can also be
            // locked by auto-assignment, which must use the same user-before-ticket ordering.
            User::whereIn('id', $adminIds->push($user->id)->unique())->orderBy('id')->lockForUpdate()->get();
            $user = User::whereKey($user->id)->firstOrFail();
            $values = $request->validated();
            $credentialsChanged = isset($values['password']) || (array_key_exists('otp_email', $values) && $values['otp_email'] !== $user->otp_email);
            $roles = $values['roles'] ?? $user->getRoleNames()->all();
            $this->assertAdminRoleUntouched($roles, $user);
            $active = array_key_exists('is_active', $values) ? (bool) $values['is_active'] : $user->is_active;
            if ($user->id === $request->user()->id && ! $active) {
                throw ValidationException::withMessages(['is_active' => ['You cannot deactivate your own account.']]);
            }
            if ($user->is_active && $user->hasRole('Admin') && (! $active || ! in_array('Admin', $roles, true)) && User::role('Admin')->where('is_active', true)->count() <= 1) {
                throw ValidationException::withMessages(['roles' => ['At least one active Admin must remain.']]);
            }
            request()->attributes->set('audit.old_values', $user->toArray());
            unset($values['roles']);
            $user->update($values);
            $user->syncRoles($roles);
            if (! $active || $credentialsChanged) {
                $user->tokens()->delete();
                $user->loginChallenges()->delete();
            }
            // Checked against the submitted roles: hasAnyRole could still answer from roles cached before the sync.
            $released = $active && array_intersect(['Admin', 'Administrative Assistant'], $roles) ? 0 : app(TicketService::class)->releaseAssignments($user);
            request()->attributes->set('audit.new_values', $user->fresh()->toArray() + ($released ? ['released_tickets' => $released] : []));
        });

        return response()->json($user->fresh());
    }

    public function unlock(Request $request, User $user)
    {
        $this->requirePermission('edit users');
        request()->attributes->set('audit.action', 'unlocked');
        request()->attributes->set('audit.old_values', ['locked_until' => LoginLockout::where('email', $user->email)->first()?->locked_until?->toIso8601String()]);
        LoginLockout::clear($user->email);
        $user->loginChallenges()->delete();
        request()->attributes->set('audit.new_values', ['locked_until' => null]);

        return response()->json(['message' => 'Sign-in unlocked successfully.']);
    }

    public function roles()
    {
        $this->requirePermission('view users');

        return response()->json(Role::with('permissions')->orderBy('id')->get()->map(fn ($role) => ['id' => $role->id, 'name' => $role->name, 'permissions' => $role->permissions->pluck('name')->sort()->values()->all()]));
    }
}
