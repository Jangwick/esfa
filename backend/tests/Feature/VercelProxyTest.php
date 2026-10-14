<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VercelProxyTest extends TestCase
{
    use RefreshDatabase;

    private array $originalEnvironment;
    private string $token;
    private User $admin;

    protected function setUp(): void
    {
        $this->originalEnvironment = [getenv('VERCEL'), $_ENV['VERCEL'] ?? null, $_SERVER['VERCEL'] ?? null];
        $this->setVercelEnvironment('1');
        TrustProxies::flushState();
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::create(['name' => 'Proxy Admin', 'email' => 'proxy@example.com', 'password' => 'long-password']);
        $this->admin->assignRole('Admin');
        $this->token = $this->admin->createToken('test', ['staff'])->plainTextToken;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        TrustProxies::flushState();
        Request::setTrustedProxies([], 0);
        [$process, $environment, $server] = $this->originalEnvironment;
        putenv($process === false ? 'VERCEL' : 'VERCEL='.$process);
        if ($environment === null) { unset($_ENV['VERCEL']); } else { $_ENV['VERCEL'] = $environment; }
        if ($server === null) { unset($_SERVER['VERCEL']); } else { $_SERVER['VERCEL'] = $server; }
    }

    public static function loopbackAddresses(): array
    {
        return [['127.0.0.1'], ['::1']];
    }

    #[DataProvider('loopbackAddresses')]
    public function test_vercel_loopback_preserves_https_pagination_and_audits_the_client_ip(string $proxy): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => $proxy]);
        $headers = $this->forwardedHeaders('203.0.113.15', 'esfa.example');
        $response = $this->getJson('http://internal.example/api/v1/users?per_page=1', $headers)->assertOk();
        $response->assertJsonPath('path', 'https://esfa.example/api/v1/users');
        $this->assertStringStartsWith('https://esfa.example/api/v1/users?', $response->json('first_page_url'));

        Auth::forgetGuards();
        $this->putJson('http://internal.example/api/v1/profile', ['name' => 'Forwarded Admin'], $headers)->assertOk();
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'User', 'entity_id' => (string) $this->admin->id, 'ip_address' => '203.0.113.15']);
    }

    public function test_untrusted_remote_cannot_spoof_https_host_or_audit_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99']);
        $headers = $this->forwardedHeaders('198.51.100.15', 'forged.example');
        $response = $this->getJson('http://original.example/api/v1/users?per_page=1', $headers)->assertOk();
        $response->assertJsonPath('path', 'http://original.example/api/v1/users');
        $this->assertStringStartsWith('http://original.example/api/v1/users?', $response->json('first_page_url'));

        Auth::forgetGuards();
        $this->putJson('http://original.example/api/v1/profile', ['name' => 'Direct Admin'], $headers)->assertOk();
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'User', 'entity_id' => (string) $this->admin->id, 'ip_address' => '203.0.113.99']);
    }

    public function test_local_loopback_does_not_trust_forwarded_headers_outside_vercel(): void
    {
        $this->setVercelEnvironment('0');
        TrustProxies::flushState();
        $this->refreshApplication();
        Route::get('/api/v1/proxy-test', fn (Request $request) => ['url' => url('/api/v1/users'), 'ip' => $request->ip()]);

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->getJson('http://local.example/api/v1/proxy-test', $this->forwardedHeaders('198.51.100.15', 'forged.example'))
            ->assertOk()->assertExactJson(['url' => 'http://local.example/api/v1/users', 'ip' => '127.0.0.1']);
    }

    private function forwardedHeaders(string $ip, string $host): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'X-Forwarded-For' => $ip, 'X-Forwarded-Host' => $host, 'X-Forwarded-Proto' => 'https'];
    }

    private function setVercelEnvironment(string $value): void
    {
        putenv('VERCEL='.$value);
        $_ENV['VERCEL'] = $value;
        $_SERVER['VERCEL'] = $value;
    }
}
