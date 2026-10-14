<?php

namespace App\Http\Controllers;

use App\Http\Requests\BillingRequest;
use App\Http\Requests\ListRequest;
use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\Invoice;
use App\Models\Payout;
use App\Models\Plan;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Subscription;
use App\Services\BillingService;
use App\Support\ListResponse;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    private const STATUSES=['subscriptions'=>['trialing','active','past_due','cancelled','expired'],'invoices'=>['draft','open','paid','void','uncollectible'],'settlements'=>['draft','finalized','paid','void'],'payouts'=>['pending','paid','failed']];
    private const MODELS=['plans'=>Plan::class,'subscriptions'=>Subscription::class,'invoices'=>Invoice::class,'commission-rules'=>CommissionRule::class,'commissions'=>CommissionEntry::class,'settlements'=>Settlement::class,'payouts'=>Payout::class];
    public function index(ListRequest $request)
    {
        $resource=$request->route('resource');$this->view($request,$resource);
        $request->validate(['seller_id'=>'nullable|integer|exists:sellers,id','plan_id'=>'nullable|integer|exists:plans,id','subscription_id'=>'nullable|integer|exists:subscriptions,id','payment_id'=>'nullable|integer|exists:payments,id','scope'=>'nullable|in:global,category,plan,seller','is_active'=>'nullable|boolean','status'=>['nullable',Rule::in(self::STATUSES[$resource]??[])]]);
        $class=self::MODELS[$resource];$query=$class::query();$columns=DB::getSchemaBuilder()->getColumnListing($query->getModel()->getTable());
        if($request->filled('search')){$search='%'.mb_strtolower($request->search).'%';$query->where(function($q)use($search,$columns){foreach(array_intersect(['name','reference','code','scope','destination_masked'],$columns) as $column)$q->orWhereRaw('LOWER('.$column.') LIKE ?',[$search]);if(in_array('seller_id',$columns))$q->orWhereHas('seller',fn($s)=>$s->whereRaw('LOWER(shop_name) LIKE ?',[$search]));});}
        foreach(['seller_id','plan_id','subscription_id','payment_id','status','scope'] as $filter)if(in_array($filter,$columns)&&$request->filled($filter))$query->where($filter,$request->input($filter));
        if($request->filled('is_active')&&in_array('is_active',$columns))$query->where('is_active',$request->boolean('is_active'));
        ListResponse::dates($query,$request);ListResponse::sort($query,$request,array_intersect(['id','created_at','name','reference','sort_order'],$columns));
        $relations=match($resource){'subscriptions'=>['seller','plan','nextPlan'],'invoices'=>['seller','subscription.plan','payment'],'commissions'=>['seller','orderItem','payment'],'settlements'=>['seller','payouts'],'payouts'=>['settlement.seller'],default=>[]};$query->with($relations);
        if($request->export==='csv')return ListResponse::csv($query,$resource.'.csv',$columns,fn($row)=>array_map(fn($field)=>is_array($row->$field)?json_encode($row->$field):(string)$row->$field,$columns));
        return ListResponse::paginate($query,$request,array_merge(['total'=>(clone $query)->count()],$this->summary($request)));
    }
    public function show(Request $request,string $id)
    {
        $resource=$request->route('resource');$this->view($request,$resource);$class=self::MODELS[$resource];$model=$class::findOrFail($id);
        $relations=match($resource){'subscriptions'=>['seller','plan','nextPlan','invoices.payment'],'invoices'=>['seller','subscription.plan','payment'],'settlements'=>['seller','entries.orderItem','payouts'],default=>[]};
        if($resource==='settlements')$model->setAttribute('auto_finalize_at',app(BillingService::class)->autoFinalizeAt($model));
        return response()->json($model->load($relations));
    }
    public function store(BillingRequest $request,BillingService $billing)
    {
        $resource=$request->route('resource');$data=$request->validated();
        $model=$resource==='subscriptions'?$billing->subscribe($data):($resource==='commission-rules'?$this->rule($data):Plan::create($data));$this->audit($model,null);
        return response()->json($model,201);
    }
    public function update(BillingRequest $request,string $id)
    {
        $resource=$request->route('resource');$class=self::MODELS[$resource];$model=$class::findOrFail($id);$old=$model->toArray();
        if($resource==='commission-rules')$model=$this->rule($request->validated(),$model);else $model->update($request->validated());$this->audit($model,$old);
        return response()->json($model->fresh());
    }
    public function destroy(BillingRequest $request,string $id)
    {
        $resource=$request->route('resource');$class=self::MODELS[$resource];$model=$class::findOrFail($id);$old=$model->toArray();
        DB::transaction(function()use($resource,$model){
            if($resource==='plans'&&$model->subscriptions()->exists())throw ValidationException::withMessages(['plan'=>['A plan with subscriptions must be deactivated instead of deleted.']]);
            if($resource==='commission-rules'&&CommissionEntry::where('rule_id',$model->id)->exists())throw ValidationException::withMessages(['rule'=>['A rule already used for commissions cannot be deleted. End its effective period.']]);
            $model->delete();if($resource==='commission-rules')$this->assertGlobalRule();
        });$this->audit($model,$old);return response()->json(['message'=>'Record deleted successfully.']);
    }
    public function options(Request $request)
    {
        $this->view($request,'subscriptions');$user=$request->user();
        $sellerFields=['id','shop_name','owner_name'];if($user->can('pay settlements'))$sellerFields[]='payout_destination_masked';
        return response()->json(['sellers'=>Seller::orderBy('shop_name')->get()->map(fn($seller)=>$seller->only($sellerFields))->values(),'plans'=>$user->can('view plans')||$user->can('create subscriptions')||$user->can('edit subscriptions')?Plan::orderBy('sort_order')->get():[],'categories'=>$user->can('view commission rules')||$user->can('create commission rules')||$user->can('edit commission rules')?\App\Models\Category::orderBy('name')->get(['id','name']):[],'settlement_auto_finalize_days'=>$user->can('view settlements')?(int)Setting::valueFor('settlement_auto_finalize_days'):0,
            // BillingService::subscribe refuses these sellers, so the New subscription form greys them out.
            'current_subscription_seller_ids'=>Subscription::whereIn('status',['active','trialing','past_due'])->distinct()->orderBy('seller_id')->pluck('seller_id')]);
    }
    public function changePlan(BillingRequest $r,string $id,BillingService $s){$old=Subscription::findOrFail($id);$new=$s->changePlan($old,Plan::findOrFail($r->plan_id));$this->audit($new,$old->toArray());return response()->json($new);}
    public function cancel(BillingRequest $r,string $id,BillingService $s){$old=Subscription::findOrFail($id);$new=$s->cancel($old,$r->boolean('immediate'));$this->audit($new,$old->toArray());return response()->json($new);}
    public function resume(BillingRequest $r,string $id,BillingService $s){$old=Subscription::findOrFail($id);$new=$s->resume($old);$this->audit($new,$old->toArray());return response()->json($new);}
    public function charge(BillingRequest $r,string $id,BillingService $s){$old=Invoice::findOrFail($id);$new=$s->charge($old);$this->audit($new,$old->toArray());return response()->json($new);}
    public function voidInvoice(BillingRequest $r,string $id,BillingService $s){$old=Invoice::findOrFail($id);$new=$s->voidInvoice($old);$this->audit($new,$old->toArray());return response()->json($new);}
    public function generate(BillingRequest $r,BillingService $s){return response()->json(['data'=>$s->generate($r->period_start,$r->period_end,$r->seller_id,$r->user()->id)],201);}
    public function finalize(BillingRequest $r,string $id,BillingService $s){return $this->settlement($r,$id,$s,'finalize');}
    public function pay(BillingRequest $r,string $id,BillingService $s){return $this->settlement($r,$id,$s,'pay');}
    public function voidSettlement(BillingRequest $r,string $id,BillingService $s){return $this->settlement($r,$id,$s,'void');}
    private function settlement(BillingRequest $r,string $id,BillingService $s,string $action){$old=Settlement::findOrFail($id);$new=$s->settlementAction($old,$action,$r->validated());$this->audit($new,$old->toArray());return response()->json($new);}
    private function rule(array $data,?CommissionRule $model=null): CommissionRule
    {
        // A plain date means midnight in the business time zone. Store UTC: Eloquent would otherwise drop the offset
        // and save local midnight as UTC midnight, starting the rule hours late.
        $zone=Setting::valueFor('business_timezone',config('sfa.timezone'));
        foreach(['effective_from','effective_to'] as $key){if(!empty($data[$key]))$data[$key]=\Carbon\CarbonImmutable::parse($data[$key],$zone)->utc();}
        return DB::transaction(function()use($data,$model){
            // Serialize rule changes on one settings row (created if missing) so global rules cannot race into an overlap.
            Setting::firstOrCreate(['key'=>'currency'],['value'=>'PHP']);Setting::where('key','currency')->lockForUpdate()->firstOrFail();
            if($data['scope']==='global'){
                $newFrom=\Carbon\CarbonImmutable::parse($data['effective_from']);
                foreach(CommissionRule::where('scope','global')->when($model,fn($q)=>$q->where('id','!=',$model->id))->get() as $existing){
                    if($existing->effective_from<$newFrom&&(!$existing->effective_to||$existing->effective_to>$newFrom))$existing->update(['effective_to'=>$newFrom]);
                    elseif($existing->effective_from>=$newFrom&&(empty($data['effective_to'])||\Carbon\CarbonImmutable::parse($data['effective_to'])>$existing->effective_from))throw ValidationException::withMessages(['effective_from'=>['A later global commission rule already exists. End this rule before it starts.']]);
                }
            }
            if($model)$model->update($data);else $model=CommissionRule::create($data);$this->assertGlobalRule();return $model;
        });
    }
    private function assertGlobalRule(): void { if(CommissionRule::where('scope','global')->where('effective_from','<=',now())->where(fn($q)=>$q->whereNull('effective_to')->orWhere('effective_to','>',now()))->count()!==1)throw ValidationException::withMessages(['scope'=>['Exactly one active global commission rule must exist.']]); }
    private function view(Request $r,string $resource): void { abort_unless($r->user()->can('view '.str_replace('-',' ',$resource)),403,'This action requires view '.str_replace('-',' ',$resource).'.'); }
    private function audit($model,?array $old): void { request()->attributes->set('audit.entity_type',class_basename($model));request()->attributes->set('audit.entity_id',$model->id);request()->attributes->set('audit.old_values',$old);request()->attributes->set('audit.new_values',$model->toArray()); }
    private function summary(Request $request): array
    {
        $user=$request->user();$subscriptions=$user->can('view subscriptions');
        $mrr=0;if($subscriptions)foreach(Subscription::with('plan')->where('status','active')->get() as $sub)$mrr+=$sub->billing_cycle==='annual'?Money::halfUp($sub->plan->annual_price_amount,1,12):$sub->plan->monthly_price_amount;
        return ['active'=>$subscriptions?Subscription::where('status','active')->count():0,'trialing'=>$subscriptions?Subscription::where('status','trialing')->count():0,'past_due'=>$subscriptions?Subscription::where('status','past_due')->count():0,'mrr_amount'=>$mrr,'commission_amount'=>$user->can('view commissions')?(int)CommissionEntry::where('created_at','>=',now()->timezone(Setting::valueFor('business_timezone',config('sfa.timezone')))->startOfMonth()->utc())->sum('commission_amount'):0,'payable_amount'=>$user->can('view settlements')?(int)Settlement::whereIn('status',['draft','finalized'])->whereNull('recovered_by_settlement_id')->sum('net_payable_amount'):0];
    }
}
