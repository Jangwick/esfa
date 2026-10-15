<?php
namespace App\Http\Controllers;
use App\Gateways\GatewayEvent;
use App\Http\Controllers\Concerns\PaymentAccess;
use App\Http\Requests\ListRequest;
use App\Http\Requests\PaymentActionRequest;
use App\Http\Requests\PaymentCreateRequest;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReconciliationItem;
use App\Models\Refund;
use App\Models\Setting;
use App\Services\PaymentService;
use App\Support\ListResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
class PaymentController extends Controller {
    use PaymentAccess;
    public function index(ListRequest $request){
        $this->requirePermission('view payments');$request->validate(['status'=>['nullable',Rule::in(Payment::STATUSES)],'method'=>'nullable|in:card,gcash,maya,bank_transfer,cod','risk_level'=>'nullable|in:none,review,blocked','customer_id'=>'nullable|integer','order_id'=>'nullable|integer']);
        $query=$this->paymentRows(Payment::with('order','customer'),$request);
        if($request->filled('search')){$search='%'.mb_strtolower($request->search).'%';$query->where(fn($q)=>$q->whereRaw('LOWER(reference) LIKE ?',[$search])->orWhereRaw('LOWER(gateway_reference) LIKE ?',[$search])->orWhereHas('customer',fn($q)=>$q->whereRaw('LOWER(name) LIKE ?',[$search])->orWhereRaw('LOWER(email) LIKE ?',[$search])));}
        foreach(['status','method','risk_level','customer_id','order_id'] as $filter){if($request->filled($filter)){$query->where($filter,$request->input($filter));}}
        ListResponse::sort(ListResponse::dates($query,$request),$request,['created_at','reference','total_amount','status','captured_at']);
        $today=CarbonImmutable::now(Setting::valueFor('business_timezone','Asia/Manila'))->startOfDay()->utc();$end=$today->addDay();
        $summary=['captured_today'=>(int)(clone $query)->where('captured_at','>=',$today)->where('captured_at','<',$end)->sum('captured_amount'),'pending'=>(clone $query)->whereIn('status',['pending','authorized'])->count(),'failed_today'=>(clone $query)->where('failed_at','>=',$today)->where('failed_at','<',$end)->count(),'refunds_awaiting_approval'=>Refund::where('status','requested')->whereIn('payment_id',$this->paymentRows(Payment::query(),$request)->select('id'))->count(),'disputes_open'=>(clone $query)->where('status','disputed')->count(),'unmatched_reconciliation_items'=>$request->user()->hasAnyRole(['Admin','Accounting Staff','Quality Control Analyst'])?ReconciliationItem::where('status','!=','matched')->whereNull('resolved_at')->count():0];
        if($request->export==='csv'){return ListResponse::csv($query,'payments.csv',['Reference','Customer','Method','Gateway','Status','Amount (centavos)','Captured (centavos)','Refunded (centavos)','Risk'],fn($p)=>[$p->reference,$p->customer?->name,$p->method,$p->gateway,$p->status,$p->amount,$p->captured_amount,$p->refunded_amount,$p->risk_level]);}
        return ListResponse::paginate($query,$request,$summary);
    }
    public function show(Request $request,Payment $payment){$this->assertPaymentView($payment,$request);return response()->json($payment->load(['customer','order.items.seller','refunds','ledgerEntries']));}
    public function receipt(Request $request,Payment $payment){$this->assertPaymentView($payment,$request);return response()->json($payment->load('customer','order.items'));}
    public function capture(PaymentActionRequest $request,Payment $payment,PaymentService $service){$this->requirePermission('edit payments');$this->assertPaymentScope($payment,$request);return response()->json($service->capture($payment,$request->validated('amount')));}
    public function cancel(PaymentActionRequest $request,Payment $payment,PaymentService $service){$this->requirePermission('edit payments');$this->assertPaymentScope($payment,$request);return response()->json($service->cancel($payment));}
    public function simulate(PaymentActionRequest $request,Payment $payment,PaymentService $service){
        abort_unless($request->user()->hasRole('Admin'),403,'This action requires the Admin role.');if($payment->gateway!=='sandbox'){throw ValidationException::withMessages(['gateway'=>['Only sandbox payments can simulate gateway events.']]);}
        $values=$request->validated();$event=new GatewayEvent('sim_'.Str::uuid(),$values['type'],$payment->reference,$values['amount']??null);$service->processEvent('sandbox',$event,array_merge($values,['event_id'=>$event->id,'reference'=>$payment->reference]));return response()->json($payment->fresh(['order','customer']));
    }
    public function cod(PaymentActionRequest $request,Payment $payment,PaymentService $service){
        $this->requirePermission('edit payments');
        $this->assertPaymentScope($payment,$request);
        $service->captureCodWhenReady($payment,true);
        return response()->json($payment->fresh());
    }
    public function createIntegration(PaymentCreateRequest $request,PaymentService $service){$values=$request->validated();$order=Order::where('source_system',$request->user()->system_code)->where('external_id',$values['order_external_id'])->firstOrFail();return response()->json($service->create($order,$values['method']),201);}
    public function showIntegration(Request $request,string $reference){$payment=Payment::with('order','refunds')->where('reference',$reference)->whereHas('order',fn($q)=>$q->where('source_system',$request->user()->system_code))->firstOrFail();return response()->json($payment);}
    public function webhook(Request $request,string $gateway,PaymentService $service){$driver=$service->gateway($gateway);abort_unless($driver->verifyWebhook($request),401,'Invalid webhook signature.');$event=$driver->parseWebhook($request);$processed=$service->processEvent($gateway,$event,$request->all());return response()->json(['message'=>$processed?'Webhook processed.':'Webhook already processed.','processed'=>$processed]);}
    public function options(Request $request){$this->requirePermission('view payments');$orders=Order::with('customer');if($request->user()->hasRole('Administrative Assistant')&&!$request->user()->hasAnyRole(['Admin','Accounting Staff'])){$orders->whereIn('id',$this->paymentRows(Payment::query(),$request)->whereNotNull('order_id')->select('order_id'));}return response()->json(['payments'=>$this->paymentRows(Payment::query(),$request)->whereIn('status',['captured','partially_refunded'])->latest()->limit(100)->get(['id','reference','total_amount','captured_amount','refunded_amount','order_id']),'orders'=>$orders->latest()->limit(100)->get(),...collect(['refund_auto_approve_cap_amount','refund_auto_approve_daily_limit','refund_auto_approve_reasons'])->mapWithKeys(fn($key)=>[$key=>Setting::valueFor($key)])->all()]);}
}
