<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
class ReconciliationResolveRequest extends FormRequest {
    public function authorize():bool{return $this->user()?->can('edit payments')??false;}
    public function rules():array{return ['notes'=>'required|string|max:10000'];}
}
