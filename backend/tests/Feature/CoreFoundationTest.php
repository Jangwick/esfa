<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LoginLockout;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoreFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function token(string $email = 'admin@example.com'): string
    {
        Auth::forgetGuards();

        return User::where('email', $email)->firstOrFail()->createToken('test', ['staff'])->plainTextToken;
    }

    private function headers(string $token): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    public static function protectedRoutes(): array
    {
        return [
            ['GET', '/user'], ['GET', '/profile'], ['PUT', '/profile'], ['POST', '/logout'], ['POST', '/change-password'],
            ['GET', '/users'], ['POST', '/users'], ['PUT', '/users/1'], ['POST', '/users/1/unlock'], ['GET', '/roles'],
            ['GET', '/audit-logs'], ['GET', '/notifications'], ['GET', '/notifications/unread-count'],
            ['PATCH', '/notifications/mark-all-as-read'], ['PATCH', '/notifications/1/read'], ['DELETE', '/notifications/1'],
            ['GET', '/settings'], ['PUT', '/settings'],
        ];
    }

    #[DataProvider('protectedRoutes')]
    public function test_core_endpoints_require_a_bearer_token(string $method, string $path): void
    {
        $this->json($method, '/api/v1'.$path)->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_health_has_the_documented_safe_shape(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertExactJson(['status' => 'ok', 'commit' => 'local', 'database' => 'connected']);
        DB::shouldReceive('select')->once()->with('SELECT 1')->andThrow(new \RuntimeException('Secret database password'));
        $this->getJson('/api/v1/health')->assertStatus(503)->assertExactJson(['status' => 'degraded', 'commit' => 'local', 'database' => 'error']);
    }

    public function test_non_admin_cannot_access_administration(): void
    {
        $token = $this->token('analyst@example.com');
        foreach ([['GET', '/users'], ['GET', '/roles'], ['GET', '/audit-logs'], ['GET', '/settings'], ['PUT', '/settings'], ['POST', '/users'], ['PUT', '/users/1'], ['POST', '/users/1/unlock']] as [$method, $path]) {
            $this->json($method, '/api/v1'.$path, [], $this->headers($token))->assertForbidden();
        }
    }

    public function test_user_create_validates_and_serializes_roles_without_passwords(): void
    {
        $token = $this->token();
        $this->postJson('/api/v1/users', [], $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password', 'roles']);
        $body = ['name' => 'New Staff', 'email' => 'NEW.STAFF@EXAMPLE.COM', 'password' => 'long-password', 'password_confirmation' => 'long-password', 'roles' => ['Accounting Staff'], 'is_active' => true, 'otp_email' => 'Codes@Example.com'];
        $created = $this->postJson('/api/v1/users', $body, $this->headers($token))->assertCreated()->assertJsonPath('email', 'new.staff@example.com')->assertJsonPath('otp_email', 'codes@example.com')->assertJsonPath('roles.0', 'Accounting Staff')->assertJsonMissingPath('password')->json();
        $this->assertContains('approve refunds', $created['permissions']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'User', 'entity_id' => (string) $created['id'], 'action' => 'created']);
        $this->postJson('/api/v1/users', $body, $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('email');
        $body['email'] = 'another@example.com';
        $body['roles'] = ['Invented Role'];
        $this->postJson('/api/v1/users', $body, $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('roles.0');
    }

    public function test_lists_filter_sort_clamp_and_export_current_filters(): void
    {
        $token = $this->token();
        $this->getJson('/api/v1/users?search=SUPPORT&role=Administrative%20Assistant&sort=name&per_page=500', $this->headers($token))
            ->assertOk()->assertJsonPath('per_page', 100)->assertJsonPath('total', 2)->assertJsonPath('summary.total', 2)->assertJsonPath('summary.active', 2)
            ->assertJsonStructure(['current_page', 'data', 'first_page_url', 'last_page', 'links', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'total', 'summary']);
        $this->getJson('/api/v1/users?per_page=-5', $this->headers($token))->assertOk()->assertJsonPath('per_page', 1);
        $this->getJson('/api/v1/users?sort=password', $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/v1/users?role=Missing', $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->getJson('/api/v1/users?date_from=bad', $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $export = $this->get('/api/v1/users?role=Accounting%20Staff&export=csv', $this->headers($token))->assertOk();
        $content = $export->streamedContent();
        $this->assertStringContainsString('finance@example.com', $content);
        $this->assertStringNotContainsString('support.one@example.com', $content);
        $this->assertStringContainsString('text/csv', $export->headers->get('Content-Type'));
    }

    public function test_edit_unchanged_otp_email_preserves_sessions_and_deactivation_revokes_them(): void
    {
        $token = $this->token();
        $target = User::where('email', 'finance@example.com')->first();
        $target->update(['otp_email' => 'codes@example.com']);
        $staffToken = $target->createToken('existing', ['staff']);
        $this->putJson('/api/v1/users/'.$target->id, ['name' => 'Updated Finance', 'otp_email' => 'codes@example.com'], $this->headers($token))->assertOk()->assertJsonPath('name', 'Updated Finance');
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $staffToken->accessToken->id]);
        $this->putJson('/api/v1/users/'.$target->id, ['is_active' => false], $this->headers($token))->assertOk()->assertJsonPath('is_active', false);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $staffToken->accessToken->id]);
        $this->getJson('/api/v1/user', $this->headers($staffToken->plainTextToken))->assertUnauthorized();
    }

    public function test_last_admin_and_self_deactivation_are_rejected(): void
    {
        $token = $this->token();
        $admin = User::where('email', 'admin@example.com')->first();
        $this->putJson('/api/v1/users/'.$admin->id, ['is_active' => false], $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('is_active');
        $this->putJson('/api/v1/users/'.$admin->id, ['roles' => ['Quality Control Analyst']], $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('roles');
    }

    public function test_user_updates_lock_the_target_and_admins_in_one_ordered_set(): void
    {
        $token = $this->token();
        $target = User::where('email', 'finance@example.com')->firstOrFail();
        $target->syncRoles(['Administrative Assistant']);
        $dualRole = User::create(['name' => 'Dual role', 'email' => 'dual-role@example.com', 'password' => 'password', 'is_active' => true]);
        $dualRole->assignRole(['Admin', 'Administrative Assistant']);
        $activeAdminIds = User::role('Admin')->where('is_active', true)->pluck('id')->all();
        $this->assertLessThan($dualRole->id, $target->id, 'The target can be earlier than a dual-role user also locked by the assignment sweep.');
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_starts_with($query->sql, 'select * from "users" where "id" in')) { $queries[] = $query; }
        });

        $this->putJson('/api/v1/users/'.$target->id, ['name' => 'Updated agent'], $this->headers($token))->assertOk();
        $this->assertNotEmpty($queries);
        $this->assertEqualsCanonicalizing([...$activeAdminIds, $target->id], $queries[0]->bindings, 'Acquire the whole set before any target-only lock can invert assignment locks.');
        $this->assertStringContainsString('order by "id" asc', $queries[0]->sql);
    }

    public function test_roles_and_unlock_sign_in(): void
    {
        $token = $this->token();
        $this->getJson('/api/v1/roles', $this->headers($token))->assertOk()->assertJsonCount(19)->assertJsonStructure([['id', 'name', 'permissions']]);
        $user = User::where('email', 'support.one@example.com')->first();
        LoginLockout::create(['email' => $user->email, 'failed_attempts' => 6, 'locked_until' => now()->addMinutes(5), 'last_failed_at' => now()]);
        $this->getJson('/api/v1/users?role=Administrative%20Assistant', $this->headers($token))->assertJsonPath('summary.locked', 1);
        // ADM-07: the "Sign-in locked" filter (an expired lock does not count).
        LoginLockout::create(['email' => 'finance@example.com', 'failed_attempts' => 6, 'locked_until' => now()->subMinute(), 'last_failed_at' => now()->subMinutes(6)]);
        $this->getJson('/api/v1/users?status=locked', $this->headers($token))->assertOk()->assertJsonStructure(['data', 'current_page', 'per_page', 'total', 'summary' => ['total', 'active', 'inactive', 'locked']])
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.email', $user->email)->assertJsonPath('summary.locked', 1);
        $this->getJson('/api/v1/users?status=frozen', $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/v1/users?status=locked', $this->headers($this->token('finance@example.com')))->assertForbidden();
        $this->postJson('/api/v1/users/'.$user->id.'/unlock', [], $this->headers($token))->assertOk();
        $this->assertDatabaseMissing('login_lockouts', ['email' => $user->email]);
        $this->getJson('/api/v1/users?status=locked', $this->headers($token))->assertOk()->assertJsonPath('total', 0);
    }

    public function test_audit_captures_old_and_new_values_and_filters_without_secrets(): void
    {
        $token = $this->token();
        $user = User::where('email', 'finance@example.com')->first();
        $this->putJson('/api/v1/users/'.$user->id, ['name' => 'Changed Name', 'password' => 'new-password', 'password_confirmation' => 'new-password'], $this->headers($token))->assertOk();
        $audit = AuditLog::where('entity_type', 'User')->latest('id')->firstOrFail();
        $this->assertSame('Bernadette Chua', $audit->old_values['name']);
        $this->assertSame('Changed Name', $audit->new_values['name']);
        $this->assertArrayNotHasKey('password', $audit->new_values);
        $this->assertStringNotContainsString('new-password', json_encode($audit->toArray()));
        $this->getJson('/api/v1/audit-logs?action=updated&entity_type=User', $this->headers($token))->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.entity_type', 'User');
        $this->getJson('/api/v1/audit-logs?action=updated&entity_type=use', $this->headers($token))->assertOk()->assertJsonPath('summary.total', 1)->assertJsonPath('data.0.entity_type', 'User');
        $csv = $this->get('/api/v1/audit-logs?action=updated&export=csv', $this->headers($token))->assertOk()->streamedContent();
        $this->assertStringContainsString('Changed Name', $csv);
        $this->assertStringNotContainsString('new-password', $csv);
    }

    public function test_cached_user_list_refreshes_after_a_write_and_permission_changes(): void
    {
        $token = $this->token();
        $this->getJson('/api/v1/users', $this->headers($token))->assertOk()->assertJsonPath('total', 20);
        $this->postJson('/api/v1/users', ['name' => 'Seven', 'email' => 'seven@example.com', 'password' => 'password', 'password_confirmation' => 'password', 'roles' => ['Quality Control Analyst']], $this->headers($token))->assertCreated();
        $this->getJson('/api/v1/users', $this->headers($token))->assertOk()->assertJsonPath('total', 21);
        User::where('email', 'admin@example.com')->first()->syncRoles(['Quality Control Analyst']);
        $this->getJson('/api/v1/users', $this->headers($token))->assertForbidden();
        $this->assertNotNull(Cache::get('sfa.api.version'));
    }

    public function test_notification_read_delete_and_bulk_read_are_owned_and_idempotent(): void
    {
        $token = $this->token('support.one@example.com');
        $own = User::where('email', 'support.one@example.com')->first()->notifications()->first();
        $foreign = User::where('email', 'finance@example.com')->first()->notifications()->first();
        $this->getJson('/api/v1/notifications', $this->headers($token))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('summary.unread', 1);
        $this->getJson('/api/v1/notifications/unread-count', $this->headers($token))->assertOk()->assertJsonPath('count', 1);
        $this->patchJson('/api/v1/notifications/'.$foreign->id.'/read', [], $this->headers($token))->assertNotFound();
        $this->deleteJson('/api/v1/notifications/'.$foreign->id, [], $this->headers($token))->assertNotFound();
        $this->patchJson('/api/v1/notifications/'.$own->id.'/read', [], $this->headers($token))->assertOk()->assertJsonPath('is_read', true);
        $readAt = $own->fresh()->read_at->toIso8601String();
        $this->travel(5)->minutes();
        $this->patchJson('/api/v1/notifications/'.$own->id.'/read', [], $this->headers($token))->assertOk()->assertJsonPath('read_at', $own->fresh()->read_at->toJSON());
        $this->assertSame($readAt, $own->fresh()->read_at->toIso8601String());
        Notification::create(['user_id' => $own->user_id, 'type' => 'test', 'title' => 'New', 'message' => 'Test']);
        $this->patchJson('/api/v1/notifications/mark-all-as-read', [], $this->headers($token))->assertOk()->assertJsonPath('updated', 1);
        $this->patchJson('/api/v1/notifications/mark-all-as-read', [], $this->headers($token))->assertOk()->assertJsonPath('updated', 0);
        $this->deleteJson('/api/v1/notifications/'.$own->id, [], $this->headers($token))->assertOk();
        $this->assertDatabaseHas('notifications', ['id' => $foreign->id, 'is_read' => false]);
    }

    public function test_settings_have_specified_defaults_and_validate_every_data_rule(): void
    {
        $token = $this->token();
        $this->getJson('/api/v1/settings', $this->headers($token))->assertOk()->assertJsonPath('currency', 'PHP')->assertJsonPath('business_timezone', 'Asia/Manila')->assertJsonPath('risk_amount_threshold', 5000000)->assertJsonPath('volumetric_divisor', 3500)->assertJsonPath('sla_policies.urgent.first_response_hours', 1);
        $this->putJson('/api/v1/settings', ['currency' => 'USD', 'business_timezone' => 'Invalid/Zone', 'risk_amount_threshold' => 1.5, 'volumetric_divisor' => 0], $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors(['currency', 'business_timezone', 'risk_amount_threshold', 'volumetric_divisor']);
        $this->putJson('/api/v1/settings', ['auto_assign' => true, 'risk_amount_threshold' => 123456, 'business_timezone' => 'Asia/Singapore'], $this->headers($token))->assertOk()->assertJsonPath('auto_assign', true)->assertJsonPath('risk_amount_threshold', 123456);
        $this->assertSame('Asia/Singapore', Setting::valueFor('business_timezone'));
        // SLA hours are no longer writable through settings (SUP-15): the SLA policies endpoint is the one editor.
        $policies = config('sfa.settings.sla_policies');
        $this->putJson('/api/v1/settings', ['sla_policies' => $policies], $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('sla_policies');
        $policies['urgent']['resolution_hours'] = 0;
        $this->putJson('/api/v1/support/sla-policies', ['policies' => $policies], $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('policies.urgent.resolution_hours');
        $policies['urgent']['resolution_hours'] = 8;
        $this->putJson('/api/v1/support/sla-policies', ['policies' => $policies], $this->headers($token))->assertOk();
        $this->getJson('/api/v1/settings', $this->headers($token))->assertOk()->assertJsonPath('sla_policies.urgent.resolution_hours', 8);
    }

    public function test_settings_list_exactly_the_time_zones_the_server_accepts(): void
    {
        $token = $this->token();
        $zones = $this->getJson('/api/v1/settings', $this->headers($token))->assertOk()->json('timezones');
        $this->assertSame(timezone_identifiers_list(), $zones);
        $this->assertContains('Asia/Manila', $zones);
        // A browser alias the server refuses is not offered.
        $this->assertNotContains('Asia/Calcutta', $zones);
        $this->putJson('/api/v1/settings', ['business_timezone' => 'Asia/Calcutta'], $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('business_timezone');
        $this->putJson('/api/v1/settings', ['business_timezone' => 'Asia/Kolkata', 'timezones' => ['Not/Saved']], $this->headers($token))->assertOk()->assertJsonMissingPath('timezones');
        $this->assertNull(Setting::valueFor('timezones'));
    }

    public function test_payment_automation_settings_default_on_and_validate(): void
    {
        $token = $this->token();
        $this->getJson('/api/v1/settings', $this->headers($token))->assertOk()->assertJsonPath('auto_capture_authorized', true)->assertJsonPath('finance_reminder_hours', 4);
        $this->putJson('/api/v1/settings', ['auto_capture_authorized' => 'sometimes', 'finance_reminder_hours' => 169], $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors(['auto_capture_authorized', 'finance_reminder_hours']);
        $this->putJson('/api/v1/settings', ['auto_capture_authorized' => false, 'finance_reminder_hours' => 0], $this->headers($token))->assertOk()->assertJsonPath('auto_capture_authorized', false)->assertJsonPath('finance_reminder_hours', 0);
    }

    public function test_risk_auto_clear_settings_default_off_and_validate(): void
    {
        $token = $this->token();
        $this->getJson('/api/v1/settings', $this->headers($token))->assertOk()->assertJsonPath('risk_auto_clear_min_captured', 0)->assertJsonPath('risk_auto_clear_max_amount', 10000000);
        $this->putJson('/api/v1/settings', ['risk_auto_clear_min_captured' => -1, 'risk_auto_clear_max_amount' => 0], $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors(['risk_auto_clear_min_captured', 'risk_auto_clear_max_amount']);
        $this->putJson('/api/v1/settings', ['risk_auto_clear_min_captured' => 3, 'risk_auto_clear_max_amount' => 2500000], $this->headers($token))->assertOk()->assertJsonPath('risk_auto_clear_min_captured', 3)->assertJsonPath('risk_auto_clear_max_amount', 2500000);
    }

    public function test_platform_automation_settings_have_defaults_and_validate(): void
    {
        $token = $this->token();
        $this->getJson('/api/v1/settings', $this->headers($token))->assertOk()->assertJsonPath('token_expiry_reminder_days', 7)->assertJsonPath('notification_retention_days', 90);
        $this->putJson('/api/v1/settings', ['token_expiry_reminder_days' => 91, 'notification_retention_days' => -1], $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors(['token_expiry_reminder_days', 'notification_retention_days']);
        $this->putJson('/api/v1/settings', ['token_expiry_reminder_days' => 0, 'notification_retention_days' => 0], $this->headers($token))->assertOk()->assertJsonPath('token_expiry_reminder_days', 0)->assertJsonPath('notification_retention_days', 0);
    }

    public function test_unknown_users_and_invalid_list_inputs_have_standard_errors(): void
    {
        $token = $this->token();
        $this->putJson('/api/v1/users/999999', ['name' => 'Missing'], $this->headers($token))->assertNotFound();
        $this->getJson('/api/v1/users?per_page=bad', $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/v1/audit-logs?date_from=2026-10-09&date_to=2026-10-01', $this->headers($token))->assertUnprocessable()->assertJsonValidationErrors('date_to');
    }
}
