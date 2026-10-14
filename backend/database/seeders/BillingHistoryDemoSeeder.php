<?php
namespace Database\Seeders;
use App\Models\{Invoice,Plan,Seller,Subscription};
use App\Services\BillingService;
use App\Support\Reference;
use Illuminate\Database\Seeder;
class BillingHistoryDemoSeeder extends Seeder
{
    public function run(): void
    {
        if(app()->environment('production'))return;
        $plans=Plan::where('code','like','demo-%')->orderBy('sort_order')->get();
        foreach(Seller::where('source_system','demo-storefront')->orderBy('id')->get() as $i=>$seller){
            if(Subscription::where('seller_id',$seller->id)->exists())continue;
            /* Active and trialing on Growth, past due and expired on Pro, cancelled on Free. */ $plan=$plans[[1,1,2,0,2][$i%5]];$status=['active','trialing','past_due','cancelled','expired'][$i%5];$start=now()->subDays(7);$end=now()->addDays(23);
            $sub=Subscription::create(['seller_id'=>$seller->id,'plan_id'=>$plan->id,'billing_cycle'=>$i%2?'annual':'monthly','status'=>$status,'trial_ends_at'=>$status==='trialing'?now()->addDays(7):null,'current_period_start'=>$start,'current_period_end'=>$end,'cancel_at_period_end'=>false,'cancelled_at'=>$status==='cancelled'?now()->subDay():null,'retry_count'=>$status==='past_due'?1:0,'next_retry_at'=>$status==='past_due'?now()->addDays(2):null]);
            if(in_array($status,['active','past_due'])){
                $amount=$status==='past_due'?10099:($sub->billing_cycle==='annual'?$plan->annual_price_amount:$plan->monthly_price_amount);
                $invoice=Invoice::create(['subscription_id'=>$sub->id,'seller_id'=>$seller->id,'period_start'=>$start,'period_end'=>$end,'kind'=>'initial','line_items'=>[['description'=>$plan->name.' demonstration subscription','amount'=>$amount]],'subtotal_amount'=>$amount,'total_amount'=>$amount,'status'=>'open','due_date'=>$start->toDateString()]);Reference::assign($invoice,'INV');
                if($status==='active')app(BillingService::class)->charge($invoice);
            }
        }
        $today=now('Asia/Manila')->startOfWeek();$billing=app(BillingService::class);
        foreach([2,1] as $weeks){$from=$today->copy()->subWeeks($weeks);$rows=$billing->generate($from->toDateString(),$from->copy()->addDays(6)->toDateString());foreach($rows as $i=>$row){if($row->status!=='draft')continue;$billing->settlementAction($row,'finalize');if($weeks===2&&$row->net_payable_amount>0)$billing->settlementAction($row,'pay',['method'=>'bank_transfer','destination_masked'=>'•••• 1234']);}}
    }
}
