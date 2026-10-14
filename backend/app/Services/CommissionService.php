<?php

namespace App\Services;

use App\Models\CommissionEntry;
use App\Models\CommissionRule;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Subscription;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

class CommissionService
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function capture(Payment $payment): void
    {
        if (!$payment->order_id || CommissionEntry::where('payment_id',$payment->id)->where('entry_type','capture')->exists()) return;
        foreach (OrderItem::where('order_id',$payment->order_id)->get() as $item) {
            $subscription = Subscription::with('plan')->where('seller_id',$item->seller_id)->whereIn('status',['active','trialing'])->latest('id')->first();
            $rule = $this->resolveRule($item, $subscription);
            $rate = $rule?->rate_bp ?? $subscription?->plan?->commission_rate_bp ?? 0;
            $fixed = $rule?->fixed_fee_amount ?? 0;
            $gross = (int) $item->line_total_amount;
            $commission = Money::halfUp($gross,$rate,10000) + $fixed;
            if ($commission > $gross) throw ValidationException::withMessages(['commission'=>['The selected commission exceeds this order item value. Update the commission rule.']]);
            CommissionEntry::create(['order_item_id'=>$item->id,'seller_id'=>$item->seller_id,'payment_id'=>$payment->id,'rule_id'=>$rule?->id,'entry_type'=>'capture','gross_amount'=>$gross,'rate_bp'=>$rate,'fixed_fee_amount'=>$fixed,'commission_amount'=>$commission,'seller_net_amount'=>$gross-$commission]);
            $links = ['payment_id'=>$payment->id];
            $this->ledger->post('payment_capture',$gross,'customer_funds','seller_payable',$links,'Allocate order item '.$item->id.' to seller '.$item->seller_id);
            $this->ledger->post('commission',$commission,'seller_payable','platform_revenue',$links,'Commission for order item '.$item->id);
        }
    }

    public function refund(Refund $refund): void
    {
        if (CommissionEntry::where('refund_id',$refund->id)->exists()) return;
        $payment = $refund->payment;
        if (!$payment || !$payment->order_id || !$payment->captured_amount) return;
        foreach (CommissionEntry::where('payment_id',$payment->id)->where('entry_type','capture')->lockForUpdate()->get() as $entry) {
            $reversed = CommissionEntry::where('reversal_of_id',$entry->id)->selectRaw('COALESCE(SUM(gross_amount),0) as gross, COALESCE(SUM(commission_amount),0) as commission')->first();
            $remainingGross = $entry->gross_amount + (int)$reversed->gross;
            $remainingCommission = $entry->commission_amount + (int)$reversed->commission;
            $lastRefund = $payment->refunded_amount >= $payment->captured_amount;
            $gross = min($remainingGross,$lastRefund ? $remainingGross : Money::halfUp($entry->gross_amount,(int)$refund->amount,(int)$payment->captured_amount));
            $commission = min($remainingCommission,$lastRefund ? $remainingCommission : Money::halfUp($entry->commission_amount,(int)$refund->amount,(int)$payment->captured_amount));
            $commission = min($gross,$commission);
            CommissionEntry::create(['order_item_id'=>$entry->order_item_id,'seller_id'=>$entry->seller_id,'payment_id'=>$payment->id,'refund_id'=>$refund->id,'rule_id'=>$entry->rule_id,'reversal_of_id'=>$entry->id,'entry_type'=>'reversal','gross_amount'=>-$gross,'rate_bp'=>$entry->rate_bp,'fixed_fee_amount'=>0,'commission_amount'=>-$commission,'seller_net_amount'=>-($gross-$commission)]);
            $this->ledger->post('commission_reversal',$commission,'platform_revenue','customer_funds',['payment_id'=>$payment->id,'refund_id'=>$refund->id],'Reverse commission for order item '.$entry->order_item_id);
            $this->ledger->post('refund',$gross-$commission,'seller_payable','customer_funds',['payment_id'=>$payment->id,'refund_id'=>$refund->id],'Reverse seller proceeds for order item '.$entry->order_item_id);
        }
    }

    public function resolveRule(OrderItem $item, ?Subscription $subscription = null): ?CommissionRule
    {
        $active = fn() => CommissionRule::where('effective_from','<=',now())->where(fn($q)=>$q->whereNull('effective_to')->orWhere('effective_to','>',now()));
        foreach (['seller'=>$item->seller_id,'plan'=>$subscription?->plan_id,'category'=>$item->category_id] as $scope=>$id) {
            if ($id && ($rule=$active()->where('scope',$scope)->where('scope_id',$id)->latest('effective_from')->latest('id')->first())) return $rule;
            if ($scope==='plan' && $subscription?->plan?->commission_rate_bp !== null) return null;
        }
        $rule=$active()->where('scope','global')->latest('effective_from')->first();
        if (!$rule) throw ValidationException::withMessages(['commission'=>['An active global commission rule is required.']]);
        return $rule;
    }
}
