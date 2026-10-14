<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;
use Spatie\Permission\Models\Permission;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles;

    protected $fillable = ['name', 'email', 'password', 'otp_email', 'is_active'];
    protected $hidden = ['password', 'remember_token', 'roles', 'permissions'];
    protected $guard_name = 'web';

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'email_verified_at' => 'datetime'];
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function loginChallenges()
    {
        return $this->hasMany(LoginChallenge::class);
    }

    public function assignedTickets()
    {
        return $this->hasMany(Ticket::class, 'assignee_id');
    }

    public function effectivePermissions()
    {
        return $this->hasRole('Admin')
            ? Permission::where('guard_name', 'web')->get()
            : $this->getAllPermissions();
    }

    public function scopeAuthorizedFor(Builder $query, string $permission): Builder
    {
        // Permission queries do not run Gate callbacks; include Admin notification recipients explicitly.
        return $query->where(fn (Builder $users) => $users->permission($permission)
            ->orWhereHas('roles', fn (Builder $roles) => $roles->where('name', 'Admin')->where('guard_name', 'web')));
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'roles' => $this->getRoleNames()->values()->all(),
            'permissions' => $this->effectivePermissions()->pluck('name')->sort()->values()->all(),
            'business_timezone' => Setting::valueFor('business_timezone', config('sfa.timezone')),
            'simulator_enabled' => (bool) config('sfa.simulator'),
            'locked_until' => LoginLockout::where('email', $this->email)->where('locked_until', '>', now())->value('locked_until')?->toIso8601String(),
        ]);
    }
}
