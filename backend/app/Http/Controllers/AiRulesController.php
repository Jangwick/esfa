<?php

namespace App\Http\Controllers;

use App\Services\AiRules;
use Illuminate\Http\JsonResponse;

class AiRulesController extends Controller
{
    // Any signed-in staff may read the rulebooks; the staff middleware keeps integration tokens out.
    public function __invoke(): JsonResponse
    {
        return response()->json(AiRules::books());
    }
}
