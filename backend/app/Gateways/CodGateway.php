<?php
namespace App\Gateways;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
class CodGateway implements PaymentGateway {
    public function createPayment(Payment $payment):GatewayResult{return new GatewayResult('pending','cod_'.$payment->reference,null,['type'=>'cash_on_delivery','message'=>'Collect cash when all shipments have been delivered.']);}
    public function capture(Payment $payment,int $amount):GatewayResult{return new GatewayResult('captured',$payment->gateway_reference ?: 'cod_'.$payment->reference);}
    public function cancel(Payment $payment):GatewayResult{return new GatewayResult('cancelled',$payment->gateway_reference ?: 'cod_'.$payment->reference);}
    public function refund(Payment $payment,int $amount,string $reason):GatewayResult{return new GatewayResult('completed','cod_refund_'.$payment->reference.'_'.$amount);}
    public function verifyWebhook(Request $request):bool{return false;}
    public function parseWebhook(Request $request):GatewayEvent{throw ValidationException::withMessages(['gateway'=>['COD events are recorded through cash collection.']]);}
}
