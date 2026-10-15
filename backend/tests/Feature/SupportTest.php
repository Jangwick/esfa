<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\CannedResponse;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\TicketService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LogisticsDemoSeeder;
use Database\Seeders\SupportDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTest extends TestCase
{
    use RefreshDatabase;
    private Customer $customer;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp(); $this->seed(DatabaseSeeder::class); $this->seed(SupportDemoSeeder::class);
        $this->customer = Customer::firstOrCreate(['source_system' => 'storefront', 'external_id' => 'support-test-customer'], ['name' => 'Support Customer', 'email' => 'customer@example.com']);
        $this->order = Order::firstOrCreate(['source_system' => 'storefront', 'external_id' => 'support-test-order'], ['customer_id' => $this->customer->id, 'status_snapshot' => 'placed', 'subtotal_amount' => 10000, 'total_amount' => 10000, 'placed_at' => now()]);
    }
    private function staff(string $email = 'admin@example.com'): array { Auth::forgetGuards(); $token = User::where('email', $email)->firstOrFail()->createToken('support-test', ['staff'])->plainTextToken; return ['Authorization' => 'Bearer '.$token]; }
    private function body(): array { return ['subject' => 'Where is my order?', 'description' => 'Please help me check this order.', 'requester_type' => 'customer', 'requester_id' => $this->customer->id, 'order_id' => $this->order->id, 'channel' => 'email', 'category' => 'delivery', 'priority' => 'normal']; }
    private function ticket(): Ticket { return app(TicketService::class)->create($this->body(), User::where('email', 'admin@example.com')->first()); }

    public static function staffRoutes(): array
    {
        return [['GET', '/options'], ['GET', '/tickets'], ['POST', '/tickets'], ['GET', '/tickets/1'], ['PUT', '/tickets/1'], ['DELETE', '/tickets/1'], ['POST', '/tickets/1/assign'], ['POST', '/tickets/1/status'], ['GET', '/tickets/1/messages'], ['POST', '/tickets/1/messages'], ['GET', '/attachments/1/download'], ['GET', '/kb/categories'], ['POST', '/kb/categories'], ['PUT', '/kb/categories/1'], ['DELETE', '/kb/categories/1'], ['GET', '/kb/articles'], ['POST', '/kb/articles'], ['GET', '/kb/articles/1'], ['PUT', '/kb/articles/1'], ['DELETE', '/kb/articles/1'], ['GET', '/canned-responses'], ['POST', '/canned-responses'], ['PUT', '/canned-responses/1'], ['DELETE', '/canned-responses/1'], ['GET', '/sla-policies'], ['PUT', '/sla-policies']];
    }
    #[DataProvider('staffRoutes')]
    public function test_staff_support_routes_require_authentication(string $method, string $path): void { $this->json($method, '/api/v1/support'.$path)->assertUnauthorized(); }

    public function test_ticket_creation_validation_reference_links_and_role_access(): void
    {
        $this->postJson('/api/v1/support/tickets', [], $this->staff())->assertUnprocessable()->assertJsonValidationErrors(['subject', 'description', 'requester_type', 'requester_id', 'channel', 'category']);
        $result = $this->postJson('/api/v1/support/tickets', $this->body(), $this->staff('support.one@example.com'))->assertCreated()->assertJsonPath('status', 'open')->assertJsonPath('sla_state', 'on_track')->json();
        $this->assertMatchesRegularExpression('/^TCK-\d{6}$/', $result['reference']);
        $this->getJson('/api/v1/support/tickets/'.$result['id'], $this->staff('finance@example.com'))->assertOk()->assertJsonStructure(['messages', 'history', 'order']);
        $this->postJson('/api/v1/support/tickets', $this->body(), $this->staff('finance@example.com'))->assertForbidden();
        $this->getJson('/api/v1/support/tickets', $this->staff('analyst@example.com'))->assertForbidden();
        $other = Customer::create(['source_system' => 'storefront', 'external_id' => 'other-support', 'name' => 'Other']);
        $this->postJson('/api/v1/support/tickets', array_replace($this->body(), ['requester_id' => $other->id]), $this->staff())->assertUnprocessable()->assertJsonValidationErrors('order_id');
    }

    public function test_own_and_unassigned_scope_assignment_and_notifications(): void
    {
        $ticket = $this->ticket(); $agent = User::where('email', 'support.one@example.com')->first();
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/assign', ['assignee_id' => 'me'], $this->staff($agent->email))->assertOk()->assertJsonPath('assignee_id', $agent->id);
        $this->assertDatabaseHas('notifications', ['user_id' => $agent->id, 'type' => 'ticket_assigned']);
        $this->getJson('/api/v1/support/tickets/'.$ticket->id, $this->staff('support.two@example.com'))->assertNotFound();
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/assign', ['assignee_id' => 'me'], $this->staff('support.two@example.com'))->assertNotFound();
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/assign', ['assignee_id' => 1], $this->staff($agent->email))->assertForbidden();
        $finance = User::where('email', 'finance@example.com')->firstOrFail();
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/assign', ['assignee_id' => $finance->id], $this->staff())->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
    }

    public function test_admin_can_take_and_reassign_tickets_and_appears_in_assignee_options(): void
    {
        $ticket = $this->ticket(); $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $agent = User::where('email', 'support.one@example.com')->firstOrFail();
        $ticket->update(['assignee_id' => $agent->id]);
        $options = $this->getJson('/api/v1/support/options', $this->staff())->assertOk()->json('agents');
        $this->assertContains($admin->id, array_column($options, 'id'));
        $this->assertContains($agent->id, array_column($options, 'id'));
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/assign', ['assignee_id' => 'me'], $this->staff())->assertOk()->assertJsonPath('assignee_id', $admin->id);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'ticket_assigned']);
        $this->getJson('/api/v1/support/tickets/'.$ticket->id, $this->staff($agent->email))->assertNotFound();
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/assign', ['assignee_id' => $agent->id], $this->staff())->assertOk()->assertJsonPath('assignee_id', $agent->id);
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/assign', ['assignee_id' => $admin->id], $this->staff())->assertOk()->assertJsonPath('assignee_id', $admin->id);
        $agent->update(['is_active' => false]);
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/assign', ['assignee_id' => $agent->id], $this->staff())->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
    }

    public function test_all_ticket_state_transitions_and_paused_clock(): void
    {
        $ticket = $this->ticket();
        foreach (Ticket::STATUSES as $from) {
            foreach (Ticket::STATUSES as $to) {
                $ticket->refresh()->update(['status' => $from, 'resolved_at' => $from === 'resolved' ? now() : null]);
                $response = $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/status', ['status' => $to], $this->staff());
                if (in_array($to, TicketService::TRANSITIONS[$from], true)) { $response->assertOk()->assertJsonPath('status', $to); } else $response->assertUnprocessable()->assertJsonValidationErrors('status');
            }
        }
        $ticket->refresh()->update(['status' => 'in_progress', 'resolution_paused_at' => null, 'resolution_paused_seconds' => 0]); $due = $ticket->fresh()->resolution_due_at->timestamp;
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/status', ['status' => 'waiting_on_customer'], $this->staff())->assertOk();
        $this->travel(2)->hours();
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/status', ['status' => 'in_progress'], $this->staff())->assertOk();
        $this->assertSame($due + 7200, $ticket->fresh()->resolution_due_at->timestamp);
        $this->assertSame(7200, $ticket->fresh()->resolution_paused_seconds);
    }

    public function test_an_archived_ticket_is_read_only(): void
    {
        $ticket = $this->ticket();
        $ticket->update(['created_at' => now()->subYears(4)]);
        $this->putJson('/api/v1/support/tickets/'.$ticket->id, ['subject' => 'Trying to edit history'], $this->staff())->assertUnprocessable()->assertJsonValidationErrors('ticket');
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/status', ['status' => 'in_progress'], $this->staff())->assertUnprocessable();
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/assign', ['assignee_id' => 'me'], $this->staff())->assertUnprocessable();
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/messages', ['body' => 'A reply to a closed-out year.'], $this->staff())->assertUnprocessable();
    }

    public function test_ticket_with_refund_cannot_be_deleted_and_status_notes_are_readable(): void
    {
        $ticket = $this->ticket();
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/status', ['status' => 'in_progress'], $this->staff())->assertOk();
        $this->assertDatabaseHas('ticket_messages', ['ticket_id' => $ticket->id, 'author_type' => 'system', 'body' => 'Status changed from open to in progress.']);
        $payment = Payment::create(['order_id' => $this->order->id, 'customer_id' => $this->customer->id, 'method' => 'card', 'gateway' => 'sandbox', 'reference' => 'PAY-SUPPORT-DELETE', 'total_amount' => 10000, 'captured_amount' => 10000, 'status' => 'captured']);
        $refund = Refund::create(['payment_id' => $payment->id, 'ticket_id' => $ticket->id, 'total_amount' => 5000, 'reason' => 'other']);
        $this->deleteJson('/api/v1/support/tickets/'.$ticket->id, [], $this->staff())->assertUnprocessable()->assertJsonValidationErrors('ticket');
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id]);
        $refund->delete();
        $this->deleteJson('/api/v1/support/tickets/'.$ticket->id, [], $this->staff())->assertOk();
        $this->assertDatabaseMissing('tickets', ['id' => $ticket->id]);
    }

    public function test_public_reply_sets_first_response_notes_do_not_and_uploads_are_hidden_authorized(): void
    {
        $ticket = $this->ticket();
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/messages', ['body' => 'Internal only', 'is_internal_note' => true], $this->staff())->assertCreated();
        $this->assertNull($ticket->fresh()->first_responded_at);
        $this->post('/api/v1/support/tickets/'.$ticket->id.'/messages', ['body' => 'We are checking your order.', 'attachments' => [UploadedFile::fake()->createWithContent('note.txt', 'A plain text note.')]], $this->staff())->assertCreated()->assertJsonMissingPath('attachments.0.content');
        $this->assertNotNull($ticket->fresh()->first_responded_at);
        $attachment = Attachment::where('ticket_id', $ticket->id)->firstOrFail();
        $this->get('/api/v1/support/attachments/'.$attachment->id.'/download', $this->staff())->assertOk()->assertContent('A plain text note.');
        $ticket->update(['assignee_id' => User::where('email', 'support.one@example.com')->first()->id]);
        $this->get('/api/v1/support/attachments/'.$attachment->id.'/download', $this->staff('support.two@example.com'))->assertNotFound();
        $this->post('/api/v1/support/tickets/'.$ticket->id.'/messages', ['body' => 'Too large', 'attachments' => [UploadedFile::fake()->create('large.pdf', 4097, 'application/pdf')]], $this->staff())->assertUnprocessable();
        $this->getJson('/api/v1/support/tickets/'.$ticket->id.'/messages?per_page=500', $this->staff())->assertOk()->assertJsonPath('per_page', 100)->assertJsonStructure(['summary', 'data']);
    }

    public function test_ticket_filters_priority_recompute_and_csv(): void
    {
        $ticket = $this->ticket(); $this->travel(3)->hours();
        $this->putJson('/api/v1/support/tickets/'.$ticket->id, ['priority' => 'urgent', 'tags' => ['urgent']], $this->staff())->assertOk()->assertJsonPath('sla_state', 'breached');
        $this->getJson('/api/v1/support/tickets?search=WHERE&priority=urgent&sla_state=breached', $this->staff())->assertOk()->assertJsonPath('summary.breached', 1)->assertJsonPath('total', 1);
        $this->getJson('/api/v1/support/tickets?status=unknown', $this->staff())->assertUnprocessable();
        $this->getJson('/api/v1/support/options', $this->staff())->assertOk()->assertJsonStructure(['customers', 'sellers', 'orders', 'payments', 'shipments', 'agents']);
        $export = $this->get('/api/v1/support/tickets?search=WHERE&export=csv', $this->staff())->assertOk()->streamedContent(); $this->assertStringContainsString('Where is my order?', $export);
        $this->deleteJson('/api/v1/support/tickets/'.$ticket->id, [], $this->staff())->assertOk(); $this->assertDatabaseMissing('tickets', ['id' => $ticket->id]);
    }

    public function test_knowledge_categories_articles_and_personal_canned_responses(): void
    {
        $category = $this->postJson('/api/v1/support/kb/categories', ['name' => 'Test category', 'sort_order' => 5], $this->staff())->assertCreated()->json();
        $this->putJson('/api/v1/support/kb/categories/'.$category['id'], ['name' => 'Renamed'], $this->staff())->assertOk();
        $article = $this->postJson('/api/v1/support/kb/articles', ['title' => 'Safe article', 'slug' => 'safe-article', 'body' => '<script>stay as text</script>', 'category_id' => $category['id'], 'status' => 'published'], $this->staff())->assertCreated()->assertJsonPath('body', '<script>stay as text</script>')->json();
        $this->getJson('/api/v1/support/kb/articles/'.$article['id'], $this->staff())->assertOk();
        $this->putJson('/api/v1/support/kb/articles/'.$article['id'], ['status' => 'draft'], $this->staff())->assertOk()->assertJsonPath('published_at', null);
        $this->getJson('/api/v1/support/kb/articles?status=draft', $this->staff())->assertOk()->assertJsonStructure(['summary', 'data']);
        $this->getJson('/api/v1/support/kb/categories', $this->staff())->assertOk()->assertJsonStructure(['summary', 'data']);
        $this->deleteJson('/api/v1/support/kb/categories/'.$category['id'], [], $this->staff())->assertOk();
        $this->deleteJson('/api/v1/support/kb/articles/'.$article['id'], [], $this->staff())->assertOk();
        $response = $this->postJson('/api/v1/support/canned-responses', ['title' => 'Personal only', 'body' => 'Hello {customer_name}', 'is_shared' => false], $this->staff('support.one@example.com'))->assertCreated()->json();
        $this->getJson('/api/v1/support/canned-responses?search=Personal%20only', $this->staff('support.two@example.com'))->assertOk()->assertJsonPath('total', 0);
        $this->putJson('/api/v1/support/canned-responses/'.$response['id'], ['title' => 'Hijack'], $this->staff('support.two@example.com'))->assertForbidden();
        $this->putJson('/api/v1/support/canned-responses/'.$response['id'], ['title' => 'Updated personal'], $this->staff('support.one@example.com'))->assertOk();
        $this->deleteJson('/api/v1/support/canned-responses/'.$response['id'], [], $this->staff('support.one@example.com'))->assertOk();
        $this->postJson('/api/v1/support/kb/articles', [], $this->staff())->assertUnprocessable();
        $this->postJson('/api/v1/support/kb/categories', ['name' => 'Finance attempt'], $this->staff('finance@example.com'))->assertForbidden();
    }

    public function test_sla_configuration_auto_assignment_and_scheduled_work_are_idempotent(): void
    {
        $this->getJson('/api/v1/support/sla-policies', $this->staff())->assertOk()->assertJsonCount(4, 'data');
        $policies = config('sfa.settings.sla_policies'); $policies['urgent']['first_response_hours'] = 2;
        $this->putJson('/api/v1/support/sla-policies', ['policies' => $policies], $this->staff())->assertOk();
        $this->putJson('/api/v1/support/sla-policies', ['policies' => $policies], $this->staff('support.one@example.com'))->assertForbidden();
        $this->putJson('/api/v1/support/sla-policies', ['policies' => []], $this->staff())->assertUnprocessable();
        Setting::updateOrCreate(['key' => 'auto_assign'], ['value' => true]); $ticket = $this->ticket(); $this->assertNotNull($ticket->assignee_id);
        $this->travel(9)->hours();
        $first = app(TicketService::class)->runScheduled(); $this->assertGreaterThan(0, $first['ticket_sla_notifications']);
        $second = app(TicketService::class)->runScheduled(); $this->assertSame(0, $second['ticket_sla_notifications']);
        // Backdated as a whole: a requester message from the last 7 days would hold the close (see the close-clock test).
        $ticket->update(['status' => 'resolved', 'resolved_at' => now()->subDays(8)]); $ticket->messages()->update(['created_at' => now()->subDays(9)]);
        $this->assertSame(1, app(TicketService::class)->runScheduled()['tickets_auto_closed']);
        $this->assertSame(0, app(TicketService::class)->runScheduled()['tickets_auto_closed']);
    }

    public function test_integrations_are_scoped_and_hide_internal_notes_and_drafts(): void
    {
        $client = IntegrationClient::firstOrCreate(['system_code' => 'storefront'], ['name' => 'Storefront', 'is_active' => true]);
        $token = $client->createToken('support-integration', ['tickets:read', 'tickets:write', 'kb:read'])->plainTextToken;
        Auth::forgetGuards(); $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'support-create-1'];
        $payload = $this->body(); unset($payload['requester_id'], $payload['order_id']); $payload += ['requester_external_id' => $this->customer->external_id, 'order_external_id' => $this->order->external_id];
        $created = $this->postJson('/api/v1/integrations/tickets', $payload, $headers)->assertCreated()->json();
        $reference = $created['reference']; $ticket = Ticket::find($created['id']);
        app(TicketService::class)->message($ticket, ['body' => 'Secret internal note', 'is_internal_note' => true], [], User::where('email', 'admin@example.com')->first());
        Auth::forgetGuards(); $this->getJson('/api/v1/integrations/tickets/'.$reference, $headers)->assertOk()->assertJsonCount(1, 'messages')->assertJsonMissing(['body' => 'Secret internal note']);
        Auth::forgetGuards(); $this->postJson('/api/v1/integrations/tickets/'.$reference.'/messages', ['body' => 'Customer follow-up'], $headers)->assertCreated();
        Auth::forgetGuards(); $this->postJson('/api/v1/integrations/tickets/'.$reference.'/messages', ['body' => 'Spoof', 'is_internal_note' => true], $headers)->assertUnprocessable();
        Auth::forgetGuards(); $this->postJson('/api/v1/integrations/tickets/'.$reference.'/csat', ['score' => 5], $headers)->assertUnprocessable();
        $ticket->update(['status' => 'resolved', 'resolved_at' => now()]);
        Auth::forgetGuards(); $this->postJson('/api/v1/integrations/tickets/'.$reference.'/csat', ['score' => 5, 'comment' => 'Thank you'], $headers)->assertOk()->assertJsonPath('csat_score', 5);
        Auth::forgetGuards(); $this->getJson('/api/v1/integrations/kb/articles', $headers)->assertOk()->assertJsonPath('summary.draft', 0);
        Auth::forgetGuards(); $this->getJson('/api/v1/integrations/kb/articles/requesting-a-refund', $headers)->assertOk();
        Auth::forgetGuards(); $this->getJson('/api/v1/integrations/kb/articles/support-team-checklist', $headers)->assertNotFound();
        $other = IntegrationClient::create(['name' => 'Other', 'system_code' => 'other-source', 'is_active' => true]); $otherToken = $other->createToken('read', ['tickets:read'])->plainTextToken;
        Auth::forgetGuards(); $this->getJson('/api/v1/integrations/tickets/'.$reference, ['Authorization' => 'Bearer '.$otherToken])->assertNotFound();
        Auth::forgetGuards(); $this->getJson('/api/v1/integrations/kb/articles', ['Authorization' => 'Bearer '.$otherToken])->assertForbidden();
    }

    public function test_status_follows_replies_a_customer_reply_alerts_the_assignee_and_a_first_reply_claims(): void
    {
        $agent = User::where('email', 'support.one@example.com')->firstOrFail(); $ticket = $this->ticket(); $url = '/api/v1/support/tickets/'.$ticket->id.'/messages';
        $this->postJson($url, ['body' => 'Checking the courier first.', 'is_internal_note' => true], $this->staff($agent->email))->assertCreated();
        $this->assertSame('open', $ticket->fresh()->status); $this->assertNull($ticket->fresh()->assignee_id);
        $this->postJson($url, ['body' => 'We are checking your order.'], $this->staff($agent->email))->assertCreated();
        $this->assertSame('in_progress', $ticket->fresh()->status); $this->assertSame($agent->id, $ticket->fresh()->assignee_id);
        $this->assertDatabaseHas('ticket_messages', ['ticket_id' => $ticket->id, 'author_type' => 'system', 'author_id' => $agent->id, 'body' => 'Status changed from open to in progress. Moved automatically by the reply.']);
        $this->assertSame('ticket_reply_follow_up', AuditLog::where('entity_type', 'Ticket')->where('entity_id', (string) $ticket->id)->whereNull('user_id')->latest('id')->firstOrFail()->new_values['automation']);
        $this->assertDatabaseMissing('notifications', ['type' => 'ticket_customer_replied']);

        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/status', ['status' => 'waiting_on_customer'], $this->staff($agent->email))->assertOk(); $due = $ticket->fresh()->resolution_due_at->timestamp;
        $this->travel(2)->hours();
        $client = IntegrationClient::firstOrCreate(['system_code' => 'storefront'], ['name' => 'Storefront', 'is_active' => true]); $token = $client->createToken('support-reply', ['tickets:write'])->plainTextToken;
        Auth::forgetGuards(); $this->postJson('/api/v1/integrations/tickets/'.$ticket->reference.'/messages', ['body' => 'Here is the photo you asked for.'], ['Authorization' => 'Bearer '.$token])->assertCreated();
        $ticket->refresh(); $this->assertSame('in_progress', $ticket->status); $this->assertNull($ticket->resolution_paused_at); $this->assertSame(7200, $ticket->resolution_paused_seconds); $this->assertSame($due + 7200, $ticket->resolution_due_at->timestamp);
        $this->assertDatabaseHas('ticket_messages', ['ticket_id' => $ticket->id, 'author_type' => 'system', 'author_id' => null, 'body' => 'Status changed from waiting on customer to in progress. Moved automatically: the customer replied.']);
        $this->assertSame(1, Notification::where('user_id', $agent->id)->where('type', 'ticket_customer_replied')->where('data->ticket_id', $ticket->id)->count());

        $other = $this->ticket();
        $this->postJson('/api/v1/support/tickets/'.$other->id.'/messages', ['body' => 'An Admin answers.'], $this->staff())->assertCreated();
        $this->assertSame('in_progress', $other->fresh()->status); $this->assertNull($other->fresh()->assignee_id);
    }

    public function test_a_reply_can_set_the_status_and_an_invalid_status_saves_nothing(): void
    {
        $ticket = $this->ticket(); $url = '/api/v1/support/tickets/'.$ticket->id.'/messages';
        $this->postJson($url, ['body' => 'Done.', 'status' => 'resolved'], $this->staff('analyst@example.com'))->assertForbidden();
        $this->postJson($url, ['body' => 'Done.', 'status' => 'closed'], $this->staff())->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($url, ['body' => 'Note.', 'is_internal_note' => true, 'status' => 'resolved'], $this->staff())->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($url, ['body' => 'Please send a photo of the parcel.', 'status' => 'waiting_on_customer'], $this->staff())->assertCreated()->assertJsonPath('body', 'Please send a photo of the parcel.');
        $this->assertSame('waiting_on_customer', $ticket->fresh()->status); $this->assertNotNull($ticket->fresh()->resolution_paused_at);
        $count = $ticket->messages()->count();
        $this->postJson($url, ['body' => 'Resolving without an answer.', 'status' => 'resolved'], $this->staff())->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame($count, $ticket->messages()->count()); $this->assertSame('waiting_on_customer', $ticket->fresh()->status);
        $this->postJson('/api/v1/support/tickets/'.$ticket->id.'/status', ['status' => 'in_progress'], $this->staff())->assertOk();
        $this->postJson($url, ['body' => 'The refund was sent.', 'status' => 'resolved'], $this->staff())->assertCreated();
        $this->assertSame('resolved', $ticket->fresh()->status); $this->assertNotNull($ticket->fresh()->resolved_at);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'Ticket', 'entity_id' => (string) $ticket->id, 'user_id' => User::where('email', 'admin@example.com')->value('id'), 'new_values->status' => 'resolved']);
        $ticket->update(['status' => 'closed', 'closed_at' => now()]);
        $this->postJson($url, ['body' => 'Late reply.', 'status' => 'resolved'], $this->staff())->assertUnprocessable()->assertJsonValidationErrors('body');
        $client = IntegrationClient::firstOrCreate(['system_code' => 'storefront'], ['name' => 'Storefront', 'is_active' => true]); $token = $client->createToken('support-reply', ['tickets:write'])->plainTextToken;
        Auth::forgetGuards(); $this->postJson('/api/v1/integrations/tickets/'.$ticket->reference.'/messages', ['body' => 'Thanks', 'status' => 'resolved'], ['Authorization' => 'Bearer '.$token])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_a_deactivated_agents_open_tickets_are_released_and_the_auto_assign_sweep_reassigns_them(): void
    {
        $one = User::where('email', 'support.one@example.com')->firstOrFail(); $two = User::where('email', 'support.two@example.com')->firstOrFail(); $service = app(TicketService::class);
        $open = $this->ticket(); $open->update(['assignee_id' => $one->id]);
        $done = $this->ticket(); $done->update(['assignee_id' => $one->id, 'status' => 'resolved', 'resolved_at' => now()]);
        $kept = $this->ticket(); $kept->update(['assignee_id' => $two->id]);
        $released = Ticket::where('assignee_id', $one->id)->whereNotIn('status', ['resolved', 'closed'])->count();
        $this->putJson('/api/v1/users/'.$one->id, ['is_active' => false], $this->staff())->assertOk();
        $this->assertNull($open->fresh()->assignee_id); $this->assertSame($one->id, $done->fresh()->assignee_id); $this->assertSame($two->id, $kept->fresh()->assignee_id);
        $this->assertSame($released, AuditLog::where('entity_type', 'User')->where('entity_id', (string) $one->id)->latest('id')->firstOrFail()->new_values['released_tickets']);

        $this->assertSame(0, $service->runScheduled()['tickets_auto_assigned'], 'The sweep only runs with auto_assign on.');
        $this->assertNull($open->fresh()->assignee_id);
        Setting::updateOrCreate(['key' => 'auto_assign'], ['value' => true]);
        $this->assertGreaterThan(0, $service->runScheduled()['tickets_auto_assigned']);
        $this->assertSame($two->id, $open->fresh()->assignee_id, 'The only active Support Agent takes the released ticket.');
        $this->assertSame(1, Notification::where('user_id', $two->id)->where('type', 'ticket_assigned')->where('data->ticket_id', $open->id)->count());
        $this->assertSame('auto_assign', AuditLog::where('entity_type', 'Ticket')->where('entity_id', (string) $open->id)->whereNull('user_id')->latest('id')->firstOrFail()->new_values['automation']);
        $this->assertSame(0, $service->runScheduled()['tickets_auto_assigned']);

        // Keeping the Admin role keeps the tickets; losing both Admin and Support Agent releases them.
        $this->putJson('/api/v1/users/'.$two->id, ['roles' => ['Admin']], $this->staff())->assertOk();
        $this->assertSame($two->id, $kept->fresh()->assignee_id);
        $this->putJson('/api/v1/users/'.$two->id, ['roles' => ['Accounting Staff']], $this->staff())->assertOk();
        $this->assertNull($kept->fresh()->assignee_id); $this->assertNull($open->fresh()->assignee_id);
    }

    public function test_a_breach_reaches_the_admins_once_a_low_score_alerts_once_and_a_late_reply_delays_closing(): void
    {
        $agent = User::where('email', 'support.one@example.com')->firstOrFail(); $service = app(TicketService::class);
        $admins = User::role('Admin')->where('is_active', true)->pluck('id')->all();
        $ticket = $this->ticket(); $ticket->update(['assignee_id' => $agent->id]);
        $this->travel(9)->hours(); $service->runScheduled(); $service->runScheduled();
        $this->assertSame('breached', $ticket->fresh()->sla_state);
        $breached = fn ($id) => Notification::where('user_id', $id)->where('type', 'ticket_sla_breached')->where('data->ticket_id', $ticket->id)->count();
        foreach ([$agent->id, ...$admins] as $id) { $this->assertSame(1, $breached($id)); }
        $this->assertSame(0, $breached(User::where('email', 'support.two@example.com')->value('id')));

        $ticket->update(['status' => 'resolved', 'resolved_at' => now()]);
        $client = IntegrationClient::firstOrCreate(['system_code' => 'storefront'], ['name' => 'Storefront', 'is_active' => true]); $headers = ['Authorization' => 'Bearer '.$client->createToken('support-csat', ['tickets:write'])->plainTextToken];
        $low = fn () => Notification::where('type', 'ticket_low_csat')->where('data->ticket_id', $ticket->id)->pluck('user_id')->sort()->values()->all();
        Auth::forgetGuards(); $this->postJson('/api/v1/integrations/tickets/'.$ticket->reference.'/csat', ['score' => 4], $headers)->assertOk();
        $this->assertSame([], $low());
        Auth::forgetGuards(); $this->postJson('/api/v1/integrations/tickets/'.$ticket->reference.'/csat', ['score' => 2, 'comment' => 'Slow'], $headers)->assertOk()->assertJsonPath('csat_score', 2);
        $expected = collect([$agent->id, ...$admins])->unique()->sort()->values()->all();
        $this->assertSame($expected, $low());
        Auth::forgetGuards(); $this->postJson('/api/v1/integrations/tickets/'.$ticket->reference.'/csat', ['score' => 1], $headers)->assertOk();
        $this->assertSame($expected, $low(), 'A re-submitted low score does not alert again.');

        $this->travel(2)->days();
        Auth::forgetGuards(); $this->postJson('/api/v1/integrations/tickets/'.$ticket->reference.'/messages', ['body' => 'It broke again.'], $headers)->assertCreated();
        $this->travel(5)->days(); $service->runScheduled();
        $this->assertSame('resolved', $ticket->fresh()->status, 'A customer reply 2 days after resolve holds the close.');
        $this->travel(3)->days(); $service->runScheduled();
        $this->assertSame('closed', $ticket->fresh()->status, 'It closes 7 days after the latest customer message.');
    }

    public function test_auto_close_rechecks_for_a_late_reply_under_the_lock(): void
    {
        [$late, $quiet] = [$this->ticket(), $this->ticket()];
        foreach ([$late, $quiet] as $ticket) { $ticket->update(['status' => 'resolved', 'resolved_at' => now()->subDays(8)]); $ticket->messages()->update(['created_at' => now()->subDays(9)]); }
        // The customer replies after the close query ran but before the ticket is locked.
        $replied = false;
        Ticket::retrieved(function (Ticket $model) use ($late, &$replied) {
            if ($replied || $model->id !== $late->id) { return; }
            $replied = true; TicketMessage::create(['ticket_id' => $late->id, 'author_type' => 'customer', 'author_id' => $this->customer->id, 'body' => 'It still does not work.', 'is_internal_note' => false]);
        });
        $this->assertSame(1, app(TicketService::class)->runScheduled()['tickets_auto_closed']);
        $this->assertTrue($replied); $this->assertSame('resolved', $late->fresh()->status); $this->assertSame('closed', $quiet->fresh()->status);
        $this->assertSame('ticket_auto_close', AuditLog::where('entity_type', 'Ticket')->where('entity_id', (string) $quiet->id)->whereNull('user_id')->latest('id')->firstOrFail()->new_values['automation']);
    }

    public function test_waiting_tickets_auto_resolve_only_when_the_rule_is_on_and_the_requester_stayed_silent(): void
    {
        $this->freezeSecond(); $service = app(TicketService::class); $admin = User::where('email', 'admin@example.com')->firstOrFail();
        [$quiet, $replied] = [$this->ticket(), $this->ticket()];
        foreach ([$quiet, $replied] as $ticket) { $service->transition($ticket, 'in_progress', $admin); $service->transition($ticket, 'waiting_on_customer', $admin); }
        $due = $quiet->fresh()->resolution_due_at->timestamp;
        $this->travel(6)->days();
        TicketMessage::create(['ticket_id' => $replied->id, 'author_type' => 'customer', 'author_id' => $this->customer->id, 'body' => 'The courier has not called yet.', 'is_internal_note' => false]);
        $this->assertSame(0, $service->runScheduled()['tickets_auto_resolved'], 'The rule is off by default.');
        Setting::updateOrCreate(['key' => 'ticket_waiting_auto_resolve_days'], ['value' => 7]);
        $this->assertSame(0, $service->runScheduled()['tickets_auto_resolved'], 'Six days of waiting is not seven.');
        Setting::updateOrCreate(['key' => 'ticket_waiting_auto_resolve_days'], ['value' => 5]);
        $this->assertSame(1, $service->runScheduled()['tickets_auto_resolved']);
        $this->assertSame(0, $service->runScheduled()['tickets_auto_resolved']);
        $quiet->refresh();
        $this->assertSame('resolved', $quiet->status); $this->assertContains('auto_resolved', $quiet->tags); $this->assertNull($quiet->resolution_paused_at);
        $this->assertSame($due + 6 * 86400, $quiet->resolution_due_at->timestamp, 'The waiting time is credited to the resolution SLA.');
        $this->assertDatabaseHas('ticket_messages', ['ticket_id' => $quiet->id, 'author_type' => 'system', 'is_internal_note' => true, 'body' => 'Status changed from in progress to resolved. Automatically resolved after 5 days without a reply from the customer.']);
        $this->assertSame('ticket_auto_resolve', AuditLog::where('entity_type', 'Ticket')->where('entity_id', (string) $quiet->id)->whereNull('user_id')->latest('id')->firstOrFail()->new_values['automation']);
        $this->assertSame('waiting_on_customer', $replied->fresh()->status, 'A requester message after the pause holds the ticket open.');
        $this->putJson('/api/v1/settings', ['ticket_waiting_auto_resolve_days' => 91, 'ticket_auto_acknowledge' => 'yes'], $this->staff())->assertUnprocessable()->assertJsonValidationErrors(['ticket_waiting_auto_resolve_days', 'ticket_auto_acknowledge']);
    }

    public function test_the_auto_acknowledgement_is_public_reaches_only_the_owning_client_and_is_not_a_first_response(): void
    {
        $this->getJson('/api/v1/settings', $this->staff())->assertOk()->assertJsonPath('ticket_auto_acknowledge', false)->assertJsonPath('ticket_waiting_auto_resolve_days', 0);
        $hooks = [];
        foreach ([['storefront', 'Storefront'], ['other-source', 'Other']] as [$code, $name]) {
            $client = IntegrationClient::firstOrCreate(['system_code' => $code], ['name' => $name, 'is_active' => true]);
            $hooks[] = WebhookEndpoint::create(['client_id' => $client->id, 'url' => 'https://8.8.8.8/'.$code, 'events' => WebhookEndpoint::EVENTS, 'secret' => 'secret-'.$code, 'is_active' => true]);
        }
        $this->assertSame(1, $this->ticket()->messages()->count(), 'Off by default: only the requester message.');
        $this->putJson('/api/v1/settings', ['ticket_auto_acknowledge' => true], $this->staff())->assertOk()->assertJsonPath('ticket_auto_acknowledge', true);
        $ticket = $this->ticket();
        $ack = $ticket->messages()->where('author_type', 'system')->sole();
        $this->assertFalse($ack->is_internal_note); $this->assertSame('Thank you for contacting us. We received your request '.$ticket->reference.' and will reply within 8 hours.', $ack->body);
        $this->assertNull($ticket->fresh()->first_responded_at); $this->assertSame('on_track', $ticket->fresh()->sla_state);
        $this->assertEqualsCanonicalizing(['ticket.created', 'ticket.created', 'ticket.replied'], WebhookDelivery::where('endpoint_id', $hooks[0]->id)->pluck('event_type')->all());
        $this->assertSame(0, WebhookDelivery::where('endpoint_id', $hooks[1]->id)->count());
    }

    public function test_the_orders_only_payment_or_shipment_is_linked_by_category(): void
    {
        $this->seed(LogisticsDemoSeeder::class); $hub = Warehouse::where('code', 'demo-las-pinas-hub')->firstOrFail();
        [$one, $two] = [Seller::create(['source_system' => 'storefront', 'external_id' => 'link-seller-1', 'shop_name' => 'Link shop one']), Seller::create(['source_system' => 'storefront', 'external_id' => 'link-seller-2', 'shop_name' => 'Link shop two'])];
        foreach ([$one, $two] as $seller) { $this->order->items()->create(['seller_id' => $seller->id, 'product_name' => 'Demo item', 'quantity' => 1, 'unit_price_amount' => 5000, 'line_total_amount' => 5000]); }
        $shipment = fn (Seller $seller) => Shipment::create(['order_id' => $this->order->id, 'seller_id' => $seller->id, 'courier_id' => Courier::where('code', 'demo-courier')->value('id'), 'service_type' => 'standard', 'origin_warehouse_id' => $hub->id, 'destination_address_id' => $hub->address_id, 'weight_grams' => 1000, 'dimensions' => [], 'shipping_fee_amount' => 0, 'promised_from' => now(), 'promised_to' => now()->addDays(3)]);
        $create = fn (string $category, array $extra = []) => $this->postJson('/api/v1/support/tickets', array_replace($this->body(), ['category' => $category], $extra), $this->staff())->assertCreated()->json();
        $first = Payment::create(['order_id' => $this->order->id, 'customer_id' => $this->customer->id, 'method' => 'card', 'gateway' => 'sandbox', 'reference' => 'PAY-LINK-1', 'total_amount' => 10000, 'captured_amount' => 10000, 'status' => 'captured']); $mine = $shipment($one);
        foreach (['payment' => [$first->id, null], 'delivery' => [null, $mine->id], 'refund_return' => [$first->id, $mine->id], 'other' => [null, null]] as $category => [$paymentId, $shipmentId]) {
            $ticket = $create($category);
            $this->assertSame([$paymentId, $shipmentId], [$ticket['payment_id'], $ticket['shipment_id']], $category);
        }
        $shipment($two);
        $ticket = $create('refund_return');
        $this->assertSame([$first->id, null], [$ticket['payment_id'], $ticket['shipment_id']], 'Two shipments: none is guessed.');
        $seller = $create('delivery', ['requester_type' => 'seller', 'requester_id' => $one->id]);
        $this->assertSame($mine->id, $seller['shipment_id'], "A seller's own only shipment is linked.");
        $other = Customer::create(['source_system' => 'other-source', 'external_id' => 'link-other', 'name' => 'Other source']);
        $this->postJson('/api/v1/support/tickets', array_replace($this->body(), ['category' => 'delivery', 'requester_id' => $other->id]), $this->staff())->assertUnprocessable()->assertJsonValidationErrors('order_id');
    }

    public function test_only_sfa_opens_system_channel_tickets_and_staff_can_still_edit_them(): void
    {
        $this->postJson('/api/v1/support/tickets', array_replace($this->body(), ['channel' => 'system']), $this->staff())->assertUnprocessable()->assertJsonValidationErrors('channel');
        $ticket = app(TicketService::class)->create(array_replace($this->body(), ['channel' => 'system']), null);
        $this->assertSame(['system', null, false], [$ticket->messages->first()->author_type, $ticket->messages->first()->author_id, $ticket->messages->first()->is_internal_note]);
        $this->putJson('/api/v1/support/tickets/'.$ticket->id, ['category' => 'order', 'channel' => 'system'], $this->staff())->assertOk()->assertJsonPath('channel', 'system')->assertJsonPath('category', 'order');
        $this->putJson('/api/v1/support/tickets/'.$this->ticket()->id, ['channel' => 'system'], $this->staff())->assertUnprocessable()->assertJsonValidationErrors('channel');
    }

    public function test_sla_alerts_skip_inactive_or_unauthorized_assignees_and_a_broken_ticket_does_not_stop_other_alerts(): void
    {
        $service = app(TicketService::class);
        [$inactive, $unauthorized, $broken, $healthy] = [$this->ticket(), $this->ticket(), $this->ticket(), $this->ticket()];
        $one = User::where('email', 'support.one@example.com')->firstOrFail();
        $two = User::where('email', 'support.two@example.com')->firstOrFail();
        $inactive->update(['assignee_id' => $one->id]); $unauthorized->update(['assignee_id' => $two->id]);
        $one->update(['is_active' => false]); Role::findByName('Administrative Assistant')->revokePermissionTo('view tickets');
        $this->travel(9)->hours();
        Ticket::retrieved(function (Ticket $ticket) use ($broken) {
            if ($ticket->id === $broken->id) { throw new \RuntimeException('One ticket cannot be loaded.'); }
        });
        $service->runScheduled();
        $ids = [$inactive->id, $unauthorized->id, $healthy->id];
        $this->assertSame(0, Notification::whereIn('user_id', [$one->id, $two->id])->where('type', 'ticket_sla_breached')->whereIn('data->ticket_id', $ids)->count());
        $this->assertTrue(Notification::where('type', 'ticket_sla_breached')->where('data->ticket_id', $healthy->id)->exists());
        $this->assertDatabaseHas('tickets', ['id' => $healthy->id, 'sla_breached_notified_at' => now()]);
        $this->assertSame(0, $service->runScheduled()['ticket_sla_notifications']);
    }

    public function test_reply_and_low_csat_notifications_require_an_active_assignee_with_ticket_access(): void
    {
        $agent = User::where('email', 'support.one@example.com')->firstOrFail(); $ticket = $this->ticket();
        $ticket->update(['assignee_id' => $agent->id]);
        Role::findByName('Administrative Assistant')->revokePermissionTo('view tickets');
        $client = IntegrationClient::firstOrCreate(['system_code' => 'storefront'], ['name' => 'Storefront', 'is_active' => true]);
        $headers = ['Authorization' => 'Bearer '.$client->createToken('notification-access', ['tickets:write'])->plainTextToken];
        Auth::forgetGuards(); $this->postJson('/api/v1/integrations/tickets/'.$ticket->reference.'/messages', ['body' => 'An update.'], $headers)->assertCreated();
        $ticket->update(['status' => 'resolved', 'resolved_at' => now()]);
        Auth::forgetGuards(); $this->postJson('/api/v1/integrations/tickets/'.$ticket->reference.'/csat', ['score' => 1], $headers)->assertOk();
        $this->assertSame(0, Notification::where('user_id', $agent->id)->whereIn('type', ['ticket_customer_replied', 'ticket_low_csat'])->where('data->ticket_id', $ticket->id)->count());
        $this->assertTrue(Notification::where('user_id', User::where('email', 'admin@example.com')->value('id'))->where('type', 'ticket_low_csat')->where('data->ticket_id', $ticket->id)->exists());
    }

    public function test_waiting_auto_resolve_rechecks_requester_messages_after_the_scan(): void
    {
        [$late, $quiet] = [$this->ticket(), $this->ticket()];
        foreach ([$late, $quiet] as $ticket) { $ticket->update(['status' => 'waiting_on_customer', 'resolution_paused_at' => now()->subDays(6)]); $ticket->messages()->update(['created_at' => now()->subDays(7)]); }
        Setting::updateOrCreate(['key' => 'ticket_waiting_auto_resolve_days'], ['value' => 5]);
        $replied = false;
        Ticket::retrieved(function (Ticket $ticket) use ($late, &$replied) {
            if ($replied || $ticket->id !== $late->id) { return; }
            $replied = true;
            TicketMessage::create(['ticket_id' => $late->id, 'author_type' => 'customer', 'author_id' => $this->customer->id, 'body' => 'Please keep checking.', 'is_internal_note' => false]);
        });
        $this->assertSame(1, app(TicketService::class)->runScheduled()['tickets_auto_resolved']);
        $this->assertTrue($replied); $this->assertSame('waiting_on_customer', $late->fresh()->status); $this->assertSame('resolved', $quiet->fresh()->status);
    }
}
