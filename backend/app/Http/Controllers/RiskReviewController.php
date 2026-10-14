<?php
namespace App\Http\Controllers;
use App\Http\Controllers\Concerns\PaymentAccess;
use App\Http\Requests\ListRequest;
use App\Http\Requests\PaymentActionRequest;
use App\Models\Payment;
use App\Services\FinanceReviewService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use App\Support\ListResponse;
class RiskReviewController extends Controller {
    use PaymentAccess;
    // The queue holds open reviews only: captured, cancelled and expired payments leave it.
    public function index(ListRequest $request){$this->requirePermission('edit payments');$request->validate(['risk_level'=>'nullable|in:review,blocked']);$query=$this->paymentRows(Payment::with('customer','order'),$request)->whereIn('risk_level',['review','blocked'])->whereIn('status',['pending','authorized']);if($request->filled('risk_level')){$query->where('risk_level',$request->risk_level);}if($request->filled('search')){$query->whereRaw('LOWER(reference) LIKE ?',['%'.mb_strtolower($request->search).'%']);}ListResponse::sort(ListResponse::dates($query,$request),$request,['created_at','reference','total_amount']);$summary=['total'=>(clone$query)->count(),'review'=>(clone$query)->where('risk_level','review')->count(),'blocked'=>(clone$query)->where('risk_level','blocked')->count()];if($request->export==='csv'){return ListResponse::csv($query,'risk-reviews.csv',['Reference','Customer','Amount (centavos)','Risk','Status'],fn($p)=>[$p->reference,$p->customer?->name,$p->amount,$p->risk_level,$p->status]);}return ListResponse::paginate($query,$request,$summary);}
    public function clear(PaymentActionRequest $request,Payment $payment,PaymentService $service){$this->requirePermission('edit payments');$this->assertPaymentScope($payment,$request);return response()->json($service->clearRisk($payment));}
    // AI explanation and recommendation for an open review: GET returns the stored answer for the same facts (or asks once),
    // POST asks again. Nothing is cleared or blocked here; the request is kept in ai_insights, not the audit log.
    public function aiReview(Request $request,Payment $payment,FinanceReviewService $service){$this->requirePermission('edit payments');$this->assertPaymentScope($payment,$request);$request->attributes->set('audit.skip',true);return response()->json($service->riskReview($payment,$request->user(),$request->isMethod('POST')));}
    public function block(PaymentActionRequest $request,Payment $payment,PaymentService $service){$this->requirePermission('edit payments');$this->assertPaymentScope($payment,$request);return response()->json($service->blockRisk($payment));}
}
