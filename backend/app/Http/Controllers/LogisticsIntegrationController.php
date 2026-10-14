<?php

namespace App\Http\Controllers;

use App\Http\Requests\LogisticsRequest;
use App\Http\Requests\QuoteRequest;
use App\Models\Shipment;
use App\Services\LogisticsService;
use Illuminate\Http\Request;

class LogisticsIntegrationController extends Controller
{
    public function quotes(QuoteRequest $request, LogisticsService $service) { return response()->json(['data' => $service->quotes($request->validated())]); }
    public function store(LogisticsRequest $request, LogisticsService $service)
    {
        $shipment = $service->create($request->validated(), $request->user());
        $request->attributes->set('audit.entity_type', 'Shipment'); $request->attributes->set('audit.entity_id', $shipment->id); $request->attributes->set('audit.new_values', $shipment->toArray());
        return response()->json($shipment, 201);
    }
    public function show(Request $request, string $reference)
    {
        $shipment = Shipment::where('reference', $reference)->whereHas('order', fn ($query) => $query->where('source_system', $request->user()->system_code))->firstOrFail();
        return response()->json($shipment->load(LogisticsService::RELATIONS));
    }
}
