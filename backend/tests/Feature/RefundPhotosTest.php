<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\CommissionRule;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Seller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\PaymentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class RefundPhotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        CommissionRule::create(['name' => 'Test global rule', 'scope' => 'global', 'rate_bp' => 500, 'fixed_fee_amount' => 0, 'effective_from' => now()->subDay()]);
    }

    // Real image bytes without GD (not installed everywhere); fake files report their type from the name.
    private static function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlS8AAAAASUVORK5CYII='));
    }

    private static function jpeg(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9");
    }

    private function user(string $role): User
    {
        $user = User::create(['name' => $role, 'email' => Str::slug($role).'.'.Str::random(6).'@example.com', 'password' => 'password', 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function as(string|User $user): self
    {
        Auth::forgetGuards();

        return $this->withToken(($user instanceof User ? $user : $this->user($user))->createToken('staff', ['staff'])->plainTextToken);
    }

    // A captured card payment of PHP 100.00 for a one-item order.
    private function payment(): Payment
    {
        $unique = Str::lower(Str::random(8));
        $customer = Customer::create(['source_system' => 'orders', 'external_id' => 'customer-'.$unique, 'name' => 'Demo Customer', 'email' => 'customer@example.com']);
        $seller = Seller::create(['source_system' => 'orders', 'external_id' => 'seller-'.$unique, 'shop_name' => 'Demo Shop']);
        $order = Order::create(['source_system' => 'orders', 'external_id' => 'order-'.$unique, 'customer_id' => $customer->id, 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
        $order->items()->create(['seller_id' => $seller->id, 'product_name' => 'Demo Item', 'quantity' => 1, 'unit_price_amount' => 10000, 'line_total_amount' => 10000]);

        return app(PaymentService::class)->create($order, 'card')['payment'];
    }

    // A ticket linked by $links (payment_id and/or order_id) with one customer message carrying $file.
    private function conversationPhoto(array $links, UploadedFile $file, string $reference): Attachment
    {
        $ticket = Ticket::create(['reference' => $reference, 'subject' => 'Broken item', 'description' => 'It arrived broken.', 'source_system' => 'orders', 'channel' => 'email', 'category' => 'refund_return', 'priority' => 'normal',
            'requester_type' => 'customer', 'requester_id' => 1, 'first_response_due_at' => now()->addHour(), 'resolution_due_at' => now()->addDay(), ...$links]);
        $message = TicketMessage::create(['ticket_id' => $ticket->id, 'author_type' => 'customer', 'body' => 'Here is a photo.']);

        return app(AttachmentService::class)->store($file, $message);
    }

    public function test_photo_routes_need_sign_in_and_payment_access(): void
    {
        $payment = $this->payment();
        $finance = $this->user('Finance');
        $refund = app(PaymentService::class)->requestRefund($payment, ['amount' => 1000, 'reason' => 'damaged'], $finance);
        $photo = app(AttachmentService::class)->store(self::png('box.png'), $refund, $finance);
        $url = '/api/v1/refunds/'.$refund->id.'/photos/'.$photo->id;

        $this->getJson($url)->assertUnauthorized();
        $this->postJson('/api/v1/refunds', ['payment_id' => $payment->id, 'amount' => 1000, 'reason' => 'damaged', 'photos' => [self::png('box.png')]])->assertUnauthorized();
        $this->as('Logistics Coordinator')->getJson($url)->assertForbidden();
        $this->as('Analyst')->getJson($url)->assertForbidden();
        $this->as('Logistics Coordinator')->postJson('/api/v1/refunds', ['payment_id' => $payment->id, 'amount' => 1000, 'reason' => 'damaged', 'photos' => [self::png('box.png')]])->assertForbidden();
        // A Support Agent sees only payments linked to their own or unassigned tickets.
        $this->as('Support Agent')->getJson($url)->assertNotFound();
        $this->as($finance)->get($url)->assertOk();
        $this->assertSame(1, Refund::count(), 'the refused upload created nothing');
    }

    public function test_photos_are_checked_before_anything_is_created(): void
    {
        $payment = $this->payment();
        $fields = ['payment_id' => $payment->id, 'amount' => 1000, 'reason' => 'damaged'];
        $finance = $this->as('Finance');

        $finance->postJson('/api/v1/refunds', [...$fields, 'photos' => array_map(fn ($n) => self::png("photo-$n.png"), range(1, 4))])
            ->assertUnprocessable()->assertJsonValidationErrors(['photos' => 'Add up to 3 photos.']);
        $finance->postJson('/api/v1/refunds', [...$fields, 'photos' => [UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf')]])
            ->assertUnprocessable()->assertJsonValidationErrors(['photos.0' => 'Each photo must be a PNG, JPG or WEBP image.']);
        $finance->postJson('/api/v1/refunds', [...$fields, 'photos' => [self::png('ok.png'), self::png('huge.png')->size(5000)]])
            ->assertUnprocessable()->assertJsonValidationErrors(['photos.1' => 'Each photo must be 4 MB or smaller.'])->assertJsonMissingValidationErrors('photos.0');
        $finance->postJson('/api/v1/refunds', [...$fields, 'photos' => ['not a file']])->assertUnprocessable()->assertJsonValidationErrors('photos.0');

        $this->assertSame([0, 0, 0], [Refund::count(), Attachment::count(), (int) Payment::whereKey($payment->id)->value('refunded_amount')], 'nothing is created or reserved');
    }

    public function test_a_staff_refund_keeps_its_photos_and_shows_them_without_their_content(): void
    {
        $payment = $this->payment();
        $box = self::png('box.png');
        $bytes = file_get_contents($box->getRealPath());
        $finance = $this->as('Finance');

        // A real form post: multipart fields arrive as strings.
        $id = $finance->post('/api/v1/refunds', ['payment_id' => (string) $payment->id, 'amount' => '2500', 'reason' => 'damaged', 'notes' => 'Box crushed.', 'photos' => [$box, self::jpeg('label.jpg')]], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('amount', 2500)->assertJsonPath('status', 'requested')->json('id');
        $refund = Refund::findOrFail($id);
        $this->assertSame(2500, $refund->total_amount);

        $photos = $finance->getJson('/api/v1/refunds/'.$id)->assertOk()->assertJsonPath('reference', $refund->reference)->assertJsonCount(2, 'photos')
            ->assertJsonPath('photos.0.filename', 'box.png')->assertJsonPath('photos.0.mime_type', 'image/png')->assertJsonPath('photos.1.mime_type', 'image/jpeg')->json('photos');
        $this->assertSame(['id', 'filename', 'mime_type', 'size', 'created_at'], array_keys($photos[0]));
        $this->assertSame(['Refund request', 'Refund request'], $refund->evidencePhotos()->pluck('source')->all());

        $download = $finance->get('/api/v1/refunds/'.$id.'/photos/'.$photos[0]['id'])->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($bytes, $download->getContent());
        $this->assertStringStartsWith('inline; filename="box.png"', $download->headers->get('Content-Disposition'));
    }

    public function test_a_json_refund_still_works_and_conversation_photos_of_linked_tickets_count_but_nothing_else(): void
    {
        $payment = $this->payment();
        $other = $this->payment();
        $finance = $this->as('Finance');
        $id = $finance->postJson('/api/v1/refunds', ['payment_id' => $payment->id, 'amount' => 1000, 'reason' => 'wrong_item'])->assertCreated()->json('id');
        $finance->getJson('/api/v1/refunds/'.$id)->assertOk()->assertJsonPath('photos', []);
        $refund = Refund::findOrFail($id);

        // A ticket on the order (not the payment) still belongs to this refund; a PDF or another order's photo does not.
        $chat = $this->conversationPhoto(['order_id' => $payment->order_id], self::jpeg('wrong-item.jpg'), 'TKT-2026-000501');
        $pdf = $this->conversationPhoto(['payment_id' => $payment->id], UploadedFile::fake()->create('invoice.pdf', 5, 'application/pdf'), 'TKT-2026-000502');
        $elsewhere = $this->conversationPhoto(['payment_id' => $other->id, 'order_id' => $other->order_id], self::png('other.png'), 'TKT-2026-000503');
        $own = app(AttachmentService::class)->store(self::png('mine.png'), $refund);

        // The request's own photo comes first, then the conversation's, each named by where it came from.
        $this->assertSame([[$own->id, 'Refund request'], [$chat->id, 'TKT-2026-000501']], $refund->evidencePhotos()->map(fn ($photo) => [$photo['id'], $photo['source']])->all());
        $this->assertSame($chat->content, $refund->evidencePhotos()[1]['content']);
        $this->assertArrayNotHasKey('content', $refund->evidencePhotos(10, false)[0]);
        $this->assertCount(1, $refund->evidencePhotos(1));

        $finance->get('/api/v1/refunds/'.$id.'/photos/'.$chat->id)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $finance->getJson('/api/v1/refunds/'.$id.'/photos/'.$pdf->id)->assertNotFound();
        $finance->getJson('/api/v1/refunds/'.$id.'/photos/'.$elsewhere->id)->assertNotFound();
        $finance->getJson('/api/v1/refunds/'.$id.'/photos/999999')->assertNotFound();
        // A photo larger than 4 MB is never used, even if it was stored.
        Attachment::whereKey($chat->id)->update(['size' => 5 * 1024 * 1024]);
        $finance->getJson('/api/v1/refunds/'.$id.'/photos/'.$chat->id)->assertNotFound();
    }
}
