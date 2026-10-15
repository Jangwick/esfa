<?php
namespace App\Http\Controllers;
use App\Http\Controllers\Concerns\PaymentAccess;
use App\Http\Requests\ListRequest;
use App\Http\Requests\PaymentActionRequest;
use App\Http\Requests\RefundRequest;
use App\Models\Attachment;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Ticket;
use App\Services\AttachmentService;
use App\Services\PaymentService;
use App\Services\TicketService;
use App\Support\ListResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class RefundController extends Controller {
    use PaymentAccess;
    public function index(ListRequest $request){
        $this->requirePermission('view payments');$request->validate(['status'=>'nullable|in:requested,approved,processing,completed,failed,rejected','payment_id'=>'nullable|integer']);
        $query=Refund::with('payment','requester')->whereIn('payment_id',$this->paymentRows(Payment::query(),$request)->select('id'));
        if($request->filled('search')){$query->whereRaw('LOWER(reference) LIKE ?',['%'.mb_strtolower($request->search).'%']);}
        foreach(['status','payment_id'] as $filter){if($request->filled($filter)){$query->where($filter,$request->input($filter));}}
        ListResponse::sort(ListResponse::dates($query,$request),$request,['created_at','reference','total_amount','status']);
        $summary=['total'=>(clone $query)->count(),'requested'=>(clone $query)->where('status','requested')->count(),'completed_amount'=>(int)(clone $query)->where('status','completed')->sum('total_amount'),'failed'=>(clone $query)->where('status','failed')->count()];
        if($request->export==='csv'){return ListResponse::csv($query,'refunds.csv',['Reference','Payment','Amount (centavos)','Reason','Status','Requester'],fn($r)=>[$r->reference,$r->payment->reference,$r->amount,$r->reason,$r->status,$r->requester?->name]);}
        return ListResponse::paginate($query,$request,$summary);
    }
    // The detail adds the photos added to the request (never their content); GET /refunds/{refund}/photos/{id} shows one.
    public function show(Request $request,Refund $refund){$this->assertPaymentView($refund->payment,$request);return response()->json([...$refund->load('payment','requester')->toArray(),'photos'=>$refund->attachments()->orderBy('id')->get(['id','filename','mime_type','size','created_at'])]);}
    // JSON as before, or multipart with up to 3 photos[] of the damaged parcel or wrong item, checked before anything is created.
    public function store(RefundRequest $request,PaymentService $service){
        $this->requirePermission('create refunds');
        $photos=$request->validate(['photos'=>'nullable|array|max:3','photos.*'=>'file|mimetypes:image/png,image/jpeg,image/webp|max:4096'],['photos.array'=>'Add the photos as files.','photos.max'=>'Add up to 3 photos.','photos.*.file'=>'Each photo must be a file.','photos.*.uploaded'=>'A photo could not be uploaded. Try again.','photos.*.mimetypes'=>'Each photo must be a PNG, JPG or WEBP image.','photos.*.max'=>'Each photo must be 4 MB or smaller.'])['photos']??[];
        $values=$request->validated();$values['amount']=(int)$values['amount'];$payment=Payment::findOrFail($values['payment_id']);$this->assertPaymentView($payment,$request);
        if(!empty($values['ticket_id'])){$ticket=Ticket::findOrFail($values['ticket_id']);app(TicketService::class)->assertView($ticket,$request->user());if($ticket->payment_id!==$payment->id){throw ValidationException::withMessages(['ticket_id'=>['The ticket must be linked to this payment.']]);}}
        if($request->user()->hasRole('Administrative Assistant')&&!$request->user()->hasAnyRole(['Admin','Accounting Staff'])&&empty($values['ticket_id'])){throw ValidationException::withMessages(['ticket_id'=>['Support refund requests require a related ticket.']]);}
        return response()->json(DB::transaction(function()use($service,$payment,$values,$request,$photos){$refund=$service->requestRefund($payment,$values,$request->user(),true);foreach($photos as $photo){app(AttachmentService::class)->store($photo,$refund,$request->user());}return $refund;}),201);
    }
    // One photo the refund check may look at (Refund::evidencePhotos: the request's own, or one in a linked ticket's conversation), shown inline.
    public function photo(Request $request,Refund $refund,Attachment $attachment,AttachmentService $files){
        $this->assertPaymentView($refund->payment,$request);abort_unless($refund->evidencePhotos(10,false)->contains('id',$attachment->id),404);
        $response=$files->download($attachment);$response->headers->set('Content-Disposition',preg_replace('/^attachment/','inline',$response->headers->get('Content-Disposition')));return $response;
    }
    // AI check of a requested refund (GET: stored answer or one new ask; POST: ask again). It never approves or rejects.
    public function aiReview(Request $request,Refund $refund,\App\Services\FinanceReviewService $service){$this->requirePermission('approve refunds');$this->assertPaymentScope($refund->payment,$request);$request->attributes->set('audit.skip',true);return response()->json($service->refundReview($refund,$request->user(),$request->isMethod('POST')));}
    public function approve(PaymentActionRequest $request,Refund $refund,PaymentService $service){$this->requirePermission('approve refunds');$this->assertPaymentScope($refund->payment,$request);return response()->json($service->approve($refund,$request->user()));}
    public function reject(PaymentActionRequest $request,Refund $refund,PaymentService $service){$this->requirePermission('approve refunds');$this->assertPaymentScope($refund->payment,$request);return response()->json($service->reject($refund,$request->user(),$request->validated('rejection_reason')));}
    public function settleCash(Request $request,Refund $refund,PaymentService $service){$this->requirePermission('approve refunds');$this->assertPaymentScope($refund->payment,$request);return response()->json($service->settleCashRefund($refund,$request->user()));}
    public function createIntegration(RefundRequest $request,PaymentService $service){$values=$request->validated();$payment=Payment::where('reference',$values['payment_reference'])->whereHas('order',fn($q)=>$q->where('source_system',$request->user()->system_code))->firstOrFail();if(!empty($values['ticket_id'])){$ticket=Ticket::findOrFail($values['ticket_id']);if($ticket->payment_id!==$payment->id||$ticket->source_system!==$request->user()->system_code){throw ValidationException::withMessages(['ticket_id'=>['The ticket must belong to this client and payment.']]);}}return response()->json($service->requestRefund($payment,$values,$request->user()),201);}
    public function showIntegration(Request $request,string $reference){$refund=Refund::with('payment')->where('reference',$reference)->whereHas('payment.order',fn($q)=>$q->where('source_system',$request->user()->system_code))->firstOrFail();return response()->json($refund);}
}
