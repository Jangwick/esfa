<?php
namespace App\Http\Controllers;
use App\Http\Requests\ListRequest;
use App\Http\Requests\ReconciliationRequest;
use App\Http\Requests\ReconciliationResolveRequest;
use App\Models\Reconciliation;
use App\Models\ReconciliationItem;
use App\Services\ReconciliationService;
use App\Support\ListResponse;
use Illuminate\Http\Request;
class ReconciliationController extends Controller {
    private function allow():void{$this->requirePermission('view payments');abort_unless(request()->user()->hasAnyRole(['Admin','Finance','Analyst']),403,'This action requires a finance or analyst role.');}
    public function index(ListRequest $request){$this->allow();$query=Reconciliation::withCount(['items','items as unmatched_count'=>fn($q)=>$q->where('status','!=','matched')->whereNull('resolved_at')]);if($request->filled('search')){$query->whereRaw('LOWER(filename) LIKE ?',['%'.mb_strtolower($request->search).'%']);}ListResponse::sort(ListResponse::dates($query,$request),$request,['created_at','filename']);$summary=['total'=>(clone$query)->count(),'unmatched_items'=>ReconciliationItem::where('status','!=','matched')->whereNull('resolved_at')->whereIn('reconciliation_id',(clone$query)->select('id'))->count()];if($request->export==='csv'){return ListResponse::csv($query,'reconciliations.csv',['ID','Filename','From','To','Items','Unmatched'],fn($r)=>[$r->id,$r->filename,$r->date_from->toDateString(),$r->date_to->toDateString(),$r->items_count,$r->unmatched_count]);}return ListResponse::paginate($query,$request,$summary);}
    public function show(Request $request,Reconciliation $run){$this->allow();return response()->json($run->load('items.payment'));}
    // Import and resolve span every payment in the file, so they need the same finance role as the list, not just the permission.
    public function store(ReconciliationRequest $request,ReconciliationService $service){$this->requirePermission('edit payments');$this->allow();return response()->json($service->import($request->file('file'),$request->date_from,$request->date_to,$request->user()),201);}
    public function resolve(ReconciliationResolveRequest $request,ReconciliationItem $item,ReconciliationService $service){$this->requirePermission('edit payments');$this->allow();return response()->json($service->resolve($item,$request->notes,$request->user()));}
}
