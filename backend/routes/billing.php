<?php
use App\Http\Controllers\BillingController as C;
use Illuminate\Support\Facades\Route;

Route::get('billing/options',[C::class,'options']);
foreach(['plans','subscriptions','invoices','commission-rules','commissions','settlements','payouts'] as $resource){
    Route::get($resource,[C::class,'index'])->defaults('resource',$resource);
}
foreach(['plans','subscriptions','commission-rules'] as $resource)Route::post($resource,[C::class,'store'])->defaults('resource',$resource);
foreach(['plans','commission-rules'] as $resource){Route::put($resource.'/{id}',[C::class,'update'])->defaults('resource',$resource);Route::delete($resource.'/{id}',[C::class,'destroy'])->defaults('resource',$resource);}
Route::post('settlements/generate',[C::class,'generate'])->defaults('resource','settlements');
foreach(['subscriptions','invoices','settlements'] as $resource)Route::get($resource.'/{id}',[C::class,'show'])->defaults('resource',$resource);
foreach(['change-plan'=>'changePlan','cancel'=>'cancel','resume'=>'resume'] as $path=>$action)Route::post('subscriptions/{id}/'.$path,[C::class,$action])->defaults('resource','subscriptions');
foreach(['charge'=>'charge','void'=>'voidInvoice'] as $path=>$action)Route::post('invoices/{id}/'.$path,[C::class,$action])->defaults('resource','invoices');
foreach(['finalize'=>'finalize','pay'=>'pay','void'=>'voidSettlement'] as $path=>$action)Route::post('settlements/{id}/'.$path,[C::class,$action])->defaults('resource','settlements');
