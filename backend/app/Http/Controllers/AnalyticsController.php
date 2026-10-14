<?php
namespace App\Http\Controllers;
use App\Http\Requests\AnalyticsRequest;
use App\Services\ReportingService;
use App\Support\ListResponse;
class AnalyticsController extends Controller
{
    // "Ask your data": Gemini answers from SFA's own read-only reports with the asker's permissions; nothing changes, and
    // the question is kept in ai_insights instead of the audit log.
    public function ask(\Illuminate\Http\Request $r,\App\Services\AskDataService $s){$this->requirePermission('view analytics');$data=$r->validate(['question'=>'required|string|min:5|max:500']);$r->attributes->set('audit.skip',true);return response()->json($s->ask($data['question'],$r->user()));}
    public function dashboard(AnalyticsRequest $r,ReportingService $s){return response()->json($s->dashboard($r->user()));}
    public function overview(AnalyticsRequest $r,ReportingService $s){return response()->json(['summary'=>$s->summary($r->user(),$r->validated()),'choices'=>$s->choices(),'timezone'=>$s->range($r->validated())[2]]);}
    public function charts(AnalyticsRequest $r,ReportingService $s){return response()->json(['reports'=>$s->charts($r->user(),$r->validated()),'timezone'=>$s->range($r->validated())[2]]);}
    public function integration(AnalyticsRequest $r,ReportingService $s){return response()->json($s->summary($r->user(),$r->validated()));}
    public function export(AnalyticsRequest $r,ReportingService $s)
    {
        $r->validate(['report'=>'required']);$report=$s->charts($r->user(),$r->validated())[$r->report];
        return response()->streamDownload(function()use($report){$stream=fopen('php://output','w');fwrite($stream,"\xEF\xBB\xBF");fputcsv($stream,['Name','Value ('.$report['unit'].')']);foreach($report['data'] as $row){fputcsv($stream,[ListResponse::cell($row['name']),$row['value']]);}fclose($stream);},$r->report.'.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
    }
}
