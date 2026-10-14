<?php
namespace App\Http\Controllers;
use App\Http\Requests\ListRequest;
use App\Models\LedgerEntry;
use App\Services\LedgerService;
use App\Support\ListResponse;
use Illuminate\Validation\Rule;
class LedgerController extends Controller {
    public function index(ListRequest $request){
        $this->requirePermission('view payments');abort_if($request->user()->hasRole('Support Agent')&&!$request->user()->hasAnyRole(['Admin','Finance','Analyst']),403,'This action requires a finance or analyst role.');
        $request->validate(['entry_type'=>['nullable',Rule::in(LedgerService::TYPES)],'account'=>['nullable',Rule::in(LedgerService::ACCOUNTS)],'direction'=>'nullable|in:debit,credit']);$query=LedgerEntry::query();
        if($request->filled('search')){$query->whereRaw('LOWER(memo) LIKE ?',['%'.mb_strtolower($request->search).'%']);}foreach(['entry_type','account','direction'] as $field){if($request->filled($field)){$query->where($field,$request->input($field));}}
        ListResponse::sort(ListResponse::dates($query,$request),$request,['created_at','entry_type','account','total_amount']);$summary=['total'=>(clone$query)->count(),'debits'=>(int)(clone$query)->where('direction','debit')->sum('total_amount'),'credits'=>(int)(clone$query)->where('direction','credit')->sum('total_amount')];
        if($request->export==='csv'){return ListResponse::csv($query,'ledger.csv',['Event','Type','Direction','Account','Amount (centavos)','Memo','Time (UTC)'],fn($e)=>[$e->event_id,$e->entry_type,$e->direction,$e->account,$e->amount,$e->memo,$e->created_at->toIso8601String()]);}return ListResponse::paginate($query,$request,$summary);
    }
}
