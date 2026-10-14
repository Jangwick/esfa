<?php
namespace App\Http\Controllers;
use App\Models\{Seller,Settlement,Subscription};
use App\Http\Requests\ListRequest;
use App\Support\ListResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
class BillingIntegrationController extends Controller
{
    // A client reads its own sellers. A client allowed to read every storefront's financials may pick the storefront with
    // source_system, and must when the external ID exists in more than one storefront.
    private function seller(ListRequest $r,string $externalId): Seller
    {
        $r->validate(['source_system'=>'nullable|string|max:80']);$client=$r->user();$query=Seller::where('external_id',$externalId);
        if(!$client->reads_all_financials)$query->where('source_system',$client->system_code);
        if($r->filled('source_system'))$query->where('source_system',$r->input('source_system'));
        $sellers=$query->limit(2)->get();
        if($sellers->count()>1)throw ValidationException::withMessages(['source_system'=>['This seller ID exists in more than one storefront. Send source_system to choose one.']]);
        return $sellers->first()??throw (new ModelNotFoundException)->setModel(Seller::class);
    }
    public function subscriptions(ListRequest $r){$r->validate(['seller'=>'required|string|max:255']);$seller=$this->seller($r,$r->input('seller'));$query=Subscription::with('plan','nextPlan')->where('seller_id',$seller->id);ListResponse::sort($query,$r,['created_at','id']);return ListResponse::paginate($query,$r,['total'=>(clone $query)->count()]);}
    public function statements(ListRequest $r,string $externalId){$seller=$this->seller($r,$externalId);$query=Settlement::with('payouts','entries')->where('seller_id',$seller->id)->whereIn('status',['finalized','paid']);ListResponse::dates($query,$r);ListResponse::sort($query,$r,['created_at','id','reference']);if($r->export==='csv')return ListResponse::csv($query,'statements.csv',['Reference','From','To','Gross','Refunds','Commission','Gateway fees','Net payable','Status'],fn($s)=>[$s->reference,$s->period_start->toDateString(),$s->period_end->toDateString(),$s->gross_sales_amount,$s->refunds_amount,$s->commission_amount,$s->gateway_fees_amount,$s->net_payable_amount,$s->status]);return ListResponse::paginate($query,$r,['total'=>(clone $query)->count(),'net_payable_amount'=>(clone $query)->sum('net_payable_amount')]);}
}
