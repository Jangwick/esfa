<?php

namespace App\Http\Controllers;

use App\Http\Requests\SettingsRequest;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class SettingsController extends Controller
{
    private function values(): array
    {
        return array_replace(config('sfa.settings'), Setting::all()->pluck('value', 'key')->all());
    }

    public function index()
    {
        $this->requirePermission('manage settings');

        // The time zones SettingsRequest accepts, so the form offers a list instead of free text the server may refuse.
        return response()->json([...$this->values(), 'timezones' => timezone_identifiers_list()]);
    }

    public function update(SettingsRequest $request)
    {
        $this->requirePermission('manage settings');
        request()->attributes->set('audit.entity_type', 'Setting');
        request()->attributes->set('audit.old_values', $this->values());
        DB::transaction(function () use ($request) {
            foreach ($request->validated() as $key => $value) { Setting::updateOrCreate(['key' => $key], ['value' => $value]); }
        });
        request()->attributes->set('audit.new_values', $this->values());

        return response()->json($this->values());
    }
}
