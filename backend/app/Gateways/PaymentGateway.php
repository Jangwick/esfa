<?php
namespace App\Gateways;
use App\Models\Payment;
use Illuminate\Http\Request;
interface PaymentGateway {
    public function createPayment(Payment $payment): GatewayResult;
    public function capture(Payment $payment, int $amount): GatewayResult;
    public function cancel(Payment $payment): GatewayResult;
    public function refund(Payment $payment, int $amount, string $reason): GatewayResult;
    public function verifyWebhook(Request $request): bool;
    public function parseWebhook(Request $request): GatewayEvent;
}
