<?php
namespace Tests\Feature;
use App\Gateways\GatewayResult;
use App\Gateways\SandboxGateway;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CommissionRule;
use App\Models\Courier;
use App\Models\Customer;
use App\Models\IntegrationClient;
use App\Models\LedgerEntry;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReconciliationItem;
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
use App\Services\PaymentService;
use App\Services\ReconciliationService;
use Database\Seeders\LogisticsDemoSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;
class PaymentsTest extends TestCase {
    use RefreshDatabase;
    private User $admin;private User $finance;private string $adminToken;private string $financeToken;private IntegrationClient $client;private string $integrationToken;
    protected function setUp():void {
        parent::setUp();$this->seed(RolePermissionSeeder::class);
        CommissionRule::create(['name'=>'Test global rule','scope'=>'global','rate_bp'=>500,'fixed_fee_amount'=>0,'effective_from'=>now()->subDay()]);
        $this->admin=User::create(['name'=>'Admin','email'=>'payments.admin@example.com','password'=>'password','is_active'=>true]);$this->admin->assignRole('Admin');$this->adminToken=$this->admin->createToken('staff',['staff'])->plainTextToken;
        $this->finance=User::create(['name'=>'Accounting Staff','email'=>'payments.finance@example.com','password'=>'password','is_active'=>true]);$this->finance->assignRole('Accounting Staff');$this->financeToken=$this->finance->createToken('staff',['staff'])->plainTextToken;
        $this->client=IntegrationClient::create(['name'=>'Orders','system_code'=>'orders','is_active'=>true]);$this->integrationToken=$this->client->createToken('orders',IntegrationClient::SCOPES)->plainTextToken;
        config(['payments.sandbox_webhook_secret'=>'test-gateway-secret']);
    }
    private function asToken(string $token):self{$this->app['auth']->forgetGuards();return $this->withToken($token);}
    private function order(int $amount=10000,string $source='orders'):Order {
        $unique=Str::lower(Str::random(8));$customer=Customer::create(['source_system'=>$source,'external_id'=>'customer-'.$unique,'name'=>'Demo Customer','email'=>'customer@example.com']);$seller=Seller::create(['source_system'=>$source,'external_id'=>'seller-'.$unique,'shop_name'=>'Demo Shop']);$category=Category::create(['source_system'=>$source,'external_id'=>'category-'.$unique,'name'=>'Demo Category']);
        $order=Order::create(['source_system'=>$source,'external_id'=>'order-'.$unique,'customer_id'=>$customer->id,'subtotal_amount'=>$amount,'total_amount'=>$amount,'placed_at'=>now()]);$order->items()->create(['seller_id'=>$seller->id,'category_id'=>$category->id,'product_name'=>'Demo Item','quantity'=>1,'unit_price_amount'=>$amount,'line_total_amount'=>$amount]);return $order;
    }
    private function payment(int $amount=10000,string $method='card'):Payment{return app(PaymentService::class)->create($this->order($amount),$method)['payment'];}
    private function balance():void {
        $debits=(int)LedgerEntry::where('direction','debit')->sum('total_amount');$credits=(int)LedgerEntry::where('direction','credit')->sum('total_amount');$this->assertSame($debits,$credits);
        foreach(LedgerEntry::select('event_id')->distinct()->pluck('event_id') as $event){$this->assertSame((int)LedgerEntry::where('event_id',$event)->where('direction','debit')->sum('total_amount'),(int)LedgerEntry::where('event_id',$event)->where('direction','credit')->sum('total_amount'));}
    }
    public function test_sandbox_outcomes_capture_guard_and_idempotent_integration_creation():void {
        $order=$this->order();$first=$this->asToken($this->integrationToken)->withHeader('Idempotency-Key','payment-one')->postJson('/api/v1/integrations/payments',['order_external_id'=>$order->external_id,'method'=>'card'])->assertCreated()->assertJsonPath('payment.status','captured')->assertJsonPath('payment.amount',10000);
        $this->asToken($this->integrationToken)->withHeader('Idempotency-Key','payment-one')->postJson('/api/v1/integrations/payments',['order_external_id'=>$order->external_id,'method'=>'card'])->assertCreated()->assertExactJson($first->json());$this->assertSame(1,Payment::count());
        $this->asToken($this->integrationToken)->withHeader('Idempotency-Key','payment-two')->postJson('/api/v1/integrations/payments',['order_external_id'=>$order->external_id,'method'=>'card'])->assertUnprocessable();
        $this->assertSame('failed',$this->payment(10099)->status);$pending=$this->payment(10098);$this->assertSame('pending',$pending->status);
        $this->asToken($this->financeToken)->postJson('/api/v1/payments/'.$pending->id.'/capture')->assertOk()->assertJsonPath('status','captured');
        $this->asToken($this->financeToken)->postJson('/api/v1/payments/'.$pending->id.'/capture')->assertUnprocessable();$this->balance();
    }
    public function test_payment_lists_details_receipt_csv_and_order_ownership():void {
        $payment=$this->payment();$this->asToken($this->adminToken)->getJson('/api/v1/payments?search=PAY&per_page=999')->assertOk()->assertJsonPath('per_page',100)->assertJsonPath('total',1)->assertJsonStructure(['summary'=>['captured_today','pending','failed_today','refunds_awaiting_approval','disputes_open','unmatched_reconciliation_items']]);
        foreach(['/payments/'.$payment->id,'/payments/'.$payment->id.'/receipt','/payment-options','/ledger'] as $path){$this->asToken($this->adminToken)->getJson('/api/v1'.$path)->assertOk();}
        foreach(['payments','ledger','refunds','risk-reviews','reconciliations'] as $path){$this->asToken($this->adminToken)->get('/api/v1/'.$path.'?export=csv')->assertOk()->assertHeader('Content-Type','text/csv; charset=UTF-8');}
        $this->asToken($this->integrationToken)->getJson('/api/v1/integrations/payments/'.$payment->reference)->assertOk();$other=$this->client->replicate();$other->system_code='checkout';$other->save();$token=$other->createToken('read',['payments:read'])->plainTextToken;
        $this->asToken($token)->getJson('/api/v1/integrations/payments/'.$payment->reference)->assertNotFound();
        $this->asToken($this->adminToken)->getJson('/api/v1/payments?sort=untrusted')->assertUnprocessable();$this->asToken($this->adminToken)->getJson('/api/v1/payments?per_page=garbage')->assertUnprocessable();
    }
    public function test_risk_clear_and_capture_block_and_cancel_and_expiry_state_machine():void {
        Setting::updateOrCreate(['key'=>'risk_amount_threshold'],['value'=>5000]);$payment=$this->payment(10000);$this->assertSame('authorized',$payment->status);$this->assertSame('review',$payment->risk_level);
        $this->asToken($this->financeToken)->getJson('/api/v1/risk-reviews')->assertOk()->assertJsonPath('summary.review',1)->assertJsonStructure(['data','total','summary'=>['total','review','blocked']]);
        $this->asToken($this->financeToken)->postJson('/api/v1/payments/'.$payment->id.'/capture')->assertUnprocessable();
        $this->asToken($this->financeToken)->postJson('/api/v1/risk-reviews/'.$payment->id.'/clear')->assertOk()->assertJsonPath('risk_level','none')->assertJsonPath('status','captured')->assertJsonPath('captured_amount',10000);
        $this->assertTrue(LedgerEntry::where('payment_id',$payment->id)->where('account','gateway_clearing')->exists());$this->balance();
        $this->asToken($this->financeToken)->postJson('/api/v1/risk-reviews/'.$payment->id.'/clear')->assertUnprocessable()->assertJsonValidationErrors('status');$this->asToken($this->financeToken)->postJson('/api/v1/risk-reviews/'.$payment->id.'/block')->assertUnprocessable()->assertJsonValidationErrors('status');
        $blocked=$this->payment(10000);$this->asToken($this->financeToken)->postJson('/api/v1/risk-reviews/'.$blocked->id.'/block')->assertOk()->assertJsonPath('risk_level','blocked')->assertJsonPath('status','cancelled');
        $this->asToken($this->financeToken)->postJson('/api/v1/payments/'.$blocked->id.'/capture')->assertUnprocessable();$this->assertSame(0,LedgerEntry::where('payment_id',$blocked->id)->count());
        $pending=$this->payment(10098);$this->asToken($this->financeToken)->postJson('/api/v1/payments/'.$pending->id.'/cancel')->assertOk()->assertJsonPath('status','cancelled');
        $expired=$this->payment(10098);$this->asToken($this->financeToken)->getJson('/api/v1/risk-reviews')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$expired->id);
        $this->travel(31)->minutes();$this->assertSame(1,app(PaymentService::class)->runScheduled()['payments_expired']);$this->assertSame(0,app(PaymentService::class)->runScheduled()['payments_expired']);$this->assertSame('expired',$expired->fresh()->status);
        $this->asToken($this->financeToken)->getJson('/api/v1/risk-reviews')->assertOk()->assertJsonPath('total',0);$this->balance();
    }
    public function test_risk_clear_skips_cancelled_orders_and_block_rolls_back_on_gateway_refusal_and_emits_to_owner_only():void {
        Setting::updateOrCreate(['key'=>'risk_amount_threshold'],['value'=>5000]);
        $cancelledOrder=$this->payment(10000);$cancelledOrder->order->update(['status_snapshot'=>'cancelled']);
        $this->asToken($this->financeToken)->postJson('/api/v1/risk-reviews/'.$cancelledOrder->id.'/clear')->assertOk()->assertJsonPath('risk_level','none')->assertJsonPath('status','authorized')->assertJsonPath('captured_amount',0);
        $refused=$this->payment(10000);$this->mock(SandboxGateway::class,fn($mock)=>$mock->shouldReceive('cancel')->once()->andReturn(new GatewayResult('failed','sandbox_refused','Gateway refused')));
        $this->asToken($this->financeToken)->postJson('/api/v1/risk-reviews/'.$refused->id.'/block')->assertUnprocessable()->assertJsonValidationErrors('gateway');
        $this->assertSame(['authorized','review'],[$refused->fresh()->status,$refused->fresh()->risk_level]);
        $this->app->forgetInstance(SandboxGateway::class);
        $other=IntegrationClient::create(['name'=>'Checkout','system_code'=>'checkout','is_active'=>true]);$hooks=[];
        foreach([$this->client,$other] as $owner){$hooks[]=WebhookEndpoint::create(['client_id'=>$owner->id,'url'=>'https://8.8.8.8/'.$owner->system_code,'events'=>WebhookEndpoint::EVENTS,'secret'=>'secret-'.$owner->system_code,'is_active'=>true]);}
        $this->asToken($this->financeToken)->postJson('/api/v1/risk-reviews/'.$refused->id.'/block')->assertOk()->assertJsonPath('status','cancelled');
        $this->assertSame(['payment.cancelled'],WebhookDelivery::where('endpoint_id',$hooks[0]->id)->pluck('event_type')->all());$this->assertSame(0,WebhookDelivery::where('endpoint_id',$hooks[1]->id)->count());
        $this->asToken($this->adminToken)->postJson('/api/v1/risk-reviews/999999/clear')->assertNotFound();$this->balance();
    }
    public function test_cod_collected_requires_ready_shipments_and_a_clear_risk_review():void {
        $this->seed(LogisticsDemoSeeder::class);$payment=$this->payment(10000,'cod');$this->assertSame('pending',$payment->status);$hub=Warehouse::where('code','demo-las-pinas-hub')->firstOrFail();
        $proof=Attachment::create(['filename'=>'proof.png','mime_type'=>'image/png','size'=>1,'content'=>'x']);
        $shipment=Shipment::create(['order_id'=>$payment->order_id,'seller_id'=>$payment->order->items()->value('seller_id'),'courier_id'=>Courier::where('code','demo-courier')->value('id'),'service_type'=>'standard','origin_warehouse_id'=>$hub->id,'destination_address_id'=>$hub->address_id,'weight_grams'=>1000,'dimensions'=>[],'shipping_fee_amount'=>0,'cod_amount'=>10000,'status'=>'out_for_delivery','promised_from'=>now(),'promised_to'=>now()->addDays(3)]);
        $path='/api/v1/payments/'.$payment->id.'/cod-collected';
        $this->asToken($this->financeToken)->postJson($path,[])->assertUnprocessable()->assertJsonValidationErrors('cash_collected');
        $this->asToken($this->financeToken)->postJson($path,['cash_collected'=>true])->assertUnprocessable()->assertJsonValidationErrors('cash_collected');
        $shipment->update(['status'=>'delivered','cash_collected'=>true,'proof_attachment_id'=>$proof->id,'recipient_name'=>'Demo recipient']);$payment->update(['risk_level'=>'review']);
        $this->asToken($this->financeToken)->postJson($path,['cash_collected'=>true])->assertUnprocessable()->assertJsonValidationErrors('risk_level');$this->assertSame('pending',$payment->fresh()->status);
        $payment->update(['risk_level'=>'none']);
        $this->asToken($this->financeToken)->postJson($path,['cash_collected'=>true])->assertOk()->assertJsonPath('status','captured')->assertJsonPath('captured_amount',10000);
        $this->asToken($this->financeToken)->postJson($path,['cash_collected'=>true])->assertUnprocessable()->assertJsonValidationErrors('status');$this->balance();
    }
    public function test_refunds_reserve_balance_require_other_finance_approver_and_allow_admin_review():void {
        $payment=$this->payment();$refund=$this->asToken($this->financeToken)->postJson('/api/v1/refunds',['payment_id'=>$payment->id,'amount'=>6000,'reason'=>'other'])->assertCreated();$id=$refund->json('id');
        $this->asToken($this->adminToken)->postJson('/api/v1/refunds',['payment_id'=>$payment->id,'amount'=>4001,'reason'=>'other'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->asToken($this->financeToken)->postJson('/api/v1/refunds/'.$id.'/approve')->assertUnprocessable()->assertJsonValidationErrors('requested_by');$this->asToken($this->adminToken)->postJson('/api/v1/refunds/'.$id.'/approve')->assertOk()->assertJsonPath('status','completed');$this->assertSame('partially_refunded',$payment->fresh()->status);
        $this->asToken($this->financeToken)->postJson('/api/v1/refunds/'.$id.'/approve')->assertUnprocessable();$this->asToken($this->adminToken)->getJson('/api/v1/refunds/'.$id)->assertOk();
        $second=$this->asToken($this->financeToken)->postJson('/api/v1/refunds',['payment_id'=>$payment->id,'amount'=>4000,'reason'=>'damaged'])->assertCreated()->json('id');
        $this->asToken($this->financeToken)->postJson('/api/v1/refunds/'.$second.'/reject',['rejection_reason'=>'Insufficient evidence'])->assertUnprocessable()->assertJsonValidationErrors('requested_by');
        $this->asToken($this->adminToken)->postJson('/api/v1/refunds/'.$second.'/reject',['rejection_reason'=>'Insufficient evidence'])->assertOk()->assertJsonPath('status','rejected');
        $third=$this->asToken($this->adminToken)->postJson('/api/v1/refunds',['payment_id'=>$payment->id,'amount'=>4000,'reason'=>'other'])->assertCreated()->json('id');$this->asToken($this->adminToken)->postJson('/api/v1/refunds/'.$third.'/approve')->assertOk()->assertJsonPath('approved_by',$this->admin->id);$this->assertSame('refunded',$payment->fresh()->status);$this->balance();
    }
    public function test_a_cod_refund_waits_for_the_cash_payout_instead_of_completing_at_once():void {
        $payment=$this->payment(10000,'cod');$payment->update(['status'=>'captured','captured_amount'=>10000,'captured_at'=>now()]);
        $refund=$this->asToken($this->financeToken)->postJson('/api/v1/refunds',['payment_id'=>$payment->id,'amount'=>4000,'reason'=>'damaged'])->assertCreated()->json();
        $this->asToken($this->adminToken)->postJson('/api/v1/refunds/'.$refund['id'].'/approve')->assertOk()->assertJsonPath('status','processing');
        $this->assertSame(0,(int)$payment->fresh()->refunded_amount);
        $this->asToken($this->adminToken)->postJson('/api/v1/refunds/'.$refund['id'].'/cash-paid')->assertOk()->assertJsonPath('status','completed');
        $this->assertSame('partially_refunded',$payment->fresh()->status);$this->assertSame(4000,(int)$payment->fresh()->refunded_amount);
        $this->asToken($this->adminToken)->postJson('/api/v1/refunds/'.$refund['id'].'/cash-paid')->assertUnprocessable();
        $this->balance();
    }
    public function test_refund_outcomes_are_noted_on_the_linked_ticket_and_a_rejection_tells_the_requester():void {
        $agent=User::create(['name'=>'Agent','email'=>'payments.agent@example.com','password'=>'password','is_active'=>true]);$agent->assignRole('Administrative Assistant');
        $payment=$this->payment();
        $ticket=Ticket::create(['subject'=>'Refund please','description'=>'The item arrived damaged.','source_system'=>'orders','channel'=>'email','category'=>'refund_return','priority'=>'normal','requester_type'=>'customer','requester_id'=>$payment->customer_id,'order_id'=>$payment->order_id,'payment_id'=>$payment->id,'assignee_id'=>$agent->id,'first_response_due_at'=>now()->addHour(),'resolution_due_at'=>now()->addDay()]);
        $request=fn(int $amount)=>$this->asToken($this->financeToken)->postJson('/api/v1/refunds',['payment_id'=>$payment->id,'amount'=>$amount,'reason'=>'damaged','ticket_id'=>$ticket->id])->assertCreated()->json();
        $completed=$request(1000);$this->asToken($this->adminToken)->postJson('/api/v1/refunds/'.$completed['id'].'/approve')->assertOk()->assertJsonPath('status','completed');
        $failed=$request(1099);$this->asToken($this->adminToken)->postJson('/api/v1/refunds/'.$failed['id'].'/approve')->assertOk()->assertJsonPath('status','failed');
        $ticket->update(['assignee_id'=>$this->admin->id]); // The approver is not told about their own decision.
        $rejected=$request(500);$this->asToken($this->adminToken)->postJson('/api/v1/refunds/'.$rejected['id'].'/reject',['rejection_reason'=>'Duplicate request'])->assertOk()->assertJsonPath('status','rejected');
        $this->assertSame(['Refund '.$completed['reference'].' completed.','Refund '.$failed['reference'].' failed: Sandbox refund declined.','Refund '.$rejected['reference'].' rejected: Duplicate request.'],TicketMessage::where('ticket_id',$ticket->id)->where('author_type','system')->where('is_internal_note',true)->orderBy('id')->pluck('body')->all());
        $this->assertSame([$completed['id'],$failed['id']],Notification::where('type','ticket_refund_outcome')->where('user_id',$agent->id)->orderBy('id')->get()->pluck('data.refund_id')->all());
        $this->assertSame(['ticket_id'=>$ticket->id,'refund_id'=>$completed['id']],Notification::where('type','ticket_refund_outcome')->orderBy('id')->first()->data);
        $this->assertSame(0,Notification::where('type','ticket_refund_outcome')->where('user_id',$this->admin->id)->count());
        $this->assertSame(['refund_id'=>$rejected['id']],Notification::where('type','refund_rejected')->where('user_id',$this->finance->id)->sole()->data);
    }
    public function test_refund_outcomes_keep_ticket_notes_without_alerting_inactive_or_unauthorized_staff():void {
        $assignee=User::create(['name'=>'Former agent','email'=>'payments.former.agent@example.com','password'=>'password','is_active'=>true]);
        $payment=$this->payment();
        $ticket=Ticket::create(['subject'=>'Refund outcome','description'=>'Please review this refund.','source_system'=>'orders','channel'=>'email','category'=>'refund_return','priority'=>'normal','requester_type'=>'customer','requester_id'=>$payment->customer_id,'order_id'=>$payment->order_id,'payment_id'=>$payment->id,'assignee_id'=>$assignee->id,'first_response_due_at'=>now()->addHour(),'resolution_due_at'=>now()->addDay()]);
        $service=app(PaymentService::class);
        $first=$service->requestRefund($payment,['amount'=>1000,'reason'=>'damaged','ticket_id'=>$ticket->id],$this->finance);
        $second=$service->requestRefund($payment,['amount'=>1000,'reason'=>'damaged','ticket_id'=>$ticket->id],$this->finance);
        $this->finance->update(['is_active'=>false]);
        $service->approve($first,$this->admin);
        $this->finance->update(['is_active'=>true]);$this->finance->syncRoles([]);
        $service->reject($second,$this->admin,'Duplicate request');
        $this->assertSame(2,TicketMessage::where('ticket_id',$ticket->id)->where('is_internal_note',true)->count());
        $this->assertSame(0,Notification::whereIn('type',['refund_completed','refund_rejected','ticket_refund_outcome'])->count());
    }

    public function test_risk_auto_clear_rechecks_customer_history_and_order_status_after_candidate_selection():void {
        $history=$this->payment();$cancelledHistory=$this->payment();
        Setting::updateOrCreate(['key'=>'risk_amount_threshold'],['value'=>5000]);
        Setting::updateOrCreate(['key'=>'risk_auto_clear_min_captured'],['value'=>1]);
        $service=app(PaymentService::class);
        $order=$this->order();$order->update(['customer_id'=>$history->customer_id]);$review=$service->create($order,'card')['payment'];
        $cancelledOrder=$this->order();$cancelledOrder->update(['customer_id'=>$cancelledHistory->customer_id]);$cancelled=$service->create($cancelledOrder,'card')['payment'];
        $dispatcher=Payment::getEventDispatcher();Payment::setEventDispatcher(clone $dispatcher);
        $changed=[];
        Payment::retrieved(function(Payment $row)use($history,$review,$cancelled,$cancelledOrder,&$changed){
            if(array_key_exists('status',$row->getAttributes())||isset($changed[$row->id]))return;
            if($row->id===$review->id){$changed[$row->id]=true;Payment::whereKey($history->id)->update(['status'=>'dispute_lost']);}
            if($row->id===$cancelled->id){$changed[$row->id]=true;Order::whereKey($cancelledOrder->id)->update(['status_snapshot'=>'cancelled']);}
        });
        try{$this->assertSame(0,$service->runScheduled()['risk_auto_cleared']);}finally{Payment::setEventDispatcher($dispatcher);}
        $this->assertCount(2,$changed,'Both records changed after their candidate snapshot was selected.');
        foreach([$review,$cancelled] as $payment){$this->assertSame(['authorized','review',0],[$payment->fresh()->status,$payment->fresh()->risk_level,$payment->fresh()->captured_amount]);}
        $this->assertSame(0,AuditLog::where('new_values->automation','risk_auto_clear')->count());
        $this->balance();
    }

    public function test_one_payment_expiry_failure_does_not_prevent_other_due_payments_from_expiring():void {
        $blocked=$this->payment(10098);$due=$this->payment(20098);$this->travelTo($due->expires_at);
        $dispatcher=Payment::getEventDispatcher();Payment::setEventDispatcher(clone $dispatcher);
        Payment::updating(function(Payment $row)use($blocked){if($row->id===$blocked->id&&$row->status==='expired')throw new LogicException('Test expiry failure');});
        try{$this->assertSame(1,app(PaymentService::class)->runScheduled()['payments_expired']);}finally{Payment::setEventDispatcher($dispatcher);}
        $this->assertSame(['pending','expired'],[$blocked->fresh()->status,$due->fresh()->status]);
    }

    public function test_support_agents_with_custom_finance_permissions_still_obey_payment_ownership():void {
        $agent=User::create(['name'=>'Custom Finance Agent','email'=>'payments.custom.agent@example.com','password'=>'password','is_active'=>true]);$agent->assignRole('Administrative Assistant');
        $other=User::create(['name'=>'Other Agent','email'=>'payments.other.agent@example.com','password'=>'password','is_active'=>true]);$other->assignRole('Administrative Assistant');
        $token=$agent->createToken('staff',['staff'])->plainTextToken;
        [$own,$unassigned,$hidden]=array_map(fn()=> $this->payment(10098),range(1,3));
        $assignees=[$agent->id,null,$other->id];
        foreach([$own,$unassigned,$hidden] as $index=>$payment){
            $payment->update(['risk_level'=>'review']);
            Ticket::create(['subject'=>'Payment ownership '.$index,'description'=>'Review this payment.','source_system'=>'orders','channel'=>'email','category'=>'payment','priority'=>'normal','requester_type'=>'customer','requester_id'=>$payment->customer_id,'order_id'=>$payment->order_id,'payment_id'=>$payment->id,'assignee_id'=>$assignees[$index],'first_response_due_at'=>now()->addHour(),'resolution_due_at'=>now()->addDay()]);
        }
        $paths=fn(Payment $payment)=>['/payments/'.$payment->id.'/capture','/payments/'.$payment->id.'/cancel','/payments/'.$payment->id.'/cod-collected','/risk-reviews/'.$payment->id.'/clear','/risk-reviews/'.$payment->id.'/block'];
        foreach($paths($own) as $path)$this->asToken($token)->postJson('/api/v1'.$path,['cash_collected'=>true])->assertForbidden();
        $this->asToken($token)->getJson('/api/v1/risk-reviews')->assertForbidden();
        $agent->givePermissionTo('edit payments','approve refunds');
        foreach($paths($hidden) as $path)$this->asToken($token)->postJson('/api/v1'.$path,['cash_collected'=>true])->assertNotFound();
        $this->asToken($token)->getJson('/api/v1/risk-reviews')->assertOk()->assertJsonPath('total',2)->assertJsonPath('summary.review',2);
        $csv=$this->asToken($token)->get('/api/v1/risk-reviews?export=csv')->assertOk()->streamedContent();
        $this->assertStringNotContainsString($hidden->reference,$csv);$this->assertStringContainsString($own->reference,$csv);$this->assertStringContainsString($unassigned->reference,$csv);
        foreach([$own,$unassigned] as $payment){
            $this->asToken($token)->postJson('/api/v1/risk-reviews/'.$payment->id.'/clear')->assertOk()->assertJsonPath('risk_level','none');
            $this->asToken($token)->postJson('/api/v1/payments/'.$payment->id.'/capture')->assertOk()->assertJsonPath('status','captured');
        }
        $hidden->update(['risk_level'=>'none']);$hidden=app(PaymentService::class)->capture($hidden);
        $refunds=array_map(fn(Payment $payment)=>app(PaymentService::class)->requestRefund($payment->fresh(),['amount'=>1000,'reason'=>'damaged'],$this->finance),[$own,$unassigned,$hidden]);
        $this->asToken($token)->postJson('/api/v1/refunds/'.$refunds[2]->id.'/approve')->assertNotFound();
        $this->asToken($token)->postJson('/api/v1/refunds/'.$refunds[2]->id.'/reject',['rejection_reason'=>'Needs evidence'])->assertNotFound();
        $this->asToken($token)->postJson('/api/v1/refunds/'.$refunds[0]->id.'/approve')->assertOk()->assertJsonPath('status','completed');
        $this->asToken($token)->postJson('/api/v1/refunds/'.$refunds[1]->id.'/reject',['rejection_reason'=>'Needs evidence'])->assertOk()->assertJsonPath('status','rejected');
        $this->assertSame('requested',$refunds[2]->fresh()->status);
        $this->balance();
    }
    public function test_admin_can_reject_own_refund_with_reason_and_cannot_repeat_review():void {
        $payment=$this->payment();$id=$this->asToken($this->adminToken)->postJson('/api/v1/refunds',['payment_id'=>$payment->id,'amount'=>1000,'reason'=>'other'])->assertCreated()->json('id');
        $this->asToken($this->adminToken)->postJson('/api/v1/refunds/'.$id.'/reject',[])->assertUnprocessable()->assertJsonValidationErrors('rejection_reason');
        $this->asToken($this->adminToken)->postJson('/api/v1/refunds/'.$id.'/reject',['rejection_reason'=>'Request no longer needed'])->assertOk()->assertJsonPath('status','rejected')->assertJsonPath('approved_by',$this->admin->id);
        $this->asToken($this->adminToken)->postJson('/api/v1/refunds/'.$id.'/approve')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(0,$payment->fresh()->refunded_amount);$this->balance();
        $this->assertDatabaseHas('audit_logs',['user_id'=>$this->admin->id,'entity_type'=>'Refund','entity_id'=>(string)$id,'action'=>'updated']);
    }
    public function test_integration_refunds_scope_idempotency_and_failed_refund_releases_reservation():void {
        $payment=$this->payment();$first=$this->asToken($this->integrationToken)->withHeader('Idempotency-Key','refund-one')->postJson('/api/v1/integrations/refunds',['payment_reference'=>$payment->reference,'amount'=>999,'reason'=>'other'])->assertCreated();
        $this->asToken($this->integrationToken)->withHeader('Idempotency-Key','refund-one')->postJson('/api/v1/integrations/refunds',['payment_reference'=>$payment->reference,'amount'=>999,'reason'=>'other'])->assertCreated()->assertExactJson($first->json());
        $this->asToken($this->financeToken)->postJson('/api/v1/refunds/'.$first->json('id').'/approve')->assertOk()->assertJsonPath('status','failed');$this->assertSame(0,$payment->fresh()->refunded_amount);
        $this->asToken($this->integrationToken)->getJson('/api/v1/integrations/refunds/'.$first->json('reference'))->assertOk();$this->balance();
    }
    public function test_gateway_signature_replay_and_dispute_lifecycle_are_balanced():void {
        $payment=$this->payment(10098);$payload=['event_id'=>'gateway-one','reference'=>$payment->reference,'type'=>'payment.captured','amount'=>10098];$body=json_encode($payload);$timestamp=now()->timestamp;$signature='t='.$timestamp.',v1='.hash_hmac('sha256',$timestamp.'.'.$body,'test-gateway-secret');
        $this->withoutToken()->withHeader('X-SFA-Signature','bad')->postJson('/api/v1/webhooks/payments/sandbox',$payload)->assertUnauthorized();
        $first=$this->withHeader('X-SFA-Signature',$signature)->postJson('/api/v1/webhooks/payments/sandbox',$payload)->assertOk()->assertJsonPath('processed',true);$this->withHeader('X-SFA-Signature',$signature)->postJson('/api/v1/webhooks/payments/sandbox',$payload)->assertOk()->assertJsonPath('processed',false);$this->assertDatabaseCount('gateway_events',1);
        $this->asToken($this->adminToken)->postJson('/api/v1/payments/'.$payment->id.'/simulate-webhook',['type'=>'payment.disputed'])->assertOk()->assertJsonPath('status','disputed');$this->asToken($this->adminToken)->postJson('/api/v1/payments/'.$payment->id.'/simulate-webhook',['type'=>'payment.dispute_won'])->assertOk()->assertJsonPath('status','dispute_won');$this->balance();
    }
    public function test_reconciliation_matches_mismatches_missing_rows_and_resolves_once():void {
        $matched=$this->payment(10000);$missing=$this->payment(20000);$date=now()->timezone('Asia/Manila')->toDateString();$csv="date,gateway_reference,amount,fee\n{$date},{$matched->gateway_reference},100.00,0.00\n{$date},unknown,5.00,0.00\n";
        $run=$this->asToken($this->financeToken)->post('/api/v1/reconciliations',['date_from'=>$date,'date_to'=>$date,'file'=>UploadedFile::fake()->createWithContent('settlement.csv',$csv)],['Accept'=>'application/json'])->assertCreated();$id=$run->json('id');$this->assertSame(['matched','missing_in_sfa','missing_in_report'],array_column($run->json('items'),'status'));
        $this->asToken($this->adminToken)->getJson('/api/v1/reconciliations/'.$id)->assertOk();$item=$run->json('items.1.id');$this->asToken($this->financeToken)->postJson('/api/v1/reconciliation-items/'.$item.'/resolve',['notes'=>'Confirmed outside this marketplace.'])->assertOk();$this->asToken($this->financeToken)->postJson('/api/v1/reconciliation-items/'.$item.'/resolve',['notes'=>'Again'])->assertUnprocessable();
        $this->asToken($this->financeToken)->post('/api/v1/reconciliations',['date_from'=>$date,'date_to'=>$date,'file'=>UploadedFile::fake()->createWithContent('bad.csv',"date,gateway_reference,amount,fee\nbad,foo,1.00,0.00\n")],['Accept'=>'application/json'])->assertUnprocessable();
    }
    public function test_a_later_file_auto_resolves_earlier_missing_rows_but_never_a_mismatch():void {
        $late=$this->payment(10000);$short=$this->payment(20000);$date=now()->timezone('Asia/Manila')->toDateString();
        $import=fn(string $name,string $rows)=>$this->asToken($this->financeToken)->post('/api/v1/reconciliations',['date_from'=>$date,'date_to'=>$date,'file'=>UploadedFile::fake()->createWithContent($name,"date,gateway_reference,amount,fee\n".$rows)],['Accept'=>'application/json'])->assertCreated();
        $first=$import('first.csv',"{$date},unknown,1.00,0.00\n");$this->assertEqualsCanonicalizing(['missing_in_sfa','missing_in_report','missing_in_report'],array_column($first->json('items'),'status'));
        $import('later.csv',"{$date},{$late->gateway_reference},100.00,0.00\n{$date},{$short->gateway_reference},199.00,0.00\n");
        $rows=ReconciliationItem::where('reconciliation_id',$first->json('id'))->where('status','missing_in_report')->get()->keyBy('payment_id');
        $this->assertNull($rows[$late->id]->resolved_by);$this->assertNotNull($rows[$late->id]->resolved_at);$this->assertStringContainsString('later.csv',$rows[$late->id]->resolution_notes);
        $this->assertNull($rows[$short->id]->resolved_at,'An amount mismatch never resolves automatically.');
        $audit=AuditLog::where('entity_type','ReconciliationItem')->whereNull('user_id')->sole();$this->assertSame([(string)$rows[$late->id]->id,'reconciliation_auto_resolve'],[$audit->entity_id,$audit->new_values['automation']]);
        $list=$this->asToken($this->financeToken)->getJson('/api/v1/reconciliations')->assertOk()->assertJsonStructure(['data','total','summary'=>['total','unmatched_items']]);$this->assertSame(2,collect($list->json('data'))->firstWhere('id',$first->json('id'))['unmatched_count'],'The unknown row and the short payment stay unmatched.');
        $this->asToken($this->financeToken)->postJson('/api/v1/reconciliation-items/'.$rows[$late->id]->id.'/resolve',['notes'=>'Too late'])->assertUnprocessable()->assertJsonValidationErrors('item');
        $this->asToken($this->financeToken)->postJson('/api/v1/reconciliation-items/'.$rows[$short->id]->id.'/resolve',['notes'=>''])->assertUnprocessable()->assertJsonValidationErrors('notes');
        $this->asToken($this->financeToken)->postJson('/api/v1/reconciliation-items/'.$rows[$short->id]->id.'/resolve',['notes'=>'Short-paid by the gateway; claim filed.'])->assertOk()->assertJsonPath('resolved_by',$this->finance->id);
    }
    public function test_mirror_sync_validates_totals_and_locks_paid_order_items():void {
        foreach([['customers','customer-x',['name'=>'Customer X']],['sellers','seller-x',['shop_name'=>'Seller X']],['categories','category-x',['name'=>'Category X']]] as [$kind,$id,$data]){$this->asToken($this->integrationToken)->putJson('/api/v1/integrations/'.$kind.'/'.$id,$data)->assertCreated();}
        $values=['customer_external_id'=>'customer-x','status_snapshot'=>'placed','subtotal_amount'=>10000,'shipping_amount'=>0,'discount_amount'=>0,'total_amount'=>10000,'placed_at'=>now()->toIso8601String(),'items'=>[['seller_external_id'=>'seller-x','category_external_id'=>'category-x','product_name'=>'Product X','quantity'=>2,'unit_price_amount'=>5000,'line_total_amount'=>10000]]];
        $this->asToken($this->integrationToken)->putJson('/api/v1/integrations/orders/order-x',$values)->assertCreated();$this->asToken($this->integrationToken)->putJson('/api/v1/integrations/orders/order-x',array_merge($values,['total_amount'=>9999]))->assertUnprocessable();
        $this->asToken($this->integrationToken)->withHeader('Idempotency-Key','mirror-payment')->postJson('/api/v1/integrations/payments',['order_external_id'=>'order-x','method'=>'card'])->assertCreated();$values['items'][0]['product_name']='Changed';$this->asToken($this->integrationToken)->putJson('/api/v1/integrations/orders/order-x',$values)->assertUnprocessable();$this->asToken($this->integrationToken)->postJson('/api/v1/integrations/orders/order-x/cancel')->assertUnprocessable();
    }
    public function test_order_upsert_to_cancelled_follows_the_cancel_rules_and_a_cancelled_order_stays_cancelled():void {
        $seed=$this->order();$line=$seed->items()->with(['seller','category'])->firstOrFail();
        $put=fn(string $id,string $status)=>$this->asToken($this->integrationToken)->putJson('/api/v1/integrations/orders/'.$id,['customer_external_id'=>$seed->customer->external_id,'status_snapshot'=>$status,'subtotal_amount'=>10000,'shipping_amount'=>0,'discount_amount'=>0,'total_amount'=>10000,'placed_at'=>now()->toIso8601String(),'items'=>[['seller_external_id'=>$line->seller->external_id,'category_external_id'=>$line->category->external_id,'product_name'=>'Product X','quantity'=>1,'unit_price_amount'=>10000,'line_total_amount'=>10000]]]);
        $status=fn(string $id)=>Order::where('source_system','orders')->where('external_id',$id)->value('status_snapshot');
        // Captured money needs a refund, as POST /integrations/orders/{id}/cancel says; nothing changes.
        $put('order-upsert-paid','placed')->assertCreated();$paid=app(PaymentService::class)->create(Order::where('external_id','order-upsert-paid')->firstOrFail(),'card')['payment'];$this->assertSame('captured',$paid->status);
        $put('order-upsert-paid','cancelled')->assertUnprocessable()->assertJsonValidationErrors('order');$this->assertSame('placed',$status('order-upsert-paid'));
        // An open payment is cancelled with the order, as the cancel route does.
        $put('order-upsert-open','placed')->assertCreated();$open=app(PaymentService::class)->create(Order::where('external_id','order-upsert-open')->firstOrFail(),'cod')['payment'];$this->assertSame('pending',$open->status);
        $put('order-upsert-open','cancelled')->assertOk()->assertJsonPath('status_snapshot','cancelled');$this->assertSame('cancelled',$open->fresh()->status);
        $put('order-upsert-open','cancelled')->assertOk()->assertJsonPath('status_snapshot','cancelled');
        // No other status reopens a cancelled order, with or without a payment attempt.
        $put('order-upsert-open','placed')->assertUnprocessable()->assertJsonValidationErrors('status_snapshot');$this->assertSame('cancelled',$status('order-upsert-open'));
        $put('order-upsert-new','cancelled')->assertCreated();$put('order-upsert-new','shipped')->assertUnprocessable()->assertJsonValidationErrors('status_snapshot');$this->assertSame('cancelled',$status('order-upsert-new'));
    }
    public function test_reconciliation_expects_only_gateway_captures_never_cash_on_delivery():void {
        $card=$this->payment(10000);$cod=$this->payment(15000,'cod');$cod->update(['status'=>'captured','captured_amount'=>15000,'captured_at'=>now()]);$date=now()->timezone('Asia/Manila')->toDateString();
        $run=app(ReconciliationService::class)->import(UploadedFile::fake()->createWithContent('gateway.csv',"date,gateway_reference,amount,fee\n{$date},unknown,1.00,0.00\n"),$date,$date,$this->finance);
        $missing=$run->items->where('status','missing_in_report');
        $this->assertSame([$card->id],$missing->pluck('payment_id')->values()->all(),'The gateway never handles COD cash, so its report cannot omit it.');
    }
    public function test_every_payment_staff_endpoint_requires_auth_and_server_permission():void {
        $payment=$this->payment();$refund=app(PaymentService::class)->requestRefund($payment,['amount'=>100,'reason'=>'other'],$this->admin);$run=app(ReconciliationService::class)->import(UploadedFile::fake()->createWithContent('demo.csv',"date,gateway_reference,amount,fee\n".now()->toDateString().",none,1.00,0.00\n"),now()->toDateString(),now()->toDateString(),$this->finance);
        $staff=User::create(['name'=>'No access','email'=>'no.payments@example.com','password'=>'password','is_active'=>true]);$token=$staff->createToken('staff',['staff'])->plainTextToken;
        $routes=[['GET','/payment-options'],['GET','/payments'],['GET',"/payments/{$payment->id}"],['GET',"/payments/{$payment->id}/receipt"],['POST',"/payments/{$payment->id}/capture"],['POST',"/payments/{$payment->id}/cancel"],['POST',"/payments/{$payment->id}/simulate-webhook"],['POST',"/payments/{$payment->id}/cod-collected"],['GET','/refunds'],['POST','/refunds'],['GET',"/refunds/{$refund->id}"],['POST',"/refunds/{$refund->id}/approve"],['POST',"/refunds/{$refund->id}/reject"],['GET','/ledger'],['GET','/reconciliations'],['POST','/reconciliations'],['GET',"/reconciliations/{$run->id}"],['POST','/reconciliation-items/'.$run->items[0]->id.'/resolve'],['GET','/risk-reviews'],['POST',"/risk-reviews/{$payment->id}/clear"],['POST',"/risk-reviews/{$payment->id}/block"]];
        foreach($routes as [$method,$path]){$this->app['auth']->forgetGuards();$this->withoutToken()->json($method,'/api/v1'.$path)->assertUnauthorized();$this->asToken($token)->json($method,'/api/v1'.$path)->assertForbidden();}
    }
    public function test_reconciliation_import_and_resolve_need_a_finance_role_not_just_the_permission():void {
        $this->payment();$date=now()->timezone('Asia/Manila')->toDateString();$csv="date,gateway_reference,amount,fee\n{$date},unknown,1.00,0.00\n";
        $run=app(ReconciliationService::class)->import(UploadedFile::fake()->createWithContent('finance.csv',$csv),$date,$date,$this->finance);
        $agent=User::create(['name'=>'Agent with edit payments','email'=>'payments.reconcile.agent@example.com','password'=>'password','is_active'=>true]);$agent->assignRole('Administrative Assistant');$agent->givePermissionTo('edit payments');$token=$agent->createToken('staff',['staff'])->plainTextToken;
        $this->asToken($token)->post('/api/v1/reconciliations',['date_from'=>$date,'date_to'=>$date,'file'=>UploadedFile::fake()->createWithContent('agent.csv',$csv)],['Accept'=>'application/json'])->assertForbidden();
        $this->asToken($token)->postJson('/api/v1/reconciliation-items/'.$run->items[0]->id.'/resolve',['notes'=>'Not mine to resolve'])->assertForbidden();
        $this->assertDatabaseCount('reconciliations',1);$this->assertNull($run->items[0]->fresh()->resolved_at);
    }
    public function test_risk_review_queue_filters_by_risk_level():void {
        Setting::updateOrCreate(['key'=>'risk_amount_threshold'],['value'=>5000]);$review=$this->payment(10000);$blocked=$this->payment(10000,'cod');
        $this->asToken($this->financeToken)->postJson('/api/v1/risk-reviews/'.$blocked->id.'/block')->assertOk()->assertJsonPath('status','pending')->assertJsonPath('risk_level','blocked');
        $this->asToken($this->financeToken)->getJson('/api/v1/risk-reviews?risk_level=blocked')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$blocked->id)->assertJsonPath('summary.review',0);
        $this->asToken($this->financeToken)->getJson('/api/v1/risk-reviews?risk_level=review')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$review->id);
        $this->asToken($this->financeToken)->getJson('/api/v1/risk-reviews?risk_level=none')->assertUnprocessable()->assertJsonValidationErrors('risk_level');
    }
    public function test_a_failed_finance_reminder_is_rolled_back_and_retried_without_blocking_the_others():void {
        $payment=$this->payment();$service=app(PaymentService::class);
        [$broken,$other]=array_map(fn()=>$service->requestRefund($payment,['amount'=>1000,'reason'=>'other'],$this->finance),[1,2]);
        $this->travel(5)->hours();$overdue=fn(Refund $refund)=>Notification::where('type','refund_approval_overdue')->where('data->refund_id',$refund->id)->count();
        $dispatcher=Notification::getEventDispatcher();Notification::setEventDispatcher(clone $dispatcher);
        // The Admin's alert is written first; the Finance one then fails, so the Admin's must roll back with it.
        Notification::creating(function(Notification $row)use($broken){if($row->type==='refund_approval_overdue'&&$row->data['refund_id']===$broken->id&&$row->user_id===$this->finance->id)throw new LogicException('Test notification failure');});
        try{$result=$service->runScheduled();}finally{Notification::setEventDispatcher($dispatcher);}
        $this->assertSame(2,$result['finance_reminders_sent'],'The other refund still reminds both approvers.');
        $this->assertSame([0,2],[$overdue($broken),$overdue($other)],'The failed record keeps no partial alerts.');
        $this->assertSame(2,$service->runScheduled()['finance_reminders_sent'],'The next run retries it.');
        $this->assertSame(2,$overdue($broken));
    }
    public function test_small_staff_refunds_are_approved_automatically_only_within_the_policy():void {
        $request=fn(string $token,Payment $payment,int $amount,string $reason='damaged',array $extra=[])=>$this->asToken($token)->postJson('/api/v1/refunds',['payment_id'=>$payment->id,'amount'=>$amount,'reason'=>$reason,...$extra])->assertCreated();
        $request($this->financeToken,$this->payment(100000),1000)->assertJsonPath('status','requested')->assertJsonPath('approved_at',null);
        foreach(['refund_auto_approve_cap_amount'=>50000,'refund_auto_approve_daily_limit'=>100000,'refund_auto_approve_reasons'=>['damaged']] as $key=>$value){Setting::updateOrCreate(['key'=>$key],['value'=>$value]);}
        [$first,$second,$third,$fourth,$fifth,$integration]=array_map(fn()=>$this->payment(100000),range(1,6));
        $ticket=Ticket::create(['subject'=>'Damaged item','description'=>'Arrived broken.','source_system'=>'orders','channel'=>'email','category'=>'refund_return','priority'=>'normal','requester_type'=>'customer','requester_id'=>$first->customer_id,'order_id'=>$first->order_id,'payment_id'=>$first->id,'assignee_id'=>$this->admin->id,'first_response_due_at'=>now()->addHour(),'resolution_due_at'=>now()->addDay()]);
        $auto=$request($this->financeToken,$first,40000,'damaged',['ticket_id'=>$ticket->id])->assertJsonPath('status','completed')->assertJsonPath('approved_by',null)->json();
        $this->assertNotNull($auto['approved_at']);$this->assertSame(['partially_refunded',40000],[$first->fresh()->status,$first->fresh()->refunded_amount]);
        $this->assertTrue(LedgerEntry::where('refund_id',$auto['id'])->where('memo',$auto['reference'].' completed')->exists());$this->assertFalse(LedgerEntry::where('refund_id',$auto['id'])->whereNotNull('created_by')->exists());
        $audit=AuditLog::where('new_values->automation','refund_auto_approve')->sole();$this->assertSame([(string)$auto['id'],null,'completed'],[$audit->entity_id,$audit->user_id,$audit->new_values['status']]);
        $this->assertSame(0,Notification::where('type','refund_awaiting_approval')->where('data->refund_id',$auto['id'])->count());
        $this->assertSame('Refund '.$auto['reference'].' approved automatically and completed.',TicketMessage::where('ticket_id',$ticket->id)->where('author_type','system')->sole()->body);
        $this->assertSame('Refund approved automatically and completed',Notification::where('type','ticket_refund_outcome')->where('user_id',$this->admin->id)->sole()->title);
        $request($this->financeToken,$first,1000)->assertJsonPath('status','requested'); // One automatic approval per payment.
        $request($this->financeToken,$second,50001)->assertJsonPath('status','requested'); // Over the cap.
        $request($this->financeToken,$second,1000,'other')->assertJsonPath('status','requested'); // Reason not listed.
        $request($this->financeToken,$third,50000)->assertJsonPath('status','completed'); // 90,000 of the 100,000 daily limit.
        $request($this->financeToken,$fourth,20000)->assertJsonPath('status','requested'); // Would pass the daily limit.
        $request($this->adminToken,$fourth,20000)->assertJsonPath('status','completed'); // The limit is per requester.
        $request($this->financeToken,$fifth,1099)->assertJsonPath('status','failed'); // A gateway decline moved no money...
        $request($this->financeToken,$fifth,1000)->assertJsonPath('status','completed'); // ...so it counts toward neither limit.
        $external=$this->asToken($this->integrationToken)->withHeader('Idempotency-Key','auto-refund')->postJson('/api/v1/integrations/refunds',['payment_reference'=>$integration->reference,'amount'=>1000,'reason'=>'damaged'])->assertCreated()->assertJsonPath('status','requested')->json('id');
        $this->assertSame(2,Notification::where('type','refund_awaiting_approval')->where('data->refund_id',$external)->count(),'Integration refunds always wait for an approver.');
        $this->travel(1)->days();$request($this->financeToken,$this->payment(100000),20000)->assertJsonPath('status','completed'); // A new business day.
        $this->balance();
    }
    public function test_refund_auto_approve_and_review_hold_settings_validate_and_reach_payment_options():void {
        $this->asToken($this->adminToken)->getJson('/api/v1/settings')->assertOk()->assertJsonPath('refund_auto_approve_cap_amount',0)->assertJsonPath('refund_auto_approve_daily_limit',200000)->assertJsonPath('risk_review_hold_hours',0)->assertJsonPath('refund_auto_approve_reasons',['item_not_received','damaged','wrong_item','cancelled_order','duplicate_charge']);
        $this->asToken($this->adminToken)->putJson('/api/v1/settings',['refund_auto_approve_cap_amount'=>-1,'refund_auto_approve_daily_limit'=>0,'refund_auto_approve_reasons'=>['damaged','unknown'],'risk_review_hold_hours'=>169])->assertUnprocessable()->assertJsonValidationErrors(['refund_auto_approve_cap_amount','refund_auto_approve_daily_limit','refund_auto_approve_reasons.1','risk_review_hold_hours']);
        $this->asToken($this->adminToken)->putJson('/api/v1/settings',['refund_auto_approve_reasons'=>'damaged','risk_review_hold_hours'=>1.5])->assertUnprocessable()->assertJsonValidationErrors(['refund_auto_approve_reasons','risk_review_hold_hours']);
        $this->asToken($this->financeToken)->putJson('/api/v1/settings',['risk_review_hold_hours'=>2])->assertForbidden();
        $this->asToken($this->adminToken)->putJson('/api/v1/settings',['refund_auto_approve_cap_amount'=>50000,'refund_auto_approve_daily_limit'=>100000,'refund_auto_approve_reasons'=>[],'risk_review_hold_hours'=>24])->assertOk()->assertJsonPath('refund_auto_approve_reasons',[])->assertJsonPath('risk_review_hold_hours',24);
        $this->asToken($this->financeToken)->getJson('/api/v1/payment-options')->assertOk()->assertJsonStructure(['payments','orders'])->assertJsonPath('refund_auto_approve_cap_amount',50000)->assertJsonPath('refund_auto_approve_daily_limit',100000)->assertJsonPath('refund_auto_approve_reasons',[]);
        $payment=$this->payment();$this->asToken($this->financeToken)->postJson('/api/v1/refunds',['payment_id'=>$payment->id,'amount'=>1000,'reason'=>'damaged'])->assertCreated()->assertJsonPath('status','requested'); // No reason listed.
    }
    public function test_risk_review_hold_moves_the_expiry_then_expires_after_the_hold():void {
        Setting::updateOrCreate(['key'=>'risk_amount_threshold'],['value'=>5000]);Setting::updateOrCreate(['key'=>'risk_review_hold_hours'],['value'=>2]);
        $review=$this->payment(10098);$plain=$this->payment(4098);$this->assertSame(['review','none'],[$review->risk_level,$plain->risk_level]);$service=app(PaymentService::class);
        $this->travel(31)->minutes();$run=$service->runScheduled();
        $this->assertSame([1,1],[$run['payments_expired'],$run['payments_held_for_review']]);$this->assertSame(['pending','expired'],[$review->fresh()->status,$plain->fresh()->status]);
        $this->assertTrue($review->fresh()->expires_at->equalTo($review->created_at->copy()->addHours(2)));
        $again=$service->runScheduled();$this->assertSame([0,0],[$again['payments_expired'],$again['payments_held_for_review']]);
        $this->asToken($this->financeToken)->getJson('/api/v1/risk-reviews')->assertOk()->assertJsonPath('total',1)->assertJsonPath('data.0.id',$review->id);
        $this->travel(90)->minutes();$run=$service->runScheduled();$this->assertSame([1,0],[$run['payments_expired'],$run['payments_held_for_review']]);
        $this->assertSame(['expired','Payment expired after its 2-hour risk-review hold.'],[$review->fresh()->status,$review->fresh()->failure_reason]);
    }
    public function test_ledger_is_append_only():void{$this->payment();$entry=LedgerEntry::firstOrFail();$this->expectException(LogicException::class);$entry->update(['total_amount'=>1]);}
}
