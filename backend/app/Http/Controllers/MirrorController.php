<?php
namespace App\Http\Controllers;
use App\Http\Requests\MirrorRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Seller;
use App\Services\LogisticsService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class MirrorController extends Controller {
    private function identity(Request $request,string $externalId):array {
        if($externalId===''||mb_strlen($externalId)>255){throw ValidationException::withMessages(['external_id'=>['External identifiers must have 1–255 characters.']]);}
        return ['source_system'=>$request->user()->system_code,'external_id'=>$externalId];
    }
    public function customer(MirrorRequest $request,string $external_id){$model=Customer::updateOrCreate($this->identity($request,$external_id),$request->validated());return response()->json($model,$model->wasRecentlyCreated?201:200);}
    public function seller(MirrorRequest $request,string $external_id){$model=Seller::updateOrCreate($this->identity($request,$external_id),$request->validated());return response()->json($model,$model->wasRecentlyCreated?201:200);}
    public function category(MirrorRequest $request,string $external_id){
        $values=$request->validated();$identity=$this->identity($request,$external_id);$parent=null;
        if(!empty($values['parent_external_id'])){$parent=Category::where('source_system',$identity['source_system'])->where('external_id',$values['parent_external_id'])->firstOrFail();if($parent->external_id===$external_id){throw ValidationException::withMessages(['parent_external_id'=>['A category cannot be its own parent.']]);}}
        $existing=Category::where($identity)->first();$cursor=$parent;$visited=[];
        while($cursor){if(isset($visited[$cursor->id])||($existing&&$cursor->id===$existing->id)){throw ValidationException::withMessages(['parent_external_id'=>['The parent would create a category cycle.']]);}$visited[$cursor->id]=true;$cursor=$cursor->parent;}
        $model=Category::updateOrCreate($identity,['name'=>$values['name'],'parent_id'=>$parent?->id]);return response()->json($model,$model->wasRecentlyCreated?201:200);
    }
    public function order(MirrorRequest $request,string $external_id,PaymentService $payments){
        return DB::transaction(function()use($request,$external_id,$payments){
            $values=$request->validated();$identity=$this->identity($request,$external_id);$customer=Customer::where('source_system',$identity['source_system'])->where('external_id',$values['customer_external_id'])->firstOrFail();
            $items=[];$subtotal='0';
            foreach($values['items'] as $item){
                $seller=Seller::where('source_system',$identity['source_system'])->where('external_id',$item['seller_external_id'])->firstOrFail();
                $category=empty($item['category_external_id'])?null:Category::where('source_system',$identity['source_system'])->where('external_id',$item['category_external_id'])->firstOrFail();
                if(bccomp(bcmul((string)$item['unit_price_amount'],(string)$item['quantity'],0),(string)$item['line_total_amount'],0)!==0){throw ValidationException::withMessages(['items'=>['Each line total must equal unit price multiplied by quantity.']]);}
                $subtotal=bcadd($subtotal,(string)$item['line_total_amount'],0);$items[]=['seller_id'=>$seller->id,'category_id'=>$category?->id,'product_name'=>$item['product_name'],'sku'=>$item['sku']??null,'quantity'=>$item['quantity'],'unit_price_amount'=>$item['unit_price_amount'],'line_total_amount'=>$item['line_total_amount']];
            }
            $expected=bcsub(bcadd($subtotal,(string)$values['shipping_amount'],0),(string)$values['discount_amount'],0);
            if(bccomp($subtotal,(string)$values['subtotal_amount'],0)!==0||bccomp($expected,(string)$values['total_amount'],0)!==0){throw ValidationException::withMessages(['total_amount'=>['Order totals must match the items, shipping and discount.']]);}
            $order=Order::where($identity)->lockForUpdate()->first();$create=!$order;
            if($order?->status_snapshot==='cancelled'&&$values['status_snapshot']!=='cancelled'){throw ValidationException::withMessages(['status_snapshot'=>['A cancelled order stays cancelled. Send a new order instead.']]);}
            $cancelling=$order&&$values['status_snapshot']==='cancelled'&&$order->status_snapshot!=='cancelled';
            if($order&&$order->payments()->exists()){
                $stored=$order->items()->get()->map(fn($item)=>$item->only(['seller_id','category_id','product_name','sku','quantity','unit_price_amount','line_total_amount']))->all();
                foreach(['subtotal_amount','shipping_amount','discount_amount','total_amount'] as $field){if($order->$field!==$values[$field]){throw ValidationException::withMessages([$field=>['Amounts cannot change after a payment attempt has been created.']]);}}
                if($order->customer_id!==$customer->id||$stored!==$items){throw ValidationException::withMessages(['items'=>['Customer and items cannot change after a payment attempt.']]);}
                $order->update(['status_snapshot'=>$values['status_snapshot'],'placed_at'=>$values['placed_at']]);
            }else{
                $data=array_intersect_key($values,array_flip(['status_snapshot','subtotal_amount','shipping_amount','discount_amount','total_amount','placed_at']));$data['customer_id']=$customer->id;
                if($order){$order->update($data);$order->items()->delete();}else{$order=Order::create(array_merge($identity,$data));}
                $order->items()->createMany($items);
            }
            // An upsert to cancelled follows POST /orders/{id}/cancel exactly; a 422 there rolls back the whole upsert.
            if($cancelling){$this->closeOrder($order,$payments);}
            return response()->json($order->load('items.seller','customer'),$create?201:200);
        });
    }
    public function cancel(Request $request,string $external_id,PaymentService $payments){
        return DB::transaction(function()use($request,$external_id,$payments){$order=Order::where($this->identity($request,$external_id))->lockForUpdate()->firstOrFail();return response()->json($this->closeOrder($order,$payments)->load('items'));});
    }
    // The one cancel rule for both routes; the caller holds the order's row lock. Row locks follow the one order used
    // everywhere: order, then payments, then shipments (DECISIONS.md, Logistics). LOG-7: shipments not yet with the
    // courier are cancelled, one already with it refuses the cancel with a 422.
    private function closeOrder(Order $order,PaymentService $payments):Order {
        $held=Payment::where('order_id',$order->id)->orderBy('id')->lockForUpdate()->get();
        if($held->contains(fn(Payment $payment)=>$payment->captured_amount>0)){throw ValidationException::withMessages(['order'=>['A captured order needs a refund rather than cancellation.']]);}
        app(LogisticsService::class)->cancelForOrder($order);
        // Re-read: cancelling a COD shipment may already have cancelled its payment.
        foreach($order->payments()->whereIn('status',['pending','authorized'])->orderBy('id')->get() as $payment){$payments->cancel($payment);}
        $order->update(['status_snapshot'=>'cancelled']);return $order;
    }
}
