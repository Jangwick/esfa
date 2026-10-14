<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class PaymentCreateRequest extends FormRequest {
    public function authorize():bool{return true;}
    public function rules():array{return ['order_external_id'=>'required|string|max:255','method'=>'required|in:card,gcash,maya,bank_transfer,cod','amount'=>'prohibited','gateway'=>'prohibited'];}
}
