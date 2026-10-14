<?php

namespace App\Services;

use App\Models\IdempotencyRecord;
use App\Models\IntegrationClient;
use App\Models\LoginLockout;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\SchedulerLock;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Shipment;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class Scheduler
{
    public function __construct(private readonly WebhookService $webhooks) {}

    public function run(bool $throttled = false): array
    {
        // SFA_SCHEDULER_ENABLED=false pauses every trigger: cron, artisan, the simulator and post-response runs.
        if (! config('sfa.scheduler_enabled')) {
            return ['ran' => false, 'paused' => true, 'webhook_attempts' => 0, 'idempotency_pruned' => 0];
        }
        $owner = (string) Str::uuid();
        $acquired = DB::transaction(function () use ($owner, $throttled) {
            DB::table('scheduler_locks')->insertOrIgnore(['name' => 'scheduled-work']);
            $lock = SchedulerLock::whereKey('scheduled-work')->lockForUpdate()->firstOrFail();
            if ($lock->locked_until?->isFuture() || ($throttled && $lock->last_run_at?->gt(now()->subMinutes(5)))) {
                return false;
            }
            $lock->update(['owner' => $owner, 'locked_until' => now()->addMinutes(5), 'last_run_at' => now()]);

            return true;
        });
        if (! $acquired) {
            return ['ran' => false, 'webhook_attempts' => 0, 'idempotency_pruned' => 0];
        }
        $attempts = 0; $pruned = 0; $failed = []; $result = null;
        try {
            // Every job is isolated: a failure is reported and listed in `failed_jobs`, and the others still run.
            $jobs = [
                'idempotency' => function () use (&$pruned) { $pruned = IdempotencyRecord::where('expires_at', '<=', now())->delete(); return []; },
                // Before 'webhooks', so a recovered endpoint's requeued deliveries go out in the same run.
                'webhook_recovery' => fn () => ['webhook_endpoints_recovered' => Setting::valueFor('webhook_auto_recover') ? $this->webhooks->recoverDisabled() : 0],
                'webhooks' => function () use (&$attempts, &$failed) {
                    // Recover a terminated process only after the original 10-second send has timed out.
                    WebhookDelivery::where('status', 'processing')->where('claimed_at', '<=', now()->subMinutes(5))
                        ->update(['status' => 'pending', 'claimed_at' => null, 'next_attempt_at' => now()]);
                    $due = WebhookDelivery::where('status', 'pending')->where('next_attempt_at', '<=', now())
                        ->whereHas('endpoint', fn ($query) => $query->where('is_active', true)
                            ->whereHas('client', fn ($query) => $query->where('is_active', true)))
                        ->orderBy('next_attempt_at')->limit(20)->get();
                    $deliveryFailed = false;
                    foreach ($due as $delivery) {
                        try {
                            $before = $delivery->attempts;
                            $this->webhooks->deliver($delivery);
                            $attempts += $delivery->fresh()?->attempts > $before ? 1 : 0;
                        } catch (Throwable $exception) {
                            report($exception);
                            $deliveryFailed = true;
                        }
                    }
                    if ($deliveryFailed) { $failed[] = 'webhooks'; }
                    return [];
                },
                'payments' => fn () => app(PaymentService::class)->runScheduled(),
                // Off unless ai_risk_second_opinion; it can only add a payment for review or send Finance a notice.
                'ai_second_opinion' => fn () => ['payments_ai_flagged' => app(FinanceReviewService::class)->secondOpinion()],
                'tickets' => fn () => app(TicketService::class)->runScheduled(),
                'billing' => fn () => app(BillingService::class)->runScheduled(),
                'logistics' => fn () => app(LogisticsService::class)->runScheduled(),
                'token_reminders' => function () use (&$failed) { return $this->remindExpiringTokens($failed); },
                // Last, so notifications about items resolved earlier in this run are cleared too.
                'notifications' => fn () => $this->tidyNotifications(),
            ];
            $domain = [];
            foreach ($jobs as $name => $job) {
                try { $domain = array_merge($domain, $job()); } catch (Throwable $exception) { report($exception); $failed[] = $name; }
            }

            $result = array_merge(['ran' => true, 'webhook_attempts' => $attempts, 'idempotency_pruned' => $pruned], $domain, ['failed_jobs' => $failed]);
            rescue(fn () => $this->alertFailedJobs($failed));

            return $result;
        } finally {
            // The last completed run's counts are kept for GET /automation.
            SchedulerLock::whereKey('scheduled-work')->where('owner', $owner)->update(['owner' => null, 'locked_until' => null]
                + ($result ? ['last_result' => json_encode([...$result, 'finished_at' => now()->toIso8601String()])] : []));
        }
    }

    // Each failed job alerts active Admins at most once per job per day; the exception itself is in the error log.
    private function alertFailedJobs(array $failed): void
    {
        $admins = $failed ? User::whereHas('roles', fn ($roles) => $roles->where('name', 'Admin'))->where('is_active', true)->pluck('id') : [];
        foreach ($failed as $job) {
            foreach ($admins as $id) {
                if (Notification::where('user_id', $id)->where('type', 'scheduler_job_failed')->where('data->job', $job)->where('created_at', '>', now()->subDay())->exists()) {
                    continue;
                }
                Notification::create(['user_id' => $id, 'type' => 'scheduler_job_failed', 'title' => 'Scheduled job failed',
                    'message' => "The scheduled {$job} job failed; the other jobs still ran. Details are in the error log.", 'data' => ['job' => $job]]);
            }
        }
    }

    // ADM-05: active users who manage integrations get one reminder per token that expires within the window (0 = off).
    private function remindExpiringTokens(array &$failed): array
    {
        $days = (int) Setting::valueFor('token_expiry_reminder_days');
        if ($days <= 0) { return ['token_expiry_reminders' => 0]; }
        $eligible = PersonalAccessToken::where('tokenable_type', (new IntegrationClient)->getMorphClass())
            ->whereIn('tokenable_id', IntegrationClient::where('is_active', true)->select('id'))
            // The Process Simulator's own 12-hour tokens (POST /simulator/connect) are renewed on every connect.
            ->where('name', '!=', 'simulator')
            ->where('expires_at', '>', now())->where('expires_at', '<=', now()->addDays($days));
        if (! $eligible->exists()) { return ['token_expiry_reminders' => 0]; }
        $users = User::authorizedFor('manage integrations')->where('is_active', true)->pluck('id');
        if ($users->isEmpty()) { return ['token_expiry_reminders' => 0]; }
        $tokens = $eligible
            // Apply deduplication before the batch limit, so the first 100 already-reminded tokens do not starve
            // later ones. Cast the id to text because token ids are deliberately stored as JSON strings.
            ->where(function ($query) use ($users) {
                foreach ($users as $id) {
                    $query->orWhereNotExists(fn ($notes) => $notes->selectRaw('1')->from('notifications')
                        ->where('user_id', $id)->where('type', 'integration_token_expiring')
                        ->whereColumn('data->token_id', DB::raw('CAST(personal_access_tokens.id AS VARCHAR)')));
                }
            })->orderBy('expires_at')->limit(100)->get();
        $sent = 0;
        $tokenFailed = false;
        foreach ($tokens as $token) {
            try {
                $sent += DB::transaction(function () use ($token, $days) {
                    $locked = PersonalAccessToken::whereKey($token->id)->lockForUpdate()->first();
                    if (! $locked || ! $locked->expires_at?->isFuture() || $locked->expires_at->gt(now()->addDays($days))
                        || ! ($locked->tokenable instanceof IntegrationClient) || ! $locked->tokenable->is_active) {
                        return 0;
                    }
                    $reminded = Notification::where('type', 'integration_token_expiring')->where('data->token_id', (string) $locked->id)->pluck('user_id');
                    $recipients = User::authorizedFor('manage integrations')->where('is_active', true)->whereNotIn('id', $reminded)->pluck('id');
                    foreach ($recipients as $id) {
                        Notification::create(['user_id' => $id, 'type' => 'integration_token_expiring', 'title' => 'Integration token expiring',
                            'message' => "{$locked->tokenable->name}'s token \"{$locked->name}\" expires on ".$locked->expires_at->timezone(Setting::valueFor('business_timezone'))->format('M j, Y g:i A').'. Issue a new one before then.',
                            'data' => ['token_id' => (string) $locked->id, 'client_id' => $locked->tokenable_id]]);
                    }

                    return $recipients->count();
                });
            } catch (Throwable $exception) {
                report($exception);
                $tokenFailed = true;
            }
        }
        if ($tokenFailed) { $failed[] = 'token_reminders'; }

        return ['token_expiry_reminders' => $sent];
    }

    // ADM-09: a notification whose item no longer needs action is marked read for every recipient; read notifications
    // older than the retention period are deleted (0 = keep). Unread notifications are never deleted.
    private function tidyNotifications(): array
    {
        $cleared = 0;
        foreach ($this->notificationChecks() as $type => [$model, $key, $actionable]) {
            Notification::where('type', $type)->where('is_read', false)->select(['id', 'data'])->chunkById(500, function ($rows) use ($model, $key, $actionable, &$cleared) {
                $ids = $rows->pluck('data.'.$key)->filter()->map(fn ($id) => (string) $id)->unique()->values();
                $open = $ids->isEmpty() ? [] : $actionable($model::query()->whereKey($ids->all()))->pluck((new $model)->getKeyName())->map(fn ($id) => (string) $id)->all();
                $stale = $rows->filter(fn ($row) => isset($row->data[$key]) && ! in_array((string) $row->data[$key], $open, true))->modelKeys();
                $cleared += $stale ? Notification::whereKey($stale)->update(['is_read' => true, 'read_at' => now()]) : 0;
            });
        }
        $days = (int) Setting::valueFor('notification_retention_days');
        $pruned = 0;
        if ($days > 0) {
            // A live token's reminder is also its once-per-user deduplication record. Retain that row while the token
            // exists, even if a manager read it and chose a shorter retention period than the reminder window.
            $tokenIds = PersonalAccessToken::where('tokenable_type', (new IntegrationClient)->getMorphClass())->pluck('id')
                ->map(fn ($id) => (string) $id)->all();
            $pruned = Notification::where('is_read', true)->where('created_at', '<', now()->subDays($days))
                ->where(function ($query) use ($tokenIds) {
                    $query->where('type', '!=', 'integration_token_expiring')->orWhere(function ($tokens) use ($tokenIds) {
                        $tokens->where('type', 'integration_token_expiring')
                            ->where(fn ($data) => $data->whereNull('data->token_id')->orWhereNotIn('data->token_id', $tokenIds));
                    });
                })->delete();
        }

        return ['notifications_cleared' => $cleared, 'notifications_pruned' => $pruned];
    }

    // Type => [model, data key, scope keeping only records that still need action]. Rows without the key (digests) and
    // types not listed here (outcomes, FYI notes) are left for people to read.
    private function notificationChecks(): array
    {
        $ticketOpen = fn ($query) => $query->whereNotIn('status', ['resolved', 'closed']);
        $refundRequested = fn ($query) => $query->where('status', 'requested');
        $inReview = fn ($query) => $query->where('risk_level', 'review')->whereIn('status', ['pending', 'authorized']);

        return [
            'ticket_assigned' => [Ticket::class, 'ticket_id', $ticketOpen],
            'ticket_customer_replied' => [Ticket::class, 'ticket_id', $ticketOpen],
            'ticket_sla_at_risk' => [Ticket::class, 'ticket_id', $ticketOpen],
            'ticket_sla_breached' => [Ticket::class, 'ticket_id', $ticketOpen],
            'refund_awaiting_approval' => [Refund::class, 'refund_id', $refundRequested],
            'refund_approval_overdue' => [Refund::class, 'refund_id', $refundRequested],
            'risk_review_needed' => [Payment::class, 'payment_id', $inReview],
            'risk_review_overdue' => [Payment::class, 'payment_id', $inReview],
            'cod_risk_hold' => [Payment::class, 'payment_id', fn ($query) => $query->where('risk_level', '!=', 'none')->where('status', 'pending')],
            'cod_amount_mismatch' => [Payment::class, 'payment_id', fn ($query) => $query->where('status', 'pending')],
            'payment_disputed' => [Payment::class, 'payment_id', fn ($query) => $query->where('status', 'disputed')],
            'shipment_failed_attempt' => [Shipment::class, 'shipment_id', fn ($query) => $query->where('status', 'failed_attempt')],
            'subscription_past_due' => [Subscription::class, 'subscription_id', fn ($query) => $query->where('status', 'past_due')],
            'settlement_ready' => [Settlement::class, 'settlement_id', fn ($query) => $query->where('status', 'draft')],
            'webhook_endpoint_disabled' => [WebhookEndpoint::class, 'endpoint_id', fn ($query) => $query->where('is_active', false)->whereNotNull('disabled_at')],
            'integration_token_expiring' => [PersonalAccessToken::class, 'token_id', fn ($query) => $query],
            'sign_in_locked' => [User::class, 'user_id', fn ($query) => $query->whereIn('email', LoginLockout::where('locked_until', '>', now())->select('email'))],
        ];
    }
}
