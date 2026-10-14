<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class MirrorRequest extends FormRequest {
    public function authorize():bool{return true;}
    public function rules():array {
        if($this->is('*/customers/*')){return ['name'=>'required|string|max:255','email'=>'nullable|email|max:255','phone'=>'nullable|string|max:80','default_address'=>'nullable|array'];}
        if($this->is('*/sellers/*')){return ['shop_name'=>'required|string|max:255','owner_name'=>'nullable|string|max:255','email'=>'nullable|email|max:255','phone'=>'nullable|string|max:80','pickup_address'=>'nullable|array','payout_destination'=>'nullable|array:bank_name,account_number,account_name,number','payout_destination.*'=>'nullable|string|max:255'];}
        if($this->is('*/categories/*')){return ['name'=>'required|string|max:255','parent_external_id'=>'nullable|string|max:255'];}
        return ['customer_external_id'=>'required|string|max:255','status_snapshot'=>'required|string|max:80','subtotal_amount'=>'required|integer|min:0|max:9000000000000000','shipping_amount'=>'required|integer|min:0|max:9000000000000000','discount_amount'=>'required|integer|min:0|max:9000000000000000','total_amount'=>'required|integer|min:0|max:9000000000000000','currency'=>'sometimes|in:PHP','placed_at'=>'required|date','items'=>'required|array|min:1|max:1000','items.*.seller_external_id'=>'required|string|max:255','items.*.category_external_id'=>'nullable|string|max:255','items.*.product_name'=>'required|string|max:255','items.*.sku'=>'nullable|string|max:255','items.*.quantity'=>'required|integer|min:1|max:1000000','items.*.unit_price_amount'=>'required|integer|min:0|max:9000000000000000','items.*.line_total_amount'=>'required|integer|min:0|max:9000000000000000'];
    }
}
