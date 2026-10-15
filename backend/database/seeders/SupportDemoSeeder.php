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
            ['tracking-your-order', 'Tracking your delivery', $ordersCategory->id, 'published', "You can track your delivery status from your marketplace account under Orders.\nLarge orders such as tile adhesive, mortar, and skimcoat may arrive in multiple drops, so check the delivery note for items still in transit.\nIf the status shows delivered but items are missing, contact support with your order reference."],
            ['requesting-a-refund', 'Requesting a refund or replacement', $paymentsCategory->id, 'published', "To request a refund or replacement for damaged, incorrect, or defective products, open a support ticket linked to your order or payment.\nPlease attach photos of the damaged items and a copy of the delivery receipt.\nOur finance team reviews each request within three (3) business days."],
            ['support-team-checklist', 'Internal: product complaint checklist', null, 'draft', "Internal draft for the support team.\n1. Confirm the product SKU and the batch number printed on the packaging.\n2. For curing or bonding complaints, ask for photos, surface preparation details, and weather conditions during application.\n3. Escalate suspected batch defects to QA before offering a refund or replacement."],
        ] as [$slug, $title, $category, $status, $body]) { KbArticle::firstOrCreate(['slug' => $slug], ['title' => $title, 'category_id' => $category, 'status' => $status, 'body' => $body, 'published_at' => $status === 'published' ? now() : null]); }
        $admin = User::where('email', 'admin@example.com')->first(); $agents = User::role('Administrative Assistant')->orderBy('id')->get();
        if (! $admin || $agents->isEmpty()) { return; }
        foreach ([['Acknowledgement', 'Hello {customer_name}, thank you for reaching out to Archon Nell support. We have received your message and are now reviewing ticket {ticket_reference}. We will get back to you within one business day. — {agent_name}', true], ['Delivery follow-up', 'Hello {customer_name}, we are following up on ticket {ticket_reference}. Our team has coordinated with the logistics partner handling your delivery and we will update you as soon as the truck is dispatched. — {agent_name}', true], ['My follow-up reminder', 'Hi {customer_name}, just following up on your concern — kindly send us your order reference or the batch number printed on the packaging so we can check with the plant. — {agent_name}', false]] as [$title, $body, $shared]) { CannedResponse::firstOrCreate(['title' => $title, 'user_id' => $agents->first()->id], ['body' => $body, 'is_shared' => $shared]); }
        // Delivery tickets opened by the logistics demo (channel system) do not count as already seeded.
        if (Ticket::where('channel', '!=', 'system')->exists()) { return; }
        $orders = Order::with('payments')->orderBy('id')->limit(18)->get();
        foreach ($orders as $index => $order) {
            $priority = Ticket::PRIORITIES[$index % 4]; $hours = SlaPolicy::hoursFor($priority);
            $scenarios = [
                ['Delivery of order '.$order->external_id.' still pending', 'Good day. Order '.$order->external_id.' was scheduled for delivery last week but nothing has arrived at our project site. Our crew is on standby waiting for the tile adhesive and skimcoat. Please provide an updated delivery date.'],
                ['Wrong item delivered under order '.$order->external_id, 'We ordered AN Tile Adhesive in 25 kg bags but the delivery under order '.$order->external_id.' contained skimcoat instead. Tiling works resume this week, so we need the correct items delivered and the wrong ones picked up.'],
                ['Tile adhesive from order '.$order->external_id.' not curing', 'The tile adhesive we received under order '.$order->external_id.' is still soft after 48 hours even though we followed the mixing ratio on the bag. The batch number is printed on the packaging. Kindly check if this is a product defect.'],
                ['Application guide for waterproofing coating — order '.$order->external_id, 'We purchased waterproofing coating under order '.$order->external_id.' and want to confirm proper application: how many coats are needed on a concrete wall, the coverage per pail in sqm, and whether a primer is required.'],
                ['Bulk quote request — repeat of order '.$order->external_id, 'We are a contractor bidding for a project in Batangas and would like a bulk quotation for mortar, joint sealant, and epoxy grout — the same items as our previous order '.$order->external_id.'. Please send pricing for 200 bags and up.'],
                ['Refund request — damaged bags in order '.$order->external_id, 'Several bags of skimcoat in order '.$order->external_id.' arrived torn and wet, and the contents have already hardened. Photos of the damaged items are attached. We would like a refund or replacement.'],
                ['Missing items in shipment for order '.$order->external_id, 'The packing list for order '.$order->external_id.' shows 20 pails of epoxy grout but we only received 18. Please verify with the warehouse and arrange delivery of the missing items.'],
                ['Site visit request — waterproofing still tacky (order '.$order->external_id.')', 'The waterproofing coating from order '.$order->external_id.' is still tacky past the stated curing time on the technical datasheet. We would like to request a technical site visit to inspect the application before we proceed with the topping.'],
            ];
            [$subject, $description] = $scenarios[$index % count($scenarios)];
            $ticket = app(TicketService::class)->create(['subject' => $subject, 'description' => $description, 'requester_type' => 'customer', 'requester_id' => $order->customer_id, 'order_id' => $order->id, 'payment_id' => $order->payments->first()?->id, 'shipment_id' => Shipment::where('order_id', $order->id)->value('id'), 'priority' => $priority, 'category' => Ticket::CATEGORIES[$index % count(Ticket::CATEGORIES)], 'channel' => Ticket::CHANNELS[$index % count(Ticket::CHANNELS)], 'tags' => ['demo']], $admin);
            $status = Ticket::STATUSES[$index % 6];
            $created = now()->subHours($index % 4 === 2 ? $hours['first_response_hours'] + 2 : ($index % 4 === 1 ? $hours['first_response_hours'] * 0.9 : 0));
            $firstDue = $created->copy()->addHours($hours['first_response_hours']); $resolutionDue = $created->copy()->addHours($hours['resolution_hours']);
            $responded = in_array($status, ['in_progress', 'waiting_on_customer', 'resolved', 'closed'], true) ? $created->copy()->addMinutes(1) : null;
            if ($status === 'in_progress' && $index % 4 === 1) { $resolutionDue = now()->addMinutes(5); }
            $ticket->update(['status' => $status, 'assignee_id' => $index % 3 === 0 ? null : $agents[$index % $agents->count()]->id, 'created_at' => $created, 'first_response_due_at' => $firstDue, 'resolution_due_at' => $resolutionDue, 'first_responded_at' => $responded, 'resolved_at' => in_array($status, ['resolved', 'closed'], true) ? $created->copy()->addMinutes(2) : null, 'closed_at' => $status === 'closed' ? $created->copy()->addMinutes(3) : null, 'resolution_paused_at' => $status === 'waiting_on_customer' ? now() : null, 'csat_score' => in_array($status, ['resolved', 'closed'], true) ? 3 + $index % 3 : null]);
        }
        // Historical tickets older than the 3-year archive cutoff, so the Archived view shows real closed history.
        if (! app()->environment('testing') && $orders->isNotEmpty() && ! Ticket::where('created_at', '<', now()->subYears(3))->exists()) {
            foreach ([
                ['Legacy portal: grout color mismatch on order AN-2022-0114', 'Closed delivery complaint from the old support portal, imported for the record.'],
                ['Batch certificate request — skimcoat lot 22-089', 'Customer asked for the lot certificate; QA sent it and the ticket closed.'],
                ['Refund follow-up for order AN-2022-0207', 'Damaged pails were refunded after warehouse verification.'],
                ['Site visit outcome report — Davao warehouse project', 'Technical visit completed; application was within spec.'],
            ] as $index => [$subject, $description]) {
                $created = now()->subYears(3)->subMonths($index + 1);
                $ticket = app(TicketService::class)->create(['subject' => $subject, 'description' => $description, 'requester_type' => 'customer', 'requester_id' => $orders->first()?->customer_id, 'priority' => 'normal', 'category' => 'other', 'channel' => 'email', 'tags' => ['demo', 'legacy']], $admin);
                $ticket->update(['status' => 'closed', 'created_at' => $created, 'updated_at' => $created, 'first_response_due_at' => $created->copy()->addDay(), 'resolution_due_at' => $created->copy()->addDays(3), 'first_responded_at' => $created->copy()->addHours(3), 'resolved_at' => $created->copy()->addDays(2), 'closed_at' => $created->copy()->addDays(2)->addHour()]);
            }
        }
    }
}
