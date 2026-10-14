<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CommissionEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Plan;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Subscription;
use App\Support\Money;
use App\Support\Reference;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function __construct(private readonly PaymentService $payments,private readonly LedgerService $ledger,private readonly WebhookService $webhooks) {}

    public function subscribe(array $data): Subscription
    {
        return DB::transaction(function()use($data){
            Seller::whereKey($data['seller_id'])->lockForUpdate()->firstOrFail();
            if(Subscription::where('seller_id',$data['seller_id'])->whereIn('status',['active','trialing','past_due'])->exists())$this->invalid('seller_id','This seller already has a current subscription.');
            $plan=Plan::whereKey($data['plan_id'])->where('is_active',true)->firstOrFail();
            $start=CarbonImmutable::now(); $trial=$plan->trial_days>0;
            $end=$trial?$start->addDays($plan->trial_days):$this->periodEnd($start,$data['billing_cycle']);
            $sub=Subscription::create(['seller_id'=>$data['seller_id'],'plan_id'=>$plan->id,'billing_cycle'=>$data['billing_cycle'],'status'=>$trial?'trialing':'active','trial_ends_at'=>$trial?$end:null,'current_period_start'=>$start,'current_period_end'=>$end]);
            if(!$trial)$this->charge($this->invoice($sub,$start,$end,$this->price($plan,$sub->billing_cycle),'initial'));
            $this->emit('subscription.created',$sub->fresh()->toArray(),$sub->seller_id);
            return $sub->fresh(['plan','seller','invoices']);
        });
    }

    public function changePlan(Subscription $subscription,Plan $plan): Subscription
    {
        return DB::transaction(function()use($subscription,$plan){
            $sub=Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if(!in_array($sub->status,['active','trialing'])||!$plan->is_active)$this->invalid('plan_id','Only current subscriptions can switch to an active plan.');
            if($plan->id===$sub->plan_id)$this->invalid('plan_id','Select a different plan.');
            $old=$this->price($sub->plan,$sub->billing_cycle);$new=$this->price($plan,$sub->billing_cycle);
            if($new<=$old){$sub->update(['next_plan_id'=>$plan->id]);return $sub->fresh(['plan','nextPlan','seller']);}
            $zone=Setting::valueFor('business_timezone',config('sfa.timezone'));
            $total=max(1,(int)$sub->current_period_start->timezone($zone)->startOfDay()->diffInDays($sub->current_period_end->timezone($zone)->startOfDay()));
            $left=max(0,(int)CarbonImmutable::now($zone)->startOfDay()->diffInDays($sub->current_period_end->timezone($zone)->startOfDay(),false));
            $amount=Money::halfUp($new-$old,$left,$total);
            $sub->update(['next_plan_id'=>$plan->id]);
            $invoice=$this->invoice($sub,CarbonImmutable::now(),$sub->current_period_end,$amount,'upgrade');
            $invoice->update(['line_items'=>[['description'=>'Prorated upgrade to '.$plan->name,'amount'=>$amount,'plan_id'=>$plan->id]]]);
            $this->charge($invoice);
            return $sub->fresh(['plan','nextPlan','seller','invoices']);
        });
    }

    public function cancel(Subscription $subscription,bool $immediate=false): Subscription
    {
        return DB::transaction(function()use($subscription,$immediate){
            $sub=Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if(!in_array($sub->status,['trialing','active','past_due']))$this->invalid('status','This subscription cannot be cancelled from its current status.');
            $status=$sub->status;
            $sub->update($immediate?['status'=>'cancelled','cancel_at_period_end'=>false,'cancelled_at'=>now(),'next_retry_at'=>null]:['cancel_at_period_end'=>true]);
            if($immediate){$this->closeOpenInvoices($sub,$status);$this->emit('subscription.cancelled',$sub->toArray(),$sub->seller_id);}
            return $sub->fresh(['seller','plan']);
        });
    }

    public function resume(Subscription $subscription): Subscription
    {
        return DB::transaction(function()use($subscription){
            $sub=Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            if(!$sub->cancel_at_period_end && !($sub->status==='cancelled'&&$sub->current_period_end->isFuture()))$this->invalid('status','Only a scheduled cancellation or a cancelled subscription within its paid period can resume.');
            if($sub->status==='cancelled'&&Subscription::where('seller_id',$sub->seller_id)->whereKeyNot($sub->id)->whereIn('status',['trialing','active','past_due'])->exists())$this->invalid('status','This seller already has another current subscription.');
            // A scheduled cancellation keeps its status (a past-due balance stays past due); only a cancelled one reactivates.
            $sub->update(['cancel_at_period_end'=>false,'cancelled_at'=>null,'status'=>$sub->status!=='cancelled'?$sub->status:($sub->trial_ends_at?->isFuture()?'trialing':'active')]);
            return $sub->fresh(['seller','plan']);
        });
    }

    public function charge(Invoice $invoice): Invoice
    {
        return DB::transaction(function()use($invoice){
            // Cancellation also locks subscription before invoices; charges use the same order.
            $sub=Subscription::whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();
            $invoice=Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if($invoice->status==='paid')return $invoice->load(['seller','subscription','payment']);
            if(!in_array($invoice->status,['draft','open']))$this->invalid('status','Only draft or open invoices can be charged.');
            // A charge would reactivate the subscription, so an ended one must be resumed (or re-subscribed) first.
            if(in_array($sub->status,['cancelled','expired']))$this->invalid('status','Invoices of a cancelled or expired subscription cannot be charged.');
            // One outstanding gateway attempt per invoice: never open a second payment while one is awaiting the gateway.
            if(Payment::where('invoice_id',$invoice->id)->whereIn('status',['pending','authorized'])->exists())$this->invalid('invoice','A payment for this invoice is still awaiting the gateway.');
            $invoice->update(['status'=>'open']);
            $payment=$invoice->total_amount>0?$this->payments->chargeInvoice($invoice):null;
            if(!$payment||in_array($payment->status,['captured','partially_refunded','refunded'])){
                $invoice->update(['status'=>'paid','paid_at'=>now(),'payment_id'=>$payment?->id]);
                $changes=['status'=>'active','retry_count'=>0,'next_retry_at'=>null];
                if($invoice->kind==='upgrade'&&$sub->next_plan_id)$changes+=['plan_id'=>$sub->next_plan_id,'next_plan_id'=>null];
                $sub->update($changes);$this->emit('invoice.paid',$invoice->fresh()->toArray(),$invoice->seller_id);
            }elseif(in_array($payment->status,['pending','authorized'])){
                // Still with the gateway, so not a failure: no dunning step, webhook or alert. Retry once the 30-minute
                // payment window has passed; a later capture is picked up by the retry or the invoice sweep. A trial that
                // reached its first charge has ended either way, like a new paid subscription whose first charge waits.
                $sub->update(['next_retry_at'=>now()->addMinutes(31)]+($sub->status==='trialing'?['status'=>'active']:[]));
            }else{
                $retries=[1,3,7];$count=$sub->retry_count;
                $sub->update(['status'=>'past_due','next_retry_at'=>$count<3?CarbonImmutable::parse($invoice->due_date)->addDays($retries[$count]):null,'retry_count'=>$count+1]);
                if($count===0){$this->emit('subscription.past_due',$sub->toArray(),$sub->seller_id);$this->notifyFinance('subscription_past_due','Subscription past due','Seller subscription '.$sub->id.' requires payment.',['subscription_id'=>$sub->id]);}
                if($count>=3){$sub->update(['status'=>'cancelled','cancelled_at'=>now()]);$this->closeOpenInvoices($sub,'past_due');$this->emit('subscription.cancelled',$sub->toArray(),$sub->seller_id);$this->notifyFinance('subscription_cancelled_unpaid','Subscription cancelled for non-payment','Seller subscription '.$sub->id.' was cancelled after its last payment retry failed; its open invoices were written off.',['subscription_id'=>$sub->id]);}
            }
            return $invoice->fresh(['seller','subscription','payment']);
        });
    }

    public function voidInvoice(Invoice $invoice): Invoice
    {
        return DB::transaction(function()use($invoice){
            $locked=Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if(!in_array($locked->status,['draft','open']))$this->invalid('status','Only draft or open invoices can be voided.');
            $locked->update(['status'=>'void']);return $locked->fresh(['seller','subscription']);
        });
    }

    public function generate(string $from,string $to,?int $sellerId=null,?int $actor=null,bool $scheduled=false,int &$errors=0): array
    {
        $zone=Setting::valueFor('business_timezone',config('sfa.timezone'));
        $start=CarbonImmutable::parse($from,$zone)->startOfDay()->utc();$end=CarbonImmutable::parse($to,$zone)->addDay()->startOfDay()->utc();
        $rows=[];
        // Scheduled runs only build drafts for sellers with unsettled sales in the period, so no empty drafts appear, and
        // send no bells: runScheduled sends one digest for every period it generated. At most 100 sellers per period and
        // run; the rest keep their unsettled sales and are picked up by the next run.
        $unsettled=fn($q)=>$q->whereIn('id',CommissionEntry::whereNull('settlement_id')->where('created_at','>=',$start)->where('created_at','<',$end)->select('seller_id'))->orderBy('id')->limit(100);
        $carry=(bool)Setting::valueFor('settlement_carry_forward');
        foreach(Seller::when($sellerId,fn($q)=>$q->whereKey($sellerId))->when($scheduled,$unsettled)->get() as $seller){
            // Scheduled work isolates each seller: one failure is reported and never blocks the others or their digest.
            try{$row=DB::transaction(function()use($seller,$from,$to,$start,$end,$actor,$scheduled,$carry){
                Seller::whereKey($seller->id)->lockForUpdate()->firstOrFail();
                // A voided settlement released its entries, so the same seller and period may be generated again.
                $existing=Settlement::where('seller_id',$seller->id)->where('status','!=','void')->whereDate('period_start',$from)->whereDate('period_end',$to)->lockForUpdate()->first();
                if($existing)return $existing;
                $entries=CommissionEntry::where('seller_id',$seller->id)->whereNull('settlement_id')->where('created_at','>=',$start)->where('created_at','<',$end)->lockForUpdate()->get();
                if($scheduled&&$entries->isEmpty())return null;
                $gross=$entries->where('entry_type','capture')->sum('gross_amount');$refunds=abs($entries->where('entry_type','reversal')->sum('gross_amount'));$commission=$entries->sum('commission_amount');
                $gatewayFees=0;
                foreach($entries->where('entry_type','capture')->groupBy('payment_id') as $paymentId=>$group){
                    // Cumulative rounding by seller id: the shares of one payment's fee sum exactly, never above the fee.
                    $payment=Payment::find($paymentId);
                    if(!$payment||$payment->captured_amount<=0||$payment->fee_amount<=0)continue;
                    $before=(int)CommissionEntry::where('payment_id',$paymentId)->where('entry_type','capture')->where('seller_id','<',$seller->id)->sum('gross_amount');
                    $own=(int)$group->sum('gross_amount');
                    $gatewayFees+=Money::halfUp((int)$payment->fee_amount,$before+$own,(int)$payment->captured_amount)-Money::halfUp((int)$payment->fee_amount,$before,(int)$payment->captured_amount);
                }
                // BIL-12 (settlement_carry_forward, off by default): what the seller still owes from finalized negative
                // settlements is netted here as an adjustment. Seller payable already holds that debt, so no ledger pair.
                $owed=$carry&&$entries->isNotEmpty()?Settlement::where('seller_id',$seller->id)->where('status','finalized')->where('net_payable_amount','<',0)->whereNull('recovered_by_settlement_id')->lockForUpdate()->get():collect();
                $adjustments=(int)$owed->sum('net_payable_amount');
                // Subscription charges have already been paid separately; do not deduct them again from seller proceeds.
                $settlement=Settlement::create(['seller_id'=>$seller->id,'period_start'=>$from,'period_end'=>$to,'gross_sales_amount'=>$gross,'refunds_amount'=>$refunds,'commission_amount'=>$commission,'gateway_fees_amount'=>$gatewayFees,'subscription_fees_amount'=>0,'adjustments_amount'=>$adjustments,'net_payable_amount'=>$gross-$refunds-$commission-$gatewayFees+$adjustments,'status'=>'draft','created_by'=>$actor]);
                Reference::assign($settlement,'STL');
                CommissionEntry::whereIn('id',$entries->pluck('id'))->update(['settlement_id'=>$settlement->id]);
                if($owed->isNotEmpty())Settlement::whereKey($owed->modelKeys())->update(['recovered_by_settlement_id'=>$settlement->id]);
                if(!$scheduled)$this->notifyFinance('settlement_ready','Settlement ready to finalize',$settlement->reference.' is ready for review.',['settlement_id'=>$settlement->id]);
                return $settlement->load(['seller','entries']);
            });if($row)$rows[]=$row;}catch(\Throwable $e){if(!$scheduled)throw $e;report($e);$errors++;}
        }
        return $rows;
    }

    /**
     * Drafts the auto-finalize rule (BIL-2) may finalize: made by scheduled work, net zero or more, not rebuilt after a
     * void (Void is how Finance holds a period), and with no disputed payment or refund still in flight.
     */
    private function autoFinalizable()
    {
        return Settlement::where('status','draft')->whereNull('created_by')->where('net_payable_amount','>=',0)
            ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('settlements as held')->where('held.status','void')->whereColumn('held.seller_id','settlements.seller_id')->whereColumn('held.period_start','settlements.period_start')->whereColumn('held.period_end','settlements.period_end'))
            ->whereDoesntHave('entries.payment',fn($q)=>$q->where(fn($p)=>$p->whereIn('status',['disputed','dispute_lost'])->orWhereHas('refunds',fn($r)=>$r->whereIn('status',['requested','approved','processing']))));
    }

    /** When scheduled work will finalize this draft, or null when the rule is off or the draft is held. */
    public function autoFinalizeAt(Settlement $settlement): ?CarbonImmutable
    {
        $days=(int)Setting::valueFor('settlement_auto_finalize_days');
        return $days>0&&$this->autoFinalizable()->whereKey($settlement->id)->exists()?CarbonImmutable::parse($settlement->created_at)->addDays($days)->max():null;
    }

    public function settlementAction(Settlement $settlement,string $action,array $data=[]): Settlement
    {
        return DB::transaction(function()use($settlement,$action,$data){
            $locked=Settlement::whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if($action==='finalize'){
                if($locked->status!=='draft')$this->invalid('status','Only draft settlements can be finalized.');
                $this->ledger->post('gateway_fee',$locked->gateway_fees_amount,'seller_payable','gateway_clearing',['settlement_id'=>$locked->id],'Gateway fees for '.$locked->reference);
                $locked->update(['status'=>'finalized','finalized_at'=>now()]);$this->emit('settlement.finalized',$locked->toArray(),$locked->seller_id);
            }elseif($action==='void'){
                if($locked->status!=='draft')$this->invalid('status','Only draft settlements can be voided.');
                $locked->update(['status'=>'void']);CommissionEntry::where('settlement_id',$locked->id)->update(['settlement_id'=>null]);
                // A carried-forward debt goes back to the seller's balance for the next settlement.
                Settlement::where('recovered_by_settlement_id',$locked->id)->update(['recovered_by_settlement_id'=>null]);
            }elseif($action==='pay'){
                if($locked->status!=='finalized'||$locked->net_payable_amount<=0)$this->invalid('status','Only finalized settlements with a positive payable balance can be paid.');
                $destination=$data['destination_masked']??$locked->seller->payout_destination_masked;
                if(!$destination)$this->invalid('destination_masked','This seller has no payout destination on file. Enter a masked destination.');
                if(!preg_match('/(?:\*|•){2,}.*\d{4}$/u',$destination))$this->invalid('destination_masked','Enter only a masked destination ending in four digits.');
                // Without a method, the seller's stored destination decides: a bank account number or a GCash number.
                $stored=$locked->seller->payout_destination?:[];
                $method=$data['method']??(isset($stored['account_number'])?'bank_transfer':(isset($stored['number'])?'gcash':null));
                if(!$method)$this->invalid('method','Select a payout method; this seller has no stored payout destination.');
                $payout=Payout::create(['settlement_id'=>$locked->id,'method'=>$method,'destination_masked'=>$destination,'amount'=>$locked->net_payable_amount,'status'=>'paid','paid_at'=>now()]);Reference::assign($payout,'PYO');
                $this->ledger->post('payout',$payout->amount,'seller_payable','gateway_clearing',['settlement_id'=>$locked->id,'payout_id'=>$payout->id],'Seller payout '.$payout->reference);
                $locked->update(['status'=>'paid','paid_at'=>now()]);$this->emit('payout.paid',$payout->toArray(),$locked->seller_id);
            }else $this->invalid('action','Select a valid settlement action.');
            return $locked->fresh(['seller','entries','payouts']);
        });
    }

    public function runScheduled(): array
    {
        // Each subscription is isolated so one failure cannot stop renewals, retries or settlements for others.
        $renewed=0;$retried=0;$errors=0;
        // BIL-7 (default_plan_id, off while null): a seller that never had a subscription gets the default plan, monthly.
        // The plan is re-checked here (active, free, no commission rate of its own) and each seller under its lock.
        $subscribed=0;$planId=Setting::valueFor('default_plan_id');
        $plan=$planId?Plan::whereKey($planId)->where('is_active',true)->where('monthly_price_amount',0)->where('annual_price_amount',0)->whereNull('commission_rate_bp')->first():null;
        if($plan)foreach(Seller::whereNotIn('id',Subscription::select('seller_id'))->orderBy('id')->limit(100)->get() as $candidate){
            try{DB::transaction(function()use($candidate,$plan,&$subscribed){
                Seller::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if(Subscription::where('seller_id',$candidate->id)->exists())return;
                $sub=$this->subscribe(['seller_id'=>$candidate->id,'plan_id'=>$plan->id,'billing_cycle'=>'monthly']);
                AuditLog::system($sub,null,$sub->only(['seller_id','plan_id','billing_cycle','status']),'subscription_auto_subscribe');$subscribed++;
            });}catch(\Throwable $e){report($e);$errors++;}
        }
        foreach(Subscription::whereIn('status',['trialing','active'])->where('current_period_end','<=',now())->orderBy('id')->limit(100)->get() as $subscription){
            try{DB::transaction(function()use($subscription,&$renewed){
                $sub=Subscription::whereKey($subscription->id)->lockForUpdate()->first();if(!$sub||!in_array($sub->status,['trialing','active'])||$sub->current_period_end->isFuture())return;
                if($sub->cancel_at_period_end){$status=$sub->status;$sub->update(['status'=>'cancelled','cancelled_at'=>now(),'cancel_at_period_end'=>false,'next_retry_at'=>null]);$this->closeOpenInvoices($sub,$status);$this->emit('subscription.cancelled',$sub->toArray(),$sub->seller_id);return;}
                $start=$sub->current_period_end;$end=$this->periodEnd($start,$sub->billing_cycle);$plan=Plan::findOrFail($sub->next_plan_id??$sub->plan_id);
                $sub->update(['plan_id'=>$plan->id,'next_plan_id'=>null,'current_period_start'=>$start,'current_period_end'=>$end]);
                $this->charge($this->invoice($sub,$start,$end,$this->price($plan,$sub->billing_cycle),'renewal'));
                $this->emit('subscription.renewed',$sub->fresh()->toArray(),$sub->seller_id);$renewed++;
            });}catch(\Throwable $e){report($e);$errors++;}
        }
        // BIL-4: an open invoice whose payment the gateway captured later is paid in the same run (payments run first).
        $reconciled=0;
        foreach(Invoice::where('status','open')->whereIn('id',Payment::where('status','captured')->whereNotNull('invoice_id')->select('invoice_id'))->limit(100)->get() as $open){
            try{DB::transaction(function()use($open,&$reconciled){
                $sub=Subscription::whereKey($open->subscription_id)->lockForUpdate()->firstOrFail();
                $invoice=Invoice::whereKey($open->id)->lockForUpdate()->firstOrFail();if($invoice->status!=='open')return;
                if(!in_array($sub->status,['cancelled','expired'])){$this->charge($invoice);$reconciled++;return;}
                // A payment after a cancel settles the invoice but never reactivates the subscription; Finance decides.
                $payment=Payment::where('invoice_id',$invoice->id)->where('status','captured')->firstOrFail();
                $invoice->update(['status'=>'paid','paid_at'=>now(),'payment_id'=>$payment->id]);$this->emit('invoice.paid',$invoice->fresh()->toArray(),$invoice->seller_id);
                $this->notifyFinance('subscription_payment_after_cancel','Payment received after cancellation',$invoice->reference.' was paid after subscription '.$sub->id.' was cancelled. The subscription was not reactivated: resume it or refund the payment.',['subscription_id'=>$sub->id,'invoice_id'=>$invoice->id]);
                $reconciled++;
            });}catch(\Throwable $e){report($e);$errors++;}
        }
        // Past-due retries, plus current subscriptions whose last charge was left pending at the gateway.
        foreach(Subscription::whereIn('status',['trialing','active','past_due'])->where('next_retry_at','<=',now())->orderBy('id')->limit(100)->get() as $candidate){
            try{DB::transaction(function()use($candidate,&$retried){
                $sub=Subscription::whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                if(!in_array($sub->status,['trialing','active','past_due'])||!$sub->next_retry_at||$sub->next_retry_at->isFuture())return;
                $invoice=$sub->invoices()->where('status','open')->latest('id')->lockForUpdate()->first();
                if(!$invoice||Payment::where('invoice_id',$invoice->id)->whereIn('status',['pending','authorized'])->exists())return;
                $this->charge($invoice);$retried++;
            });}catch(\Throwable $e){report($e);$errors++;}
        }
        $zone=Setting::valueFor('business_timezone',config('sfa.timezone'));$today=CarbonImmutable::now($zone)->startOfDay();
        $monthly=Setting::valueFor('settlement_schedule','weekly')==='monthly';
        // The last complete period plus the seven before it (catch-up): a voided draft or a late entry in an older period
        // gets its draft without a manual Generate. Periods without unsettled entries create nothing.
        $checked=0;$generated=0;$periods=[];
        for($back=1;$back<=8;$back++){
            $from=$monthly?$today->subMonthsNoOverflow($back)->startOfMonth():$today->startOfWeek()->subWeeks($back);$to=$monthly?$from->endOfMonth():$from->addDays(6);
            try{$rows=$this->generate($from->toDateString(),$to->toDateString(),null,null,true,$errors);$checked+=count($rows);$new=count(array_filter($rows,fn($row)=>$row->wasRecentlyCreated));if($new){$generated+=$new;$periods[]=[$from->toDateString(),$to->toDateString()];}}catch(\Throwable $e){report($e);$errors++;}
        }
        // One digest per run instead of one bell per seller or period.
        if($generated){$start=min(array_column($periods,0));$end=max(array_column($periods,1));$this->notifyFinance('settlement_ready','Settlements ready to finalize',$generated.' draft settlement'.($generated===1?' is':'s are').' ready for review for '.$start.' to '.$end.'.',['period_start'=>$start,'period_end'=>$end,'count'=>$generated]);}
        // Auto-finalize (BIL-2, 0 = off): each draft is re-checked under its lock, then finalized like the button would.
        $finalized=0;$days=(int)Setting::valueFor('settlement_auto_finalize_days');
        if($days>0)foreach($this->autoFinalizable()->where('created_at','<=',now()->subDays($days))->orderBy('id')->limit(100)->get() as $draft){
            try{DB::transaction(function()use($draft,&$finalized){
                if(!$this->autoFinalizable()->whereKey($draft->id)->lockForUpdate()->first())return;
                $settlement=$this->settlementAction($draft,'finalize');
                AuditLog::system($settlement,['status'=>'draft'],$settlement->only(['status','finalized_at']),'settlement_auto_finalize');$finalized++;
            });}catch(\Throwable $e){report($e);$errors++;}
        }
        return ['subscriptions_auto_created'=>$subscribed,'subscriptions_renewed'=>$renewed,'invoices_reconciled'=>$reconciled,'billing_retries'=>$retried,'settlements_checked'=>$checked,'settlements_generated'=>$generated,'settlements_auto_finalized'=>$finalized,'billing_errors'=>$errors];
    }

    private function invoice(Subscription $sub,CarbonImmutable $start,CarbonImmutable $end,int $amount,string $kind): Invoice
    {
        $invoice=Invoice::firstOrCreate(['subscription_id'=>$sub->id,'period_start'=>$start,'kind'=>$kind],['seller_id'=>$sub->seller_id,'period_end'=>$end,'line_items'=>[['description'=>$sub->plan->name.' '.$sub->billing_cycle.' subscription','amount'=>$amount]],'subtotal_amount'=>$amount,'total_amount'=>$amount,'status'=>'open','due_date'=>$start->timezone(Setting::valueFor('business_timezone',config('sfa.timezone')))->toDateString()]);
        if(!$invoice->reference)Reference::assign($invoice,'INV');return $invoice;
    }
    /**
     * A cancelled subscription leaves no collectable invoice behind: past-due debt is written off (uncollectible), anything
     * else is voided. An invoice whose payment is still with the gateway, or already captured, is left for that payment.
     */
    private function closeOpenInvoices(Subscription $sub,string $status): void
    {
        foreach(Invoice::where('subscription_id',$sub->id)->whereIn('status',['draft','open'])->lockForUpdate()->get() as $invoice){
            if(Payment::where('invoice_id',$invoice->id)->whereIn('status',['pending','authorized','captured'])->exists())continue;
            $invoice->update(['status'=>$status==='past_due'?'uncollectible':'void']);
        }
    }
    /** Billing events are private to the integration client that owns the seller's source system. */
    private function emit(string $type,array $data,int $sellerId): void { $this->webhooks->emitFor($type,$data,Seller::whereKey($sellerId)->value('source_system')); }
    private function price(Plan $plan,string $cycle): int { return $cycle==='annual'?$plan->annual_price_amount:$plan->monthly_price_amount; }
    /** Periods are calendar months or years in the business time zone (1 Oct 00:00 Manila ends 1 Nov 00:00 Manila), stored in UTC. */
    private function periodEnd(CarbonImmutable $start,string $cycle): CarbonImmutable { $local=$start->timezone(Setting::valueFor('business_timezone',config('sfa.timezone')));return ($cycle==='annual'?$local->addYearNoOverflow():$local->addMonthNoOverflow())->utc(); }
    private function invalid(string $field,string $message): never { throw ValidationException::withMessages([$field=>[$message]]); }
    private function notifyFinance(string $type,string $title,string $message,array $data=[]): void { $permission=$type==='settlement_ready'?'finalize settlements':'edit subscriptions';foreach(\App\Models\User::authorizedFor($permission)->where('is_active',true)->get(['id']) as $user)\App\Models\Notification::create(['user_id'=>$user->id,'type'=>$type,'title'=>$title,'message'=>$message,'data'=>$data]); }
}
