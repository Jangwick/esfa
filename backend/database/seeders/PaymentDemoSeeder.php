<?php
namespace Database\Seeders;
use App\Gateways\GatewayEvent;
use App\Models\Category;
use App\Models\CommissionRule;
use App\Support\Money;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Reconciliation;
use App\Models\Seller;
use App\Models\Setting;
use App\Models\User;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
class PaymentDemoSeeder extends Seeder {
    public function run():void {
        if(app()->environment('production')){return;}
        $service=app(PaymentService::class);$admin=User::where('email','admin@example.com')->firstOrFail();$finance=User::where('email','finance@example.com')->firstOrFail();
        $categories=[];foreach(['demo-general'=>'Demo general merchandise','demo-electronics'=>'Demo electronics','demo-home'=>'Demo home and living'] as $external=>$name){$categories[]=Category::firstOrCreate(['source_system'=>'demo-storefront','external_id'=>$external],['name'=>$name]);}
        $sellers=[];
        for($i=1;$i<=5;$i++){$sellers[]=Seller::firstOrCreate(['source_system'=>'demo-storefront','external_id'=>'demo-seller-'.$i],['shop_name'=>'Demo Shop '.$i,'owner_name'=>'Demo Seller '.$i,'email'=>'seller.'.$i.'@example.com','payout_destination'=>['bank_name'=>'Demo Bank','account_number'=>'000000000'.(1000+$i),'account_name'=>'Demo Seller '.$i],'pickup_address'=>['line1'=>'Demo pickup location '.$i,'contact_name'=>'Demo Seller '.$i,'contact_phone'=>'09000000000']]);}
        // Category and seller rules demonstrate precedence (seller > plan > category > global); plan rules are seeded by BillingDemoSeeder.
        CommissionRule::firstOrCreate(['scope'=>'category','scope_id'=>$categories[1]->id],['rate_bp'=>800,'fixed_fee_amount'=>0,'effective_from'=>now()->subYears(5)]);
        CommissionRule::firstOrCreate(['scope'=>'seller','scope_id'=>$sellers[1]->id],['rate_bp'=>300,'fixed_fee_amount'=>500,'effective_from'=>now()->subYears(5)]);
        $current=now();$states=['captured','pending','authorized','failed','cancelled','expired','partially_refunded','refunded','disputed','dispute_won','dispute_lost','captured'];
        try{
            for($index=1;$index<=60;$index++){
                $external='demo-order-'.str_pad((string)$index,3,'0',STR_PAD_LEFT);if(Order::where('source_system','demo-storefront')->where('external_id',$external)->exists()){continue;}
                $date=$current->copy()->subDays(($index-1)%45)->startOfDay()->addHours(8+($index%10));Carbon::setTestNow($date);
                $customer=Customer::firstOrCreate(['source_system'=>'demo-storefront','external_id'=>'demo-customer-'.(($index-1)%12+1)],['name'=>'Demo Customer '.(($index-1)%12+1),'email'=>'customer.'.(($index-1)%12+1).'@example.com','phone'=>'09000000000','default_address'=>['line1'=>'Demo delivery address','contact_name'=>'Demo customer','contact_phone'=>'09000000000']]);
                $state=$states[($index-1)%count($states)];$amount=10000+$index*300; if(in_array($state,['pending','authorized','cancelled','expired'],true)){$amount=intdiv($amount,100)*100+98;}elseif($state==='failed'){$amount=intdiv($amount,100)*100+99;}
                $order=Order::create(['source_system'=>'demo-storefront','external_id'=>$external,'customer_id'=>$customer->id,'status_snapshot'=>'placed','subtotal_amount'=>$amount,'total_amount'=>$amount,'placed_at'=>$date]);$order->items()->create(['seller_id'=>$sellers[($index-1)%count($sellers)]->id,'category_id'=>$categories[$index%count($categories)]->id,'product_name'=>'Demo product '.$index,'sku'=>'DEMO-'.str_pad((string)$index,3,'0',STR_PAD_LEFT),'quantity'=>1,'unit_price_amount'=>$amount,'line_total_amount'=>$amount]);
                $method=['card','gcash','maya','bank_transfer'][($index-1)%4];if($index%12===0){$method='cod';}$payment=$service->create($order,$method)['payment'];
                if($state==='authorized'){$service->processEvent('sandbox',new GatewayEvent('demo-authorize-'.$index,'payment.authorized',$payment->reference),['demo'=>true]);}
                elseif($state==='cancelled'){$service->cancel($payment);$order->update(['status_snapshot'=>'cancelled']);}
                elseif($state==='expired'){$payment->update(['expires_at'=>now()->subMinute()]);$service->runScheduled();}
                elseif(in_array($state,['partially_refunded','refunded'],true)){$refund=$service->requestRefund($payment,['amount'=>$state==='refunded'?$amount:intdiv($amount,2),'reason'=>'other','notes'=>'Clearly fake demo refund.'],$admin);$service->approve($refund,$finance);}
                elseif(in_array($state,['disputed','dispute_won','dispute_lost'],true)){$service->processEvent('sandbox',new GatewayEvent('demo-dispute-'.$index,'payment.disputed',$payment->reference),['demo'=>true]);if($state!=='disputed'){$service->processEvent('sandbox',new GatewayEvent('demo-dispute-result-'.$index,'payment.'.$state,$payment->reference),['demo'=>true]);}}
                // COD stays pending here; LogisticsDemoSeeder captures it by delivering the shipments with cash collected.
            }
        }finally{Carbon::setTestNow();}
        // One high-value order above the ₱50,000 risk threshold so the Finance risk-review queue has an example.
        if(!Order::where('source_system','demo-storefront')->where('external_id','demo-order-risk')->exists()){
            $customer=Customer::where('source_system','demo-storefront')->where('external_id','demo-customer-1')->first();
            $order=Order::create(['source_system'=>'demo-storefront','external_id'=>'demo-order-risk','customer_id'=>$customer->id,'status_snapshot'=>'placed','subtotal_amount'=>7500000,'total_amount'=>7500000,'placed_at'=>now()]);
            $order->items()->create(['seller_id'=>$sellers[2]->id,'category_id'=>$categories[1]->id,'product_name'=>'Demo high-value item','sku'=>'DEMO-RISK','quantity'=>1,'unit_price_amount'=>7500000,'line_total_amount'=>7500000]);
            $service->create($order,'card');
        }
        // Clearly synthetic gateway fees (2.5%) so settlements show the fee deduction; the sandbox itself reports no fees.
        foreach(Payment::where('gateway','sandbox')->whereNull('invoice_id')->where('captured_amount','>',0)->where('fee_amount',0)->get() as $paid){$paid->update(['fee_amount'=>Money::halfUp($paid->captured_amount,250,10000)]);}
        $payment=Payment::where('captured_amount','>',0)->first();
        if($payment&&!Reconciliation::where('filename','Demo settlement.csv')->exists()){
            $run=Reconciliation::create(['filename'=>'Demo settlement.csv','mime_type'=>'text/csv','size'=>58,'content'=>base64_encode("date,gateway_reference,amount,fee\n"),'date_from'=>$current->copy()->subDays(45)->toDateString(),'date_to'=>$current->toDateString(),'created_by'=>$finance->id]);
            foreach(['matched','amount_mismatch','missing_in_sfa','missing_in_report'] as $index=>$status){$run->items()->create(['payment_id'=>$status==='missing_in_sfa'?null:$payment->id,'date'=>$current->toDateString(),'gateway_reference'=>$status==='missing_in_sfa'?'demo-external-missing':$payment->gateway_reference,'total_amount'=>$payment->captured_amount+($status==='amount_mismatch'?100:0),'fee_amount'=>0,'status'=>$status]);}
        }
        $payment=Payment::where('status','captured')->first();if($payment&&!$payment->refunds()->where('status','requested')->exists()){$service->requestRefund($payment,['amount'=>100,'reason'=>'damaged','notes'=>'Demo refund awaiting Finance approval.'],$admin);}
    }
}

