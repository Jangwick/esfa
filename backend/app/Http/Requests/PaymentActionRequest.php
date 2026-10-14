<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
class PaymentActionRequest extends FormRequest {
    public function authorize():bool {
        if($this->is('*/simulate-webhook')){return $this->user()?->hasRole('Admin')??false;}
        return $this->user()?->can($this->is('*/approve')||$this->is('*/reject')?'approve refunds':'edit payments')??false;
    }
    public function rules():array {
        if($this->is('*/reject')){return ['rejection_reason'=>'required|string|max:10000'];}
        if($this->is('*/simulate-webhook')){return ['type'=>['required',Rule::in(['payment.authorized','payment.captured','payment.failed','payment.cancelled','payment.disputed','payment.dispute_won','payment.dispute_lost'])],'amount'=>'nullable|integer|min:1|max:9000000000000000'];}
        if($this->is('*/capture')){return ['amount'=>'nullable|integer|min:1|max:9000000000000000'];}
        if($this->is('*/cod-collected')){return ['cash_collected'=>'required|accepted'];}
        return [];
    }
}
