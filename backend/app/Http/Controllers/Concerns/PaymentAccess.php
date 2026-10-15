<?php
namespace App\Http\Controllers\Concerns;
use App\Models\Payment;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
trait PaymentAccess {
    protected function paymentRows(Builder $query,Request $request):Builder {
        $user=$request->user();
        if($user->hasRole('Administrative Assistant')&&!$user->hasAnyRole(['Admin','Accounting Staff'])){
            $query->where(function($query)use($user){$query->whereIn('id',Ticket::where(fn($query)=>$query->whereNull('assignee_id')->orWhere('assignee_id',$user->id))->whereNotNull('payment_id')->select('payment_id'))
                ->orWhereIn('order_id',Ticket::where(fn($query)=>$query->whereNull('assignee_id')->orWhere('assignee_id',$user->id))->whereNotNull('order_id')->select('order_id'));});
        }return $query;
    }
    protected function assertPaymentView(Payment $payment,Request $request):void {
        $this->requirePermission('view payments');$this->assertPaymentScope($payment,$request);
    }
    protected function assertPaymentScope(Payment $payment,Request $request):void {
        abort_unless($this->paymentRows(Payment::query(),$request)->whereKey($payment->id)->exists(),404);
    }
}
