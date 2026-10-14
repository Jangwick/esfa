<?php
namespace Database\Seeders;
use App\Models\{CommissionRule,Plan};
use Illuminate\Database\Seeder;
class BillingDemoSeeder extends Seeder
{
    public function run(): void
    {
        if(app()->environment('production'))return;
        foreach([
            ['Free (Demo)','demo-free',0,0,0,['Basic seller tools'],['max_products'=>20],null],
            ['Growth (Demo)','demo-growth',199900,1999000,14,['Advanced seller tools','Priority support'],['max_products'=>500],400],
            ['Pro (Demo)','demo-pro',499900,4999000,14,['Unlimited seller tools','Premium support'],['max_products'=>10000],300],
        ] as $i=>[$name,$code,$monthly,$annual,$trial,$features,$limits,$rate])Plan::firstOrCreate(['code'=>$code],['name'=>$name,'monthly_price_amount'=>$monthly,'annual_price_amount'=>$annual,'trial_days'=>$trial,'features'=>$features,'limits'=>$limits,'commission_rate_bp'=>$rate,'is_active'=>true,'sort_order'=>$i]);
        $pro=Plan::where('code','demo-pro')->first();
        if($pro)CommissionRule::firstOrCreate(['scope'=>'plan','scope_id'=>$pro->id],['rate_bp'=>250,'fixed_fee_amount'=>0,'effective_from'=>now()->subYears(5)]);
        if(!CommissionRule::where('scope','global')->where('effective_from','<=',now())->where(fn($q)=>$q->whereNull('effective_to')->orWhere('effective_to','>',now()))->exists())CommissionRule::create(['scope'=>'global','rate_bp'=>500,'fixed_fee_amount'=>0,'effective_from'=>now()->subYears(5)]);
    }
}
