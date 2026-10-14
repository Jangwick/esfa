<?php
namespace App\Http\Requests;
use App\Models\IntegrationClient;
use Illuminate\Foundation\Http\FormRequest;
class QuoteRequest extends FormRequest
{
    public function authorize(): bool { return $this->user() instanceof IntegrationClient || (bool) $this->user()?->can('view logistics'); }
    public function rules(): array { return ['origin_city_id' => 'required_without:origin_code|integer|exists:cities,id', 'origin_code' => 'required_without:origin_city_id|digits:10|exists:cities,code', 'destination_city_id' => 'required_without:destination_code|integer|exists:cities,id', 'destination_code' => 'required_without:destination_city_id|digits:10|exists:cities,code', 'weight_grams' => 'required|integer|min:1|max:100000000', 'dimensions' => 'required|array:length_cm,width_cm,height_cm|required_array_keys:length_cm,width_cm,height_cm', 'dimensions.*' => 'required|numeric|min:0.01|max:10000|decimal:0,2', 'cod_amount' => 'required|integer|min:0|max:9000000000000000']; }
}
