<?php
namespace App\Http\Requests;
use App\Models\IntegrationClient;
use App\Models\Refund;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class RefundRequest extends FormRequest {
    public function authorize():bool{return $this->user() instanceof IntegrationClient || ($this->user()?->can('create refunds')??false);}
    public function rules():array{return ['payment_id'=>$this->user() instanceof IntegrationClient?'prohibited':'required|integer|exists:payments,id','payment_reference'=>$this->user() instanceof IntegrationClient?'required|string|max:80':'prohibited','amount'=>'required|integer|min:1|max:9000000000000000','reason'=>['required',Rule::in(Refund::REASONS)],'notes'=>'nullable|string|max:10000','ticket_id'=>'nullable|integer|exists:tickets,id'];}
}
