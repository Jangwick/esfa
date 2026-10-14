<?php
namespace App\Services;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Reconciliation;
use App\Models\ReconciliationItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class ReconciliationService {
    public function import(UploadedFile $file,string $from,string $to,User $actor):Reconciliation {
        $raw=$file->getContent();$stream=fopen('php://temp','r+');fwrite($stream,$raw);rewind($stream);
        $header=fgetcsv($stream);if($header){$header[0]=ltrim($header[0],"\xEF\xBB\xBF");}$required=['date','gateway_reference','amount','fee'];
        if(!$header||array_diff($required,$header)){fclose($stream);throw ValidationException::withMessages(['file'=>['CSV headers must include date, gateway_reference, amount and fee.']]);}
        $rows=[];$seen=[];$line=1;
        while(($row=fgetcsv($stream))!==false){$line++;if($row===[null]){continue;}if(count($row)!==count($header)){throw ValidationException::withMessages(['file'=>["CSV row {$line} has the wrong number of columns."]]);}
            $data=array_combine($header,$row);$date=$data['date'];$parsed=null;
            try{$parsed=CarbonImmutable::createFromFormat('!Y-m-d',$date);}catch(\Throwable){}
            // The most common import mistake (a period shorter than the file), so it gets its own message.
            if($parsed&&$parsed->format('Y-m-d')===$date&&($date<$from||$date>$to)){throw ValidationException::withMessages(['file'=>["CSV row {$line} is dated {$date}, outside the period {$from} to {$to}. Import it with a period that covers every row."]]);}
            if(!$parsed||$parsed->format('Y-m-d')!==$date||$date<$from||$date>$to||trim($data['gateway_reference'])===''||strlen($data['gateway_reference'])>255||isset($seen[$data['gateway_reference']])){throw ValidationException::withMessages(['file'=>["CSV row {$line} has an invalid date or duplicate/empty gateway reference."]]);}
            $seen[$data['gateway_reference']]=true;$rows[]=['date'=>$date,'gateway_reference'=>$data['gateway_reference'],'total_amount'=>Money::pesos($data['amount']),'fee_amount'=>Money::pesos($data['fee'])];
        }fclose($stream);if(!$rows){throw ValidationException::withMessages(['file'=>['The CSV must contain at least one settlement row.']]);}
        return DB::transaction(function()use($file,$raw,$from,$to,$actor,$rows,$seen){
            $run=Reconciliation::create(['filename'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType() ?: 'text/csv','size'=>$file->getSize(),'content'=>base64_encode($raw),'date_from'=>$from,'date_to'=>$to,'created_by'=>$actor->id]);
            $matched=[];foreach($rows as $row){$payment=Payment::where('gateway_reference',$row['gateway_reference'])->first();$status=!$payment?'missing_in_sfa':($payment->captured_amount===$row['total_amount']&&$payment->fee_amount===$row['fee_amount']?'matched':'amount_mismatch');$run->items()->create(array_merge($row,['payment_id'=>$payment?->id,'status'=>$status]));if($status==='matched'){$matched[]=$payment->id;}}
            // PAY-10: a later file that reports a payment with the right amounts settles an earlier "missing in report" row. A mismatch never does.
            foreach(array_chunk($matched,500) as $ids){foreach(ReconciliationItem::where('status','missing_in_report')->whereNull('resolved_at')->whereIn('payment_id',$ids)->lockForUpdate()->get() as $item){$item->update(['resolution_notes'=>'Resolved automatically: '.$run->filename.' reported this payment with matching amounts.','resolved_by'=>null,'resolved_at'=>now()]);AuditLog::system($item,['resolved_at'=>null],['resolved_at'=>$item->resolved_at->toIso8601String(),'resolution_notes'=>$item->resolution_notes,'reconciliation_id'=>$run->id],'reconciliation_auto_resolve');}}
            $zone=Setting::valueFor('business_timezone','Asia/Manila');
            foreach($this->gatewayCaptures($from,$to)->get() as $payment){if(!isset($seen[$payment->gateway_reference])){$run->items()->create(['payment_id'=>$payment->id,'date'=>$payment->captured_at->timezone($zone)->toDateString(),'gateway_reference'=>$payment->gateway_reference,'total_amount'=>$payment->captured_amount,'fee_amount'=>$payment->fee_amount,'status'=>'missing_in_report']);}}
            return $run->load('items.payment');
        });
    }
    /**
     * The payments a gateway settlement report for these business-timezone dates should list: captured through a gateway
     * driver. COD cash never passes through the gateway (CodGateway is a local stand-in); subscription charges do
     * (PaymentService::chargeInvoice uses the sandbox driver), so they stay in.
     */
    public function gatewayCaptures(string $from,string $to):Builder {
        $zone=Setting::valueFor('business_timezone','Asia/Manila');$start=CarbonImmutable::parse($from,$zone)->startOfDay()->utc();$end=CarbonImmutable::parse($to,$zone)->addDay()->startOfDay()->utc();
        return Payment::where('gateway','!=','cod')->where('captured_amount','>',0)->where('captured_at','>=',$start)->where('captured_at','<',$end);
    }
    public function resolve(ReconciliationItem $item,string $notes,User $actor):ReconciliationItem {
        return DB::transaction(function()use($item,$notes,$actor){
            $item=ReconciliationItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            if($item->status==='matched'||$item->resolved_at){throw ValidationException::withMessages(['item'=>['Only an unresolved mismatch may be resolved.']]);}
            $item->update(['resolution_notes'=>$notes,'resolved_by'=>$actor->id,'resolved_at'=>now()]);return $item->fresh('payment');
        });
    }
}
