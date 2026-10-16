<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\WebhookService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

// SFA's built-in stand-in partner receiver (/api/v1/simrx) on hosted demos: its public page shows nothing it holds,
// only this SFA can set it up, and only an Admin reads what it received (GET /simulator/simrx).
class SimrxReceiverTest extends TestCase
{
    use RefreshDatabase;

    private const PARTNER = 'https://sfa.example.test/api/v1/simrx';
    private const CONFIG = ['sfa_api_url' => 'https://sfa.example.test/api/v1', 'client_code' => 'demo-storefront', 'token' => '7|plain-storefront-token', 'webhook_secret' => 'whsec_receiver_secret', 'courier_code' => 'demo-courier', 'courier_secret' => 'courier_receiver_secret', 'gateway_secret' => 'gateway_receiver_secret'];

    private string $adminToken;
    private string $financeToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        config(['sfa.simulator' => true, 'sfa.partner_url' => self::PARTNER]);
        Http::preventStrayRequests();
        $admin = User::create(['name' => 'Admin', 'email' => 'simrx.admin@example.com', 'password' => 'password', 'is_active' => true]);
        $admin->assignRole('Admin');
        $this->adminToken = $admin->createToken('staff', ['staff'])->plainTextToken;
        $finance = User::create(['name' => 'Accounting Staff', 'email' => 'simrx.finance@example.com', 'password' => 'password', 'is_active' => true]);
        $finance->assignRole('Accounting Staff');
        $this->financeToken = $finance->createToken('staff', ['staff'])->plainTextToken;
    }

    private function as(?string $token): self
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $token ? $this->withToken($token) : $this;
    }

    // A shipment event as the receiver keeps it, with the recipient's personal details in the payload.
    private function seedReceived(): array
    {
        $events = [['at' => '2026-10-16T02:00:00.000000Z', 'event' => 'shipment.created', 'delivery' => '9b1f0c2e-4d5a-4e6f-8a7b-1c2d3e4f5a6b',
            'payload' => ['id' => 'evt_secret01', 'type' => 'shipment.created', 'data' => ['reference' => 'SHP-REF-0001', 'destination' => ['contact_name' => 'Juana Recipient', 'contact_phone' => '09179998888', 'line1' => '12 Hidden Street']]]]];
        Setting::create(['key' => 'simrx.config', 'value' => self::CONFIG]);
        Setting::create(['key' => 'simrx.events', 'value' => $events]);

        return $events;
    }

    private function postSetup(string $body, ?string $signature): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + ($signature === null ? [] : ['HTTP_X_SFA_SETUP_SIGNATURE' => $signature]);

        return $this->as(null)->call('POST', '/api/v1/simrx/api/setup', [], [], [], $server, $body);
    }

    private function sign(string $body, ?string $key = null): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $key ?? config('app.key'));
    }

    public function test_settings_never_show_or_audit_the_receivers_secrets_or_payloads(): void
    {
        $this->seedReceived();

        $settings = $this->as($this->adminToken)->getJson('/api/v1/settings')->assertOk();
        $this->assertArrayNotHasKey('simrx.config', $settings->json());
        $this->assertArrayNotHasKey('simrx.events', $settings->json());
        foreach (['plain-storefront-token', 'whsec_receiver_secret', 'Juana Recipient', '09179998888'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $settings->getContent());
        }
        $this->as($this->adminToken)->putJson('/api/v1/settings', ['company_name' => 'Archon Nell'])->assertOk();
        $audit = \App\Models\AuditLog::latest('id')->first();
        foreach (['plain-storefront-token', 'whsec_receiver_secret', 'Juana Recipient', 'simrx.'] as $hidden) {
            $this->assertStringNotContainsString($hidden, json_encode([$audit?->old_values, $audit?->new_values]));
        }
    }

    public function test_the_public_page_names_the_receiver_and_shows_nothing_it_holds(): void
    {
        $events = $this->seedReceived();
        $response = $this->as(null)->get('/api/v1/simrx')->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
        $this->assertStringStartsWith('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertSee('<title>SFA partner receiver</title>', false)->assertSee('Process Simulator');
        $html = $response->getContent();
        foreach (['Juana Recipient', '09179998888', '12 Hidden Street', 'SHP-REF-0001', 'evt_secret01', 'shipment.created', $events[0]['delivery'], substr($events[0]['delivery'], 0, 8), '2026-10-16', ...array_values(self::CONFIG)] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html);
        }

        config(['sfa.simulator' => false]);
        $this->as(null)->get('/api/v1/simrx')->assertNotFound();
    }

    public function test_setup_takes_only_a_body_signed_with_this_sfas_key(): void
    {
        $body = json_encode(self::CONFIG);
        $stored = fn () => Setting::where('key', 'simrx.config')->first()?->value;

        // Unsigned, wrongly signed, signed with another key or signed over another body: 401 before validation, nothing stored.
        $this->postSetup($body, null)->assertUnauthorized()->assertJsonPath('message', 'This setup request was not signed by this SFA.');
        $this->postSetup('{}', null)->assertUnauthorized();
        $this->postSetup($body, 'sha256=deadbeef')->assertUnauthorized();
        $this->postSetup($body, $this->sign($body, 'base64:someone-elses-key'))->assertUnauthorized();
        $this->postSetup(json_encode(['webhook_secret' => 'whsec_attacker'] + self::CONFIG), $this->sign($body))->assertUnauthorized();
        $this->postSetup($body, substr($this->sign($body), 7))->assertUnauthorized();
        $this->assertNull($stored());

        $this->postSetup($body, $this->sign($body))->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(self::CONFIG, $stored());
        // A signed but invalid body is still validated.
        $this->postSetup('{}', $this->sign('{}'))->assertUnprocessable()->assertJsonValidationErrors('webhook_secret');

        // An unsigned overwrite of the secret leaves the receiver as this SFA set it up.
        $attack = json_encode(['webhook_secret' => 'whsec_attacker'] + self::CONFIG);
        $this->postSetup($attack, null)->assertUnauthorized();
        $this->postSetup($attack, $this->sign($attack, 'base64:someone-elses-key'))->assertUnauthorized();
        $this->assertSame(self::CONFIG, $stored());

        config(['sfa.simulator' => false]);
        $this->postSetup($body, $this->sign($body))->assertNotFound();
    }

    public function test_connect_signs_the_exact_setup_body_and_the_receiver_accepts_it(): void
    {
        Http::fake(['sfa.example.test/*' => Http::response(['ok' => true])]);
        $connection = $this->as($this->adminToken)->postJson('/api/v1/simulator/connect')->assertOk()->assertJsonPath('receiver.online', true)->json();

        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->first(fn ($request) => $request->url() === self::PARTNER.'/api/setup');
        $this->assertNotNull($sent, 'connect posts the storefront configuration to the receiver.');
        $this->assertSame($this->sign($sent->body()), $sent->header('X-SFA-Setup-Signature')[0]);
        $this->assertSame([$connection['systems']['storefront']['token'], 'demo-storefront'], [$sent['token'], $sent['client_code']]);

        // Replayed to the built-in receiver as sent, it is accepted; the same signature over a changed body is not.
        $this->postSetup($sent->body(), $sent->header('X-SFA-Setup-Signature')[0])->assertOk();
        $this->assertSame($sent['webhook_secret'], Setting::valueFor('simrx.config')['webhook_secret']);
        $this->postSetup(str_replace($sent['webhook_secret'], 'whsec_attacker', $sent->body()), $sent->header('X-SFA-Setup-Signature')[0])->assertUnauthorized();
        $this->assertSame($sent['webhook_secret'], Setting::valueFor('simrx.config')['webhook_secret']);
    }

    public function test_only_an_admin_reads_what_the_receiver_got(): void
    {
        $events = $this->seedReceived();
        $this->as(null)->getJson('/api/v1/simulator/simrx')->assertUnauthorized();
        $this->as($this->financeToken)->getJson('/api/v1/simulator/simrx')->assertForbidden();
        $this->as($this->adminToken)->getJson('/api/v1/simulator/simrx')->assertOk()->assertExactJson(['configured' => true, 'events' => $events]);

        config(['sfa.simulator' => false]);
        $this->as($this->adminToken)->getJson('/api/v1/simulator/simrx')->assertNotFound();
    }

    // A signed webhook lands in the Admin list with what the Process Simulator shows (time, event, delivery id, payload).
    public function test_a_signed_webhook_reaches_the_admin_list_and_never_the_public_page(): void
    {
        $this->as($this->adminToken)->getJson('/api/v1/simulator/simrx')->assertOk()->assertExactJson(['configured' => false, 'events' => []]);
        Setting::create(['key' => 'simrx.config', 'value' => self::CONFIG]);
        $body = json_encode(['id' => 'evt_webhook01', 'type' => 'ticket.created', 'data' => ['reference' => 'TCK-0001', 'subject' => 'Parcel for Pedro Private']]);
        $timestamp = now()->timestamp;
        $this->as(null)->call('POST', '/api/v1/simrx/webhooks', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_SFA_EVENT' => 'ticket.created', 'HTTP_X_SFA_DELIVERY' => 'dlv-0001',
            'HTTP_X_SFA_SIGNATURE' => app(WebhookService::class)->signature($timestamp, $body, self::CONFIG['webhook_secret'])], $body)->assertOk();

        $events = $this->as($this->adminToken)->getJson('/api/v1/simulator/simrx')->assertOk()->assertJsonPath('configured', true)->json('events');
        $this->assertCount(1, $events);
        $this->assertSame(['ticket.created', 'dlv-0001', json_decode($body, true)], [$events[0]['event'], $events[0]['delivery'], $events[0]['payload']]);
        $this->assertNotEmpty($events[0]['at']);
        $this->as(null)->get('/api/v1/simrx')->assertOk()->assertDontSee('Pedro Private')->assertDontSee('dlv-0001')->assertDontSee('ticket.created');
    }
}
