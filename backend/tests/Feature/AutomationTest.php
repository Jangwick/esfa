<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SchedulerLock;
use App\Models\User;
use App\Services\PaymentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function as(string $role): self
    {
        $user = User::create(['name' => $role, 'email' => Str::slug($role).'.'.Str::random(6).'@example.com', 'password' => 'password', 'is_active' => true]);
        $user->assignRole($role);
        Auth::forgetGuards();

        return $this->withToken($user->createToken('staff', ['staff'])->plainTextToken);
    }

    private function duePayment(): Payment
    {
        $customer = Customer::create(['source_system' => 'orders', 'external_id' => 'automation-customer', 'name' => 'Automation customer']);
        // Sandbox amounts ending in 98 stay pending until a gateway event, so they expire after 30 minutes.
        $order = Order::create(['source_system' => 'orders', 'external_id' => 'automation-order', 'customer_id' => $customer->id, 'subtotal_amount' => 10098, 'total_amount' => 10098, 'placed_at' => now()]);
        $payment = app(PaymentService::class)->create($order, 'card')['payment'];
        $this->travel(31)->minutes();

        return $payment;
    }

    public function test_guests_and_roles_without_manage_settings_are_refused(): void
    {
        $this->getJson('/api/v1/automation')->assertUnauthorized();
        $this->postJson('/api/v1/automation/run')->assertUnauthorized();
        foreach (['Finance', 'Support Agent', 'Analyst'] as $role) {
            $this->as($role)->getJson('/api/v1/automation')->assertForbidden();
            $this->as($role)->postJson('/api/v1/automation/run')->assertForbidden();
        }
    }

    public function test_status_shape_before_any_run_and_a_paused_run_is_rejected(): void
    {
        // phpunit.xml pauses scheduled work.
        $this->as('Admin')->getJson('/api/v1/automation')->assertOk()
            ->assertExactJson(['enabled' => false, 'running' => false, 'last_run_at' => null, 'last_result' => null,
                'ai' => ['configured' => false, 'model' => 'gemini-3.5-flash-lite', 'fallback_model' => 'gemini-3.1-flash-lite', 'today' => ['calls' => 0, 'tokens' => 0, 'estimated_usd' => 0.0]]]);
        $payment = $this->duePayment();
        $this->as('Admin')->postJson('/api/v1/automation/run')->assertUnprocessable()->assertJsonValidationErrors('scheduler');
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_run_now_is_idempotent_records_its_result_and_reports_a_held_lock(): void
    {
        config(['sfa.scheduler_enabled' => true]);
        $payment = $this->duePayment();
        $first = $this->as('Admin')->postJson('/api/v1/automation/run')->assertOk()
            ->assertJsonPath('ran', true)->assertJsonPath('payments_expired', 1)->assertJsonPath('failed_jobs', []);
        $this->assertSame('expired', $payment->fresh()->status);
        $this->assertArrayHasKey('subscriptions_renewed', $first->json());

        $status = $this->as('Admin')->getJson('/api/v1/automation')->assertOk()
            ->assertJsonPath('enabled', true)->assertJsonPath('running', false)
            ->assertJsonPath('last_result.ran', true)->assertJsonPath('last_result.payments_expired', 1)->assertJsonPath('last_result.failed_jobs', []);
        $this->assertNotNull($status->json('last_run_at'));
        $this->assertNotNull($status->json('last_result.finished_at'));

        $this->as('Admin')->postJson('/api/v1/automation/run')->assertOk()->assertJsonPath('ran', true)->assertJsonPath('payments_expired', 0);
        $this->assertSame(0, $this->as('Admin')->getJson('/api/v1/automation')->json('last_result.payments_expired'));

        SchedulerLock::whereKey('scheduled-work')->update(['owner' => (string) Str::uuid(), 'locked_until' => now()->addMinutes(4)]);
        $this->as('Admin')->postJson('/api/v1/automation/run')->assertOk()->assertJsonPath('ran', false);
        $this->as('Admin')->getJson('/api/v1/automation')->assertOk()->assertJsonPath('running', true)->assertJsonPath('last_result.ran', true);
    }

    public function test_run_now_is_rate_limited(): void
    {
        $admin = $this->as('Admin');
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $admin->postJson('/api/v1/automation/run')->assertUnprocessable();
        }
        $admin->postJson('/api/v1/automation/run')->assertTooManyRequests();
    }
}
