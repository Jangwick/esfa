<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $resource=$this->route('resource');$method=$this->route()->getActionMethod();
        $permission=match($method){'store'=>'create '.str_replace('-',' ',$resource),'destroy'=>'delete '.str_replace('-',' ',$resource),'generate'=>'create settlements','finalize'=>'finalize settlements','pay'=>'pay settlements',default=>'edit '.str_replace('-',' ',$resource)};
        return $this->user()?->can($permission)??false;
    }
    public function rules(): array
    {
        $method=$this->route()->getActionMethod();$resource=$this->route('resource');$id=$this->route('id');
        if(in_array($method,['store','update'])&&$resource==='plans')return [
            'name'=>'required|string|max:255','code'=>['required','string','max:50','regex:/^[a-zA-Z0-9_-]+$/',Rule::unique('plans')->ignore($id)],
            'monthly_price_amount'=>'required|integer|min:0|max:9000000000000000','annual_price_amount'=>'required|integer|min:0|max:9000000000000000','trial_days'=>'required|integer|min:0|max:365',
            'features'=>'required|array|max:100','features.*'=>'string|max:500','limits'=>'required|array','limits.max_products'=>'nullable|integer|min:0',
            'commission_rate_bp'=>'nullable|integer|min:0|max:10000','is_active'=>'required|boolean','sort_order'=>'sometimes|integer',
        ];
        if($method==='store'&&$resource==='subscriptions')return ['seller_id'=>'required|integer|exists:sellers,id','plan_id'=>'required|integer|exists:plans,id','billing_cycle'=>'required|in:monthly,annual'];
        if(in_array($method,['store','update'])&&$resource==='commission-rules')return [
            'scope'=>'required|in:global,category,plan,seller','scope_id'=>['nullable','integer',Rule::requiredIf(fn()=> $this->scope!=='global')],
            'rate_bp'=>'required|integer|min:0|max:10000','fixed_fee_amount'=>'required|integer|min:0|max:9000000000000000',
            'effective_from'=>'required|date','effective_to'=>'nullable|date|after:effective_from',
        ];
        return match($method){
            'changePlan'=>['plan_id'=>'required|integer|exists:plans,id'],
            'cancel'=>['immediate'=>'sometimes|boolean'],
            'generate'=>['period_start'=>'required|date_format:Y-m-d','period_end'=>'required|date_format:Y-m-d|after_or_equal:period_start|before_or_equal:'.\Carbon\CarbonImmutable::now(\App\Models\Setting::valueFor('business_timezone',config('sfa.timezone')))->toDateString(),'seller_id'=>'nullable|integer|exists:sellers,id'],
            'pay'=>['method'=>'nullable|in:bank_transfer,gcash','destination_masked'=>'nullable|string|max:100'],
            default=>[],
        };
    }
    public function after(): array
    {
        return [function($validator){
            if($this->route('resource')!=='commission-rules'||$validator->errors()->isNotEmpty())return;
            $table=match($this->scope){'seller'=>'sellers','plan'=>'plans','category'=>'categories',default=>null};
            if($table&&!\Illuminate\Support\Facades\DB::table($table)->where('id',$this->scope_id)->exists())$validator->errors()->add('scope_id','The selected scope record does not exist.');
            if($this->scope==='global'&&$this->scope_id!==null)$validator->errors()->add('scope_id','A global rule has no scope record.');
            // Global rules stay open-ended so exactly one is active at every moment; a later global rule ends the previous one.
            if($this->scope==='global'&&$this->filled('effective_to'))$validator->errors()->add('effective_to','A global rule has no end date. Create a later global rule to replace it.');
        }];
    }
}
