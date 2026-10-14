<?php

namespace App\Http\Controllers;

use App\Models\Courier;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\Shipment;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\LogisticsService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CourierWebhookController extends Controller
{
    public function __invoke(Request $request, string $courierCode, LogisticsService $service)
    {
        $courier = Courier::where('code', $courierCode)->where('is_active', true)->firstOrFail();
        $signature = $request->header('X-SFA-Signature', '');
        abort_unless(preg_match('/^t=(\d+),v1=([a-f0-9]{64})$/', $signature, $parts) && abs(now()->timestamp - (int) $parts[1]) <= 300 && hash_equals(hash_hmac('sha256', $parts[1].'.'.$request->getContent(), $courier->webhook_secret), $parts[2]), 401, 'Invalid or expired courier signature.');
        $values = $request->validate(['id' => 'required|string|max:255', 'tracking_number' => 'required|string|max:100', 'status' => ['required', Rule::in(Shipment::STATUSES)], 'location' => 'nullable|string|max:255', 'note' => 'nullable|string|max:10000', 'occurred_at' => 'nullable|date|before_or_equal:now', 'recipient_name' => 'nullable|required_with:proof_photo|string|max:255', 'proof_photo' => 'nullable|required_with:recipient_name|string']);
        try {
            $response = DB::transaction(function () use ($courier, $values, $service) {
                Courier::whereKey($courier->id)->lockForUpdate()->firstOrFail();
                $processed = DB::table('courier_webhook_events')->where('courier_id', $courier->id)->where('event_id', $values['id'])->first();
                if ($processed) { return response()->json(['message' => 'Courier event already processed.', 'duplicate' => true]); }
                $shipment = Shipment::where('courier_id', $courier->id)->where('tracking_number', $values['tracking_number'])->firstOrFail();
                $shipment = $service->lockForMutation($shipment);
                abort_unless($shipment->courier_id === $courier->id && $shipment->tracking_number === $values['tracking_number'], 404);
                if ($values['status'] === 'delivered' && isset($values['proof_photo'])) { $shipment = $this->courierProof($shipment, $values); }
                // With logistics_courier_fill_gaps on, the forward steps the courier skipped are recorded first.
                $from = array_search($shipment->status, LogisticsService::COURIER_FORWARD, true); $to = array_search($values['status'], LogisticsService::COURIER_FORWARD, true);
                if ($from !== false && $to !== false && $to > $from + 1 && Setting::valueFor('logistics_courier_fill_gaps')) {
                    foreach (array_slice(LogisticsService::COURIER_FORWARD, $from + 1, $to - $from - 1) as $step) { $shipment = $service->transition($shipment, array_filter(['status' => $step, 'note' => 'Inferred from a later courier event.', 'occurred_at' => $values['occurred_at'] ?? null]), 'courier_webhook'); }
                }
                $shipment = $service->transition($shipment, array_filter($values, fn ($value) => $value !== null), 'courier_webhook');
                DB::table('courier_webhook_events')->insert(['courier_id' => $courier->id, 'event_id' => $values['id'], 'shipment_id' => $shipment->id, 'created_at' => now(), 'updated_at' => now()]);
                return response()->json(['message' => 'Courier event processed.', 'shipment' => $shipment]);
            });
        } catch (ValidationException $exception) {
            // Outside the rolled-back transaction, so staff hear about every refused event once; the courier still gets 422.
            $this->alertRejected($courier, $values, $exception);
            throw $exception;
        }
        Cache::forever('sfa.api.version', (string) Str::uuid());

        return $response;
    }

    // LOG-9: with logistics_courier_pod on, a prepaid parcel out for delivery takes the courier's recipient and photo as
    // proof (stored like a staff upload, with no uploader), so the delivered event that follows passes the proof check.
    // COD parcels always need staff proof with cash collection.
    private function courierProof(Shipment $shipment, array $values): Shipment
    {
        if (! Setting::valueFor('logistics_courier_pod')) { throw ValidationException::withMessages(['proof_photo' => ['SFA does not accept courier proof of delivery; staff record the proof.']]); }
        if ($shipment->cod_amount > 0) { throw ValidationException::withMessages(['proof_photo' => ['A cash on delivery parcel needs staff proof of delivery with cash collection.']]); }
        if ($shipment->status !== 'out_for_delivery') { throw ValidationException::withMessages(['status' => ['Only an out-for-delivery shipment can receive proof of delivery.']]); }
        $bytes = base64_decode($values['proof_photo'], true);
        $mime = $bytes ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) : null;
        if (! $bytes || strlen($bytes) > 2 * 1024 * 1024 || ! in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) { throw ValidationException::withMessages(['proof_photo' => ['Send the proof photo as base64 PNG, JPG or WEBP up to 2 MB.']]); }
        $path = tempnam(sys_get_temp_dir(), 'pod');
        try {
            file_put_contents($path, $bytes);
            $attachment = app(AttachmentService::class)->store(new UploadedFile($path, 'courier-proof.'.explode('/', $mime)[1], $mime, null, true), $shipment);
        } finally { @unlink($path); }
        $shipment->update(['proof_attachment_id' => $attachment->id, 'recipient_name' => $values['recipient_name'], 'delivery_note' => $values['note'] ?? null]);

        return $shipment;
    }

    private function alertRejected(Courier $courier, array $values, ValidationException $exception): void
    {
        $key = $courier->code.':'.$values['id'];
        if (Notification::where('type', 'courier_event_rejected')->where('data->event', $key)->exists()) { return; }
        $shipment = Shipment::where('courier_id', $courier->id)->where('tracking_number', $values['tracking_number'])->first();
        $message = "{$courier->name} reported ".str_replace('_', ' ', $values['status']).' for '.($shipment?->reference ?? $values['tracking_number']).', and SFA refused it: '.collect($exception->errors())->flatten()->first();
        foreach (User::authorizedFor('edit shipments')->where('is_active', true)->pluck('id') as $userId) { Notification::create(['user_id' => $userId, 'type' => 'courier_event_rejected', 'title' => 'Courier update refused', 'message' => $message, 'data' => array_filter(['shipment_id' => $shipment?->id, 'reference' => $shipment?->reference, 'event' => $key])]); }
    }
}
