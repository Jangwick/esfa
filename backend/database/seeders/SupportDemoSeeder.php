<?php

namespace Database\Seeders;

use App\Models\CannedResponse;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\SlaPolicy;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Database\Seeder;

class SupportDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) { return; }
        foreach (config('sfa.settings.sla_policies') as $priority => $hours) { SlaPolicy::firstOrCreate(['priority' => $priority], $hours); }
        $ordersCategory = KbCategory::firstOrCreate(['name' => 'Orders & deliveries'], ['sort_order' => 1]);
        $paymentsCategory = KbCategory::firstOrCreate(['name' => 'Payments & refunds'], ['sort_order' => 2]);
        foreach ([
            ['tracking-your-order', 'Tracking your order', $ordersCategory->id, 'published', "You can check your order status in your marketplace account.\nContact support with your order reference if you need help."],
            ['requesting-a-refund', 'Requesting a refund', $paymentsCategory->id, 'published', "Open a support ticket linked to your payment.\nOur finance team reviews each refund request."],
            ['support-team-checklist', 'Support team checklist', null, 'draft', 'Demonstration draft for the support team.'],
        ] as [$slug, $title, $category, $status, $body]) { KbArticle::firstOrCreate(['slug' => $slug], ['title' => $title, 'category_id' => $category, 'status' => $status, 'body' => $body, 'published_at' => $status === 'published' ? now() : null]); }
        $admin = User::where('email', 'admin@example.com')->first(); $agents = User::role('Support Agent')->orderBy('id')->get();
        if (! $admin || $agents->isEmpty()) { return; }
        foreach ([['Acknowledgement', 'Hello {customer_name}, thank you for contacting us. We are reviewing ticket {ticket_reference}. — {agent_name}', true], ['Delivery follow-up', 'Hello {customer_name}, we are checking the delivery details for ticket {ticket_reference}. — {agent_name}', true], ['My follow-up reminder', 'Please share your order reference so we can help you, {customer_name}.', false]] as [$title, $body, $shared]) { CannedResponse::firstOrCreate(['title' => $title, 'user_id' => $agents->first()->id], ['body' => $body, 'is_shared' => $shared]); }
        // Delivery tickets opened by the logistics demo (channel system) do not count as already seeded.
        if (Ticket::where('channel', '!=', 'system')->exists()) { return; }
        $orders = Order::with('payments')->orderBy('id')->limit(18)->get();
        foreach ($orders as $index => $order) {
            $priority = Ticket::PRIORITIES[$index % 4]; $hours = SlaPolicy::hoursFor($priority);
            $ticket = app(TicketService::class)->create(['subject' => 'Demo request for order '.$order->external_id, 'description' => 'This is a clearly fictional customer support request for demonstration.', 'requester_type' => 'customer', 'requester_id' => $order->customer_id, 'order_id' => $order->id, 'payment_id' => $order->payments->first()?->id, 'shipment_id' => Shipment::where('order_id', $order->id)->value('id'), 'priority' => $priority, 'category' => Ticket::CATEGORIES[$index % count(Ticket::CATEGORIES)], 'channel' => Ticket::CHANNELS[$index % count(Ticket::CHANNELS)], 'tags' => ['demo']], $admin);
            $status = Ticket::STATUSES[$index % 6];
            $created = now()->subHours($index % 4 === 2 ? $hours['first_response_hours'] + 2 : ($index % 4 === 1 ? $hours['first_response_hours'] * 0.9 : 0));
            $firstDue = $created->copy()->addHours($hours['first_response_hours']); $resolutionDue = $created->copy()->addHours($hours['resolution_hours']);
            $responded = in_array($status, ['in_progress', 'waiting_on_customer', 'resolved', 'closed'], true) ? $created->copy()->addMinutes(1) : null;
            if ($status === 'in_progress' && $index % 4 === 1) { $resolutionDue = now()->addMinutes(5); }
            $ticket->update(['status' => $status, 'assignee_id' => $index % 3 === 0 ? null : $agents[$index % $agents->count()]->id, 'created_at' => $created, 'first_response_due_at' => $firstDue, 'resolution_due_at' => $resolutionDue, 'first_responded_at' => $responded, 'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? $created->copy()->addMinutes(2) : null, 'closed_at' => $status === 'closed' ? $created->copy()->addMinutes(3) : null, 'resolution_paused_at' => $status === 'waiting_on_customer' ? now() : null, 'csat_score' => in_array($status, ['resolved', 'closed'], true) ? 3 + $index % 3 : null]);
        }
    }
}
