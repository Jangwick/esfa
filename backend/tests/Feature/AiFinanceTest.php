<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\AuditLog;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\FinanceReviewService;
use App\Services\AttachmentService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LogisticsDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\FakesGemini;
use Tests\TestCase;

class AiFinanceTest extends TestCase
{
    use FakesGemini, RefreshDatabase;

    private Customer $customer;

    // The model only gives verdicts on the AI-judged rules and explains; the recommendation is worked out by SFA.
    private const RISK = ['checks' => [['id' => 'RSK-5', 'result' => 'pass', 'reason' => 'The order and tickets look ordinary.']], 'confidence' => 'medium', 'summary' => 'Held because the amount is far above the review threshold.', 'reasons' => ['First payment from this customer.', 'Amount is over the threshold.']];
    private const REFUND = ['checks' => [['id' => 'REF-6', 'result' => 'pass', 'reason' => 'The notes say the box never came.'], ['id' => 'REF-7', 'result' => 'not_applicable', 'reason' => 'Not a damaged or wrong item.'],
        ['id' => 'REF-8', 'result' => 'not_applicable', 'reason' => 'There is no photo.'], ['id' => 'REF-9', 'result' => 'pass', 'reason' => 'The amount is within the order total.']], 'confidence' => 'high', 'reasons' => ['The parcel was not delivered.'], 'rejection_note_draft' => 'Our records show your parcel was delivered.'];
    private const PASSED = ['REF-6' => 'pass', 'REF-7' => 'not_applicable', 'REF-8' => 'not_applicable', 'REF-9' => 'pass'];
    private const SECOND = ['checks' => [['id' => 'RSK-5', 'result' => 'pass', 'reason' => 'Nothing unusual.']], 'reason' => ''];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->customer = Customer::create(['source_system' => 'storefront', 'external_id' => 'ai-finance-customer', 'name' => 'Maria Santos', 'email' => 'maria@example.com']);
    }

    private function staff(string $email = 'finance@example.com'): array
    {
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.User::where('email', $email)->firstOrFail()->createToken('ai-test', ['staff'])->plainTextToken];
    }

    // Over the PHP 50,000 threshold, the sandbox authorizes it and the risk rule holds it for review.
    private function heldPayment(int $amount = 6000000, string $method = 'card'): Payment
    {
        $order = Order::create(['source_system' => 'storefront', 'external_id' => 'ai-order-'.uniqid(), 'customer_id' => $this->customer->id, 'subtotal_amount' => $amount, 'total_amount' => $amount, 'placed_at' => now()]);

        return app(PaymentService::class)->create($order, $method)['payment'];
    }

    // $daysLater: the refund is asked for that many days after the payment was captured.
    private function requestedRefund(int $captured = 10000, int $amount = 4000, string $reason = 'item_not_received', int $daysLater = 0): Refund
    {
        $payment = $this->heldPayment($captured);
        $this->assertSame('captured', $payment->status);
        if ($daysLater) { $this->travel($daysLater)->days(); }

        return app(PaymentService::class)->requestRefund($payment, ['amount' => $amount, 'reason' => $reason, 'notes' => 'Customer says the box never came. Call 0917 222 3333.'], User::where('email', 'support.one@example.com')->first());
    }

    // A parcel of the payment's order; each one has its own seller (one shipment per order and seller).
    private function shipment(Payment $payment, string $status, array $values = []): Shipment
    {
        if (! Warehouse::where('code', 'demo-las-pinas-hub')->exists()) {
            $clock = Carbon::getTestNow();
            $this->seed(LogisticsDemoSeeder::class);
            Carbon::setTestNow($clock); // the seeder resets the test clock
        }
        $hub = Warehouse::where('code', 'demo-las-pinas-hub')->firstOrFail();
        $seller = Seller::create(['source_system' => 'orders', 'external_id' => 'ai-finance-'.uniqid(), 'shop_name' => 'Demo shop']);

        return Shipment::create(['order_id' => $payment->order_id, 'seller_id' => $seller->id, 'courier_id' => Courier::where('code', 'demo-courier')->value('id'), 'service_type' => 'standard', 'origin_warehouse_id' => $hub->id,
            'destination_address_id' => $hub->address_id, 'weight_grams' => 1000, 'dimensions' => [], 'shipping_fee_amount' => 0, 'promised_from' => now(), 'promised_to' => now()->addDays(3), 'status' => $status, ...$values]);
    }

    // Rule id => result, in rulebook order.
    private function results(TestResponse $response): array
    {
        return collect($response->json('checks'))->pluck('result', 'id')->all();
    }

    // The calendar day staff see: refund check dates are in the business timezone.
    private static function day(\DateTimeInterface $date): string
    {
        return Carbon::instance($date)->timezone(Setting::valueFor('business_timezone'))->toDateString();
    }

    // The facts SFA sent to the model in its last request.
    private static function sentFacts(): array
    {
        return json_decode(Http::recorded()->last()[0]['contents'][0]['parts'][0]['text'], true);
    }

    public function test_finance_reviews_need_sign_in_the_right_permission_scope_and_the_switch(): void
    {
        $payment = $this->heldPayment();
        $refund = $this->requestedRefund();
        foreach (['/api/v1/risk-reviews/'.$payment->id.'/ai-review', '/api/v1/refunds/'.$refund->id.'/ai-review'] as $url) {
            Auth::forgetGuards();
            $this->getJson($url)->assertUnauthorized();
            $this->getJson($url, $this->staff('support.one@example.com'))->assertForbidden();
            $this->postJson($url, [], $this->staff('analyst@example.com'))->assertForbidden();
            Http::preventStrayRequests();
            $this->getJson($url, $this->staff())->assertUnprocessable()->assertJsonValidationErrors('ai');
        }
    }

    public function test_a_risk_review_is_explained_with_sfa_facts_and_changes_nothing(): void
    {
        $this->freezeTime();
        $payment = $this->heldPayment();
        $this->assertSame(['authorized', 'review'], [$payment->status, $payment->risk_level]);
        $this->enableAi('finance_review');
        $this->fakeGemini(self::RISK, ['summary' => 'A fresh look.'] + self::RISK);

        // A brand-new customer with nothing captured before fails two advisory rules: look closer, whatever the model thinks.
        $response = $this->getJson('/api/v1/risk-reviews/'.$payment->id.'/ai-review', $this->staff())->assertOk()
            ->assertJsonPath('recommendation', 'need_more_info')->assertJsonPath('reasons.1', 'Amount is over the threshold.')
            ->assertJsonPath('flagged_because.0', 'The amount PHP 60,000.00 is over the review threshold of PHP 50,000.00.')
            ->assertJsonPath('checks.2.detail', 'The customer has no other captured payment.')
            ->assertJsonPath('checks.3.detail', 'Customer since '.now()->toDateString().', 0 days before this payment; newer than 7 days is a risk signal.');
        $this->assertSame(['RSK-1' => 'pass', 'RSK-2' => 'pass', 'RSK-3' => 'fail', 'RSK-4' => 'fail', 'RSK-5' => 'pass'], $this->results($response));
        $this->assertSame(['recommendation', 'confidence', 'summary', 'reasons', 'checks', 'flagged_because', 'created_at'], array_keys($response->json()));
        Http::assertSent(function ($request) {
            $facts = json_decode($request['contents'][0]['parts'][0]['text'], true);
            $this->assertSame('PHP 60,000.00', $facts['payment']['amount']);
            $this->assertSame(0, $facts['customer_history']['captured_payments']);
            $this->assertSame(['RSK-1', 'RSK-2', 'RSK-3', 'RSK-4'], array_column($facts['sfa_checks'], 'id'));
            $this->assertSame(['RSK-5'], array_column($facts['rules_to_judge'], 'id'));
            $this->assertSame(['RSK-5'], $request['generationConfig']['responseJsonSchema']['properties']['checks']['items']['properties']['id']['enum']);
            $this->assertStringNotContainsString('Maria', $request->body());
            $this->assertStringNotContainsString('maria@example.com', $request->body());

            return true;
        });
        $this->getJson('/api/v1/risk-reviews/'.$payment->id.'/ai-review', $this->staff())->assertOk()->assertJsonPath('summary', self::RISK['summary']);
        Http::assertSentCount(1);
        $this->postJson('/api/v1/risk-reviews/'.$payment->id.'/ai-review', [], $this->staff())->assertOk()->assertJsonPath('summary', 'A fresh look.');

        $fresh = $payment->fresh();
        $this->assertSame(['authorized', 'review', 0], [$fresh->status, $fresh->risk_level, $fresh->captured_amount], 'the AI never clears, blocks or captures');
        $this->assertSame(0, AuditLog::where('entity_type', 'Payment')->where('entity_id', (string) $payment->id)->whereNotNull('user_id')->count(), 'asking is not an edit');
    }

    public function test_a_risk_review_recommends_what_the_rules_decide(): void
    {
        Customer::whereKey($this->customer->id)->update(['created_at' => now()->subDays(30)]);
        $earlier = $this->heldPayment(2000000);
        $this->assertSame('captured', $earlier->status);
        $url = '/api/v1/risk-reviews/'.$this->heldPayment()->id.'/ai-review';
        $this->enableAi('finance_review');
        $this->fakeGemini(self::RISK, self::RISK, ['checks' => [['id' => 'RSK-5', 'result' => 'unclear', 'reason' => 'The tickets do not say.']]] + self::RISK);

        // An established customer within 5 times the largest earlier capture: every rule passes.
        $response = $this->postJson($url, [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'clear')
            ->assertJsonPath('checks.2.detail', 'PHP 60,000.00 against the customer\'s largest captured payment of PHP 20,000.00; more than 5 times that, PHP 100,000.00, is a risk signal.');
        $this->assertSame(['RSK-1' => 'pass', 'RSK-2' => 'pass', 'RSK-3' => 'pass', 'RSK-4' => 'pass', 'RSK-5' => 'pass'], $this->results($response));

        // A lost dispute fails a required rule.
        Payment::whereKey($earlier->id)->update(['status' => 'dispute_lost']);
        $this->postJson($url, [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'block')
            ->assertJsonPath('checks.0.result', 'fail')->assertJsonPath('checks.0.detail', 'The customer lost 1 earlier payment dispute(s).');

        // The AI cannot tell: look closer.
        Payment::whereKey($earlier->id)->update(['status' => 'captured']);
        $this->postJson($url, [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'need_more_info')->assertJsonPath('checks.4.result', 'unclear');
    }

    public function test_a_payment_that_is_not_under_review_or_an_unusable_answer_is_refused_clearly(): void
    {
        $this->enableAi('finance_review');
        $captured = $this->heldPayment(10000);
        Http::preventStrayRequests();
        $this->getJson('/api/v1/risk-reviews/'.$captured->id.'/ai-review', $this->staff())->assertUnprocessable()->assertJsonValidationErrors('payment');

        $held = $this->heldPayment();
        $this->fakeGemini(['confidence' => 'certain'] + self::RISK);
        $this->getJson('/api/v1/risk-reviews/'.$held->id.'/ai-review', $this->staff())->assertStatus(503)->assertJsonPath('message', 'The AI review could not be used. Try again.');
        $this->fakeGemini(['checks' => 'all fine'] + self::RISK);
        $this->postJson('/api/v1/risk-reviews/'.$held->id.'/ai-review', [], $this->staff())->assertStatus(503)->assertJsonPath('message', 'The AI review could not be used. Try again.');
        $this->fakeGemini(429, 429, 429);
        $this->postJson('/api/v1/risk-reviews/'.$held->id.'/ai-review', [], $this->staff())->assertStatus(503)->assertJsonPath('message', 'Gemini is busy (rate limit reached). Try again in a minute.');
    }

    public function test_a_refund_check_keeps_sfa_findings_whatever_the_model_says_and_never_decides(): void
    {
        $refund = $this->requestedRefund();
        $this->enableAi('finance_review');
        // The model's verdicts all pass; SFA's own over-the-balance finding (a required rule) still means reject.
        Refund::whereKey($refund->id)->update(['total_amount' => 999999]);
        $this->fakeGemini(['rejection_note_draft' => ''] + self::REFUND);

        $response = $this->getJson('/api/v1/refunds/'.$refund->id.'/ai-review', $this->staff())->assertOk()
            ->assertJsonPath('recommendation', 'reject')->assertJsonPath('rejection_note_draft', null)
            ->assertJsonPath('checks.1.detail', 'The refund of PHP 9,999.99 is more than the PHP 100.00 still refundable on '.$refund->payment->reference.'.');
        $this->assertSame(['REF-1' => 'pass', 'REF-2' => 'fail', 'REF-3' => 'pass', 'REF-4' => 'pass', 'REF-5' => 'pass', ...self::PASSED], $this->results($response));
        $this->assertSame(['recommendation', 'confidence', 'reasons', 'checks', 'rejection_note_draft', 'created_at', 'photos'], array_keys($response->json()), 'policy_flags and sfa_flags are gone; photos lists what the AI was shown');
        $this->assertSame([], $response->json('photos'));
        Http::assertSent(function ($request) {
            $facts = json_decode($request['contents'][0]['parts'][0]['text'], true);
            $this->assertSame('item_not_received', $facts['refund']['reason']);
            $this->assertStringContainsString('[phone]', $facts['refund']['notes']);
            $this->assertStringNotContainsString('0917 222 3333', $request->body());
            $this->assertSame(['REF-1', 'REF-2', 'REF-3', 'REF-4', 'REF-5'], array_column($facts['sfa_checks'], 'id'));
            $this->assertSame('fail', $facts['sfa_checks'][1]['result']);
            $this->assertSame(['REF-6', 'REF-7', 'REF-8', 'REF-9'], array_column($facts['rules_to_judge'], 'id'));
            $this->assertSame([], $facts['photos']);
            $this->assertCount(1, $request['contents'][0]['parts'], 'no photo, no image part');

            return true;
        });
        $this->assertSame('requested', $refund->fresh()->status, 'the AI never approves or rejects');

        $this->fakeGemini(self::REFUND);
        $this->postJson('/api/v1/refunds/'.$refund->id.'/ai-review', [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'reject')->assertJsonPath('rejection_note_draft', self::REFUND['rejection_note_draft']);
        Refund::whereKey($refund->id)->update(['status' => 'rejected']);
        $this->getJson('/api/v1/refunds/'.$refund->id.'/ai-review', $this->staff())->assertUnprocessable()->assertJsonValidationErrors('refund');
    }

    public function test_a_refund_that_passes_every_rule_is_approved_and_an_unclear_ai_verdict_needs_more_info(): void
    {
        $this->freezeTime();
        $refund = $this->requestedRefund();
        $url = '/api/v1/refunds/'.$refund->id.'/ai-review';
        $this->enableAi('finance_review');
        $this->fakeGemini(self::REFUND, ['checks' => [['id' => 'REF-6', 'result' => 'unclear', 'reason' => 'The notes do not describe the problem.']]] + self::REFUND);
        $today = self::day(now());

        // A rejection note only goes with a rejection.
        $response = $this->postJson($url, [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'approve')->assertJsonPath('rejection_note_draft', null)
            ->assertJsonPath('checks.0', ['id' => 'REF-1', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Payment was captured', 'result' => 'pass', 'detail' => $refund->payment->reference.' is captured.'])
            ->assertJsonPath('checks.2.detail', "Requested on $today, 0 days after payment on $today (the window is 15 days).")
            ->assertJsonPath('checks.3.detail', 'No parcel of this order is marked delivered with proof.')
            ->assertJsonPath('checks.4.detail', '0 approved or completed refunds for this customer in the last 30 days (the limit is 2).')
            ->assertJsonPath('checks.5.detail', 'The notes say the box never came.');
        $this->assertSame(['REF-1' => 'pass', 'REF-2' => 'pass', 'REF-3' => 'pass', 'REF-4' => 'pass', 'REF-5' => 'pass', ...self::PASSED], $this->results($response));

        $this->postJson($url, [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'need_more_info')
            ->assertJsonPath('checks.5.result', 'unclear')->assertJsonPath('checks.6.detail', 'The AI gave no verdict on this rule.');
    }

    public function test_the_refund_window_counts_from_delivery_or_else_from_payment(): void
    {
        $this->freezeTime();
        $refund = $this->requestedRefund(daysLater: 16);
        $url = '/api/v1/refunds/'.$refund->id.'/ai-review';
        $this->enableAi('finance_review');
        $this->fakeGemini(self::REFUND, self::REFUND);

        $this->postJson($url, [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'reject')->assertJsonPath('checks.2.result', 'fail')
            ->assertJsonPath('checks.2.detail', 'Requested on '.self::day(now()).', 16 days after payment on '.self::day(now()->subDays(16)).' (the window is 15 days).');

        // A delivery 5 days before the request: the window runs from the delivery.
        $this->shipment($refund->payment, 'delivered', ['delivered_at' => now()->subDays(5)]);
        $this->postJson($url, [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'approve')->assertJsonPath('checks.2.result', 'pass')
            ->assertJsonPath('checks.2.detail', 'Requested on '.self::day(now()).', 5 days after delivery on '.self::day(now()->subDays(5)).' (the window is 15 days).');
    }

    public function test_the_refund_window_counts_whole_calendar_days_so_the_result_matches_the_sentence(): void
    {
        $manila = fn (string $at) => CarbonImmutable::parse($at, 'Asia/Manila')->utc();
        Setting::updateOrCreate(['key' => 'business_timezone'], ['value' => 'Asia/Manila']);
        $this->travelTo($manila('2026-10-01 09:00'));
        $payment = $this->heldPayment(10000);
        $parcel = $this->shipment($payment, 'delivered', ['delivered_at' => $manila('2026-10-01 10:00')]);
        // An hour later on the 15th day used to fail while saying "15 days".
        $this->travelTo($manila('2026-10-16 11:00'));
        $refund = app(PaymentService::class)->requestRefund($payment, ['amount' => 4000, 'reason' => 'other'], User::where('email', 'support.one@example.com')->first());
        $url = '/api/v1/refunds/'.$refund->id.'/ai-review';
        $this->enableAi('finance_review');
        $this->fakeGemini(self::REFUND, self::REFUND);

        $this->postJson($url, [], $this->staff())->assertOk()->assertJsonPath('checks.2.result', 'pass')
            ->assertJsonPath('checks.2.detail', 'Requested on 2026-10-16, 15 days after delivery on 2026-10-01 (the window is 15 days).');

        // Delivered late in the evening, asked for just after midnight 16 days later: 16 calendar days, outside the window.
        $parcel->update(['delivered_at' => $manila('2026-09-30 23:30')]);
        Refund::whereKey($refund->id)->update(['created_at' => $manila('2026-10-16 00:30')]);
        $this->postJson($url, [], $this->staff())->assertOk()->assertJsonPath('checks.2.result', 'fail')->assertJsonPath('recommendation', 'reject')
            ->assertJsonPath('checks.2.detail', 'Requested on 2026-10-16, 16 days after delivery on 2026-09-30 (the window is 15 days).');
    }

    public function test_the_refund_reason_must_fit_the_deliveries(): void
    {
        $this->freezeTime();
        $refund = $this->requestedRefund();
        $url = fn (Refund $refund) => '/api/v1/refunds/'.$refund->id.'/ai-review';
        $this->enableAi('finance_review');
        $this->fakeGemini(self::REFUND, self::REFUND, self::REFUND, self::REFUND, self::REFUND);

        $parcel = $this->shipment($refund->payment, 'out_for_delivery');
        $this->postJson($url($refund), [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'approve')->assertJsonPath('checks.3.result', 'pass');
        // Item not received, but the parcel was delivered with proof.
        $parcel->update(['status' => 'delivered', 'delivered_at' => now(), 'recipient_name' => 'Juan Cruz']);
        $this->postJson($url($refund), [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'reject')
            ->assertJsonPath('checks.3', ['id' => 'REF-4', 'kind' => 'sfa', 'severity' => 'required', 'title' => 'Reason fits the delivery', 'result' => 'fail', 'detail' => 'A parcel of this order was delivered on '.self::day(now()).' with proof of delivery.']);

        // Damaged before anything was delivered.
        $damaged = $this->requestedRefund(reason: 'damaged');
        $this->shipment($damaged->payment, 'in_transit');
        $this->postJson($url($damaged), [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'reject')
            ->assertJsonPath('checks.3.result', 'fail')->assertJsonPath('checks.3.detail', 'No parcel of this order has been delivered.');

        // A duplicate charge needs another captured payment of the same amount.
        $duplicate = $this->requestedRefund(12345, 4000, 'duplicate_charge');
        $this->postJson($url($duplicate), [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'reject')
            ->assertJsonPath('checks.3.detail', 'No other captured payment of PHP 123.45 was found for this customer or order.');
        $twin = $this->heldPayment(12345);
        $this->postJson($url($duplicate), [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'approve')
            ->assertJsonPath('checks.3.detail', $twin->reference.' is another captured payment of PHP 123.45 for this customer or order.');
    }

    public function test_a_damaged_item_photo_and_the_priced_items_are_sent_to_the_model(): void
    {
        $refund = $this->requestedRefund(reason: 'damaged');
        $this->shipment($refund->payment, 'delivered', ['delivered_at' => now()]);
        $seller = Seller::create(['source_system' => 'storefront', 'external_id' => 'ai-mug-shop', 'shop_name' => 'Mug shop']);
        $refund->payment->order->items()->create(['seller_id' => $seller->id, 'product_name' => 'Ceramic mug', 'sku' => 'MUG-1', 'quantity' => 2, 'unit_price_amount' => 2500, 'line_total_amount' => 5000]);
        $photo = app(AttachmentService::class)->store(UploadedFile::fake()->createWithContent('crushed-box.jpg', "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9"), $refund, User::where('email', 'support.one@example.com')->first());
        $this->enableAi('finance_review');
        $this->fakeGemini(['checks' => [['id' => 'REF-6', 'result' => 'pass', 'reason' => 'The notes describe a broken mug.'], ['id' => 'REF-7', 'result' => 'pass', 'reason' => 'Photo 1 shows the broken mug.'],
            ['id' => 'REF-8', 'result' => 'pass', 'reason' => 'Photo 1 shows the mug from this order.'], ['id' => 'REF-9', 'result' => 'pass', 'reason' => 'PHP 40.00 is less than one mug.']]] + self::REFUND);

        $this->getJson('/api/v1/refunds/'.$refund->id.'/ai-review', $this->staff())->assertOk()->assertJsonPath('recommendation', 'approve')
            ->assertJsonPath('photos', [['id' => $photo->id, 'filename' => 'crushed-box.jpg', 'source' => 'Refund request']])
            ->assertJsonPath('checks.6', ['id' => 'REF-7', 'kind' => 'ai', 'severity' => 'required', 'title' => 'Evidence of damage or a wrong item', 'result' => 'pass', 'detail' => 'Photo 1 shows the broken mug.']);
        $parts = Http::recorded()->last()[0]['contents'][0]['parts'];
        $this->assertCount(2, $parts);
        $this->assertSame(['mimeType' => 'image/jpeg', 'data' => $photo->content], $parts[1]['inlineData']);
        $facts = self::sentFacts();
        $this->assertSame([['number' => 1, 'source' => 'Refund request', 'filename' => 'crushed-box.jpg']], $facts['photos']);
        $this->assertSame([['name' => 'Ceramic mug', 'sku' => 'MUG-1', 'quantity' => 2, 'unit_price' => 'PHP 25.00', 'line_total' => 'PHP 50.00']], $facts['order']['items']);
        $this->assertSame(['PHP 0.00', 'PHP 100.00'], [$facts['order']['shipping'], $facts['order']['total']]);
    }

    public function test_a_damaged_item_without_a_photo_fails_the_required_evidence_rule(): void
    {
        $refund = $this->requestedRefund(reason: 'wrong_item');
        $this->shipment($refund->payment, 'delivered', ['delivered_at' => now()]);
        $this->enableAi('finance_review');
        $this->fakeGemini(['checks' => [['id' => 'REF-6', 'result' => 'pass', 'reason' => 'The notes say a different item came.'], ['id' => 'REF-7', 'result' => 'fail', 'reason' => 'No photo of the problem was provided.'],
            ['id' => 'REF-8', 'result' => 'not_applicable', 'reason' => 'There is no photo.'], ['id' => 'REF-9', 'result' => 'pass', 'reason' => 'Within the order total.']]] + self::REFUND);

        $response = $this->getJson('/api/v1/refunds/'.$refund->id.'/ai-review', $this->staff())->assertOk()->assertJsonPath('recommendation', 'reject')
            ->assertJsonPath('checks.6.detail', 'No photo of the problem was provided.')->assertJsonPath('photos', [])->assertJsonPath('rejection_note_draft', self::REFUND['rejection_note_draft']);
        $this->assertSame(['REF-1' => 'pass', 'REF-2' => 'pass', 'REF-3' => 'pass', 'REF-4' => 'pass', 'REF-5' => 'pass', 'REF-6' => 'pass', 'REF-7' => 'fail', 'REF-8' => 'not_applicable', 'REF-9' => 'pass'], $this->results($response));
        $this->assertCount(1, Http::recorded()->last()[0]['contents'][0]['parts']);
        $this->assertSame([], self::sentFacts()['photos']);
    }

    public function test_refund_history_over_the_limit_needs_a_closer_look(): void
    {
        $refund = $this->requestedRefund();
        $url = '/api/v1/refunds/'.$refund->id.'/ai-review';
        $earlier = $this->heldPayment(50000);
        $past = fn (string $status, int $daysAgo) => Refund::create(['payment_id' => $earlier->id, 'total_amount' => 1000, 'reason' => 'other', 'status' => $status, 'created_at' => now()->subDays($daysAgo)]);
        // Rejected refunds and those older than 30 days do not count.
        $past('approved', 3); $past('completed', 20); $past('completed', 40); $past('rejected', 5);
        $this->enableAi('finance_review');
        $this->fakeGemini(self::REFUND, self::REFUND);

        $this->postJson($url, [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'approve')
            ->assertJsonPath('checks.4.detail', '2 approved or completed refunds for this customer in the last 30 days (the limit is 2).');
        $past('completed', 1);
        $this->postJson($url, [], $this->staff())->assertOk()->assertJsonPath('recommendation', 'need_more_info')->assertJsonPath('checks.4.result', 'fail')
            ->assertJsonPath('checks.4.detail', '3 approved or completed refunds for this customer in the last 30 days (the limit is 2).');
    }

    public function test_the_second_opinion_only_adds_uncaptured_payments_for_review_and_notices_captured_ones(): void
    {
        $cod = $this->heldPayment(250000, 'cod');
        $card = $this->heldPayment(300000);
        $this->assertSame([['pending', 'none'], ['captured', 'none']], [[$cod->status, $cod->risk_level], [$card->status, $card->risk_level]]);
        // A lost dispute fails a required risk rule (RSK-1) for both.
        Payment::whereKey($this->heldPayment(10000)->id)->update(['status' => 'dispute_lost']);
        $service = app(FinanceReviewService::class);
        Http::preventStrayRequests();
        $this->assertSame(0, $service->secondOpinion(), 'off by default');

        $this->enableAi('risk_second_opinion');
        $this->fakeGemini(['reason' => 'A new customer with an unusually large first order.'] + self::SECOND, ['reason' => 'Far above anything paid before.'] + self::SECOND);
        $this->assertSame(2, $service->secondOpinion());

        $this->assertSame(['pending', 'review'], [$cod->fresh()->status, $cod->fresh()->risk_level]);
        $this->assertSame(['captured', 'none', 300000], [$card->fresh()->status, $card->fresh()->risk_level, $card->fresh()->captured_amount], 'a captured payment is never changed');
        $this->assertTrue(Notification::where('type', 'risk_review_needed')->where('data->payment_id', $cod->id)->exists());
        $newCustomer = 'RSK-4 Customer since '.$this->customer->created_at->toDateString().', 0 days before this payment; newer than 7 days is a risk signal.';
        $this->assertSame($card->reference.' was already captured; the AI second opinion noted: Risk signals found: RSK-1 The customer lost 1 earlier payment dispute(s). RSK-3 PHP 3,000.00 against the customer\'s largest captured payment of PHP 100.00; more than 5 times that, PHP 500.00, is a risk signal. '.$newCustomer.' Far above anything paid before.',
            Notification::where('type', 'ai_payment_flagged')->where('data->payment_id', $card->id)->value('message'));
        $reason = 'Risk signals found: RSK-1 The customer lost 1 earlier payment dispute(s). '.$newCustomer.' A new customer with an unusually large first order.';
        $this->assertSame($reason, AuditLog::where('entity_type', 'Payment')->where('entity_id', (string) $cod->id)->where('new_values->automation', 'ai_risk_second_opinion')->first()->new_values['reason']);

        // Each payment gets one look; the flagged COD payment now explains itself in its risk review.
        $this->fakeGemini(self::RISK);
        $this->assertSame(0, $service->secondOpinion());
        $this->enableAi('finance_review');
        $this->getJson('/api/v1/risk-reviews/'.$cod->id.'/ai-review', $this->staff())->assertOk()
            ->assertJsonPath('flagged_because.0', 'The AI second opinion added it: '.$reason)->assertJsonPath('recommendation', 'block');
        $this->assertSame(3, AiInsight::count());
    }

    public function test_the_second_opinion_flags_two_failed_rules_but_not_one_advisory_failure(): void
    {
        $cod = $this->heldPayment(250000, 'cod');
        $card = $this->heldPayment(300000);
        $this->enableAi('risk_second_opinion');
        $this->fakeGemini(self::SECOND, self::SECOND);

        // Both come from a new customer (RSK-4); only the card payment is also far above anything captured before (RSK-3).
        $this->assertSame(1, app(FinanceReviewService::class)->secondOpinion());
        $this->assertSame('none', $cod->fresh()->risk_level);
        $this->assertFalse(Notification::where('data->payment_id', $cod->id)->exists());
        $this->assertSame($card->reference.' was already captured; the AI second opinion noted: Risk signals found: RSK-3 The customer has no other captured payment. RSK-4 Customer since '.$this->customer->created_at->toDateString().', 0 days before this payment; newer than 7 days is a risk signal.',
            Notification::where('type', 'ai_payment_flagged')->where('data->payment_id', $card->id)->value('message'));
    }

    public function test_a_calm_second_opinion_changes_nothing(): void
    {
        // An established customer with an earlier capture (outside the second opinion's day) passes every SFA rule.
        Customer::whereKey($this->customer->id)->update(['created_at' => now()->subDays(30)]);
        Payment::whereKey($this->heldPayment(100000)->id)->update(['created_at' => now()->subDays(3)]);
        $cod = $this->heldPayment(250000, 'cod');
        $this->enableAi('risk_second_opinion');
        $this->fakeGemini(self::SECOND);
        $this->assertSame(0, app(FinanceReviewService::class)->secondOpinion());
        $this->assertSame('none', $cod->fresh()->risk_level);
        $this->assertSame(0, Notification::where('data->payment_id', $cod->id)->count());
        // A payment the rules let through is not described as held.
        Http::assertSent(function ($request) {
            $facts = json_decode($request['contents'][0]['parts'][0]['text'], true);

            return $facts['flagged_because'] === ['The risk rules let this payment through.'] && array_column($facts['sfa_checks'], 'result') === ['pass', 'pass', 'pass', 'pass'];
        });
    }

    public function test_the_second_opinion_stops_quietly_when_the_daily_limit_is_reached_mid_run(): void
    {
        $cod = $this->heldPayment(250000, 'cod');
        $card = $this->heldPayment(300000);
        $this->enableAi('risk_second_opinion');
        // The first answer's 150 tokens cross the limit; the second payment waits for a later run instead of failing this one.
        Setting::updateOrCreate(['key' => 'ai_daily_token_limit'], ['value' => 100]);
        // A new customer (RSK-4) plus the model's RSK-5 failure: two failed rules.
        $this->fakeGemini(['checks' => [['id' => 'RSK-5', 'result' => 'fail', 'reason' => 'Far above anything paid before.']], 'reason' => 'Far above anything paid before.']);

        $this->assertSame(1, app(FinanceReviewService::class)->secondOpinion());
        $this->assertSame('review', $cod->fresh()->risk_level);
        $this->assertFalse(Notification::where('data->payment_id', $card->id)->exists());
        Http::assertSentCount(1);
    }

    public function test_a_refund_on_a_payment_without_a_customer_counts_no_earlier_refunds(): void
    {
        $refund = $this->requestedRefund();
        $other = $this->requestedRefund();
        // Subscription invoices have no customer; other customer-less refunds are not this payer's history.
        Payment::whereIn('id', [$refund->payment_id, $other->payment_id])->update(['customer_id' => null]);
        $this->enableAi('finance_review');
        $this->fakeGemini(self::REFUND);

        $this->getJson('/api/v1/refunds/'.$refund->id.'/ai-review', $this->staff())->assertOk()->assertJsonPath('checks.4.result', 'not_applicable');
        Http::assertSent(fn ($request) => json_decode($request['contents'][0]['parts'][0]['text'], true)['customer_earlier_refunds']['count'] === 0);
    }
}
