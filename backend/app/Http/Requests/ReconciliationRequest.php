<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class ReconciliationRequest extends FormRequest {
    public function authorize():bool{return $this->user()?->can('edit payments')??false;}
    public function rules():array{return ['file'=>'required|file|max:4096|mimes:csv,txt','date_from'=>'required|date_format:Y-m-d','date_to'=>'required|date_format:Y-m-d|after_or_equal:date_from'];}
}
