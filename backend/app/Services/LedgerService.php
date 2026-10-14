<?php
namespace App\Services;
use App\Models\LedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
class LedgerService {
    public const TYPES=['payment_capture','refund','chargeback','chargeback_reversal','gateway_fee','commission','commission_reversal','subscription_charge','payout','adjustment'];
    public const ACCOUNTS=['customer_funds','platform_revenue','seller_payable','gateway_clearing','cash_on_delivery'];
    public function post(string $entryType,int $amount,string $debitAccount,string $creditAccount,array $links=[],string $memo=''):void {
        if($amount<0 || !in_array($entryType,self::TYPES,true) || !in_array($debitAccount,self::ACCOUNTS,true) || !in_array($creditAccount,self::ACCOUNTS,true) || $debitAccount===$creditAccount){throw new InvalidArgumentException('A ledger event needs a nonnegative integer amount and two distinct valid accounts.');}
        if($amount===0){return;}
        DB::transaction(function()use($entryType,$amount,$debitAccount,$creditAccount,$links,$memo){
            $base=array_merge(array_intersect_key($links,array_flip(['payment_id','refund_id','settlement_id','payout_id','created_by'])),['event_id'=>(string)Str::uuid(),'entry_type'=>$entryType,'total_amount'=>$amount,'currency'=>'PHP','memo'=>$memo ?: str_replace('_',' ',$entryType),'created_at'=>now()]);
            LedgerEntry::create(array_merge($base,['direction'=>'debit','account'=>$debitAccount]));
            LedgerEntry::create(array_merge($base,['direction'=>'credit','account'=>$creditAccount]));
        });
    }
}
