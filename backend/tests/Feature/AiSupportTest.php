<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\AuditLog;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Seller;
use App\Models\Shipment;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TicketService;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LogisticsDemoSeeder;
use Database\Seeders\SupportDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\FakesGemini;
use Tests\TestCase;

class AiSupportTest extends TestCase
{
    use FakesGemini, RefreshDatabase;

    private Customer $customer;
    private Order $order;

    private const TRIAGE = ['category' => 'delivery', 'priority' => 'high', 'ground' => 'delivery_problem', 'sentiment' => 'angry', 'tags' => ['Late Delivery', 'courier'], 'summary' => 'The customer wants to know where the parcel is.', 'reason' => 'The delivery is late and the customer is upset.'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->seed(SupportDemoSeeder::class);
        // Seeded tickets are from "now"; later tests move the clock so only their own tickets are recent.
        $this->customer = Customer::create(['source_system' => 'storefront', 'external_id' => 'ai-support-customer', 'name' => 'Juan Dela Cruz', 'email' => 'juan@example.com']);
        $this->order = Order::create(['source_system' => 'storefront', 'external_id' => 'ai-support-order', 'customer_id' => $this->customer->id, 'status_snapshot' => 'placed', 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
    }

    private function staff(string $email = 'admin@example.com'): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.User::where('email', $email)->firstOrFail()->createToken('ai-test', ['staff'])->plainTextToken];
    }

    private function body(array $values = []): array
    {
        return ['subject' => 'Nasaan na ang parcel ko?', 'description' => 'Three days late already. Call me at 0917 123 4567.', 'requester_type' => 'customer', 'requester_id' => $this->customer->id, 'order_id' => $this->order->id, 'channel' => 'email', 'category' => 'other', ...$values];
    }

    private function open(array $values = []): Ticket
    {
        return Ticket::findOrFail($this->postJson('/api/v1/support/tickets', $this->body($values), $this->staff())->assertCreated()->json('id'));
    }

    public function test_a_new_ticket_is_triaged_after_it_is_saved_and_staff_see_why(): void
    {
        $this->enableAi('ticket_triage');
        $this->fakeGemini(self::TRIAGE);
        $ticket = $this->open();

        $this->assertSame(['delivery', 'high'], [$ticket->category, $ticket->priority]);
        $this->assertSame(['late_delivery', 'courier', 'ai_triaged'], $ticket->tags);
        $hours = SlaPolicy::hoursFor('high');
        $this->assertEquals($ticket->created_at->copy()->addHours($hours['first_response_hours']), $ticket->first_response_due_at, 'the SLA follows the raised priority');
        $audit = AuditLog::where('entity_type', 'Ticket')->where('entity_id', (string) $ticket->id)->where('new_values->automation', 'ai_ticket_triage')->firstOrFail();
        $this->assertSame(['other', 'normal'], [$audit->old_values['category'], $audit->old_values['priority']]);
        $this->assertNull(AiInsight::firstOrFail()->created_by, 'triage is automatic work');
        Http::assertSent(fn ($request) => ! str_contains($request->body(), '0917 123 4567') && str_contains($request->body(), 'Nasaan na ang parcel ko?')
            && str_contains($request['contents'][0]['parts'][0]['text'], '"order_status": "placed"'));

        $this->getJson('/api/v1/support/tickets/'.$ticket->id, $this->staff('support.one@example.com'))->assertOk()
            ->assertJsonPath('ai_triage.sentiment', 'angry')->assertJsonPath('ai_triage.reason', self::TRIAGE['reason']);
    }

    public function test_triage_keeps_a_chosen_category_and_never_lowers_or_replaces_a_chosen_priority(): void
    {
        $this->enableAi('ticket_triage');
        $this->fakeGemini(['category' => 'delivery', 'priority' => 'urgent'] + self::TRIAGE);
        $low = $this->open(['category' => 'payment', 'priority' => 'low']);
        $this->assertSame(['payment', 'low'], [$low->category, $low->priority]);
        $this->assertContains('ai_triaged', $low->tags);

        $this->fakeGemini(['priority' => 'low'] + self::TRIAGE);
        $urgent = $this->open(['priority' => 'urgent', 'subject' => 'Charged twice']);
        $this->assertSame('urgent', $urgent->priority);
    }

    public function test_triage_that_is_off_or_fails_leaves_the_ticket_as_it_was(): void
    {
        Http::preventStrayRequests();
        $off = $this->open();
        $this->assertSame(['other', 'normal', []], [$off->category, $off->priority, $off->tags]);
        $this->assertSame(0, AiInsight::count());

        $this->enableAi('ticket_triage');
        $this->fakeGemini(503, 503);
        $busy = $this->open(['subject' => 'Busy model']);
        $this->assertSame(['other', 'normal', []], [$busy->category, $busy->priority, $busy->tags]);

        $this->fakeGemini(['category' => 'not_a_category'] + self::TRIAGE);
        $invalid = $this->open(['subject' => 'Invalid answer']);
        $this->assertSame(['other', []], [$invalid->category, $invalid->tags]);
        $this->assertStringContainsString('did not fit', AiInsight::latest('id')->first()->error);

        $this->fakeGeminiTimeout();
        $this->assertSame([], $this->open(['subject' => 'Slow model'])->tags);
        $this->assertSame(0, AiInsight::where('status', 'ok')->count(), 'the rejected answer is stored as failed');
        $this->getJson('/api/v1/support/tickets/'.$invalid->id, $this->staff())->assertOk()->assertJsonPath('ai_triage', null);
    }

    public function test_tickets_sfa_opens_itself_are_not_triaged(): void
    {
        $this->enableAi('ticket_triage');
        Http::preventStrayRequests();
        $ticket = app(TicketService::class)->create($this->body(['channel' => 'system', 'category' => 'delivery']), null);
        $this->assertSame([], Ticket::find($ticket->id)->tags);
    }

    public function test_scheduled_work_triages_missed_tickets_with_at_most_three_tries(): void
    {
        $this->travel(2)->days();
        Http::preventStrayRequests();
        $missed = $this->open();
        $this->enableAi('ticket_triage');
        $service = app(TicketService::class);

        foreach (range(1, 3) as $try) {
            $this->fakeGemini(503, 503);
            $this->assertSame(0, $service->runScheduled()['tickets_ai_triaged']);
        }
        $this->fakeGemini();
        $this->assertSame(0, $service->runScheduled()['tickets_ai_triaged']);
        Http::assertNothingSent();
        $this->assertSame(3, AiInsight::where('subject_id', $missed->id)->count());

        $this->travel(1)->minutes();
        $this->setting('ai_ticket_triage', false);
        $later = $this->open(['subject' => 'Switched on later']);
        $this->setting('ai_ticket_triage', true);
        $this->fakeGemini(self::TRIAGE);
        $this->assertSame(1, $service->runScheduled()['tickets_ai_triaged']);
        $this->assertContains('ai_triaged', $later->fresh()->tags);
        $this->fakeGemini();
        $this->assertSame(0, $service->runScheduled()['tickets_ai_triaged']);
    }

    private function draftUrl(Ticket $ticket): string
    {
        return '/api/v1/support/tickets/'.$ticket->id.'/ai-draft';
    }

    private const DRAFT = ['reply' => 'Hi {first_name}, your parcel is on its way.', 'source_article_ids' => [], 'confidence' => 'high', 'needs_human_reason' => '',
        'checks' => [['id' => 'RPL-6', 'result' => 'pass', 'reason' => 'It promises nothing.'], ['id' => 'RPL-7', 'result' => 'not_applicable', 'reason' => 'No article covers this.']]];

    public function test_reply_drafts_need_sign_in_edit_rights_ticket_access_and_the_switch(): void
    {
        $ticket = $this->open();
        Auth::forgetGuards();
        $this->postJson($this->draftUrl($ticket))->assertUnauthorized();
        $this->postJson($this->draftUrl($ticket), [], $this->staff('finance@example.com'))->assertForbidden();
        $this->postJson($this->draftUrl($ticket), [], $this->staff('analyst@example.com'))->assertForbidden();
        $ticket->update(['assignee_id' => User::where('email', 'support.one@example.com')->value('id')]);
        $this->postJson($this->draftUrl($ticket), [], $this->staff('support.two@example.com'))->assertNotFound();
        Http::preventStrayRequests();
        $this->postJson($this->draftUrl($ticket), [], $this->staff('support.one@example.com'))->assertUnprocessable()->assertJsonValidationErrors('ai');
        $this->postJson($this->draftUrl($ticket), ['fresh' => 'often'], $this->staff('support.one@example.com'))->assertUnprocessable()->assertJsonValidationErrors('fresh');
        $this->getJson('/api/v1/user', $this->staff('support.one@example.com'))->assertOk()->assertJsonPath('ai_features', []);
        $this->enableAi('reply_drafts');
        $this->getJson('/api/v1/user', $this->staff('support.one@example.com'))->assertOk()->assertJsonPath('ai_features', ['reply_drafts']);
    }

    public function test_a_draft_uses_published_articles_and_sfa_records_and_cites_only_real_articles(): void
    {
        $category = \App\Models\KbCategory::create(['name' => 'AI test']);
        $published = \App\Models\KbArticle::create(['title' => 'Tracking your parcel', 'slug' => 'ai-tracking', 'body' => 'Track it in the app under My Orders.', 'category_id' => $category->id, 'status' => 'published', 'published_at' => now()]);
        $draft = \App\Models\KbArticle::create(['title' => 'Secret unreleased policy', 'slug' => 'ai-secret', 'body' => 'Not yet.', 'category_id' => $category->id, 'status' => 'draft']);
        $ticket = $this->open();
        $this->enableAi('reply_drafts');
        $this->fakeGemini(['source_article_ids' => [$published->id, $draft->id, 999999]] + self::DRAFT);

        $this->postJson($this->draftUrl($ticket), [], $this->staff('support.one@example.com'))->assertOk()
            ->assertJsonPath('reply', self::DRAFT['reply'])->assertJsonPath('confidence', 'high')->assertJsonPath('needs_human_reason', null)
            ->assertJsonPath('sources', [['id' => $published->id, 'title' => 'Tracking your parcel']]);
        Http::assertSent(function ($request) {
            $body = $request['contents'][0]['parts'][0]['text'];
            foreach (['Tracking your parcel', 'PHP 100.00', 'Nasaan na ang parcel ko?', '"requester": "customer"'] as $sent) { $this->assertStringContainsString($sent, $body); }
            foreach (['Secret unreleased policy', '0917 123 4567', 'Juan Dela Cruz', 'juan@example.com'] as $kept) { $this->assertStringNotContainsString($kept, $body); }
            $this->assertSame('medium', $request['generationConfig']['thinkingConfig']['thinkingLevel']);

            return true;
        });
        $this->assertSame(User::where('email', 'support.one@example.com')->value('id'), AiInsight::where('feature', 'reply_drafts')->value('created_by'));
        $this->assertSame(0, \App\Models\AuditLog::where('entity_type', 'Ticket')->where('entity_id', (string) $ticket->id)->where('created_at', '>=', now()->subSecond())->whereNotNull('user_id')->where('action', 'updated')->count(), 'drafting is not an edit');
    }

    public function test_asking_again_reuses_the_draft_unless_a_fresh_one_is_requested(): void
    {
        $ticket = $this->open();
        $this->enableAi('reply_drafts');
        $this->fakeGemini(self::DRAFT, ['reply' => 'Hi {first_name}, a second version.'] + self::DRAFT);
        $this->postJson($this->draftUrl($ticket), [], $this->staff())->assertOk();
        $this->postJson($this->draftUrl($ticket), [], $this->staff())->assertOk()->assertJsonPath('reply', self::DRAFT['reply']);
        Http::assertSentCount(1);
        $this->postJson($this->draftUrl($ticket), ['fresh' => true], $this->staff())->assertOk()->assertJsonPath('reply', 'Hi {first_name}, a second version.');
    }

    public function test_a_closed_ticket_or_an_unusable_answer_gives_a_clear_message(): void
    {
        $ticket = $this->open();
        $this->enableAi('reply_drafts');
        $this->fakeGemini(503, 503, 503);
        $this->postJson($this->draftUrl($ticket), [], $this->staff())->assertStatus(503)->assertJsonPath('message', 'Gemini is unavailable right now (HTTP 503). Try again in a moment.');
        $this->fakeGemini(['confidence' => 'certain'] + self::DRAFT);
        $this->postJson($this->draftUrl($ticket), ['fresh' => true], $this->staff())->assertStatus(503)->assertJsonPath('message', 'The AI draft could not be used. Try again.');
        $ticket->update(['status' => 'closed']);
        $this->postJson($this->draftUrl($ticket), [], $this->staff())->assertUnprocessable()->assertJsonValidationErrors('ticket');
    }

    private function pay(Order $order, string $reference, string $status = 'captured', int $amount = 10000): Payment
    {
        return Payment::create(['order_id' => $order->id, 'customer_id' => $order->customer_id, 'method' => 'card', 'gateway' => 'sandbox', 'status' => $status, 'total_amount' => $amount, 'captured_amount' => $amount, 'captured_at' => now(), 'reference' => $reference]);
    }

    private function otherOrder(string $id, string $status = 'placed'): Order
    {
        return Order::create(['source_system' => 'storefront', 'external_id' => $id, 'customer_id' => $this->customer->id, 'status_snapshot' => $status, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
    }

    // Opens a ticket triaged with $answer; returns it with its TRI checks by id.
    private function triaged(array $answer, array $values = []): array
    {
        $this->enableAi('ticket_triage');
        $this->fakeGemini($answer + self::TRIAGE);
        $ticket = $this->open($values);

        return [$ticket, collect(AiInsight::where('feature', 'ticket_triage')->where('subject_id', $ticket->id)->latest('id')->firstOrFail()->output['checks'])->keyBy('id')];
    }

    public function test_triage_caps_urgent_at_high_when_sfa_cannot_confirm_a_duplicate_charge(): void
    {
        $payment = $this->pay($this->order, 'PAY-2026-000901');
        $this->pay($this->otherOrder('ai-refunded'), 'PAY-2026-000902', 'refunded');
        [$ticket, $checks] = $this->triaged(['category' => 'payment', 'priority' => 'urgent', 'ground' => 'duplicate_charge'], ['payment_id' => $payment->id, 'subject' => 'Charged twice']);

        $this->assertSame('high', $ticket->priority);
        $this->assertSame(['fail', 'Asked for Urgent for a duplicate charge, but SFA\'s records show no second captured payment of the same amount; capped at High.'], [$checks['TRI-2']['result'], $checks['TRI-2']['detail']]);
        $this->assertSame(['pass', 'Raised the default priority Normal to High.'], [$checks['TRI-1']['result'], $checks['TRI-1']['detail']]);
        $this->assertSame(['pass', 'pass'], [$checks['TRI-3']['result'], $checks['TRI-4']['result']]);
        $this->assertSame(['TRI-1', 'TRI-2', 'TRI-3', 'TRI-4'], $checks->keys()->all());
    }

    public function test_triage_allows_urgent_for_money_grounds_sfa_confirms(): void
    {
        $payment = $this->pay($this->order, 'PAY-2026-000901');
        $this->pay($this->otherOrder('ai-second'), 'PAY-2026-000902');
        [$ticket, $checks] = $this->triaged(['category' => 'payment', 'priority' => 'urgent', 'ground' => 'duplicate_charge'], ['payment_id' => $payment->id, 'subject' => 'Charged twice']);
        $this->assertSame('urgent', $ticket->priority);
        $this->assertSame(['pass', 'Urgent for a duplicate charge: SFA\'s records show a second captured payment of the same amount for this customer.'], [$checks['TRI-2']['result'], $checks['TRI-2']['detail']]);
        $this->assertSame('not_applicable', $checks['TRI-3']['result']);

        $cancelled = $this->otherOrder('ai-cancelled', 'cancelled');
        $this->pay($cancelled, 'PAY-2026-000903', 'partially_refunded');
        [$ticket, $checks] = $this->triaged(['category' => 'refund_return', 'priority' => 'urgent', 'ground' => 'money_without_order'], ['order_id' => $cancelled->id, 'subject' => 'Cancelled but charged']);
        $this->assertSame(['urgent', 'pass'], [$ticket->priority, $checks['TRI-2']['result']]);
        $this->assertSame('Urgent for money taken for a cancelled order: SFA\'s records show a captured payment on this cancelled order.', $checks['TRI-2']['detail']);
    }

    public function test_triage_checks_never_show_logistics_a_payment_reference(): void
    {
        $clock = Carbon::getTestNow();
        $this->seed(LogisticsDemoSeeder::class);
        Carbon::setTestNow($clock); // the seeder resets the test clock
        $hub = Warehouse::where('code', 'demo-las-pinas-hub')->firstOrFail();
        $seller = Seller::create(['source_system' => 'storefront', 'external_id' => 'ai-support-seller', 'shop_name' => 'Demo shop']);
        $shipment = Shipment::create(['order_id' => $this->order->id, 'seller_id' => $seller->id, 'courier_id' => Courier::where('code', 'demo-courier')->value('id'), 'service_type' => 'standard', 'origin_warehouse_id' => $hub->id,
            'destination_address_id' => $hub->address_id, 'weight_grams' => 1000, 'dimensions' => [], 'shipping_fee_amount' => 0, 'promised_from' => now(), 'promised_to' => now()->addDays(3)]);
        $payment = $this->pay($this->order, 'PAY-2026-000901');
        $this->pay($this->otherOrder('ai-twin'), 'PAY-2026-000902');
        [$ticket, $checks] = $this->triaged(['category' => 'payment', 'priority' => 'urgent', 'ground' => 'duplicate_charge'], ['payment_id' => $payment->id, 'shipment_id' => $shipment->id, 'subject' => 'Charged twice']);
        $this->assertSame(['urgent', 'pass'], [$ticket->priority, $checks['TRI-2']['result']]);
        $url = '/api/v1/support/tickets/'.$ticket->id;
        $this->assertStringNotContainsString('PAY-', $this->getJson($url, $this->staff('logistics@example.com'))->assertOk()->assertJsonPath('ai_triage.checks.1.result', 'pass')->getContent());

        // A check saved before the fix still named payments: logistics sees it masked, a role with view payments as saved.
        $insight = AiInsight::where('feature', 'ticket_triage')->where('subject_id', $ticket->id)->firstOrFail();
        $output = $insight->output; $output['checks'][1]['detail'] = 'Urgent for a duplicate charge: PAY-2026-000901 and PAY-2026-000902 are both captured for PHP 100.00.';
        $insight->update(['output' => $output]);
        $this->assertStringNotContainsString('PAY-', $this->getJson($url, $this->staff('logistics@example.com'))->assertOk()
            ->assertJsonPath('ai_triage.checks.1.detail', 'Urgent for a duplicate charge: a payment and a payment are both captured for PHP 100.00.')->getContent());
        $this->getJson($url, $this->staff())->assertOk()->assertJsonPath('ai_triage.checks.1.detail', $output['checks'][1]['detail']);
    }

    public function test_a_low_suggestion_keeps_normal_and_passes_tri_1(): void
    {
        [$ticket, $checks] = $this->triaged(['priority' => 'low', 'ground' => 'none']);
        $this->assertSame('normal', $ticket->priority);
        $this->assertSame(['pass', 'Suggested Low; SFA never lowers a priority, kept at Normal.'], [$checks['TRI-1']['result'], $checks['TRI-1']['detail']]);
        $this->assertSame(['not_applicable', 'not_applicable'], [$checks['TRI-2']['result'], $checks['TRI-3']['result']]);
        Http::assertSent(fn ($request) => ! str_contains($request['systemInstruction']['parts'][0]['text'], 'low for'));
    }

    public function test_a_priority_a_person_chose_passes_tri_1_and_tri_2_and_tri_3_do_not_apply(): void
    {
        $chosen = 'A person chose the priority; nothing was applied.';
        [$high, $checks] = $this->triaged(['priority' => 'low', 'ground' => 'none'], ['priority' => 'high']);
        $this->assertSame('high', $high->priority);
        $this->assertSame(['pass', 'Suggested Low; SFA never lowers a priority, kept the priority High a person chose.'], [$checks['TRI-1']['result'], $checks['TRI-1']['detail']]);
        foreach (['TRI-2', 'TRI-3'] as $id) { $this->assertSame(['not_applicable', $chosen], [$checks[$id]['result'], $checks[$id]['detail']]); }

        [$low, $checks] = $this->triaged(['priority' => 'urgent', 'ground' => 'safety'], ['priority' => 'low', 'subject' => 'Smoke from the charger']);
        $this->assertSame('low', $low->priority);
        $this->assertSame(['pass', 'Kept the priority Low a person chose.'], [$checks['TRI-1']['result'], $checks['TRI-1']['detail']]);
        foreach (['TRI-2', 'TRI-3'] as $id) { $this->assertSame(['not_applicable', $chosen], [$checks[$id]['result'], $checks[$id]['detail']]); }
    }

    public function test_triage_with_no_ground_keeps_normal_and_records_why(): void
    {
        [$ticket, $checks] = $this->triaged(['priority' => 'high', 'ground' => 'none']);
        $this->assertSame('normal', $ticket->priority);
        $this->assertSame(['pass', 'Kept the default priority Normal.'], [$checks['TRI-1']['result'], $checks['TRI-1']['detail']]);
        $this->assertSame(['fail', 'Asked for High for no ground; capped at Normal.'], [$checks['TRI-3']['result'], $checks['TRI-3']['detail']]);
        $this->assertSame('not_applicable', $checks['TRI-2']['result']);
    }

    public function test_the_ticket_shows_the_triage_ground_and_checks(): void
    {
        [$ticket] = $this->triaged([]);
        $this->getJson('/api/v1/support/tickets/'.$ticket->id, $this->staff())->assertOk()
            ->assertJsonPath('ai_triage.ground', 'delivery_problem')->assertJsonPath('ai_triage.checks.2.id', 'TRI-3')->assertJsonPath('ai_triage.checks.2.result', 'pass')
            ->assertJsonPath('ai_triage.checks.2.kind', 'sfa')->assertJsonPath('ai_triage.checks.3.detail', 'Replaced the category Other with Delivery.');

        $insight = AiInsight::where('feature', 'ticket_triage')->where('subject_id', $ticket->id)->firstOrFail();
        $insight->update(['output' => \Illuminate\Support\Arr::except($insight->output, ['ground', 'checks'])]);
        $this->getJson('/api/v1/support/tickets/'.$ticket->id, $this->staff())->assertOk()->assertJsonPath('ai_triage.ground', null)->assertJsonPath('ai_triage.checks', []);
    }

    // Drafts $answer for a ticket linked to the order's payment; returns the response.
    private function drafted(array $answer, ?Ticket $ticket = null): \Illuminate\Testing\TestResponse
    {
        $ticket ??= $this->open(['payment_id' => $this->pay($this->order, 'PAY-2026-000901')->id]);
        $this->enableAi('reply_drafts');
        $this->fakeGemini($answer + self::DRAFT);

        return $this->postJson($this->draftUrl($ticket), ['fresh' => true], $this->staff())->assertOk();
    }

    private static function results(array $checks): array
    {
        return array_column($checks, 'result', 'id');
    }

    public function test_a_clean_draft_passes_every_rule_and_needs_no_correction(): void
    {
        $response = $this->drafted([])->assertJsonPath('needs_correction', false);
        $this->assertSame(['RPL-1' => 'pass', 'RPL-2' => 'pass', 'RPL-3' => 'pass', 'RPL-4' => 'pass', 'RPL-5' => 'pass', 'RPL-6' => 'pass', 'RPL-7' => 'not_applicable'], self::results($response->json('checks')));
        $this->assertSame(['ai', 'required', 'It promises nothing.'], [$response->json('checks.5.kind'), $response->json('checks.5.severity'), $response->json('checks.5.detail')]);
        Http::assertSent(fn ($request) => str_contains($request['systemInstruction']['parts'][0]['text'], 'RPL-6 No unsupported promises')
            && $request['generationConfig']['responseJsonSchema']['properties']['checks']['items']['properties']['id']['enum'] === ['RPL-6', 'RPL-7']);
    }

    public function test_a_draft_asking_for_an_otp_needs_correction(): void
    {
        $response = $this->drafted(['reply' => 'Hi {first_name}, paki-send po ang OTP na natanggap ninyo para ma-check namin.'])->assertJsonPath('needs_correction', true);
        $this->assertSame('fail', self::results($response->json('checks'))['RPL-1']);
        $this->assertStringContainsString('"OTP"', $response->json('checks.0.detail'));
    }

    public function test_a_draft_with_an_amount_sfa_does_not_have_fails_rpl_2(): void
    {
        $ticket = $this->open(['payment_id' => ($payment = $this->pay($this->order, 'PAY-2026-000901'))->id]);
        Refund::create(['payment_id' => $payment->id, 'total_amount' => 2500, 'reason' => 'other']);
        $ok = $this->drafted(['reply' => 'Hi {first_name}, you paid PHP 100.00 and ₱25 is being refunded.'], $ticket);
        $this->assertSame(['pass', 'Every amount matches the ticket\'s records.'], [$ok->json('checks.1.result'), $ok->json('checks.1.detail')]);

        $bad = $this->drafted(['reply' => 'Hi {first_name}, you paid PHP 100.00 and P1,250.50 will be refunded.'], $ticket)->assertJsonPath('needs_correction', true);
        $this->assertSame(['fail', 'Not in the ticket\'s order, payment or refund records: PHP 1,250.50.'], [$bad->json('checks.1.result'), $bad->json('checks.1.detail')]);

        $lower = $this->drafted(['reply' => 'Hi {first_name}, you paid php 100.00, not php 1,250.'], $ticket)->assertJsonPath('needs_correction', true);
        $this->assertSame(['fail', 'Not in the ticket\'s order, payment or refund records: PHP 1,250.00.'], [$lower->json('checks.1.result'), $lower->json('checks.1.detail')]);
    }

    public function test_rpl_1_flags_a_pin_code_but_not_a_map_pin(): void
    {
        $ticket = $this->open(['payment_id' => $this->pay($this->order, 'PAY-2026-000901')->id]);
        $map = $this->drafted(['reply' => 'Hi {first_name}, paki-pin po ang exact location ninyo, or pin your exact location on the map.'], $ticket)->assertJsonPath('needs_correction', false);
        $this->assertSame(['pass', 'Asks for no password, one-time code, PIN or card number.'], [$map->json('checks.0.result'), $map->json('checks.0.detail')]);
        foreach (['PIN' => 'Hi {first_name}, please send us your PIN.', 'mpin' => 'Hi {first_name}, paki-send po ang mpin ninyo.', 'pin code' => 'Hi {first_name}, ano po ang pin code ng card?', 'card number' => 'Hi {first_name}, what is your card number?'] as $word => $reply) {
            $response = $this->drafted(['reply' => $reply], $ticket)->assertJsonPath('needs_correction', true);
            $this->assertSame('fail', $response->json('checks.0.result'));
            $this->assertStringContainsString('"'.$word.'"', $response->json('checks.0.detail'));
        }
    }

    public function test_a_draft_citing_another_customers_payment_fails_rpl_3(): void
    {
        $ticket = $this->open(['payment_id' => $this->pay($this->order, 'PAY-2026-000901')->id]);
        $this->pay($this->otherOrder('ai-foreign'), 'PAY-2026-000777');
        $response = $this->drafted(['reply' => 'Hi {first_name}, about '.$ticket->reference.' and PAY-2026-000901: we also see PAY-2026-000777.'], $ticket)->assertJsonPath('needs_correction', true);
        $this->assertSame(['fail', 'Not this ticket\'s records: PAY-2026-000777.'], [$response->json('checks.2.result'), $response->json('checks.2.detail')]);
    }

    private function setting(string $key, mixed $value): void
    {
        \App\Models\Setting::updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
