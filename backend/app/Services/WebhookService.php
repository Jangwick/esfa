<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\IntegrationClient;
use App\Models\Notification;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class WebhookService
{
    public const RETRY_SECONDS = [60, 300, 1800, 7200, 43200];

    public function __construct(private readonly WebhookUrlGuard $urlGuard) {}

    /**
     * Queue an event for the integration client that owns the record's source system.
     * Events are private to that client: with no active owner nothing is sent.
     */
    public function emitFor(string $type, array $data, ?string $sourceSystem): array
    {
        $client = $sourceSystem ? IntegrationClient::where('system_code', $sourceSystem)->where('is_active', true)->first() : null;

        return $client ? $this->emit($type, $data, $client) : [];
    }

    /** Call inside the business transaction; the scheduler sends committed deliveries. */
    public function emit(string $type, array $data, IntegrationClient $client): array
    {
        if (! in_array($type, WebhookEndpoint::EVENTS, true)) {
            throw ValidationException::withMessages(['event_type' => ['This webhook event is not in the event catalog.']]);
        }
        $query = WebhookEndpoint::where('client_id', $client->id)->where('is_active', true)
            ->whereHas('client', fn ($query) => $query->where('is_active', true));
        $eventId = 'evt_'.Str::uuid();
        $body = $this->eventBody($eventId, $type, $data);
        $deliveries = [];
        foreach ($query->get() as $endpoint) {
            if (in_array($type, $endpoint->events, true)) {
                $deliveries[] = $this->createDelivery($endpoint, $eventId, $type, $body);
            }
        }
        // Simulator mode (never production by default): send once the response is done instead of waiting for the
        // scheduler, so a demo partner sees events within seconds. Failures still retry on the normal schedule.
        if ($deliveries && config('sfa.simulator')) {
            app()->terminating(function () use ($deliveries) { foreach ($deliveries as $delivery) { $this->deliver($delivery); } });
        }

        return $deliveries;
    }

    public function test(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $this->ensureActive($endpoint);
        $eventId = 'evt_'.Str::uuid();
        $delivery = $this->createDelivery($endpoint, $eventId, 'webhook.test',
            $this->eventBody($eventId, 'webhook.test', ['message' => 'Archon Nell SFA webhook test.']));
        $this->deliver($delivery);

        return $delivery->fresh('endpoint:id,url,client_id');
    }

    public function resend(WebhookDelivery $delivery): WebhookDelivery
    {
        $this->ensureActive($delivery->endpoint);
        DB::transaction(function () use ($delivery) {
            $locked = WebhookDelivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'processing') {
                throw ValidationException::withMessages(['delivery' => ['This webhook delivery is currently processing.']]);
            }
            $locked->update(['status' => 'pending', 'retry_count' => 0, 'next_attempt_at' => now(), 'claimed_at' => null]);
        });
        $this->deliver($delivery->fresh());

        return $delivery->fresh('endpoint:id,url,client_id');
    }

    public function deliver(WebhookDelivery $delivery): bool
    {
        $claimed = DB::transaction(function () use ($delivery) {
            $locked = WebhookDelivery::whereKey($delivery->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'pending' || $locked->next_attempt_at?->isFuture()) {
                return null;
            }
            $endpoint = $locked->endpoint;
            if (! $endpoint || ! $endpoint->is_active || ! $endpoint->client?->is_active) {
                return null;
            }
            $locked->update(['status' => 'processing', 'claimed_at' => now()]);

            return $locked;
        });
        if (! $claimed) {
            return false;
        }
        $endpoint = $claimed->endpoint;
        $timestamp = now()->timestamp;
        $status = null;
        $responseBody = '';
        $startedAt = microtime(true);
        $successful = false;
        try {
            $response = $this->post($endpoint, $claimed->id, $claimed->event_type, $claimed->request_body, $timestamp, 10);
            $status = $response->status();
            $responseBody = mb_strcut($response->body(), 0, 2048, 'UTF-8');
            $successful = $response->successful();
        } catch (Throwable $exception) {
            // Store a safe transport error; never expose client secrets or resolver details.
            $responseBody = $exception instanceof ValidationException
                ? 'Delivery blocked: the webhook URL is no longer a permitted destination.'
                : 'Webhook connection failed or exceeded the 10-second timeout.';
        }
        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        DB::transaction(function () use ($claimed, $status, $responseBody, $duration, $successful, $timestamp) {
            $locked = WebhookDelivery::whereKey($claimed->id)->lockForUpdate()->first();
            $endpoint = WebhookEndpoint::whereKey($claimed->endpoint_id)->lockForUpdate()->first();
            if (! $locked || ! $endpoint) {
                return;
            }
            $count = $locked->retry_count + 1;
            $log = $locked->attempt_log ?? [];
            $log[] = [
                'attempt' => $locked->attempts + 1, 'timestamp' => $timestamp,
                'response_status' => $status, 'response_body' => $responseBody, 'duration_ms' => $duration,
            ];
            $retry = ! $successful && isset(self::RETRY_SECONDS[$count - 1]);
            $locked->update([
                'status' => $successful ? 'delivered' : ($retry ? 'pending' : 'failed'),
                'attempts' => $locked->attempts + 1, 'retry_count' => $count,
                'next_attempt_at' => $retry ? now()->addSeconds(self::RETRY_SECONDS[$count - 1]) : null,
                'claimed_at' => null, 'response_status' => $status, 'response_body' => $responseBody,
                'duration_ms' => $duration, 'attempt_log' => $log,
            ]);
            $failures = $successful ? 0 : $endpoint->consecutive_failures + 1;
            $disable = $failures >= 20 && $endpoint->is_active;
            $endpoint->update([
                'consecutive_failures' => $failures,
                'is_active' => $disable ? false : $endpoint->is_active,
                'disabled_at' => $disable ? now() : $endpoint->disabled_at,
            ]);
            if ($disable) {
                $this->notifyAdmins('webhook_endpoint_disabled', 'Webhook endpoint disabled',
                    'A webhook endpoint was disabled after 20 consecutive failed delivery attempts.', ['endpoint_id' => $endpoint->id]);
            }
        });
        // The attempt changed a delivery outside any write request, so cached lists (the delivery log) must not keep
        // showing it as pending.
        Cache::forever('sfa.api.version', (string) Str::uuid());

        return $successful;
    }

    /**
     * Give failed deliveries on active endpoints of active clients a fresh retry cycle (at most 500 per call). The
     * scheduler sends them; the status re-check in the UPDATE makes a repeated or concurrent call queue nothing twice.
     */
    public function requeueFailed(?int $endpointId = null): int
    {
        $ids = WebhookDelivery::where('status', 'failed')->when($endpointId, fn ($query) => $query->where('endpoint_id', $endpointId))
            ->whereHas('endpoint', fn ($query) => $query->where('is_active', true)
                ->whereHas('client', fn ($query) => $query->where('is_active', true)))
            ->orderBy('created_at')->limit(500)->pluck('id');

        return WebhookDelivery::whereIn('id', $ids)->where('status', 'failed')
            ->whereHas('endpoint', fn ($query) => $query->where('is_active', true)
                ->whereHas('client', fn ($query) => $query->where('is_active', true)))
            ->update(['status' => 'pending', 'retry_count' => 0, 'next_attempt_at' => now(), 'claimed_at' => null]);
    }

    /**
     * ADM-04: probe one endpoint the system disabled after 20 failures (disabled_at set; a hand-set disable clears it)
     * with a signed webhook.test, at most once an hour per endpoint. A 2xx re-enables it, requeues its failed
     * deliveries and tells active Admins. Returns the number of endpoints re-enabled (0 or 1).
     */
    public function recoverDisabled(): int
    {
        // Reserve the hourly probe before sending. A slow probe must not let a concurrent run probe it again.
        $endpoint = DB::transaction(function () {
            $endpoint = WebhookEndpoint::where('is_active', false)->whereNotNull('disabled_at')->where('updated_at', '<=', now()->subHour())
                ->whereHas('client', fn ($query) => $query->where('is_active', true))->orderBy('updated_at')->lockForUpdate()->first();
            $endpoint?->touch();

            return $endpoint;
        });
        if (! $endpoint) {
            return 0;
        }
        $eventId = 'evt_'.Str::uuid();
        $body = $this->eventBody($eventId, 'webhook.test', ['message' => 'Archon Nell SFA endpoint recovery check.']);
        try {
            $answered = $this->post($endpoint, (string) Str::uuid(), 'webhook.test', $body, now()->timestamp, 5)->successful();
        } catch (Throwable) {
            $answered = false;
        }
        if (! $answered) {
            return 0;
        }

        return DB::transaction(function () use ($endpoint) {
            $locked = WebhookEndpoint::whereKey($endpoint->id)->lockForUpdate()->first();
            $client = $locked ? IntegrationClient::whereKey($locked->client_id)->lockForUpdate()->first() : null;
            if (! $locked || $locked->is_active || ! $locked->disabled_at || ! $client?->is_active) {
                return 0; // The endpoint or owning client changed while the probe was in flight.
            }
            foreach (['client_id', 'url', 'secret', 'disabled_at', 'updated_at'] as $field) {
                if ($locked->getRawOriginal($field) !== $endpoint->getRawOriginal($field)) {
                    return 0; // A response from an old URL or signing secret cannot enable changed settings.
                }
            }
            $fields = ['is_active', 'consecutive_failures', 'disabled_at'];
            $old = $locked->only($fields);
            $locked->update(['is_active' => true, 'consecutive_failures' => 0, 'disabled_at' => null]);
            $requeued = $this->requeueFailed($locked->id);
            AuditLog::system($locked, $old, [...$locked->only($fields), 'requeued_deliveries' => $requeued], 'webhook_auto_recover');
            $this->notifyAdmins('webhook_endpoint_recovered', 'Webhook endpoint re-enabled',
                "A disabled webhook endpoint answered a test event, so it was re-enabled. Failed deliveries queued again: {$requeued}.",
                ['endpoint_id' => $locked->id]);

            return 1;
        });
    }

    public function signature(int $timestamp, string $rawBody, string $secret): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    // Every outbound request goes through the URL guard (no redirects, DNS pinned) and carries the signed headers.
    private function post(WebhookEndpoint $endpoint, string $deliveryId, string $eventType, string $body, int $timestamp, int $timeout): Response
    {
        return Http::withOptions($this->urlGuard->transportOptions($endpoint->url))->connectTimeout(3)->timeout($timeout)
            ->withHeaders([
                'X-SFA-Event' => $eventType,
                'X-SFA-Delivery' => $deliveryId,
                'X-SFA-Signature' => $this->signature($timestamp, $body, $endpoint->secret),
            ])->withBody($body, 'application/json')->post($endpoint->url);
    }

    private function notifyAdmins(string $type, string $title, string $message, array $data): void
    {
        $admins = User::whereHas('roles', fn ($roles) => $roles->where('name', 'Admin'))->where('is_active', true)->pluck('id');
        foreach ($admins as $id) {
            Notification::create(['user_id' => $id, 'type' => $type, 'title' => $title, 'message' => $message, 'data' => $data]);
        }
    }

    private function createDelivery(WebhookEndpoint $endpoint, string $eventId, string $type, string $body): WebhookDelivery
    {
        return WebhookDelivery::create([
            'id' => (string) Str::uuid(), 'endpoint_id' => $endpoint->id,
            'event_id' => $eventId, 'event_type' => $type, 'request_body' => $body,
            'status' => 'pending', 'next_attempt_at' => now(), 'attempt_log' => [],
        ]);
    }

    private function eventBody(string $eventId, string $type, array $data): string
    {
        return json_encode([
            'id' => $eventId, 'type' => $type, 'created_at' => now()->utc()->toIso8601String(), 'data' => $data,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public function ensureActive(WebhookEndpoint $endpoint): void
    {
        if (! $endpoint->is_active || ! $endpoint->client?->is_active) {
            throw ValidationException::withMessages(['endpoint' => ['Enable the webhook endpoint and integration client before sending.']]);
        }
    }
}
