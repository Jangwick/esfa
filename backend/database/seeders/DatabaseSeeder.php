<?php

namespace Database\Seeders;

use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);
        foreach (config('sfa.settings') as $key => $value) { Setting::firstOrCreate(['key' => $key], ['value' => $value]); }
        if (app()->environment('production')) { return; }
        foreach ([
            ['Admin Demo', 'admin@example.com', 'Admin'],
            ['Finance Demo', 'finance@example.com', 'Finance'],
            ['Support One Demo', 'support.one@example.com', 'Support Agent'],
            ['Support Two Demo', 'support.two@example.com', 'Support Agent'],
            ['Logistics Demo', 'logistics@example.com', 'Logistics Coordinator'],
            ['Analyst Demo', 'analyst@example.com', 'Analyst'],
        ] as [$name, $email, $role]) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make('password'), 'is_active' => true]);
            $user->syncRoles([$role]);
            Notification::firstOrCreate(['user_id' => $user->id, 'type' => 'foundation_ready'], ['title' => 'Welcome to Archon Nell', 'message' => 'This is a demonstration account for Support, Finance & Administration.', 'data' => ['demo' => true]]);
        }
        // Feature tests seed only the foundation above; every other non-production environment gets the full demo dataset.
        if (! app()->environment('testing')) {
            $this->call(self::DEMO);
        }
    }

    /** Domain demo seeders in dependency order (rules before captures, orders before shipments and tickets). */
    public const DEMO = [BillingDemoSeeder::class, PaymentDemoSeeder::class, LogisticsDemoSeeder::class, SupportDemoSeeder::class, BillingHistoryDemoSeeder::class, IntegrationDemoSeeder::class];
}
