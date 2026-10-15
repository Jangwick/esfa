<?php

namespace Tests\Feature;

use App\Models\AiInsight;
use App\Models\AuditLog;
use App\Models\Barangay;
use App\Models\City;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Province;
use App\Models\Region;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AiRules;
use App\Services\AiService;
use App\Services\LogisticsService;
use Database\Seeders\LogisticsDemoSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\FakesGemini;
use Tests\TestCase;

class AiLogisticsTest extends TestCase
{
    use FakesGemini, RefreshDatabase;

    private City $lasPinas;
    private Barangay $almanza;
    private array $address;
    private Order $order;
    private Seller $seller;
    private Warehouse $warehouse;

    private const READ = ['line1' => 'Blk 5 Lot 7 Mabuhay St.', 'line2' => 'BF Resort Village', 'barangay' => '', 'city' => 'Las Pinas', 'province' => '', 'region' => 'National Capital Region (NCR)', 'postal_code' => '1740'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolePermissionSeeder::class, LogisticsDemoSeeder::class]);
        $this->lasPinas = City::where('code', '1380200000')->firstOrFail();
        $this->almanza = Barangay::where('code', '1380200001')->firstOrFail();
        $this->address = ['line1' => 'Demo logistics address', 'line2' => null, 'region_id' => $this->lasPinas->region_id, 'province_id' => null, 'city_id' => $this->lasPinas->id, 'barangay_id' => $this->almanza->id, 'postal_code' => '1740', 'contact_name' => 'Demo recipient', 'contact_phone' => '09170000000'];
        $customer = Customer::create(['source_system' => 'orders', 'external_id' => 'ai-logistics-customer', 'name' => 'Demo customer']);
        $this->seller = Seller::create(['source_system' => 'orders', 'external_id' => 'ai-logistics-seller', 'shop_name' => 'Demo seller']);
        $this->order = Order::create(['source_system' => 'orders', 'external_id' => 'ai-logistics-order', 'customer_id' => $customer->id, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
        OrderItem::create(['order_id' => $this->order->id, 'seller_id' => $this->seller->id, 'product_name' => 'Demo item', 'quantity' => 1, 'unit_price_amount' => 10000, 'line_total_amount' => 10000]);
        $this->warehouse = Warehouse::create(['name' => 'AI test hub', 'code' => 'ai-test-hub', 'type' => 'platform_hub', 'address_id' => app(LogisticsService::class)->address($this->address)->id, 'is_active' => true]);
    }

    private function as(string $role): array
    {
        $user = User::create(['name' => $role, 'email' => Str::slug($role).'.'.Str::random(6).'@example.com', 'password' => 'password', 'is_active' => true]);
        $user->assignRole($role);
        Auth::forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('ai-test', ['staff'])->plainTextToken];
    }

    private function parse(array $headers, string $text = 'Juan Dela Cruz 0917 123 4567, Blk 5 Lot 7 Mabuhay St., BF Resort Village, Brgy. Almanza Uno, Las Pinas City, Metro Manila 1740')
    {
        return $this->postJson('/api/v1/logistics/address/parse', ['text' => $text], $headers);
    }

    public function test_address_fill_needs_sign_in_shipment_rights_valid_text_and_the_switch(): void
    {
        $this->parse([])->assertUnauthorized();
        $this->parse($this->as('Quality Control Analyst'))->assertForbidden();
        $this->parse($this->as('Administrative Assistant'))->assertForbidden();
        $coordinator = $this->as('Warehouseman');
        $this->parse($coordinator, 'short')->assertUnprocessable()->assertJsonValidationErrors('text');
        Http::preventStrayRequests();
        $this->parse($coordinator)->assertUnprocessable()->assertJsonValidationErrors('ai');
    }

    public function test_the_ai_reads_the_parts_and_sfa_matches_its_own_location_ids(): void
    {
        $this->enableAi('address_fill');
        // A made-up id in the answer is ignored: every id comes from SFA's tables.
        $this->fakeGemini(['barangay' => 'Almanza Uno', 'city_id' => 999999] + self::READ);
        $this->parse($this->as('Warehouseman'))->assertOk()
            ->assertJsonPath('region_id', $this->lasPinas->region_id)->assertJsonPath('province_id', null)->assertJsonPath('city_id', $this->lasPinas->id)->assertJsonPath('barangay_id', $this->almanza->id)
            ->assertJsonPath('line1', 'Blk 5 Lot 7 Mabuhay St.')->assertJsonPath('postal_code', '1740')->assertJsonPath('contact_name', 'Juan Dela Cruz')
            ->assertJsonPath('contact_phone', '09171234567')->assertJsonPath('unmatched', [])
            ->assertJsonPath('checks.*.id', ['ADR-1', 'ADR-2', 'ADR-3', 'ADR-4'])->assertJsonPath('checks.*.result', ['pass', 'pass', 'pass', 'pass'])
            ->assertJsonPath('checks.0.detail', 'Kept in SFA, not sent to the AI: the recipient\'s name and the phone number.')
            ->assertJsonPath('checks.1.detail', 'Matched to SFA\'s location list: region, city, barangay.')->assertJsonPath('checks.3.detail', '1740 has 4 digits.');
        Http::assertSent(function ($request) {
            $sent = json_decode($request['contents'][0]['parts'][0]['text'], true);
            $this->assertStringNotContainsString('0917 123 4567', $request->body());
            $this->assertStringNotContainsString('Juan Dela Cruz', $request->body());
            $this->assertStringContainsString('[phone]', $sent['pasted_address']);
            $this->assertContains('National Capital Region (NCR)', $sent['regions']);

            return true;
        });
        $this->assertSame('address_fill', AiInsight::sole()->feature);
    }

    public function test_the_name_is_read_only_from_a_first_part_that_is_not_address_text(): void
    {
        $this->enableAi('address_fill');
        $coordinator = $this->as('Warehouseman');
        $this->fakeGemini(self::READ);
        $this->parse($coordinator, 'Blk 5 Lot 7 Mabuhay St., BF Resort Village, Las Pinas City 1740')->assertOk()->assertJsonPath('contact_name', '')
            ->assertJsonPath('city_id', $this->lasPinas->id);
        Http::assertSent(fn ($request) => str_contains($request['contents'][0]['parts'][0]['text'], 'Blk 5 Lot 7 Mabuhay St., BF Resort Village'));

        $this->fakeGemini(self::READ);
        $this->parse($coordinator, "Para kay Maria Santos\nBlk 5 Lot 7 Mabuhay St., Las Pinas City 1740")->assertOk()->assertJsonPath('contact_name', 'Maria Santos');
        Http::assertSent(fn ($request) => ! str_contains($request->body(), 'Maria Santos'));
    }

    public function test_a_city_that_contradicts_the_province_read_is_left_for_the_person(): void
    {
        $calabarzon = Region::create(['code' => '0400000000', 'name' => 'Region IV-A (CALABARZON)']);
        $centralLuzon = Region::create(['code' => '0300000000', 'name' => 'Region III (Central Luzon)']);
        $batangas = Province::create(['code' => '0401000000', 'name' => 'Batangas', 'region_id' => $calabarzon->id]);
        $nuevaEcija = Province::create(['code' => '0349000000', 'name' => 'Nueva Ecija', 'region_id' => $centralLuzon->id]);
        City::create(['code' => '0349099000', 'name' => 'City of San Jose', 'type' => 'city', 'region_id' => $centralLuzon->id, 'province_id' => $nuevaEcija->id]);
        $this->enableAi('address_fill');
        $coordinator = $this->as('Warehouseman');

        $this->fakeGemini(['city' => 'San Jose', 'province' => 'Batangas', 'region' => ''] + self::READ);
        $this->parse($coordinator, 'Purok 3, San Jose, Batangas 4227')->assertOk()
            ->assertJsonPath('city_id', null)->assertJsonPath('province_id', $batangas->id)->assertJsonPath('region_id', $calabarzon->id)->assertJsonPath('unmatched', ['city'])
            ->assertJsonPath('checks.*.result', ['pass', 'fail', 'fail', 'pass'])->assertJsonPath('checks.2.detail', 'No city named "San Jose" is in Batangas; pick the city yourself.');

        // A city outside any province still agrees with a province of its own region.
        Province::create(['code' => '0456000000', 'name' => 'Quezon', 'region_id' => $calabarzon->id]);
        $lucena = City::create(['code' => '0431200000', 'name' => 'City of Lucena', 'type' => 'city', 'region_id' => $calabarzon->id, 'province_id' => null]);
        $this->fakeGemini(['city' => 'Lucena City', 'province' => 'Quezon', 'region' => ''] + self::READ);
        $this->parse($coordinator, 'Purok 3, Lucena City, Quezon 4301')->assertOk()
            ->assertJsonPath('city_id', $lucena->id)->assertJsonPath('province_id', null)->assertJsonPath('unmatched', [])->assertJsonPath('checks.2.result', 'pass');
    }

    public function test_towns_that_share_a_name_need_the_province_and_unknown_parts_are_left_for_the_person(): void
    {
        $calabarzon = Region::create(['code' => '0400000000', 'name' => 'Region IV-A (CALABARZON)']);
        $centralLuzon = Region::create(['code' => '0300000000', 'name' => 'Region III (Central Luzon)']);
        $batangas = Province::create(['code' => '0401000000', 'name' => 'Batangas', 'region_id' => $calabarzon->id]);
        $nuevaEcija = Province::create(['code' => '0349000000', 'name' => 'Nueva Ecija', 'region_id' => $centralLuzon->id]);
        $inBatangas = City::create(['code' => '0401099000', 'name' => 'San Jose', 'type' => 'municipality', 'region_id' => $calabarzon->id, 'province_id' => $batangas->id]);
        City::create(['code' => '0349099000', 'name' => 'City of San Jose', 'type' => 'city', 'region_id' => $centralLuzon->id, 'province_id' => $nuevaEcija->id]);
        $this->enableAi('address_fill');
        $coordinator = $this->as('Warehouseman');

        $this->fakeGemini(['city' => 'San Jose', 'province' => 'Batangas', 'region' => '', 'barangay' => 'Nowhere Village'] + self::READ);
        $this->parse($coordinator, 'Purok 3, Brgy. Nowhere Village, San Jose, Batangas 4227')->assertOk()
            ->assertJsonPath('city_id', $inBatangas->id)->assertJsonPath('province_id', $batangas->id)->assertJsonPath('region_id', $calabarzon->id)
            ->assertJsonPath('barangay_id', null)->assertJsonPath('unmatched', ['barangay'])
            ->assertJsonPath('checks.*.result', ['pass', 'fail', 'pass', 'pass'])->assertJsonPath('checks.1.detail', 'Not in SFA\'s location list, left for you to pick: barangay.');

        $this->fakeGemini(['city' => 'San Jose', 'province' => '', 'region' => '', 'barangay' => ''] + self::READ);
        $this->parse($coordinator, 'Somewhere in San Jose, two towns share this name')->assertOk()
            ->assertJsonPath('city_id', null)->assertJsonPath('region_id', null)->assertJsonPath('unmatched', ['city'])
            ->assertJsonPath('checks.2.result', 'fail')->assertJsonPath('checks.2.detail', '2 cities named "San Jose" are in SFA\'s list; pick the city yourself.');
    }

    public function test_a_region_not_in_sfa_fails_the_location_check_even_when_the_city_gives_the_region(): void
    {
        $this->enableAi('address_fill');
        $this->fakeGemini(['region' => 'Region of Atlantis', 'barangay' => 'Almanza Uno'] + self::READ);
        $this->parse($this->as('Warehouseman'))->assertOk()
            ->assertJsonPath('city_id', $this->lasPinas->id)->assertJsonPath('region_id', $this->lasPinas->region_id)->assertJsonPath('barangay_id', $this->almanza->id)
            ->assertJsonPath('unmatched', [])
            ->assertJsonPath('checks.*.result', ['pass', 'fail', 'pass', 'pass'])->assertJsonPath('checks.1.detail', 'Not in SFA\'s location list, left for you to pick: region.');
    }

    public function test_a_bad_zip_code_fails_its_check_and_parts_not_read_do_not_apply(): void
    {
        $this->enableAi('address_fill');
        $coordinator = $this->as('Warehouseman');

        $this->fakeGemini(['postal_code' => '17400', 'barangay' => 'Almanza Uno'] + self::READ);
        $this->parse($coordinator, 'Blk 5 Lot 7 Mabuhay St., Almanza Uno, Las Pinas City 17400')->assertOk()->assertJsonPath('postal_code', '')
            ->assertJsonPath('checks.*.result', ['pass', 'pass', 'pass', 'fail'])->assertJsonPath('checks.0.detail', 'No name or phone number was found in the text.')
            ->assertJsonPath('checks.3.detail', '"17400" is not a 4-digit ZIP code, so it was left empty.');

        $this->fakeGemini(array_fill_keys(['line1', 'line2', 'barangay', 'city', 'province', 'region', 'postal_code'], ''));
        $this->parse($coordinator, 'Unit 4, somewhere far away')->assertOk()->assertJsonPath('checks.*.result', ['pass', 'not_applicable', 'not_applicable', 'not_applicable']);
    }

    private function failedShipment(string $note): Shipment
    {
        $headers = $this->as('Admin');
        // One shipment per order and seller, so each failed parcel gets its own order.
        $order = Order::create(['source_system' => 'orders', 'external_id' => 'ai-logistics-'.Str::random(8), 'customer_id' => $this->order->customer_id, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
        OrderItem::create(['order_id' => $order->id, 'seller_id' => $this->seller->id, 'product_name' => 'Demo item', 'quantity' => 1, 'unit_price_amount' => 10000, 'line_total_amount' => 10000]);
        $id = $this->postJson('/api/v1/logistics/shipments', ['order_id' => $order->id, 'seller_id' => $this->seller->id, 'courier_id' => Courier::where('code', 'demo-courier')->value('id'), 'service_type' => 'standard', 'origin_warehouse_id' => $this->warehouse->id, 'destination' => $this->address, 'weight_grams' => 1000, 'dimensions' => ['length_cm' => '10', 'width_cm' => '10', 'height_cm' => '10'], 'cod_amount' => 0, 'tracking_number' => 'AI-LOG-'.Str::random(6)], $headers)->assertCreated()->json('id');
        foreach (['ready_for_pickup', 'picked_up', 'in_transit', 'out_for_delivery'] as $status) { $this->postJson('/api/v1/logistics/shipments/'.$id.'/events', ['status' => $status], $headers)->assertOk(); }
        $this->postJson('/api/v1/logistics/shipments/'.$id.'/events', ['status' => 'failed_attempt', 'note' => $note], $headers)->assertOk();

        return Shipment::findOrFail($id);
    }

    public function test_a_failed_delivery_ticket_gets_the_reason_and_next_step_once(): void
    {
        $this->enableAi('delivery_reason');
        $this->fakeGemini(['reason' => 'wrong_or_incomplete_address']);
        $shipment = $this->failedShipment('INCOMPLETE ADDR, no house no. Called 09179998888 no answer');

        $ticket = Ticket::where('shipment_id', $shipment->id)->sole();
        $this->assertSame(['auto', 'delivery', 'reason:wrong_or_incomplete_address'], $ticket->tags);
        $this->assertTrue($ticket->messages()->where('is_internal_note', true)->where('body', 'AI reading of the courier\'s note: Wrong or incomplete address. Next step (SFA playbook): '.AiRules::DELIVERY_PLAYBOOK['wrong_or_incomplete_address'])->exists());
        $this->assertTrue(AuditLog::where('entity_type', 'Ticket')->where('entity_id', (string) $ticket->id)->where('new_values->automation', 'ai_delivery_reason')->exists());
        // The model only picks the reason; the next step is SFA's.
        Http::assertSent(fn ($request) => ! str_contains($request->body(), '09179998888') && str_contains($request->body(), 'INCOMPLETE ADDR') && ! str_contains($request->body(), 'next_step'));

        // Another call for the same attempt reuses the stored reading and adds nothing.
        app(LogisticsService::class)->deliveryTicket($shipment->id);
        Http::assertSentCount(1);
        $this->assertSame(1, $ticket->messages()->where('body', 'like', 'AI reading%')->count());
    }

    public function test_the_delivery_ticket_is_unchanged_when_the_reading_is_off_fails_or_is_off_the_list(): void
    {
        Http::preventStrayRequests();
        $off = $this->failedShipment('Nobody home');
        $this->assertSame(['auto', 'delivery'], Ticket::where('shipment_id', $off->id)->sole()->tags);

        $this->enableAi('delivery_reason');
        $this->fakeGemini(503, 503);
        $busy = $this->failedShipment('Gate locked');
        $this->assertSame(['auto', 'delivery'], Ticket::where('shipment_id', $busy->id)->sole()->tags);

        $this->fakeGemini(['reason' => 'aliens']);
        $odd = $this->failedShipment('Strange note');
        $this->assertSame(['auto', 'delivery'], Ticket::where('shipment_id', $odd->id)->sole()->tags);
        $this->assertSame(1, Ticket::where('shipment_id', $odd->id)->sole()->messages()->count());
    }

    public function test_the_delivery_ticket_still_opens_when_the_daily_ai_limit_is_reached(): void
    {
        Http::preventStrayRequests();
        $this->enableAi('delivery_reason');
        Setting::updateOrCreate(['key' => 'ai_daily_token_limit'], ['value' => 10]);
        AiInsight::create(['feature' => 'delivery_reason', 'status' => 'ok', 'model' => 'gemini-3.5-flash-lite', 'input_hash' => 'spent', 'output' => [], 'prompt_tokens' => 100, 'output_tokens' => 20]);
        $limited = $this->failedShipment('Nobody home');
        $ticket = Ticket::where('shipment_id', $limited->id)->sole();
        $this->assertSame(['auto', 'delivery'], $ticket->tags);
        $this->assertTrue($ticket->messages()->where('body', "Delivery attempt 1 of 3 for {$limited->reference} failed: Nobody home")->exists());

        // The limit can be crossed between the reading's own check and the request; the ticket still opens.
        $this->partialMock(AiService::class, function ($mock) {
            $mock->shouldReceive('unavailable')->andReturn(null);
            $mock->shouldReceive('json')->andThrow(ValidationException::withMessages(['ai' => ['Today\'s AI limit has been reached.']]));
        });
        $raced = $this->failedShipment('Gate locked');
        $this->assertSame(['auto', 'delivery'], Ticket::where('shipment_id', $raced->id)->sole()->tags);
    }
}
