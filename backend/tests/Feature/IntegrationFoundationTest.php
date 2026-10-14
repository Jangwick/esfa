<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\IdempotencyRecord;
use App\Models\IntegrationClient;
use App\Models\Notification;
use App\Models\SchedulerLock;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Scheduler;
use App\Services\WebhookService;
use App\Services\WebhookUrlGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IntegrationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'manage integrations', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'web']);
        $role->givePermissionTo('manage integrations');
        $this->admin = User::create(['name' => 'Admin', 'email' => 'admin.integration@example.com', 'password' => 'password', 'is_active' => true]);
        $this->admin->assignRole($role);
        $this->adminToken = $this->admin->createToken('staff', ['staff'])->plainTextToken;
        config(['integrations.allow_localhost' => true, 'integrations.cron_secret' => 'test-cron-secret']);
        Http::preventStrayRequests();
    }

    private function client(string $code = 'orders'): IntegrationClient
    {
        return IntegrationClient::create(['name' => ucfirst($code), 'system_code' => $code, 'is_active' => true]);
    }

    private function endpoint(?IntegrationClient $client = null): WebhookEndpoint
    {
        return WebhookEndpoint::create([
            'client_id' => ($client ?? $this->client())->id, 'url' => 'https://8.8.8.8/webhooks',
            'events' => ['payment.captured'], 'secret' => 'known-webhook-secret', 'is_active' => true,
        ]);
    }

    private function asToken(string $token): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_admin_client_crud_filters_csv_tokens_and_revocation(): void
    {
        $response = $this->asToken($this->adminToken)->postJson('/api/v1/integration-clients', [
            'name' => 'Order system', 'system_code' => 'orders', 'contact_email' => 'orders@example.com', 'is_active' => true,
        ])->assertCreated()->assertJsonPath('name', 'Order system')->assertJsonCount(0, 'tokens');
        $id = $response->json('id');
        $this->asToken($this->adminToken)->postJson('/api/v1/integration-clients', ['name' => 'Duplicate', 'system_code' => 'orders'])
            ->assertUnprocessable()->assertJsonValidationErrors('system_code');
        $this->asToken($this->adminToken)->putJson('/api/v1/integration-clients/'.$id, [
            'name' => 'Orders API', 'system_code' => 'orders', 'contact_email' => 'new@example.com', 'is_active' => false,
        ])->assertOk()->assertJsonPath('is_active', false);
        $issued = $this->asToken($this->adminToken)->postJson("/api/v1/integration-clients/{$id}/tokens", [
            'name' => 'Storefront', 'abilities' => ['webhooks:manage'],
        ])->assertCreated()->assertJsonPath('token_record.abilities', ['webhooks:manage']);
        $plain = $issued->json('token');
        $tokenId = $issued->json('token_record.id');
        $this->assertNotSame(explode('|', $plain)[1], DB::table('personal_access_tokens')->where('id', $tokenId)->value('token'));
        $this->asToken($this->adminToken)->postJson("/api/v1/integration-clients/{$id}/tokens", ['name' => 'Too broad', 'abilities' => ['*']])
            ->assertUnprocessable()->assertJsonValidationErrors('abilities.0');
        $list = $this->asToken($this->adminToken)->getJson('/api/v1/integration-clients?search=ORDERS&per_page=1000')
            ->assertOk()->assertJsonPath('per_page', 100)->assertJsonPath('total', 1)->assertJsonPath('summary.tokens', 1);
        $this->assertStringNotContainsString($plain, $list->getContent());
        $this->assertStringNotContainsString($plain, AuditLog::all()->toJson());
        $this->asToken($this->adminToken)->get('/api/v1/integration-clients?search=orders&export=csv')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->asToken($this->adminToken)->deleteJson("/api/v1/integration-clients/{$id}/tokens/{$tokenId}")->assertOk();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);
        $this->asToken($plain)->getJson('/api/v1/integrations/webhook-endpoints')->assertUnauthorized();
        $this->asToken($this->adminToken)->deleteJson('/api/v1/integration-clients/'.$id)->assertOk();
        $this->assertDatabaseMissing('integration_clients', ['id' => $id]);
    }

    public function test_every_staff_integration_endpoint_requires_auth_and_permission(): void
    {
        $client = $this->client();
        $endpoint = $this->endpoint($client);
        $delivery = app(WebhookService::class)->emit('payment.captured', ['reference' => 'PAY-2026-000001'], $client)[0];
        $token = $client->createToken('orders', ['webhooks:manage']);
        $staff = User::create(['name' => 'Read only', 'email' => 'readonly@example.com', 'password' => 'password', 'is_active' => true]);
        $staffToken = $staff->createToken('staff', ['staff'])->plainTextToken;
        $routes = [
            ['GET', '/integration-clients'], ['POST', '/integration-clients'],
            ['PUT', "/integration-clients/{$client->id}"], ['DELETE', "/integration-clients/{$client->id}"],
            ['POST', "/integration-clients/{$client->id}/tokens"], ['DELETE', "/integration-clients/{$client->id}/tokens/{$token->accessToken->id}"],
            ['GET', '/webhook-endpoints'], ['POST', '/webhook-endpoints'],
            ['PUT', "/webhook-endpoints/{$endpoint->id}"], ['DELETE', "/webhook-endpoints/{$endpoint->id}"],
            ['POST', "/webhook-endpoints/{$endpoint->id}/test"], ['GET', '/webhook-deliveries'],
            ['POST', "/webhook-deliveries/{$delivery->id}/resend"], ['POST', '/webhook-deliveries/resend-failed'],
        ];
        foreach ($routes as [$method, $path]) {
            $this->app['auth']->forgetGuards();
            $this->withoutToken()->json($method, '/api/v1'.$path)->assertUnauthorized();
            $this->asToken($staffToken)->json($method, '/api/v1'.$path)->assertForbidden();
        }
    }

    public function test_integration_scopes_staff_isolation_inactive_clients_and_own_endpoints(): void
    {
        $client = $this->client();
        $other = $this->client('checkout');
        $foreign = $this->endpoint($other);
        $token = $client->createToken('owned', ['webhooks:manage'])->plainTextToken;
        $withoutScope = $client->createToken('limited', ['payments:read'])->plainTextToken;
        $this->asToken($this->adminToken)->getJson('/api/v1/integrations/webhook-endpoints')->assertForbidden();
        $this->asToken($token)->getJson('/api/v1/integration-clients')->assertForbidden();
        $this->asToken($withoutScope)->getJson('/api/v1/integrations/webhook-endpoints')->assertForbidden();
        $this->asToken($token)->getJson('/api/v1/integrations/webhook-endpoints')->assertOk()->assertJsonPath('total', 0);
        $created = $this->asToken($token)->postJson('/api/v1/integrations/webhook-endpoints', [
            'url' => 'https://8.8.8.8/hook', 'events' => ['payment.captured'],
        ])->assertCreated()->assertJsonPath('client_id', $client->id);
        $ownId = $created->json('id');
        $this->asToken($token)->putJson('/api/v1/integrations/webhook-endpoints/'.$ownId, [
            'url' => 'https://8.8.8.8/hook-updated', 'events' => ['payment.failed'],
        ])->assertOk()->assertJsonPath('events', ['payment.failed']);
        $this->asToken($token)->putJson('/api/v1/integrations/webhook-endpoints/'.$foreign->id, [
            'url' => 'https://8.8.8.8/evil', 'events' => ['payment.captured'],
        ])->assertNotFound();
        $this->asToken($token)->deleteJson('/api/v1/integrations/webhook-endpoints/'.$foreign->id)->assertNotFound();
        $this->asToken($token)->postJson('/api/v1/integrations/webhook-endpoints', [
            'client_id' => $other->id, 'url' => 'https://8.8.8.8/hook', 'events' => ['payment.captured'],
        ])->assertUnprocessable()->assertJsonValidationErrors('client_id');
        $this->asToken($token)->deleteJson('/api/v1/integrations/webhook-endpoints/'.$ownId)->assertOk();
        $client->update(['is_active' => false]);
        $this->asToken($token)->getJson('/api/v1/integrations/webhook-endpoints')->assertForbidden();
    }

    public function test_webhook_crud_secret_is_shown_once_encrypted_and_never_audited(): void
    {
        $client = $this->client();
        $created = $this->asToken($this->adminToken)->postJson('/api/v1/webhook-endpoints', [
            'client_id' => $client->id, 'url' => 'http://localhost:8787/webhooks', 'events' => ['payment.captured'], 'is_active' => true,
        ])->assertCreated();
        $id = $created->json('id');
        $secret = $created->json('secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $this->assertNotSame($secret, DB::table('webhook_endpoints')->where('id', $id)->value('secret'));
        $this->assertSame($secret, WebhookEndpoint::findOrFail($id)->secret);
        $listed = $this->asToken($this->adminToken)->getJson('/api/v1/webhook-endpoints?per_page=0')
            ->assertOk()->assertJsonPath('per_page', 1)->assertJsonPath('summary.active', 1);
        $this->assertArrayNotHasKey('secret', $listed->json('data.0'));
        $rotated = $this->asToken($this->adminToken)->putJson('/api/v1/webhook-endpoints/'.$id, [
            'client_id' => $client->id, 'url' => 'http://localhost:8787/webhooks', 'events' => ['payment.failed'], 'rotate_secret' => true,
        ])->assertOk()->json('secret');
        $this->assertNotSame($secret, $rotated);
        $this->assertStringNotContainsString($secret, AuditLog::all()->toJson());
        $this->assertStringNotContainsString($rotated, AuditLog::all()->toJson());
        $this->asToken($this->adminToken)->get('/api/v1/webhook-endpoints?export=csv')->assertOk();
        $this->asToken($this->adminToken)->deleteJson('/api/v1/webhook-endpoints/'.$id)->assertOk();
    }

    public function test_webhook_url_validation_blocks_ssrf_and_unrecognized_events(): void
    {
        $client = $this->client();
        foreach (['http://example.com/hook', 'https://127.0.0.1/hook', 'https://10.0.0.1/hook',
            'https://169.254.169.254/latest/meta-data', 'https://[::1]/hook', 'https://user:password@8.8.8.8/hook'] as $url) {
            $this->asToken($this->adminToken)->postJson('/api/v1/webhook-endpoints', [
                'client_id' => $client->id, 'url' => $url, 'events' => ['payment.captured'],
            ])->assertUnprocessable()->assertJsonValidationErrors('url');
        }
        $this->asToken($this->adminToken)->postJson('/api/v1/webhook-endpoints', [
            'client_id' => $client->id, 'url' => 'https://8.8.8.8/hook', 'events' => ['invented.event'],
        ])->assertUnprocessable()->assertJsonValidationErrors('events.0');
        $guard = Mockery::mock(WebhookUrlGuard::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $guard->shouldReceive('resolveAddresses')->with('mixed.example.com')->andReturn(['8.8.8.8', '127.0.0.1']);
        $this->expectException(ValidationException::class);
        $guard->transportOptions('https://mixed.example.com/hook');
    }

    public function test_localhost_is_blocked_outside_development_and_redirects_are_disabled(): void
    {
        $options = app(WebhookUrlGuard::class)->transportOptions('https://8.8.8.8/hook');
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame('', $options['proxy']);
        $this->assertSame(['8.8.8.8:443:8.8.8.8'], $options['curl'][CURLOPT_RESOLVE]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->expectException(ValidationException::class);
        app(WebhookUrlGuard::class)->transportOptions('http://localhost:8787/hook');
    }

    public function test_webhook_signature_raw_body_headers_response_limit_and_test_resend_endpoints(): void
    {
        $endpoint = $this->endpoint();
        Http::fake(['https://8.8.8.8/*' => Http::response(str_repeat('a', 3000), 204)]);
        $response = $this->asToken($this->adminToken)->postJson("/api/v1/webhook-endpoints/{$endpoint->id}/test")
            ->assertCreated()->assertJsonPath('status', 'delivered')->assertJsonPath('attempts', 1);
        $id = $response->json('id');
        Http::assertSent(function ($request) use ($endpoint, $id) {
            $signature = $request->header('X-SFA-Signature')[0];
            preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $signature, $matches);
            $expected = hash_hmac('sha256', $matches[1].'.'.$request->body(), $endpoint->secret);

            return count($matches) === 3 && hash_equals($expected, $matches[2])
                && $request->header('X-SFA-Event')[0] === 'webhook.test'
                && $request->header('X-SFA-Delivery')[0] === $id
                && json_decode($request->body(), true)['type'] === 'webhook.test';
        });
        $this->assertSame(2048, strlen($response->json('response_body')));
        $this->asToken($this->adminToken)->postJson('/api/v1/webhook-deliveries/'.$id.'/resend')
            ->assertOk()->assertJsonPath('attempts', 2)->assertJsonCount(2, 'attempt_log');
        $this->asToken($this->adminToken)->getJson('/api/v1/webhook-deliveries?status=delivered&per_page=200')
            ->assertOk()->assertJsonPath('summary.delivered', 1)->assertJsonPath('per_page', 100);
        $this->asToken($this->adminToken)->get('/api/v1/webhook-deliveries?export=csv')->assertOk();
        $endpoint->update(['is_active' => false]);
        $this->asToken($this->adminToken)->postJson("/api/v1/webhook-endpoints/{$endpoint->id}/test")->assertUnprocessable();
        $this->asToken($this->adminToken)->postJson('/api/v1/webhook-deliveries/'.$id.'/resend')->assertUnprocessable();
    }

    public function test_delivery_search_matches_a_whole_delivery_id_by_equality_and_never_a_fragment(): void
    {
        $endpoint = $this->endpoint();
        $make = fn (string $type) => WebhookDelivery::create([
            'id' => (string) Str::uuid(), 'endpoint_id' => $endpoint->id, 'event_id' => 'evt_'.Str::uuid(), 'event_type' => $type,
            'request_body' => '{}', 'status' => 'delivered', 'attempts' => 1, 'retry_count' => 0, 'attempt_log' => [],
        ]);
        $target = $make('payment.captured');
        $make('payment.refunded');
        $ids = fn (string $search) => array_column($this->asToken($this->adminToken)
            ->getJson('/api/v1/webhook-deliveries?search='.urlencode($search))->assertOk()->json('data'), 'id');

        // PostgreSQL has no uuid LIKE operator, so only a whole UUID is compared, by equality and in either case.
        $this->assertSame([$target->id], $ids($target->id));
        $this->assertSame([$target->id], $ids(strtoupper($target->id)));
        $this->assertSame([], $ids(substr($target->id, 0, 8)));
        $this->assertSame([$target->id], $ids('CAPTURED'), 'The event type still matches in any case.');
        $this->assertSame([$target->id], $ids(substr($target->event_id, 4, 12)), 'A fragment of the event id still matches.');
    }

    public function test_resend_failed_requeues_only_failed_deliveries_on_active_endpoints_once(): void
    {
        $active = $this->endpoint();
        $disabled = $this->endpoint($this->client('billing'));
        $disabled->update(['is_active' => false]);
        $make = fn (WebhookEndpoint $endpoint, string $status) => WebhookDelivery::create([
            'id' => (string) Str::uuid(), 'endpoint_id' => $endpoint->id, 'event_id' => 'evt_'.Str::uuid(), 'event_type' => 'payment.captured',
            'request_body' => '{}', 'status' => $status, 'attempts' => 6, 'retry_count' => 6, 'attempt_log' => [],
        ]);
        $failed = [$make($active, 'failed'), $make($active, 'failed')];
        $delivered = $make($active, 'delivered');
        $other = $make($disabled, 'failed');

        $this->asToken($this->adminToken)->postJson('/api/v1/webhook-deliveries/resend-failed')->assertOk()->assertExactJson(['requeued' => 2]);
        foreach ($failed as $delivery) {
            $delivery->refresh();
            $this->assertSame(['pending', 0], [$delivery->status, $delivery->retry_count]);
            $this->assertFalse($delivery->next_attempt_at->isFuture());
        }
        $this->assertSame('delivered', $delivered->fresh()->status);
        $this->assertSame('failed', $other->fresh()->status, 'Deliveries on a disabled endpoint are left alone.');
        Http::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->admin->id, 'action' => 'updated', 'entity_type' => 'WebhookEndpoint']);

        $this->asToken($this->adminToken)->postJson('/api/v1/webhook-deliveries/resend-failed')->assertOk()->assertExactJson(['requeued' => 0]);
        $this->asToken($this->adminToken)->postJson('/api/v1/webhook-deliveries/resend-failed', ['endpoint_id' => $disabled->id])
            ->assertUnprocessable()->assertJsonValidationErrors('endpoint');
        $this->asToken($this->adminToken)->postJson('/api/v1/webhook-deliveries/resend-failed', ['endpoint_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('endpoint_id');
        $this->asToken($this->adminToken)->postJson('/api/v1/webhook-deliveries/resend-failed', ['endpoint_id' => 'abc'])
            ->assertUnprocessable()->assertJsonValidationErrors('endpoint_id');

        $disabled->update(['is_active' => true]);
        $this->asToken($this->adminToken)->postJson('/api/v1/webhook-deliveries/resend-failed', ['endpoint_id' => $disabled->id])
            ->assertOk()->assertExactJson(['requeued' => 1]);
        $this->assertSame('pending', $other->fresh()->status);
    }

    public function test_webhook_retries_all_five_intervals_then_fails_and_records_every_attempt(): void
    {
        $this->freezeTime();
        $endpoint = $this->endpoint();
        Http::fake(['https://8.8.8.8/*' => Http::response('Unavailable', 503)]);
        $service = app(WebhookService::class);
        $delivery = $service->emit('payment.captured', ['reference' => 'PAY-2026-000001'], $endpoint->client)[0];
        foreach (WebhookService::RETRY_SECONDS as $index => $delay) {
            $service->deliver($delivery->fresh());
            $delivery->refresh();
            $this->assertSame('pending', $delivery->status);
            $this->assertSame($index + 1, $delivery->attempts);
            $this->assertSame($delay, $delivery->next_attempt_at->timestamp - now()->timestamp);
            $this->travel($delay)->seconds();
        }
        $service->deliver($delivery->fresh());
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertCount(6, $delivery->fresh()->attempt_log);
        $this->assertNull($delivery->fresh()->next_attempt_at);
        $service->deliver($delivery->fresh());
        Http::assertSentCount(6);
    }

    public function test_twenty_consecutive_failures_disable_endpoint_and_notify_admin_once(): void
    {
        $endpoint = $this->endpoint();
        Http::fake(['https://8.8.8.8/*' => Http::response('Error', 500)]);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $delivery = app(WebhookService::class)->emit('payment.captured', ['sequence' => $attempt], $endpoint->client)[0];
            app(WebhookService::class)->deliver($delivery);
        }
        $this->assertFalse($endpoint->fresh()->is_active);
        $this->assertSame(20, $endpoint->fresh()->consecutive_failures);
        $this->assertSame(1, Notification::where('type', 'webhook_endpoint_disabled')->where('user_id', $this->admin->id)->count());
        $this->assertSame([], app(WebhookService::class)->emit('payment.captured', [], $endpoint->client));
        $this->asToken($this->adminToken)->putJson('/api/v1/webhook-endpoints/'.$endpoint->id, [
            'client_id' => $endpoint->client_id, 'url' => $endpoint->url, 'events' => $endpoint->events, 'is_active' => true,
        ])->assertOk()->assertJsonPath('consecutive_failures', 0)->assertJsonPath('disabled_at', null);
    }

    public function test_webhook_success_resets_consecutive_failures_and_delivery_is_claimed_once(): void
    {
        $endpoint = $this->endpoint();
        $endpoint->update(['consecutive_failures' => 19]);
        Http::fake(['https://8.8.8.8/*' => Http::response('', 200)]);
        $delivery = app(WebhookService::class)->emit('payment.captured', [], $endpoint->client)[0];
        app(WebhookService::class)->deliver($delivery);
        app(WebhookService::class)->deliver($delivery->fresh());
        $this->assertSame(0, $endpoint->fresh()->consecutive_failures);
        Http::assertSentCount(1);
    }

    private function registerIdempotentRoute(bool $serverError = false): string
    {
        $path = 'api/v1/integrations/test-idempotency';
        Route::post($path, function () use ($serverError) {
            $client = IntegrationClient::create(['name' => 'Created by idempotency test', 'system_code' => 'created-'.Str::lower(Str::random(12))]);

            return response()->json(['id' => $client->id], $serverError ? 500 : 201);
        })->middleware(['auth:sanctum', 'integration.only', 'idempotency']);

        return '/'.$path;
    }

    public function test_idempotency_requires_header_replays_response_and_conflicts_atomically(): void
    {
        $client = $this->client();
        $token = $client->createToken('create', ['payments:write'])->plainTextToken;
        $path = $this->registerIdempotentRoute();
        $this->asToken($token)->postJson($path, ['amount' => 100])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->asToken($token)->withHeader('Idempotency-Key', str_repeat('x', 256))->postJson($path, ['amount' => 100])->assertUnprocessable();
        $first = $this->asToken($token)->withHeader('Idempotency-Key', 'same-key')->postJson($path, ['amount' => 100])->assertCreated();
        $this->asToken($token)->withHeader('Idempotency-Key', 'same-key')->postJson($path, ['amount' => 100])
            ->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertExactJson($first->json());
        $this->asToken($token)->withHeader('Idempotency-Key', 'same-key')->postJson($path, ['amount' => 101])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(2, IntegrationClient::count());
        $this->assertDatabaseCount('idempotency_records', 1);
        $this->travel(25)->hours();
        $this->asToken($token)->withHeader('Idempotency-Key', 'same-key')->postJson($path, ['amount' => 100])->assertCreated();
        $this->assertSame(3, IntegrationClient::count());
        $this->assertDatabaseCount('idempotency_records', 1);
    }

    public function test_idempotency_in_progress_conflict_and_server_failure_roll_back_business_changes(): void
    {
        $client = $this->client();
        $token = $client->createToken('create', ['payments:write'])->plainTextToken;
        $path = $this->registerIdempotentRoute(true);
        $body = json_encode(['amount' => 100]);
        IdempotencyRecord::create([
            'client_id' => $client->id, 'key' => 'busy', 'request_hash' => hash('sha256', "POST\n{$path}\n{$body}"),
            'created_at' => now(), 'expires_at' => now()->addDay(),
        ]);
        $this->asToken($token)->withHeader('Idempotency-Key', 'busy')->postJson($path, ['amount' => 100])
            ->assertStatus(409)->assertHeader('Retry-After', '5');
        $this->asToken($token)->withHeader('Idempotency-Key', 'error')->postJson($path, ['amount' => 100])->assertStatus(500);
        $this->assertSame(1, IntegrationClient::count());
        $this->assertDatabaseMissing('idempotency_records', ['key' => 'error']);
    }

    public function test_integration_rate_limit_has_retry_after_and_last_used_timestamp_is_tracked(): void
    {
        $client = $this->client();
        $token = $client->createToken('rate', ['webhooks:manage']);
        for ($request = 0; $request < 120; $request++) {
            $this->asToken($token->plainTextToken)->getJson('/api/v1/integrations/webhook-endpoints')->assertOk();
        }
        $this->asToken($token->plainTextToken)->getJson('/api/v1/integrations/webhook-endpoints')->assertStatus(429)->assertHeader('Retry-After');
        $this->assertNotNull($token->accessToken->fresh()->last_used_at);
    }

    public function test_scheduler_cron_secret_lock_throttle_pruning_and_idempotent_due_work(): void
    {
        config(['sfa.scheduler_enabled' => true]); // phpunit.xml pauses scheduled work by default.
        $endpoint = $this->endpoint();
        Http::fake(['https://8.8.8.8/*' => Http::response('', 200)]);
        $delivery = app(WebhookService::class)->emit('payment.captured', [], $endpoint->client)[0];
        IdempotencyRecord::create([
            'client_id' => $delivery->endpoint->client_id, 'key' => 'expired', 'request_hash' => str_repeat('a', 64),
            'created_at' => now()->subDays(2), 'expires_at' => now()->subDay(),
        ]);
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->getJson('/api/v1/cron/run')->assertUnauthorized();
        $this->asToken('bad-secret')->getJson('/api/v1/cron/run')->assertUnauthorized();
        $this->asToken('test-cron-secret')->getJson('/api/v1/cron/run')->assertOk()
            ->assertJsonPath('webhook_attempts', 1)->assertJsonPath('idempotency_pruned', 1);
        $this->asToken('test-cron-secret')->getJson('/api/v1/cron/run')->assertOk()->assertJsonPath('webhook_attempts', 0);
        $this->assertFalse(app(Scheduler::class)->run(true)['ran']);
        SchedulerLock::whereKey('scheduled-work')->update(['locked_until' => now()->addMinute(), 'owner' => (string) Str::uuid()]);
        $this->assertFalse(app(Scheduler::class)->run()['ran']);
        $this->travel(6)->minutes();
        $this->assertTrue(app(Scheduler::class)->run(true)['ran']);
        Http::assertSentCount(1);
        $this->artisan('sfa:run-scheduled')->assertSuccessful();
    }

    public function test_paused_cron_keeps_its_secret_check_and_runs_nothing(): void
    {
        $endpoint = $this->endpoint();
        Http::fake(['https://8.8.8.8/*' => Http::response('', 200)]);
        app(WebhookService::class)->emit('payment.captured', [], $endpoint->client);
        config(['sfa.scheduler_enabled' => false]);
        $this->app['auth']->forgetGuards();
        $this->withoutToken()->getJson('/api/v1/cron/run')->assertUnauthorized();
        $this->asToken('bad-secret')->getJson('/api/v1/cron/run')->assertUnauthorized();
        $this->asToken('test-cron-secret')->getJson('/api/v1/cron/run')->assertOk()
            ->assertJsonPath('ran', false)->assertJsonPath('paused', true)->assertJsonPath('webhook_attempts', 0);
        Http::assertNothingSent();
        $this->assertNull(SchedulerLock::find('scheduled-work'));
    }
}
