<?php

namespace Tests\Feature;

use App\Models\IntegrationClient;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AiRulesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function staff(string $email): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.User::where('email', $email)->firstOrFail()->createToken('ai-rules', ['staff'])->plainTextToken];
    }

    public function test_the_rulebooks_need_a_staff_session(): void
    {
        $this->getJson('/api/v1/ai/rules')->assertUnauthorized();
        $token = IntegrationClient::create(['name' => 'Shop', 'system_code' => 'rules-shop', 'is_active' => true])->createToken('shop', ['*'])->plainTextToken;
        $this->getJson('/api/v1/ai/rules', ['Authorization' => 'Bearer '.$token])->assertForbidden();
    }

    public function test_any_staff_member_reads_every_rulebook_with_its_thresholds_filled_in(): void
    {
        Setting::updateOrCreate(['key' => 'refund_window_days'], ['value' => 30]);

        foreach (['support.one@example.com', 'admin@example.com'] as $email) {
            $response = $this->getJson('/api/v1/ai/rules', $this->staff($email))->assertOk()
                ->assertJsonStructure(['books' => [['name', 'title', 'used_by', 'decision', 'rules' => [['id', 'kind', 'severity', 'title', 'description']]]], 'delivery_playbook']);

            $this->assertSame(['refund', 'risk', 'reply', 'triage', 'address', 'delivery', 'ask'], array_column($response->json('books'), 'name'), $email);
            $this->assertSame(['REF-3', 'sfa', 'required'], [$response->json('books.0.rules.2.id'), $response->json('books.0.rules.2.kind'), $response->json('books.0.rules.2.severity')]);
            $this->assertStringContainsString('within 30 days of delivery', $response->json('books.0.rules.2.description'));
            $this->assertDoesNotMatchRegularExpression('/\{\w+\}/', implode(' ', $response->json('books.*.rules.*.description')), 'every {placeholder} is filled in');
            $this->assertSame(['recipient_unavailable', 'wrong_or_incomplete_address', 'refused', 'inaccessible_area', 'cod_cash_not_ready', 'rescheduled_by_customer', 'other'], array_keys($response->json('delivery_playbook')));
        }
    }
}
