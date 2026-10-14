<?php

return [
    'timezone' => env('SFA_TIMEZONE', 'Asia/Manila'),
    'login_otp' => env('SFA_LOGIN_OTP', 'off'),
    'commit' => env('SFA_COMMIT', env('VERCEL_GIT_COMMIT_SHA', 'local')),
    'sandbox_gateway' => (bool) env('SFA_SANDBOX_GATEWAY', true),
    'scheduler_enabled' => (bool) env('SFA_SCHEDULER_ENABLED', true),
    // Process simulator (Admin): stands in for the storefront, customers and gateway while no integration is connected.
    'simulator' => (bool) env('SFA_SIMULATOR', env('APP_ENV', 'production') !== 'production'),
    // Where the partner simulator (tools/partner-simulator) listens; it plays the storefront, gateway and courier.
    'partner_url' => env('SFA_PARTNER_URL', 'http://localhost:8787'),
    'settings' => [
        'company_name' => 'Archon Nell',
        'support_email' => 'support@example.com',
        'business_timezone' => env('SFA_TIMEZONE', 'Asia/Manila'),
        'currency' => 'PHP',
        'sla_policies' => [
            'urgent' => ['first_response_hours' => 1, 'resolution_hours' => 8],
            'high' => ['first_response_hours' => 4, 'resolution_hours' => 24],
            'normal' => ['first_response_hours' => 8, 'resolution_hours' => 48],
            'low' => ['first_response_hours' => 24, 'resolution_hours' => 72],
        ],
        'auto_assign' => false,
        'ticket_waiting_auto_resolve_days' => 0,
        'ticket_auto_acknowledge' => false,
        'risk_failed_attempts' => 3,
        'risk_amount_threshold' => 5000000,
        'settlement_schedule' => 'weekly',
        'volumetric_divisor' => 3500,
        'webhook_auto_recover' => true,
        'auto_capture_authorized' => true,
        'finance_reminder_hours' => 4,
        // PAY-6: auto-clear risk reviews of customers with this many captured payments (0 = off), up to this amount.
        'risk_auto_clear_min_captured' => 0,
        'risk_auto_clear_max_amount' => 10000000,
        // PAY-8: staff refund requests up to this amount are approved at once (0 = off), within a per-requester daily
        // total and for these reasons only.
        'refund_auto_approve_cap_amount' => 0,
        'refund_auto_approve_daily_limit' => 200000,
        'refund_auto_approve_reasons' => ['item_not_received', 'damaged', 'wrong_item', 'cancelled_order', 'duplicate_charge'],
        // PAY-2: a payment under risk review expires this many hours after creation instead of 30 minutes (0 = off).
        'risk_review_hold_hours' => 0,
        'settlement_auto_finalize_days' => 0,
        // BIL-12: net a finalized negative settlement from the seller's next generated settlement (off by default).
        'settlement_carry_forward' => false,
        // BIL-7: scheduled work subscribes sellers that never had a subscription to this free plan (null = off).
        'default_plan_id' => null,
        'token_expiry_reminder_days' => 7,
        'notification_retention_days' => 90,
        'logistics_stuck_hours' => 48,
        'logistics_auto_ticket' => true,
        'logistics_courier_fill_gaps' => false,
        // LOG-9: accept the courier's recipient and photo as proof of delivery for prepaid parcels (COD never).
        'logistics_courier_pod' => false,
        // AI assistant (Gemini, AiService): a master switch and one switch per feature, all off until an Admin turns
        // them on; AI only labels and suggests. Tokens per day across all features (prompt + answer + thinking).
        'ai_enabled' => false,
        'ai_ticket_triage' => false,
        'ai_reply_drafts' => false,
        'ai_finance_review' => false,
        'ai_risk_second_opinion' => false,
        'ai_address_fill' => false,
        'ai_delivery_reason' => false,
        'ai_ask_data' => false,
        'ai_daily_token_limit' => 1000000,
        // Compliance rulebooks (App\Services\AiRules): thresholds SFA checks before the AI recommends anything.
        'refund_window_days' => 15,
        'refund_max_per_30_days' => 2,
        'risk_amount_multiple' => 5,
        'risk_new_customer_days' => 7,
    ],
];
