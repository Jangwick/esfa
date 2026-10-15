<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\Setting;
use App\Models\User;
use App\Services\AiService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\FakesGemini;
use Tests\TestCase;

class AiServiceTest extends TestCase
{
    use FakesGemini, RefreshDatabase;

    private const SCHEMA = ['type' => 'object', 'properties' => ['label' => ['type' => 'string']], 'required' => ['label']];

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

    private function ask(array $input = ['text' => 'Where is my parcel?'], array $options = []): AiInsight
    {
        return app(AiService::class)->json('ticket_triage', null, 'Label the text.', $input, self::SCHEMA, $options);
    }

    public function test_each_reason_the_ai_cannot_run_is_given_in_plain_words(): void
    {
        $ai = app(AiService::class);
        $this->assertStringContainsString('SFA_GEMINI_API_KEY', $ai->unavailable('ticket_triage'));
        config(['services.gemini.key' => 'test-key']);
        $this->assertStringContainsString('assistant is turned off', $ai->unavailable('ticket_triage'));
        Setting::create(['key' => 'ai_enabled', 'value' => true]);
        $this->assertStringContainsString('feature is turned off', $ai->unavailable('ticket_triage'));
        Setting::create(['key' => 'ai_ticket_triage', 'value' => true]);
        $this->assertNull($ai->unavailable('ticket_triage'));
        Setting::create(['key' => 'ai_daily_token_limit', 'value' => 100]);
        AiInsight::create(['feature' => 'ticket_triage', 'status' => 'ok', 'model' => 'm', 'input_hash' => str_repeat('a', 64), 'prompt_tokens' => 80, 'output_tokens' => 20]);
        $this->assertStringContainsString('limit has been reached', $ai->unavailable('ticket_triage'));
        $this->travel(1)->days();
        $this->assertNull($ai->unavailable('ticket_triage'));
    }

    public function test_a_feature_that_cannot_run_is_a_422_and_calls_nothing(): void
    {
        Http::preventStrayRequests();
        $this->expectException(ValidationException::class);
        $this->ask();
    }

    public function test_an_answer_is_stored_with_its_tokens_and_the_same_input_is_not_asked_twice(): void
    {
        $this->enableAi('ticket_triage');
        $this->fakeGemini(['label' => 'delivery'], ['label' => 'billing']);

        $first = $this->ask();
        $this->assertTrue($first->ok());
        $this->assertSame(['label' => 'delivery'], $first->output);
        $this->assertSame(['gemini-3.5-flash-lite', 100, 50], [$first->model, $first->prompt_tokens, $first->output_tokens]);
        $this->assertSame($first->id, $this->ask()->id);
        Http::assertSentCount(1);

        $fresh = $this->ask(options: ['fresh' => true]);
        $this->assertSame(['label' => 'billing'], $fresh->output);
        Http::assertSentCount(2);
    }

    public function test_the_request_is_structured_json_with_the_key_in_a_header_and_private_details_masked(): void
    {
        $this->enableAi('ticket_triage');
        $this->fakeGemini(['label' => 'x']);
        $this->ask(['text' => 'Mail juan.dela.cruz@example.com or call 0917 123 4567 / +63 917-123-4567, card 4111111111111111. Order PAY-2026-000123 on 2026-10-12 08:30 for 1,250.00.'], ['thinking' => 'medium']);

        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $this->assertSame('test-key', $request->header('x-goog-api-key')[0]);
            $this->assertStringNotContainsString('test-key', $request->url());
            $this->assertStringContainsString('models/gemini-3.5-flash-lite:generateContent', $request->url());
            $this->assertSame('application/json', $request['generationConfig']['responseMimeType']);
            $this->assertSame(self::SCHEMA, $request['generationConfig']['responseJsonSchema']);
            $this->assertSame('medium', $request['generationConfig']['thinkingConfig']['thinkingLevel']);
            foreach (['juan.dela.cruz', '0917 123 4567', '917-123-4567', '4111111111111111'] as $private) { $this->assertStringNotContainsString($private, $body); }
            foreach (['PAY-2026-000123', '2026-10-12 08:30', '1,250.00', '[email]', '[phone]', '[number]'] as $kept) { $this->assertStringContainsString($kept, $body); }

            return true;
        });
    }

    public function test_images_follow_the_text_unmasked_and_a_different_photo_is_asked_again(): void
    {
        $this->enableAi('ticket_triage');
        $this->fakeGemini(['label' => 'one'], ['label' => 'two']);
        // Digits that the text masking would turn into [number]: photos are sent exactly as stored.
        $png = ['mime_type' => 'image/png', 'data' => '12345678901234567890AAAA'];
        $webp = ['mime_type' => 'image/webp', 'data' => base64_encode('webp bytes')];

        $first = $this->ask(options: ['images' => [$png, $webp]]);
        Http::assertSent(function (Request $request) use ($png, $webp) {
            $parts = $request['contents'][0]['parts'];
            $this->assertSame('Where is my parcel?', json_decode($parts[0]['text'], true)['text']);
            $this->assertSame([['inlineData' => ['mimeType' => 'image/png', 'data' => $png['data']]], ['inlineData' => ['mimeType' => 'image/webp', 'data' => $webp['data']]]], array_slice($parts, 1));

            return true;
        });
        $this->assertSame($first->id, $this->ask(options: ['images' => [$png, $webp]])->id, 'the same photos reuse the stored answer');
        Http::assertSentCount(1);

        $second = $this->ask(options: ['images' => [$png, ['mime_type' => 'image/webp', 'data' => base64_encode('another photo')]]]);
        Http::assertSentCount(2);
        $this->assertSame(['label' => 'two'], $second->output);
        $this->assertNotSame($first->input_hash, $second->input_hash);
    }

    public function test_a_busy_or_failing_model_is_retried_once_on_the_fallback_model(): void
    {
        $this->enableAi('ticket_triage');
        $this->fakeGemini(503, ['label' => 'delivery']);
        $insight = $this->ask();

        $this->assertTrue($insight->ok());
        $this->assertSame('gemini-3.1-flash-lite', $insight->model);
        $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();
        $this->assertStringContainsString('gemini-3.5-flash-lite', $urls[0]);
        $this->assertStringContainsString('gemini-3.1-flash-lite', $urls[1]);
    }

    public function test_someone_waiting_gets_one_more_try_after_a_pause_but_automatic_work_does_not(): void
    {
        $this->enableAi('ticket_triage');
        $this->fakeGemini(503, 503, ['label' => 'delivery']);
        $insight = $this->ask();
        $this->assertSame(['ok', 'gemini-3.5-flash-lite'], [$insight->status, $insight->model]);
        Http::assertSentCount(3);

        $this->fakeGemini(503, 503);
        $this->assertSame('failed', $this->ask(['text' => 'automatic'], ['patient' => false])->status);
        Http::assertSentCount(2);
    }

    public function test_every_failure_is_stored_with_a_reason_staff_can_read(): void
    {
        $this->enableAi('ticket_triage');
        $cases = [
            [[429, 429, 429], 'busy'],
            [[400], 'refused the request (HTTP 400)'],
            [[['candidates' => [], 'promptFeedback' => ['blockReason' => 'SAFETY']]], 'declined to answer (SAFETY)'],
            [[self::geminiAnswer(['label' => 'x'], 'MAX_TOKENS')], 'cut off'],
        ];
        foreach ($cases as $index => [$answers, $message]) {
            $this->fakeGemini(...$answers);
            $insight = $this->ask(['text' => "case $index"]);
            $this->assertSame('failed', $insight->status);
            $this->assertStringContainsString($message, $insight->error);
        }

        $malformed = self::geminiAnswer([]);
        $malformed['candidates'][0]['content']['parts'][0]['text'] = 'not json';
        $this->fakeGemini($malformed);
        $insight = $this->ask(['text' => 'malformed']);
        $this->assertStringContainsString('expected format', $insight->error);
        $this->assertSame(100, $insight->prompt_tokens, 'tokens of an unusable answer still count');

        $this->fakeGeminiTimeout();
        $this->assertStringContainsString('did not answer in time', $this->ask(['text' => 'timeout'])->error);
        $this->assertSame(0, AiInsight::where('status', 'ok')->count());
    }

    public function test_masking_keeps_dates_amounts_and_references(): void
    {
        $this->assertSame(['a' => '[email]', 'b' => ['[phone]', 7], 'c' => 'PAY-2026-000123 2026-10-12 08:30 1,250.00'], AiService::mask(['a' => 'x@y.ph', 'b' => ['09171234567', 7], 'c' => 'PAY-2026-000123 2026-10-12 08:30 1,250.00']));
        $this->assertSame('2026-10-11T21:02:57+00:00', AiService::mask('2026-10-11T21:02:57+00:00'));
    }

    public function test_masking_catches_numbers_written_with_spaces_dashes_and_brackets(): void
    {
        $cases = [
            '4111 1111 1111 1111' => '[number]', '4111-1111-1111-1111' => '[number]', 'BPI acct 1234 5678 90' => 'BPI acct [number]',
            '(0917) 123-4567' => '[phone]', '917-123-4567' => '[phone]', '63 917 123 4567' => '[phone]', '+63 (917) 123 4567' => '[phone]', '(02) 8123-4567' => '[phone]',
        ];
        $this->assertSame(array_values($cases), AiService::mask(array_keys($cases)));
    }

    public function test_a_key_quoted_in_googles_error_is_never_stored(): void
    {
        $this->enableAi('ticket_triage');
        Http::preventStrayRequests();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'message' => "Consumer 'api_key:test-key' has been suspended."]], 403)]);
        $insight = $this->ask();

        $this->assertStringContainsString('refused the request (HTTP 403)', $insight->error);
        $this->assertStringContainsString('[key]', $insight->error);
        $this->assertStringNotContainsString('test-key', $insight->error);
    }

    public function test_any_other_http_client_error_is_a_failed_insight_not_an_exception(): void
    {
        $this->enableAi('ticket_triage');
        Http::fake(fn () => throw new TransferException('cURL error 60: SSL certificate problem'));
        $insight = $this->ask();

        $this->assertSame('failed', $insight->status);
        $this->assertSame('Gemini could not be reached. Try again in a moment.', $insight->error);
    }

    public function test_each_record_gets_its_own_answer_and_the_same_record_reuses_it(): void
    {
        $this->enableAi('ticket_triage');
        $this->fakeGemini(['label' => 'one'], ['label' => 'two']);
        [$one, $two] = array_map(fn ($name) => User::create(['name' => $name, 'email' => "$name@example.com", 'password' => 'password', 'is_active' => true]), ['one', 'two']);
        $ai = app(AiService::class);
        $ask = fn (User $subject) => $ai->json('ticket_triage', $subject, 'Label the text.', ['text' => 'Same words'], self::SCHEMA);

        $first = $ask($one);
        $this->assertSame($first->id, $ask($one)->id);
        Http::assertSentCount(1);
        $second = $ask($two);
        Http::assertSentCount(2);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame([$first->id, $second->id], [$ai->latest('ticket_triage', $one)->id, $ai->latest('ticket_triage', $two)->id]);
    }

    public function test_the_daily_limit_counts_from_business_midnight_not_utc_midnight(): void
    {
        config(['services.gemini.key' => 'test-key']);
        $manila = fn (string $at) => CarbonImmutable::parse($at, 'Asia/Manila')->utc();
        foreach (['2026-10-12 00:10' => 300, '2026-10-11 23:50' => 5000] as $at => $tokens) {
            $this->travelTo($manila($at), fn () => AiInsight::create(['feature' => 'ticket_triage', 'status' => 'ok', 'model' => 'm', 'input_hash' => str_repeat('a', 64), 'prompt_tokens' => $tokens, 'output_tokens' => 0]));
        }
        $this->travelTo($manila('2026-10-12 23:30'));

        $this->assertSame(300, AiInsight::tokensToday());
        $this->as('Admin')->getJson('/api/v1/automation')->assertOk()->assertJsonPath('ai.today.calls', 1)->assertJsonPath('ai.today.tokens', 300);
    }

    public function test_the_automation_page_shows_ai_setup_and_usage_but_never_the_key(): void
    {
        config(['services.gemini.key' => 'secret-test-key']);
        AiInsight::create(['feature' => 'ticket_triage', 'status' => 'ok', 'model' => 'm', 'input_hash' => str_repeat('a', 64), 'prompt_tokens' => 1000000, 'output_tokens' => 200000]);
        $response = $this->as('Admin')->getJson('/api/v1/automation')->assertOk()
            ->assertJsonPath('ai.configured', true)->assertJsonPath('ai.model', 'gemini-3.5-flash-lite')
            ->assertJsonPath('ai.today.calls', 1)->assertJsonPath('ai.today.tokens', 1200000)->assertJsonPath('ai.today.estimated_usd', 0.8);
        $this->assertStringNotContainsString('secret-test-key', $response->getContent());
    }

    public function test_only_admins_change_the_ai_settings_and_values_are_checked(): void
    {
        $this->putJson('/api/v1/settings', ['ai_enabled' => true])->assertUnauthorized();
        $this->as('Accounting Staff')->putJson('/api/v1/settings', ['ai_enabled' => true])->assertForbidden();
        $this->as('Admin')->putJson('/api/v1/settings', ['ai_enabled' => 'yes', 'ai_daily_token_limit' => -1])->assertUnprocessable()->assertJsonValidationErrors(['ai_enabled', 'ai_daily_token_limit']);
        $this->as('Admin')->putJson('/api/v1/settings', ['ai_enabled' => true, 'ai_ticket_triage' => true, 'ai_daily_token_limit' => 5000])->assertOk()
            ->assertJsonPath('ai_enabled', true)->assertJsonPath('ai_ticket_triage', true)->assertJsonPath('ai_reply_drafts', false)->assertJsonPath('ai_daily_token_limit', 5000);
    }
}
