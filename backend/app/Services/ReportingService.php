<?php
namespace App\Services;
use App\Models\{CommissionEntry,Courier,IntegrationClient,Invoice,LoginLockout,Order,Payment,ReconciliationItem,Refund,SchedulerLock,Seller,Setting,Settlement,Shipment,Subscription,Ticket,User,WebhookDelivery,WebhookEndpoint};
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;

class ReportingService
{
    public function range(array $filters): array
    {
        $zone=Setting::valueFor('business_timezone','Asia/Manila');
        $to=CarbonImmutable::parse($filters['date_to']??now($zone)->toDateString(),$zone)->startOfDay();
        $from=CarbonImmutable::parse($filters['date_from']??$to->subDays(29)->toDateString(),$zone)->startOfDay();
        return [$from->utc(),$to->addDay()->utc(),$zone];
    }
    private function period(Builder $query,array $filters,string $column='created_at'): Builder
    {
        [$start,$end]=$this->range($filters);return $query->where($column,'>=',$start)->where($column,'<',$end);
    }
    // An integration client reads only its own source system, unless an Admin let it read every storefront's financials.
    private function ownOnly(User|IntegrationClient $actor): bool { return $actor instanceof IntegrationClient&&!$actor->reads_all_financials; }
    private function orders(User|IntegrationClient $actor,array $filters): Builder
    {
        $query=Order::query();
        if($this->ownOnly($actor))$query->where('source_system',$actor->system_code);
        if(!empty($filters['seller_id']))$query->whereHas('items',fn($q)=>$q->where('seller_id',$filters['seller_id']));
        if(!empty($filters['method']))$query->whereHas('payments',fn($q)=>$q->where('method',$filters['method']));
        if(!empty($filters['courier_id']))$query->whereIn('id',Shipment::where('courier_id',$filters['courier_id'])->select('order_id'));
        return $query;
    }
    private function payments(User|IntegrationClient $actor,array $filters): Builder
    {
        $query=Payment::query();$query->where(function($q)use($actor,$filters){
            $q->whereIn('order_id',$this->orders($actor,$filters)->select('id'));
            if(empty($filters['courier_id']))$q->orWhere(function($q)use($actor,$filters){$q->whereNotNull('invoice_id');if(!empty($filters['seller_id']))$q->where('seller_id',$filters['seller_id']);if($this->ownOnly($actor))$q->whereHas('seller',fn($s)=>$s->where('source_system',$actor->system_code));});
        });
        if(!empty($filters['method']))$query->where('method',$filters['method']);
        if($actor instanceof User&&$actor->hasRole('Administrative Assistant')&&!$actor->hasAnyRole(['Admin','Accounting Staff','Quality Control Analyst']))$query->where(function($q)use($actor){$visible=app(TicketService::class)->visibleQuery($actor);$q->whereIn('id',(clone $visible)->whereNotNull('payment_id')->select('payment_id'))->orWhereIn('order_id',(clone $visible)->whereNotNull('order_id')->select('order_id'));});
        return $query;
    }
    private function tickets(User|IntegrationClient $actor,array $filters): Builder
    {
        $q=Ticket::query();
        if($actor instanceof User&&!$actor->hasAnyRole(['Admin','Accounting Staff','Quality Control Analyst']))$q=$actor->can('view tickets')?app(TicketService::class)->visibleQuery($actor):$q->whereRaw('1 = 0');
        elseif($this->ownOnly($actor))$q->where('source_system',$actor->system_code);
        if(!empty($filters['seller_id'])||!empty($filters['method'])||!empty($filters['courier_id']))$q->whereIn('order_id',$this->orders($actor,$filters)->select('id'));
        return $q;
    }
    private function shipments(User|IntegrationClient $actor,array $filters): Builder
    {
        $q=Shipment::query()->whereIn('order_id',$this->orders($actor,$filters)->select('id'));
        if(!empty($filters['seller_id']))$q->where('seller_id',$filters['seller_id']);
        if(!empty($filters['courier_id']))$q->where('courier_id',$filters['courier_id']);
        if($actor instanceof User&&$actor->hasRole('Administrative Assistant')&&!$actor->hasAnyRole(['Admin','Accounting Staff','Warehouseman','Quality Control Analyst'])){$visible=app(TicketService::class)->visibleQuery($actor);$q->where(fn($q)=>$q->whereIn('id',(clone $visible)->whereNotNull('shipment_id')->select('shipment_id'))->orWhereIn('order_id',(clone $visible)->whereNotNull('order_id')->select('order_id')));}
        return $q;
    }
    private function subscriptions(User|IntegrationClient $actor,array $filters): Builder
    {
        $q=Subscription::with('plan')->where('status','active');if(!empty($filters['seller_id']))$q->where('seller_id',$filters['seller_id']);if($this->ownOnly($actor))$q->whereHas('seller',fn($s)=>$s->where('source_system',$actor->system_code));return $q;
    }
    private function invoices(User|IntegrationClient $actor,array $filters): Builder
    {
        $q=Invoice::where('status','paid')->whereIn('payment_id',$this->payments($actor,$filters)->select('id'));return $this->period($q,$filters,'paid_at');
    }
    private function entries(User|IntegrationClient $actor,array $filters): Builder
    {
        $q=CommissionEntry::query()->whereIn('payment_id',$this->payments($actor,$filters)->select('id'));if(!empty($filters['seller_id']))$q->where('seller_id',$filters['seller_id']);return $this->period($q,$filters);
    }
    public function summary(User|IntegrationClient $actor,array $filters): array
    {
        $payments=$this->period($this->payments($actor,$filters),$filters,'captured_at');$refunds=$this->period(Refund::where('status','completed')->whereIn('payment_id',$this->payments($actor,$filters)->select('id')),$filters,'completed_at');
        $captured=(int)(clone $payments)->sum('captured_amount');$refunded=(int)(clone $refunds)->sum('total_amount');$fees=(int)(clone $payments)->sum('fee_amount');$commission=(int)$this->entries($actor,$filters)->sum('commission_amount');$subscriptionFees=(int)$this->invoices($actor,$filters)->sum('total_amount');
        $orders=$this->period($this->orders($actor,$filters),$filters,'placed_at')->where('status_snapshot','!=','cancelled');
        $gmv=empty($filters['seller_id'])?(int)$orders->sum('total_amount'):(int)\App\Models\OrderItem::where('seller_id',$filters['seller_id'])->whereIn('order_id',$orders->select('id'))->sum('line_total_amount');
        $tickets=$this->period($this->tickets($actor,$filters),$filters)->get();$resolved=$tickets->whereIn('status',['resolved','closed']);$met=$resolved->filter(fn($t)=>$t->sla_state==='met')->count();
        $shipments=$this->period($this->shipments($actor,$filters),$filters,'delivered_at')->where('status','delivered')->get();$onTime=$shipments->filter(fn($s)=>$s->on_time)->count();
        $mrr=0;foreach($this->subscriptions($actor,$filters)->get() as $sub)$mrr+=$sub->billing_cycle==='annual'?Money::halfUp($sub->plan->annual_price_amount,1,12):$sub->plan->monthly_price_amount;
        return ['currency'=>'PHP','gmv_amount'=>$gmv,'captured_amount'=>$captured,'captured_count'=>(clone $payments)->where('captured_amount','>',0)->count(),'refund_amount'=>$refunded,'refund_rate'=>$captured?round($refunded*100/$captured,2):0,'commission_amount'=>$commission,'subscription_amount'=>$subscriptionFees,'gateway_fees_amount'=>$fees,'net_revenue_amount'=>$commission+$subscriptionFees-$fees,'open_tickets'=>$tickets->whereIn('status',['open','reopened','in_progress','waiting_on_customer'])->count(),'sla_compliance'=>$resolved->count()?round($met*100/$resolved->count(),2):0,'sla_resolved_count'=>$resolved->count(),'shipments_in_transit'=>$this->shipments($actor,$filters)->whereIn('status',['picked_up','in_transit','out_for_delivery'])->count(),'on_time_rate'=>$shipments->count()?round($onTime*100/$shipments->count(),2):0,'delivered_count'=>$shipments->count(),'active_subscriptions'=>$this->subscriptions($actor,$filters)->count(),'mrr_amount'=>$mrr];
    }
    public function charts(User|IntegrationClient $actor,array $filters): array
    {
        [$start,$end,$zone]=$this->range($filters);$reports=[];$daily=[];
        for($date=$start->timezone($zone);$date<$end->timezone($zone);$date=$date->addDay())$daily[$date->toDateString()]=0;
        foreach($this->entries($actor,$filters)->get() as $entry){$date=$entry->created_at->timezone($zone)->toDateString();$daily[$date]=($daily[$date]??0)+$entry->commission_amount;}
        foreach($this->invoices($actor,$filters)->get() as $invoice){$date=$invoice->paid_at->timezone($zone)->toDateString();$daily[$date]=($daily[$date]??0)+$invoice->total_amount;}
        foreach($this->period($this->payments($actor,$filters),$filters,'captured_at')->get() as $payment){$date=$payment->captured_at->timezone($zone)->toDateString();$daily[$date]=($daily[$date]??0)-$payment->fee_amount;}
        $reports['revenue_by_day']=$this->series($daily,'Net platform revenue by day','money','bar');
        $reports['payments_by_method']=$this->series($this->period($this->payments($actor,$filters),$filters)->selectRaw('method, COUNT(*) as aggregate')->groupBy('method')->pluck('aggregate','method')->all(),'Payments by method','count','pie');
        $reports['refunds_by_reason']=$this->series($this->period(Refund::whereIn('payment_id',$this->payments($actor,$filters)->select('id')),$filters)->selectRaw('reason, COUNT(*) as aggregate')->groupBy('reason')->pluck('aggregate','reason')->all(),'Refunds by reason','count','bar');
        $tickets=$this->period($this->tickets($actor,$filters),$filters);
        $reports['tickets_by_category']=$this->series((clone $tickets)->selectRaw('category, COUNT(*) as aggregate')->groupBy('category')->pluck('aggregate','category')->all(),'Tickets by category','count','bar');
        $reports['ticket_status']=$this->series((clone $tickets)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate','status')->all(),'Ticket status','count','pie');
        $reports['shipment_status']=$this->series($this->period($this->shipments($actor,$filters),$filters)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate','status')->all(),'Shipments by status','count','pie');
        $byCourier=[];foreach($this->period($this->shipments($actor,$filters),$filters,'delivered_at')->where('status','delivered')->with('courier')->get()->groupBy('courier_id') as $group)$byCourier[$group->first()->courier->name]=round($group->filter(fn($s)=>$s->on_time)->count()*100/$group->count(),2);
        $reports['on_time_by_courier']=$this->series($byCourier,'On-time rate by courier','percent','bar');
        $items=\App\Models\OrderItem::whereIn('order_id',$this->period($this->orders($actor,$filters),$filters,'placed_at')->where('status_snapshot','!=','cancelled')->select('id'));if(!empty($filters['seller_id']))$items->where('seller_id',$filters['seller_id']);
        $top=[];foreach($items->selectRaw('seller_id, SUM(line_total_amount) as aggregate')->groupBy('seller_id')->orderByDesc('aggregate')->limit(10)->get() as $row)$top[Seller::find($row->seller_id)->shop_name]=(int)$row->aggregate;
        $reports['top_sellers']=$this->series($top,'Top 10 sellers by GMV','money','bar');$plans=[];
        foreach($this->subscriptions($actor,$filters)->where('current_period_start','<',$end)->where('current_period_end','>=',$start)->get() as $sub){$name=$sub->plan->name;$plans[$name]=($plans[$name]??0)+($sub->billing_cycle==='annual'?Money::halfUp($sub->plan->annual_price_amount,1,12):$sub->plan->monthly_price_amount);}
        $reports['mrr_by_plan']=$this->series($plans,'MRR by plan','money','pie');return $reports;
    }
    private function series(array $values,string $title,string $unit,string $type): array { return ['title'=>$title,'unit'=>$unit,'type'=>$type,'data'=>array_map(fn($key,$value)=>['name'=>(string)$key,'value'=>is_numeric($value)?$value+0:0],array_keys($values),array_values($values))]; }
    public function dashboard(User $user): array
    {
        $zone=Setting::valueFor('business_timezone','Asia/Manila');$filters=['date_from'=>now($zone)->startOfMonth()->toDateString(),'date_to'=>now($zone)->toDateString()];$all=$this->summary($user,$filters);$summary=[];
        $analyst=$user->hasRole('Quality Control Analyst');
        if($user->hasAnyRole(['Admin','Accounting Staff','Quality Control Analyst']))foreach(['gmv_amount','captured_amount','captured_count','net_revenue_amount','refund_rate'] as $key)$summary[$key]=$all[$key];
        if($user->can('view tickets')||$analyst)foreach(['open_tickets','sla_compliance','sla_resolved_count'] as $key)$summary[$key]=$all[$key];
        if($user->can('view shipments')||$analyst)foreach(['shipments_in_transit','on_time_rate','delivered_count'] as $key)$summary[$key]=$all[$key];
        if($user->can('view subscriptions')||$analyst)$summary['active_subscriptions']=$all['active_subscriptions'];
        $charts=$this->charts($user,$filters);$distribution=[];
        if($user->can('view tickets')||$analyst)$distribution['ticket_status']=$charts['ticket_status'];
        if($user->can('view payments')||$analyst)$distribution['payment_status']=$this->series($this->period($this->payments($user,$filters),$filters)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate','status')->all(),'Payment status','count','pie');
        if($user->can('view shipments')||$analyst)$distribution['shipment_status']=$charts['shipment_status'];
        $activity=[];if($user->can('view audit logs'))$activity=\App\Models\AuditLog::with('user:id,name')->latest()->limit(8)->get();
        $lock=$user->can('manage settings')?SchedulerLock::find('scheduled-work'):null;
        return ['summary'=>$summary,'distributions'=>$distribution,'activity'=>$activity,'attention'=>$this->attention($user,$lock)]+($user->can('manage settings')?['scheduler'=>['enabled'=>(bool)config('sfa.scheduler_enabled'),'last_run_at'=>$lock?->last_run_at?->toIso8601String()]]:[])+['timezone'=>$zone,'date_from'=>$filters['date_from'],'date_to'=>$filters['date_to']];
    }
    // "Needs attention": work waiting in each list this user may open, gated by that list's permission. Zero counts are left out.
    private function attention(User $user,?SchedulerLock $lock): array
    {
        $counts=[];
        if($user->can('manage integrations')){
            $counts['failed_deliveries']=WebhookDelivery::where('status','failed')->count();
            $counts['disabled_endpoints']=WebhookEndpoint::where('is_active',false)->whereNotNull('disabled_at')->count();
            $counts['expiring_tokens']=PersonalAccessToken::where('tokenable_type',(new IntegrationClient)->getMorphClass())->where('name','!=','simulator')->where('expires_at','>',now())->where('expires_at','<=',now()->addDays(7))->count();
        }
        if($user->hasRole('Admin'))$counts['locked_signins']=User::whereIn('email',LoginLockout::where('locked_until','>',now())->select('email'))->count();
        if($user->can('approve refunds'))$counts['refunds_requested']=Refund::where('status','requested')->count();
        if($user->can('edit payments'))$counts['risk_reviews']=Payment::where('risk_level','review')->whereIn('status',['pending','authorized'])->count();
        if($user->can('view tickets')){
            $open=app(TicketService::class)->visibleQuery($user)->whereNotIn('status',['resolved','closed']);
            $counts['unassigned_tickets']=(clone $open)->whereNull('assignee_id')->count();
            // A breached ticket is past one of its due times; sla_state settles pauses and responses on that short list.
            $counts['breached_tickets']=(clone $open)->where(fn($q)=>$q->where('first_response_due_at','<',now())->orWhere('resolution_due_at','<',now()))->get()->filter(fn($t)=>$t->sla_state==='breached')->count();
        }
        if($user->can('view subscriptions'))$counts['past_due_subscriptions']=Subscription::where('status','past_due')->count();
        if($user->can('view settlements'))$counts['draft_settlements']=Settlement::where('status','draft')->count();
        if($user->can('view reconciliation'))$counts['unresolved_reconciliation']=ReconciliationItem::where('status','!=','matched')->whereNull('resolved_at')->count();
        if($user->can('create shipments'))$counts['awaiting_booking']=app(LogisticsService::class)->awaitingBooking()->count();
        if($user->can('edit shipments'))$counts['overdue_shipments']=Shipment::overdue()->count();
        if($user->can('manage settings')){
            $counts['scheduler_stale']=$lock?->last_run_at?->gt(now()->subHours(26))?0:1;
            $counts['scheduler_failed_jobs']=count($lock?->last_result['failed_jobs']??[]);
        }
        return collect($counts)->filter()->map(fn($count,$key)=>['key'=>$key,'count'=>$count])->values()->all();
    }
    public function choices(): array { return ['sellers'=>Seller::orderBy('shop_name')->get(['id','shop_name']),'couriers'=>Courier::orderBy('name')->get(['id','name'])]; }
}
