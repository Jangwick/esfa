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
use Illuminate\Support\Str;
class PaymentDemoSeeder extends Seeder {
    public function run():void {
        if(app()->environment('production')){return;}
        $service=app(PaymentService::class);$admin=User::where('email','admin@example.com')->firstOrFail();$finance=User::where('email','finance@example.com')->firstOrFail();
        $categories=[];foreach(['demo-general'=>'Adhesives & sealants','demo-electronics'=>'Waterproofing & coatings','demo-home'=>'Concrete repair & mortars'] as $external=>$name){$categories[]=Category::firstOrCreate(['source_system'=>'demo-storefront','external_id'=>$external],['name'=>$name]);}
        // Archon Nell catalog lines per category index, so items read like a real order.
        $productRows=[
            [['AN Tile Adhesive 25kg','AN-TA25'],['AN Epoxy Grout 2kg','AN-EG2'],['AN Skimcoat White 20kg','AN-SC20']],
            [['AN FlexSeal Waterproofing 4L','AN-FS4'],['AN RoofSeal Elastomeric 16L','AN-RS16'],['AN Primer Plus 4L','AN-PP4']],
            [['AN Repair Mortar 25kg','AN-RM25'],['AN Joint Sealant PU 600ml','AN-JSPU'],['AN Concrete Hardener 20kg','AN-CH20']],
        ];
        // Sellers are construction-supply shops at real Philippine locations so logistics reads like a real marketplace.
        $sellerRows=[
            ['Kalibre BuildMart','Ramon Delgado','Unit 8, Commonwealth Ave., Fairview, Quezon City','1121'],
            ['ProMix Construction Supply','Grace Villanueva','1890 J.P. Rizal Ave., San Antonio, Makati City','1203'],
            ['Mandaue Steel & Tools','Enrico Bautista','A.C. Cortes Ave., Tipolo, Mandaue City','6014'],
            ['Davao Industrial Depot','Liza Mercado','Km. 5 Buhangin Rd., Buhangin, Davao City','8000'],
            ['Pinoy Paint World','Marco Santiago','Sta. Rosa Rd., Brgy. Macabling, Santa Rosa, Laguna','4026'],
            ['Cebu Stone & Tile','Andrea Lim','Ouano Ave., North Reclamation Area, Mandaue City','6014'],
            ['Batangas Hardware Central','Jose Ramos','P. Burgos St., Poblacion, Batangas City','4200'],
            ['Iloilo Mason Supply','Carmen Soriano','J.M. Basa St., City Proper, Iloilo City','5000'],
            ['Cagayan Builders Hub','Ricardo Torres','Nazareth Rd., Barangay 9, Cagayan de Oro City','9000'],
            ['Naga Aggregates & Supply','Fe Almeda','Panganiban Dr., Concepcion Pequena, Naga City','4400'],
        ];
        $sellers=[];
        $banks=['BDO Unibank','BPI','Metrobank','Security Bank','UnionBank','Land Bank','RCBC','PNB','China Bank','EastWest Bank'];
        foreach($sellerRows as $i=>[$shop,$owner,$line,$postal]){$n=$i+1;$sellers[]=Seller::updateOrCreate(['source_system'=>'demo-storefront','external_id'=>'demo-seller-'.$n],['shop_name'=>$shop,'owner_name'=>$owner,'email'=>'seller.'.$n.'@example.com','payout_destination'=>['bank_name'=>$banks[$i],'account_number'=>'000000000'.(1000+$n),'account_name'=>$owner],'pickup_address'=>['line1'=>$line,'postal_code'=>$postal,'contact_name'=>$owner,'contact_phone'=>'09000000000']]);}
        // Category and seller rules demonstrate precedence (seller > plan > category > global); plan rules are seeded by BillingDemoSeeder.
        CommissionRule::firstOrCreate(['scope'=>'category','scope_id'=>$categories[1]->id],['rate_bp'=>800,'fixed_fee_amount'=>0,'effective_from'=>now()->subYears(5)]);
        CommissionRule::firstOrCreate(['scope'=>'seller','scope_id'=>$sellers[1]->id],['rate_bp'=>300,'fixed_fee_amount'=>500,'effective_from'=>now()->subYears(5)]);
        // Twelve recurring buyers at real Philippine addresses — contractors, hardware stores and site offices.
        $customerRows=[
            ['Maria Santos','2248 Mabini St., Brgy. Poblacion, Makati City','0917 234 5601'],
            ['Jose Ramirez','Lot 14, Villa Aurora Subd., Fairview, Quezon City','0918 555 1202'],
            ['Anna Reyes','88 Session Rd., Kabayanihan, Baguio City','0920 345 2890'],
            ['Pedro Lim','Unit 6, Magsaysay Ave., Naga City','0915 776 4310'],
            ['Grace Villanueva','Blk 3 Lot 8, Camella Homes, Cabanatuan City','0917 802 6654'],
            ['Renato Cruz','12 Veterans Ave., Brgy. Kapitolyo, Pasig City','0928 114 7820'],
            ['Lorna Aquino','45 Lacson St., Brgy. Mandalagan, Bacolod City','0916 990 3311'],
            ['Daniel Garcia','7-B M.L. Quezon Ext., Antipolo City','0927 458 0192'],
            ['Carmen Flores','23 Clarin Ave., Tagbilaran City','0919 630 4477'],
            ['Ramon Torres','Lot 2, Santiago Blvd., General Santos City','0917 345 8812'],
            ['Elena Ramos','15 Andres Bonifacio Ave., Tuguegarao City','0921 773 2245'],
            ['Victor Mendoza','Unit 4, Roxas Blvd., Dumaguete City','0926 118 9034'],
        ];
        $current=now();$states=['captured','pending','authorized','failed','cancelled','expired','partially_refunded','refunded','disputed','dispute_won','dispute_lost','captured'];
        try{
            for($index=1;$index<=60;$index++){
                $external='AN-2026-'.str_pad((string)$index,4,'0',STR_PAD_LEFT);if(Order::where('source_system','demo-storefront')->where('external_id',$external)->exists()){continue;}
                $date=$current->copy()->subDays(($index-1)%45)->startOfDay()->addHours(8+($index%10));Carbon::setTestNow($date);
                [$buyerName,$buyerLine,$buyerPhone]=$customerRows[($index-1)%12];
                $customer=Customer::firstOrCreate(['source_system'=>'demo-storefront','external_id'=>'demo-customer-'.(($index-1)%12+1)],['name'=>$buyerName,'email'=>Str::slug($buyerName,'.').'@example.com','phone'=>preg_replace('/\D/','',$buyerPhone),'default_address'=>['line1'=>$buyerLine,'contact_name'=>$buyerName,'contact_phone'=>preg_replace('/\D/','',$buyerPhone)]]);
                $state=$states[($index-1)%count($states)];$amount=10000+$index*300; if(in_array($state,['pending','authorized','cancelled','expired'],true)){$amount=intdiv($amount,100)*100+98;}elseif($state==='failed'){$amount=intdiv($amount,100)*100+99;}
                $order=Order::create(['source_system'=>'demo-storefront','external_id'=>$external,'customer_id'=>$customer->id,'status_snapshot'=>'placed','subtotal_amount'=>$amount,'total_amount'=>$amount,'placed_at'=>$date]);
                // Every third order is a multi-seller basket, so most lists exercise the order+seller split.
                $splits=$index%3===0?3:1;$left=$amount;
                for($piece=1;$piece<=$splits;$piece++){$line=$piece===$splits?$left:intdiv($amount,$splits);$left-=$line;$catIndex=($index+$piece)%count($categories);[$pname,$psku]=$productRows[$catIndex][($index+$piece)%3];$order->items()->create(['seller_id'=>$sellers[($index-1+$piece)%count($sellers)]->id,'category_id'=>$categories[$catIndex]->id,'product_name'=>$pname,'sku'=>$psku.'-'.str_pad((string)$index,3,'0',STR_PAD_LEFT).($splits>1?'-'.$piece:''),'quantity'=>1,'unit_price_amount'=>$line,'line_total_amount'=>$line]);}
                $method=['card','gcash','maya','bank_transfer'][($index-1)%4];if($index%12===0){$method='cod';}$payment=$service->create($order,$method)['payment'];
                if($state==='authorized'){$service->processEvent('sandbox',new GatewayEvent('demo-authorize-'.$index,'payment.authorized',$payment->reference),['demo'=>true]);}
                elseif($state==='cancelled'){$service->cancel($payment);$order->update(['status_snapshot'=>'cancelled']);}
                elseif($state==='expired'){$payment->update(['expires_at'=>now()->subMinute()]);$service->runScheduled();}
                elseif(in_array($state,['partially_refunded','refunded'],true)){$refund=$service->requestRefund($payment,['amount'=>$state==='refunded'?$amount:intdiv($amount,2),'reason'=>'other','notes'=>'Bags arrived torn; buyer sent photos.'],$admin);$service->approve($refund,$finance);}
                elseif(in_array($state,['disputed','dispute_won','dispute_lost'],true)){$service->processEvent('sandbox',new GatewayEvent('demo-dispute-'.$index,'payment.disputed',$payment->reference),['demo'=>true]);if($state!=='disputed'){$service->processEvent('sandbox',new GatewayEvent('demo-dispute-result-'.$index,'payment.'.$state,$payment->reference),['demo'=>true]);}}
                // COD stays pending here; LogisticsDemoSeeder captures it by delivering the shipments with cash collected.
            }
        }finally{Carbon::setTestNow();}
        // Basket orders with items from several sellers: each seller gets its own Book row and shipment, and the
        // shipment form's seller picker offers a real choice instead of one option.
        for($basket=1;$basket<=6;$basket++){
            $external='AN-2026-B'.str_pad((string)$basket,2,'0',STR_PAD_LEFT);if(Order::where('source_system','demo-storefront')->where('external_id',$external)->exists()){continue;}
            $customer=Customer::where('source_system','demo-storefront')->where('external_id','demo-customer-'.$basket)->first();
            $order=Order::create(['source_system'=>'demo-storefront','external_id'=>$external,'customer_id'=>$customer->id,'status_snapshot'=>'placed','subtotal_amount'=>0,'total_amount'=>0,'placed_at'=>now()->subDays($basket)]);
            $total=0;foreach([0,1,2] as $piece){$sellerIndex=($basket+$piece*3)%count($sellers);[$pname,$psku]=$productRows[$piece][($basket+$piece)%3];$price=12000+$basket*900+$piece*2500;$order->items()->create(['seller_id'=>$sellers[$sellerIndex]->id,'category_id'=>$categories[$piece]->id,'product_name'=>$pname,'sku'=>$psku.'-B'.$basket,'quantity'=>1+$piece,'unit_price_amount'=>$price,'line_total_amount'=>$price*(1+$piece)]);$total+=$price*(1+$piece);}
            $order->update(['subtotal_amount'=>$total,'total_amount'=>$total]);
            $service->create($order,'card');
        }
        // A few orders above the ₱50,000 risk threshold so the Finance risk-review queue has open examples; the default
        // 30-minute expiry is lifted so they stay in the queue (a configured risk_review_hold_hours would do the same).
        if(!Order::where('source_system','demo-storefront')->where('external_id','AN-2026-RK01')->exists()){
            $riskOrders=[
                ['AN-2026-RK01','demo-customer-1',7500000,'card','AN Industrial Epoxy Kit 20L','AN-IEK20'],
                ['AN-2026-RK02','demo-customer-3',6200000,'gcash','AN Skimcoat pallet (40 bags)','AN-SCP40'],
                ['AN-2026-RK03','demo-customer-5',5480000,'maya','AN Structural repair bundle','AN-SRB'],
            ];
            foreach($riskOrders as [$external,$customerExternal,$amount,$method,$product,$sku]){
                $customer=Customer::where('source_system','demo-storefront')->where('external_id',$customerExternal)->firstOrFail();
                $order=Order::create(['source_system'=>'demo-storefront','external_id'=>$external,'customer_id'=>$customer->id,'status_snapshot'=>'placed','subtotal_amount'=>$amount,'total_amount'=>$amount,'placed_at'=>now()]);
                $order->items()->create(['seller_id'=>$sellers[2]->id,'category_id'=>$categories[1]->id,'product_name'=>$product,'sku'=>$sku,'quantity'=>1,'unit_price_amount'=>$amount,'line_total_amount'=>$amount]);
                $service->create($order,$method)['payment']->update(['expires_at'=>null]);
            }
            // A customer whose last hour is full of failed attempts: the next payment is flagged even though it is small.
            $flaky=Customer::where('source_system','demo-storefront')->where('external_id','demo-customer-7')->firstOrFail();
            foreach(range(1,4) as $try){Payment::create(['customer_id'=>$flaky->id,'method'=>'card','gateway'=>'sandbox','status'=>'failed','total_amount'=>1500000,'failed_at'=>now()->subMinutes(10),'failure_reason'=>'Card declined by issuer (do_not_honor)']);}
            $flakyOrder=Order::create(['source_system'=>'demo-storefront','external_id'=>'AN-2026-RK04','customer_id'=>$flaky->id,'status_snapshot'=>'placed','subtotal_amount'=>1500000,'total_amount'=>1500000,'placed_at'=>now()]);
            $flakyOrder->items()->create(['seller_id'=>$sellers[2]->id,'category_id'=>$categories[1]->id,'product_name'=>'AN Tile Adhesive 25kg','sku'=>'AN-TA25-RISK','quantity'=>3,'unit_price_amount'=>500000,'line_total_amount'=>1500000]);
            $service->create($flakyOrder,'card')['payment']->update(['expires_at'=>null]);
            // A COD parcel above the threshold stays pending while blocked: the goods may already be on their way.
            $codCustomer=Customer::where('source_system','demo-storefront')->where('external_id','demo-customer-9')->firstOrFail();
            $codOrder=Order::create(['source_system'=>'demo-storefront','external_id'=>'AN-2026-RK05','customer_id'=>$codCustomer->id,'status_snapshot'=>'placed','subtotal_amount'=>5600000,'total_amount'=>5600000,'placed_at'=>now()]);
            $codOrder->items()->create(['seller_id'=>$sellers[2]->id,'category_id'=>$categories[1]->id,'product_name'=>'AN Waterproofing project pack','sku'=>'AN-WPP','quantity'=>1,'unit_price_amount'=>5600000,'line_total_amount'=>5600000]);
            $service->blockRisk($service->create($codOrder,'cod')['payment']);
        }
        // Clearly synthetic gateway fees (2.5%) so settlements show the fee deduction; the sandbox itself reports no fees.
        foreach(Payment::where('gateway','sandbox')->whereNull('invoice_id')->where('captured_amount','>',0)->where('fee_amount',0)->get() as $paid){$paid->update(['fee_amount'=>Money::halfUp($paid->captured_amount,250,10000)]);}
        $payment=Payment::where('captured_amount','>',0)->first();
        if($payment&&!Reconciliation::where('filename','sandbox-settlement.csv')->exists()){
            $run=Reconciliation::create(['filename'=>'sandbox-settlement.csv','mime_type'=>'text/csv','size'=>58,'content'=>base64_encode("date,gateway_reference,amount,fee\n"),'date_from'=>$current->copy()->subDays(45)->toDateString(),'date_to'=>$current->toDateString(),'created_by'=>$finance->id]);
            foreach(['matched','amount_mismatch','missing_in_sfa','missing_in_report'] as $index=>$status){$run->items()->create(['payment_id'=>$status==='missing_in_sfa'?null:$payment->id,'date'=>$current->toDateString(),'gateway_reference'=>$status==='missing_in_sfa'?'demo-external-missing':$payment->gateway_reference,'total_amount'=>$payment->captured_amount+($status==='amount_mismatch'?100:0),'fee_amount'=>0,'status'=>$status]);}
        }
        $payment=Payment::where('status','captured')->first();if($payment&&!$payment->refunds()->where('status','requested')->exists()){$service->requestRefund($payment,['amount'=>100,'reason'=>'damaged','notes'=>'Customer returned the wrong variant; awaiting Finance approval.'],$admin);}
    }
}

