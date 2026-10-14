<?php
namespace App\Gateways;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class SandboxGateway implements PaymentGateway {
    public function createPayment(Payment $payment): GatewayResult {
        $reference='sandbox_'.$payment->reference;
        if($payment->amount%100===99){return new GatewayResult('failed',$reference,'Card declined');}
        if($payment->amount%100===98){return new GatewayResult('pending',$reference,null,['type'=>'sandbox_pending','message'=>'Waiting for a sandbox webhook.']);}
        return new GatewayResult('authorized',$reference);
    }
    public function capture(Payment $payment,int $amount):GatewayResult{return new GatewayResult('captured',$payment->gateway_reference ?: 'sandbox_'.$payment->reference);}
    public function cancel(Payment $payment):GatewayResult{return new GatewayResult('cancelled',$payment->gateway_reference ?: 'sandbox_'.$payment->reference);}
    public function refund(Payment $payment,int $amount,string $reason):GatewayResult {
        return new GatewayResult($amount%100===99?'failed':'completed','sandbox_refund_'.$payment->reference.'_'.$amount,$amount%100===99?'Sandbox refund declined':null);
    }
    public function verifyWebhook(Request $request):bool {
        $secret=config('payments.sandbox_webhook_secret');
        if(!is_string($secret)||$secret===''){return false;}
        if(!preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/',(string)$request->header('X-SFA-Signature'),$matches)){return false;}
        if(abs(now()->timestamp-(int)$matches[1])>300){return false;}
        return hash_equals(hash_hmac('sha256',$matches[1].'.'.$request->getContent(),$secret),$matches[2]);
    }
    public function parseWebhook(Request $request):GatewayEvent {
        $values=$request->validate(['event_id'=>'required|string|max:255','reference'=>'required|string|max:80','type'=>['required',Rule::in(['payment.authorized','payment.captured','payment.failed','payment.cancelled','payment.disputed','payment.dispute_won','payment.dispute_lost'])],'amount'=>'nullable|integer|min:1|max:9000000000000000']);
        return new GatewayEvent($values['event_id'],$values['type'],$values['reference'],$values['amount']??null);
    }
}
