<?php
namespace App\Services;

use App\Gateways\CodGateway;
use App\Gateways\GatewayEvent as ParsedGatewayEvent;
use App\Gateways\PaymentGateway;
use App\Gateways\SandboxGateway;
use App\Models\AuditLog;
use App\Models\GatewayEvent;
use App\Models\IntegrationClient;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Support\Reference;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    /**
     * The gateway events processEvent applies, by the payment statuses each one needs. payment.captured also needs no risk
     * review and no other captured payment on the order (captureLocked). processEvent is the owner's code and keeps its own
     * checks; SimulatorTest sends every event to every status so the two cannot drift apart.
     */
    public const GATEWAY_EVENT_STATUSES = ['payment.authorized'=>['pending'],'payment.captured'=>['pending','authorized'],'payment.failed'=>['pending','authorized'],'payment.cancelled'=>['pending','authorized'],'payment.disputed'=>['captured','partially_refunded'],'payment.dispute_won'=>['disputed'],'payment.dispute_lost'=>['disputed']];
    public const REFUNDABLE_STATUSES = ['captured','partially_refunded'];
    // Refunds that still hold part of the captured balance.
    public const RESERVING_REFUND_STATUSES = ['requested','approved','processing'];

    public function __construct(private readonly LedgerService $ledger, private readonly WebhookService $webhooks) {}

    /** Why create() would refuse a new payment for this order, as [field, message], or null when it would accept one. */
    public function paymentBlock(Order $order):?array {
        if($order->status_snapshot==='cancelled'){return ['order','Cancelled orders cannot receive payments.'];}
        if($order->payments->contains(fn(Payment $payment)=>$payment->captured_amount>0)){return ['order','This order already has a captured payment.'];}
        return $order->total_amount>0?null:['amount','The order total must be positive.'];
    }

    /** What a new refund of this payment may take: captured less refunded and less the refunds still waiting; 0 when it cannot be refunded. */
    public function refundableAmount(Payment $payment):int {
        if(!in_array($payment->status,self::REFUNDABLE_STATUSES,true)){return 0;}
        // Eager-loaded refunds are summed in memory (the simulator lists many payments); otherwise one query.
        $refunds=$payment->relationLoaded('refunds')?$payment->refunds:$payment->refunds();
        return max(0,$payment->captured_amount-$payment->refunded_amount-(int)$refunds->whereIn('status',self::RESERVING_REFUND_STATUSES)->sum('total_amount'));
    }

    /** The sandbox gateway events processEvent would apply to this payment now (see GATEWAY_EVENT_STATUSES). */
    public function gatewayEvents(Payment $payment):array {
        if($payment->gateway!=='sandbox'){return [];}
        $siblingCaptured=$payment->order_id&&$payment->order->payments->contains(fn(Payment $other)=>$other->id!==$payment->id&&$other->captured_amount>0);
        return array_keys(array_filter(self::GATEWAY_EVENT_STATUSES,fn(array $statuses,string $type)=>in_array($payment->status,$statuses,true)
            &&($type!=='payment.captured'||($payment->risk_level==='none'&&!$siblingCaptured)),ARRAY_FILTER_USE_BOTH));
    }

    public function gateway(string $name):PaymentGateway {
        return match($name){'sandbox'=>app(SandboxGateway::class),'cod'=>app(CodGateway::class),default=>throw ValidationException::withMessages(['gateway'=>['This payment gateway is not configured.']])};
    }

    public function create(Order $order,string $method):array {
        return DB::transaction(function()use($order,$method){
            $order=Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if($block=$this->paymentBlock($order)){$this->check(false,...$block);}
            $payment=Payment::create(['order_id'=>$order->id,'customer_id'=>$order->customer_id,'method'=>$method,'gateway'=>$method==='cod'?'cod':'sandbox','total_amount'=>$order->total_amount,'expires_at'=>$method==='cod'?null:now()->addMinutes(30)]);
            Reference::assign($payment,'PAY');
            $risk=$this->risk($payment);
            $payment->update(['risk_level'=>$risk]);
            $result=$this->gateway($payment->gateway)->createPayment($payment);
            $payment->update(['gateway_reference'=>$result->reference]);
            if($result->status==='failed'){$this->fail($payment,$result->failureReason ?: 'Payment failed');}
            elseif($result->status==='authorized'){
                $payment->update(['status'=>'authorized','authorized_at'=>now()]); $this->emit('payment.authorized',$payment);
                if($risk==='none'){$payment=$this->captureLocked($payment,$payment->amount);}
            }
            if($risk==='review'){$this->notifyPermission('edit payments','risk_review_needed','Payment needs risk review',$payment->reference.' requires a Finance risk review.',['payment_id'=>$payment->id]);}
            return ['payment'=>$payment->fresh(['order.items','customer']),'next_action'=>$result->nextAction];
        });
    }

    public function capture(Payment $payment,?int $amount=null):Payment {
        return DB::transaction(function()use($payment,$amount){
            if($payment->order_id){Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();}
            $payment=Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->check($payment->method!=='cod','method','COD capture requires verified cash collection.');
            return $this->captureLocked($payment,$amount??$payment->amount);
        });
    }

    private function captureLocked(Payment $payment,int $amount):Payment {
        $this->check(in_array($payment->status,['pending','authorized'],true),'status','Only pending or authorized payments can be captured.');
        $this->check($payment->risk_level==='none','risk_level','Clear the risk review before capturing this payment.');
        $this->check($amount===$payment->amount,'amount','Capture must equal the full payment amount.');
        if($payment->order_id){$this->check(!Payment::where('order_id',$payment->order_id)->where('id','!=',$payment->id)->where('captured_amount','>',0)->exists(),'order','This order already has a captured payment.');}
        $result=$this->gateway($payment->gateway)->capture($payment,$amount);
        if($result->status!=='captured'){$this->fail($payment,$result->failureReason ?: 'Capture failed');return $payment->fresh();}
        if($payment->status==='pending'&&$payment->method!=='cod'){$payment->update(['status'=>'authorized','authorized_at'=>now()]);$this->emit('payment.authorized',$payment);}
        $payment->update(['status'=>'captured','captured_amount'=>$amount,'captured_at'=>now(),'gateway_reference'=>$result->reference,'expires_at'=>null]);
        $this->ledger->post($payment->invoice_id?'subscription_charge':'payment_capture',$amount,$payment->method==='cod'?'cash_on_delivery':'gateway_clearing',$payment->invoice_id?'platform_revenue':'customer_funds',['payment_id'=>$payment->id],$payment->reference.' captured');
        if($payment->order_id && class_exists(CommissionService::class)){app(CommissionService::class)->capture($payment);}
        $this->emit('payment.captured',$payment);
        return $payment->fresh();
    }

    /**
     * The one COD readiness rule: every COD shipment of the order is delivered with proof and cash collected, and their
     * COD amounts add up to the payment. Captures and returns true when ready, otherwise returns false. A risk hold or an
     * amount mismatch alerts Finance once instead of blocking the delivery. $strict (staff "Record COD collected")
     * turns every reason into a 422 instead.
     */
    public function captureCodWhenReady(Payment $payment,bool $strict=false):bool {
        return DB::transaction(function()use($payment,$strict){
            if($payment->order_id){Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();}
            $payment=Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->check($payment->method==='cod','method','This payment is not cash on delivery.');
            if($payment->status!=='pending'){$this->check(!$strict,'status','Only pending COD payments can be captured.');return false;}
            $shipments=Shipment::where('order_id',$payment->order_id)->where('cod_amount','>',0)->orderBy('id')->lockForUpdate()->get();
            $delivered=$shipments->isNotEmpty()&&$shipments->every(fn($part)=>$part->status==='delivered'&&$part->cash_collected&&$part->proof_attachment_id);
            $matches=(int)$shipments->sum('cod_amount')===$payment->amount;
            if($strict){$this->check($delivered&&$matches,'cash_collected','All COD shipments must be delivered with proof and recorded cash collection equal to the payment amount.');$this->check($payment->risk_level==='none','risk_level','Clear the risk review before capturing this payment.');}
            if(!$delivered){return false;}
            if(!$matches){$this->notifyOnce($payment,'cod_amount_mismatch','COD amount mismatch',$payment->reference.' was delivered, but the COD amounts of its shipments do not add up to the payment. Check the shipments.');return false;}
            if($payment->risk_level!=='none'){$this->notifyOnce($payment,'cod_risk_hold','COD cash held for risk review','Cash for '.$payment->reference.' was collected while the payment is under risk review. Clear it to capture the cash.');return false;}
            return $this->captureLocked($payment,$payment->amount)->status==='captured';
        });
    }

    /** Clear and capture: an authorized non-COD payment is captured at once (unless its order was cancelled); COD captures when ready. */
    public function clearRisk(Payment $payment):Payment {
        return DB::transaction(function()use($payment){
            $order=$payment->order_id?Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail():null;
            $payment=Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->check(in_array($payment->status,['pending','authorized'],true),'status','Only pending or authorized payments can be reviewed.');
            $payment->update(['risk_level'=>'none']);
            if($payment->method==='cod'){$this->captureCodWhenReady($payment);}
            elseif($payment->status==='authorized'&&$order?->status_snapshot!=='cancelled'){$this->captureLocked($payment,$payment->amount);}
            return $payment->fresh(['customer','order']);
        });
    }

    /** Block and cancel: a gateway refusal rolls the block back (422). COD stays pending and blocked, since the parcel may be on its way. */
    public function blockRisk(Payment $payment):Payment {
        return DB::transaction(function()use($payment){
            $payment=Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->check(in_array($payment->status,['pending','authorized'],true),'status','Only pending or authorized payments can be reviewed.');
            $payment->update(['risk_level'=>'blocked']);
            if($payment->method!=='cod'){$this->cancel($payment);}
            return $payment->fresh(['customer','order']);
        });
    }

    public function cancel(Payment $payment):Payment {
        return DB::transaction(function()use($payment){
            $payment=Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->check(in_array($payment->status,['pending','authorized'],true),'status','Only pending or authorized payments can be cancelled.');
            $result=$this->gateway($payment->gateway)->cancel($payment);
            $this->check($result->status==='cancelled','gateway','The gateway did not confirm cancellation.');
            $payment->update(['status'=>'cancelled','expires_at'=>null]);$this->emit('payment.cancelled',$payment);
            return $payment->fresh();
        });
    }

    /** $allowAutoApprove is passed only by staff RefundController::store, so integration refunds always wait for an approver. */
    public function requestRefund(Payment $payment,array $values,User|IntegrationClient $actor,bool $allowAutoApprove=false):Refund {
        return DB::transaction(function()use($payment,$values,$actor,$allowAutoApprove){
            $payment=Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $this->check(in_array($payment->status,self::REFUNDABLE_STATUSES,true),'status','Only captured payments can be refunded.');
            $this->check($values['amount']>0&&$values['amount']<=$this->refundableAmount($payment),'amount','The refund exceeds the unreserved captured balance.');
            $refund=Refund::create(['payment_id'=>$payment->id,'ticket_id'=>$values['ticket_id']??null,'total_amount'=>$values['amount'],'reason'=>$values['reason'],'notes'=>$values['notes']??null,'requested_by'=>$actor instanceof User?$actor->id:null,'client_id'=>$actor instanceof IntegrationClient?$actor->id:null]);
            Reference::assign($refund,'RFD');$this->emit('refund.requested',$refund);
            if($allowAutoApprove&&$actor instanceof User&&$this->autoApprovable($refund,$payment,$actor)){
                $this->approveLocked($refund,$payment,null);
                AuditLog::system($refund,['status'=>'requested'],['status'=>$refund->status,'approved_at'=>$refund->approved_at?->toIso8601String()],'refund_auto_approve');
                return $refund->fresh('payment');
            }
            $this->notifyPermission('approve refunds','refund_awaiting_approval','Refund awaiting approval',$refund->reference.' is awaiting approval.',['refund_id'=>$refund->id]);
            return $refund->fresh('payment');
        });
    }

    /**
     * PAY-8 (off while refund_auto_approve_cap_amount is 0): within the cap, a listed reason, the payment's first automatic
     * approval, and the requester's automatic total for the business day within refund_auto_approve_daily_limit. The
     * requester row is locked so two requests at once cannot both use the last of the daily limit. Failed refunds moved
     * no money, so they count toward neither limit.
     */
    private function autoApprovable(Refund $refund,Payment $payment,User $requester):bool {
        $cap=(int)Setting::valueFor('refund_auto_approve_cap_amount');
        if($cap<=0||$refund->amount>$cap||!in_array($refund->reason,(array)Setting::valueFor('refund_auto_approve_reasons'),true)){return false;}
        $automatic=fn()=>Refund::whereNull('approved_by')->whereNotNull('approved_at')->where('status','!=','failed');
        if($automatic()->where('payment_id',$payment->id)->exists()){return false;}
        User::whereKey($requester->id)->lockForUpdate()->firstOrFail();
        $today=CarbonImmutable::now(Setting::valueFor('business_timezone'))->startOfDay()->utc();
        return (int)$automatic()->where('requested_by',$requester->id)->where('approved_at','>=',$today)->sum('total_amount')+$refund->amount<=(int)Setting::valueFor('refund_auto_approve_daily_limit');
    }

    public function approve(Refund $refund,User $actor):Refund {
        return DB::transaction(function()use($refund,$actor){
            $payment=Payment::whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            $refund=Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $this->check($refund->status==='requested','status','Only requested refunds can be approved.');
            $this->check($actor->hasRole('Admin')||$refund->requested_by!==$actor->id,'requested_by','Only an Admin can approve their own refund request.');
            $this->check(in_array($payment->status,['captured','partially_refunded'],true)&&$refund->amount<=$payment->captured_amount-$payment->refunded_amount,'amount','This refund is no longer within the captured balance.');
            return $this->approveLocked($refund,$payment,$actor);
        });
    }

    /** Approve, refund at the gateway and settle a locked refund. $actor is null for a PAY-8 automatic approval. */
    private function approveLocked(Refund $refund,Payment $payment,?User $actor):Refund {
        $refund->update(['status'=>'approved','approved_by'=>$actor?->id,'approved_at'=>now()]);$this->emit('refund.approved',$refund);
        $refund->update(['status'=>'processing']);$result=$this->gateway($payment->gateway)->refund($payment,$refund->amount,$refund->reason);
        $how=$actor?'':'approved automatically and ';
        if($result->status==='completed'){
            $refund->update(['status'=>'completed','gateway_reference'=>$result->reference,'completed_at'=>now()]);
            $total=$payment->refunded_amount+$refund->amount;$payment->update(['refunded_amount'=>$total,'status'=>$total===$payment->captured_amount?'refunded':'partially_refunded']);
            $this->ledger->post('refund',$refund->amount,'customer_funds',$payment->method==='cod'?'cash_on_delivery':'gateway_clearing',['payment_id'=>$payment->id,'refund_id'=>$refund->id,'created_by'=>$actor?->id],$refund->reference.' completed');
            if(class_exists(CommissionService::class)){app(CommissionService::class)->refund($refund);}
            $this->emit('refund.completed',$refund);$this->notifyRequester($refund,'refund_completed','Refund completed');$this->noteOnTicket($refund,$actor,$how.'completed');
        }else{$refund->update(['status'=>'failed','gateway_reference'=>$result->reference,'rejection_reason'=>$result->failureReason]);$this->emit('refund.failed',$refund);$this->notifyRequester($refund,'refund_failed','Refund failed');$this->noteOnTicket($refund,$actor,$how.'failed: '.($result->failureReason ?: 'the gateway declined it'));}
        return $refund->fresh('payment');
    }

    public function reject(Refund $refund,User $actor,string $reason):Refund {
        return DB::transaction(function()use($refund,$actor,$reason){
            Payment::whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();$refund=Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $this->check($refund->status==='requested','status','Only requested refunds can be rejected.');$this->check($actor->hasRole('Admin')||$refund->requested_by!==$actor->id,'requested_by','Only an Admin can reject their own refund request.');
            $refund->update(['status'=>'rejected','approved_by'=>$actor->id,'approved_at'=>now(),'rejection_reason'=>$reason]);$this->emit('refund.rejected',$refund);
            $this->notifyRequester($refund,'refund_rejected','Refund rejected');$this->noteOnTicket($refund,$actor,'rejected: '.$reason);return $refund->fresh('payment');
        });
    }

    public function processEvent(string $gateway,ParsedGatewayEvent $event,array $payload):bool {
        return DB::transaction(function()use($gateway,$event,$payload){
            $created=DB::table('gateway_events')->insertOrIgnore(['gateway'=>$gateway,'event_id'=>$event->id,'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            if(!$created){return false;}
            $payment=Payment::where('reference',$event->reference)->where('gateway',$gateway)->firstOrFail();
            if($payment->order_id){Order::whereKey($payment->order_id)->lockForUpdate()->firstOrFail();}
            $payment=Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            switch($event->type){
                case 'payment.authorized':$this->check($payment->status==='pending','status','Only pending payments can be authorized.');$payment->update(['status'=>'authorized','authorized_at'=>now()]);$this->emit('payment.authorized',$payment);break;
                case 'payment.captured':$this->captureLocked($payment,$event->amount??$payment->amount);break;
                case 'payment.failed':$this->check(in_array($payment->status,['pending','authorized'],true),'status','Only pending or authorized payments can fail.');$this->fail($payment,'Sandbox gateway reported a failure.');break;
                case 'payment.cancelled':$this->cancel($payment);break;
                case 'payment.disputed':$this->check(in_array($payment->status,['captured','partially_refunded'],true),'status','Only captured payments can be disputed.');$payment->update(['status'=>'disputed']);$this->ledger->post('chargeback',$payment->captured_amount-$payment->refunded_amount,'customer_funds','gateway_clearing',['payment_id'=>$payment->id],$payment->reference.' disputed');$this->emit('payment.disputed',$payment);break;
                case 'payment.dispute_won':$this->check($payment->status==='disputed','status','The payment must be disputed.');$payment->update(['status'=>'dispute_won']);$this->ledger->post('chargeback_reversal',$payment->captured_amount-$payment->refunded_amount,'gateway_clearing','customer_funds',['payment_id'=>$payment->id],$payment->reference.' dispute won');break;
                case 'payment.dispute_lost':$this->check($payment->status==='disputed','status','The payment must be disputed.');$payment->update(['status'=>'dispute_lost']);break;
                default:$this->check(false,'type','The gateway event type is invalid.');
            }
            return true;
        });
    }

    public function chargeInvoice(Model $invoice):Payment {
        return DB::transaction(function()use($invoice){
            $invoice=$invoice->newQuery()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $existing=Payment::where('invoice_id',$invoice->id)->where('status','captured')->first();if($existing){return $existing;}
            $payment=Payment::create(['invoice_id'=>$invoice->id,'seller_id'=>$invoice->seller_id,'method'=>'card','gateway'=>'sandbox','total_amount'=>$invoice->total_amount,'expires_at'=>now()->addMinutes(30)]);Reference::assign($payment,'PAY');
            $result=$this->gateway('sandbox')->createPayment($payment);$payment->update(['gateway_reference'=>$result->reference]);
            if($result->status==='failed'){$this->fail($payment,$result->failureReason ?: 'Subscription charge failed');}
            elseif($result->status==='authorized'){$payment=$this->captureLocked($payment->refresh(),$payment->amount);}
            return $payment->fresh();
        });
    }

    public function runScheduled():array {
        // Before the expiry loop, so a payment the gateway authorized late is collected instead of expired.
        $cleared=$this->autoClearRisk();
        $captures=$this->autoCaptureAuthorized();
        $expired=0;$held=0;$hold=(int)Setting::valueFor('risk_review_hold_hours');
        foreach(Payment::whereIn('status',['pending','authorized'])->whereNotNull('expires_at')->where('expires_at','<=',now())->orderBy('id')->limit(100)->get() as $payment){
            try{DB::transaction(function()use($payment,$hold,&$expired,&$held){
                $locked=Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if(!in_array($locked->status,['pending','authorized'],true)||!$locked->expires_at?->lte(now())){return;}
                // PAY-2 (0 = off): a payment waiting for a risk decision expires risk_review_hold_hours after it was created.
                // Moving expires_at (instead of skipping the row) keeps it out of this batch until then.
                $until=$locked->created_at->copy()->addHours($hold);
                if($hold>0&&$locked->risk_level==='review'&&$until->isFuture()){$locked->update(['expires_at'=>$until]);$held++;return;}
                $locked->update(['status'=>'expired','failure_reason'=>$hold>0&&$locked->risk_level==='review'?'Payment expired after its '.$hold.'-hour risk-review hold.':'Payment expired after 30 minutes.']);$expired++;
            });}catch(\Throwable $e){report($e);}
        }
        return ['risk_auto_cleared'=>$cleared,...$captures,'payments_expired'=>$expired,'payments_held_for_review'=>$held,'finance_reminders_sent'=>$this->remind()];
    }

    /**
     * PAY-6 (off while risk_auto_clear_min_captured is 0): Clear and capture an open risk review on its own when the
     * customer already has that many captured payments, has never had a disputed or lost payment, has no more recent
     * failures than the risk rule allows, and the amount is within risk_auto_clear_max_amount. Blocked payments and
     * cancelled orders stay with Finance. The rule is part of the query, so reviews it skips never fill the 100-row batch.
     */
    private function autoClearRisk():int {
        $min=(int)Setting::valueFor('risk_auto_clear_min_captured');
        if($min<=0){return 0;}
        $cap=(int)Setting::valueFor('risk_auto_clear_max_amount');$attempts=(int)Setting::valueFor('risk_failed_attempts',3);$cleared=0;
        $eligible=fn()=>Payment::whereIn('status',['pending','authorized'])->where('risk_level','review')->where('total_amount','<=',$cap)
            ->whereHas('order',fn($order)=>$order->where('status_snapshot','!=','cancelled'))
            ->whereHas('customer',fn($customer)=>$customer
                ->whereHas('payments',fn($query)=>$query->where('captured_amount','>',0),'>=',$min)
                ->whereDoesntHave('payments',fn($query)=>$query->whereIn('status',['disputed','dispute_lost']))
                ->whereHas('payments',fn($query)=>$query->where('status','failed')->where('failed_at','>=',now()->subHour()),'<=',$attempts));
        $due=$eligible()->orderBy('id')->limit(100)->get(['id','order_id']);
        foreach($due as $row){
            try{DB::transaction(function()use($row,$eligible,&$cleared){
                Order::whereKey($row->order_id)->lockForUpdate()->firstOrFail();
                $payment=Payment::whereKey($row->id)->lockForUpdate()->firstOrFail();
                if(!$eligible()->whereKey($payment->id)->exists()){return;}
                $after=$this->clearRisk($payment);
                AuditLog::system($payment,['risk_level'=>'review','status'=>$payment->status],['risk_level'=>'none','status'=>$after->status],'risk_auto_clear');
                $cleared++;
            });}catch(\Throwable $e){report($e);}
        }
        return $cleared;
    }

    /**
     * PAY-1: the same policy create() applies at once, for payments the gateway authorizes later (sandbox "98" plus a
     * webhook, or a subscription charge). Risk reviews, COD and cancelled orders are never captured here.
     */
    private function autoCaptureAuthorized():array {
        $captured=0;$errors=0;
        if(!Setting::valueFor('auto_capture_authorized')){return ['payments_auto_captured'=>0,'payments_auto_capture_errors'=>0];}
        $due=Payment::where('status','authorized')->where('risk_level','none')->where('method','!=','cod')
            // An invoice that was voided or written off while its payment waited at the gateway is never charged.
            ->where(fn($query)=>$query->whereIn('invoice_id',Invoice::whereIn('status',['draft','open'])->select('id'))->orWhereHas('order',fn($order)=>$order->where('status_snapshot','!=','cancelled')))
            ->orderBy('id')->limit(100)->get(['id','order_id','invoice_id']);
        foreach($due as $row){
            try{DB::transaction(function()use($row,&$captured,&$errors){
                $order=$row->order_id?Order::whereKey($row->order_id)->lockForUpdate()->firstOrFail():null;
                $invoice=$row->invoice_id?Invoice::whereKey($row->invoice_id)->lockForUpdate()->firstOrFail():null;
                $payment=Payment::whereKey($row->id)->lockForUpdate()->firstOrFail();
                if($payment->status!=='authorized'||$payment->risk_level!=='none'||$payment->method==='cod'||$order?->status_snapshot==='cancelled'){return;}
                if($invoice&&!in_array($invoice->status,['draft','open'],true)){return;}
                if($order&&Payment::where('order_id',$order->id)->whereKeyNot($payment->id)->where('captured_amount','>',0)->exists()){return;}
                $payment=$this->captureLocked($payment,$payment->amount);
                AuditLog::system($payment,['status'=>'authorized'],['status'=>$payment->status],'auto_capture_authorized');
                if($payment->status==='captured'){$captured++;}else{$errors++;}
            });}catch(\Throwable $e){report($e);$errors++;}
        }
        return ['payments_auto_captured'=>$captured,'payments_auto_capture_errors'=>$errors];
    }

    /**
     * PAY-9: one alert per record, finance_reminder_hours (0 = off) after it started waiting: refunds awaiting approval
     * and open risk reviews (COD holds included). A dispute alerts on the next run, since nothing else announces it.
     * A record is skipped once any notification of that type names its reference.
     */
    private function remind():int {
        $hours=(int)Setting::valueFor('finance_reminder_hours');
        if($hours<=0){return 0;}
        $cutoff=now()->subHours($hours);$sent=0;
        $unsent=fn(string $type,string $table)=>fn($query)=>$query->from('notifications')->where('type',$type)->whereColumn('notifications.data->reference',$table.'.reference');
        // Each record's alerts are all-or-nothing (they are also its dedupe record); a failure is reported and retried next run.
        $notify=function(...$args)use(&$sent){try{$sent+=DB::transaction(fn()=>$this->notifyPermission(...$args));}catch(\Throwable $e){report($e);}};
        foreach(Refund::where('status','requested')->where('created_at','<=',$cutoff)->whereNotExists($unsent('refund_approval_overdue','refunds'))->orderBy('id')->limit(100)->get() as $refund){
            $notify('approve refunds','refund_approval_overdue','Refund approval overdue',$refund->reference.' has waited more than '.$hours.' hours for approval.',['refund_id'=>$refund->id,'reference'=>$refund->reference]);
        }
        $payments=[
            'risk_review_overdue'=>[Payment::whereIn('status',['pending','authorized'])->where('risk_level','review')->where('created_at','<=',$cutoff),'Risk review overdue',' has waited more than '.$hours.' hours for a risk decision.'],
            'payment_disputed'=>[Payment::where('status','disputed'),'Payment disputed',' was disputed with the customer\'s bank. The gateway sends the decision; keep the order\'s evidence ready.'],
        ];
        foreach($payments as $type=>[$query,$title,$message]){
            foreach($query->whereNotExists($unsent($type,'payments'))->orderBy('id')->limit(100)->get() as $payment){
                $notify('edit payments',$type,$title,$payment->reference.$message,['payment_id'=>$payment->id,'reference'=>$payment->reference]);
            }
        }
        return $sent;
    }

    private function risk(Payment $payment):string {
        $failed=Payment::where('customer_id',$payment->customer_id)->where('status','failed')->where('failed_at','>=',now()->subHour())->count();
        return $payment->amount>(int)Setting::valueFor('risk_amount_threshold',5000000)||$failed>(int)Setting::valueFor('risk_failed_attempts',3)?'review':'none';
    }
    private function fail(Payment $payment,string $reason):void{$payment->update(['status'=>'failed','failure_reason'=>$reason,'failed_at'=>now(),'expires_at'=>null]);$this->emit('payment.failed',$payment);}
    private function emit(string $type,Model $entity):void{$payment=$entity instanceof Refund?$entity->payment:$entity;$code=$payment?->order_id?$payment->order?->source_system:$payment?->seller?->source_system;if(!$code){return;}$client=IntegrationClient::where('system_code',$code)->first();if(!$client){return;}$this->webhooks->emit($type,$entity->toArray(),$client);}
    private function check(bool $valid,string $field,string $message):void{if(!$valid){throw ValidationException::withMessages([$field=>[$message]]);}}
    private function notifyPermission(string $permission,string $type,string $title,string $message,array $data):int{$users=User::authorizedFor($permission)->where('is_active',true)->get(['id']);foreach($users as $user){Notification::create(['user_id'=>$user->id,'type'=>$type,'title'=>$title,'message'=>$message,'data'=>$data]);}return $users->count();}
    private function notifyOnce(Payment $payment,string $type,string $title,string $message):void{if(!Notification::where('type',$type)->where('data->reference',$payment->reference)->exists()){$this->notifyPermission('edit payments',$type,$title,$message,['payment_id'=>$payment->id,'reference'=>$payment->reference]);}}
    /** SUP-13: the outcome is noted on the refund's ticket, and its assignee is told unless they acted or requested it. */
    private function noteOnTicket(Refund $refund,?User $actor,string $outcome):void{
        $ticket=$refund->ticket_id?Ticket::find($refund->ticket_id):null;if(!$ticket){return;}
        $body='Refund '.$refund->reference.' '.$outcome.(str_ends_with($outcome,'.')?'':'.');
        TicketMessage::create(['ticket_id'=>$ticket->id,'author_type'=>'system','author_id'=>$actor?->id,'body'=>$body,'is_internal_note'=>true]);
        if($ticket->assignee_id&&!in_array($ticket->assignee_id,[$actor?->id,$refund->requested_by])&&User::authorizedFor('view tickets')->whereKey($ticket->assignee_id)->where('is_active',true)->exists()){Notification::create(['user_id'=>$ticket->assignee_id,'type'=>'ticket_refund_outcome','title'=>'Refund '.explode(':',$outcome)[0],'message'=>$body,'data'=>['ticket_id'=>$ticket->id,'refund_id'=>$refund->id]]);}
    }
    private function notifyRequester(Refund $refund,string $type,string $title):void{if($refund->requested_by&&User::authorizedFor('view payments')->whereKey($refund->requested_by)->where('is_active',true)->exists()){Notification::create(['user_id'=>$refund->requested_by,'type'=>$type,'title'=>$title,'message'=>$refund->reference.' '.$title.'.','data'=>['refund_id'=>$refund->id]]);}}
}
