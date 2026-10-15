<?php
namespace App\Http\Requests;
use App\Models\IntegrationClient;
use Illuminate\Foundation\Http\FormRequest;
class AnalyticsRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() instanceof IntegrationClient || ($this->user()?->can($this->route()->getActionMethod()==='dashboard'?'view dashboard':'view analytics') ?? false); }
    protected function prepareForValidation(): void { $this->merge(['date_from'=>$this->input('date_from',$this->input('from')),'date_to'=>$this->input('date_to',$this->input('to'))]); }
    public function rules(): array { return ['date_from'=>'nullable|date_format:Y-m-d','date_to'=>'nullable|date_format:Y-m-d|after_or_equal:date_from','seller_id'=>'nullable|integer|exists:sellers,id','method'=>'nullable|in:card,gcash,maya,bank_transfer,cod','courier_id'=>'nullable|integer|exists:couriers,id','report'=>'nullable|in:all,revenue_by_day,payments_by_method,refunds_by_reason,tickets_by_category,ticket_status,shipment_status,on_time_by_courier,top_sellers,mrr_by_plan']; }
}
