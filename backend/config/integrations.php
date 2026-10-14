<?php

return [
    'cron_secret' => env('CRON_SECRET'),
    'allow_localhost' => env('SFA_WEBHOOK_ALLOW_LOCALHOST', true),
];
