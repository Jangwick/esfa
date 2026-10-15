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
            ['Alexandra Reyes', 'admin@example.com', 'Admin'],
            ['Bernadette Chua', 'finance@example.com', 'Accounting Staff'],
            ['Camille Ong', 'support.one@example.com', 'Administrative Assistant'],
            ['Dominic Sy', 'support.two@example.com', 'Administrative Assistant'],
            ['Edgar Manalo', 'logistics@example.com', 'Warehouseman'],
            ['Francesca Lim', 'analyst@example.com', 'Quality Control Analyst'],
            ['Gabriel Tan', 'gm@example.com', 'General Manager'],
            ['Hector Villar', 'ops@example.com', 'Operations Manager'],
            ['Isabella Cruz', 'production.supervisor@example.com', 'Production Supervisor'],
            ['Jerome Padilla', 'operator@example.com', 'Production Operator'],
            ['Katrina Sison', 'mixer@example.com', 'Chemical Mixer'],
            ['Leonardo Uy', 'lab@example.com', 'Laboratory Technician'],
            ['Melissa Go', 'safety@example.com', 'Safety Officer'],
            ['Nathaniel Garcia', 'maintenance@example.com', 'Maintenance Technician'],
            ['Olivia Fernandez', 'inventory@example.com', 'Inventory Controller'],
            ['Patrick Rivera', 'purchasing@example.com', 'Purchasing Officer'],
            ['Regina Mercado', 'sales@example.com', 'Sales Representative'],
            ['Stephanie Yu', 'hr@example.com', 'HR Staff'],
            ['Terence Aquino', 'driver@example.com', 'Delivery Driver'],
            ['Vanessa Torres', 'utility@example.com', 'Utility Worker'],
        ] as [$name, $email, $role]) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make('password'), 'is_active' => true]);
            $user->syncRoles([$role]);
            Notification::firstOrCreate(['user_id' => $user->id, 'type' => 'foundation_ready'], ['title' => 'Welcome to Archon Nell', 'message' => 'This is a demonstration account for Support, Finance & Administration.', 'data' => ['demo' => true]]);
        }
        // Feature tests seed only the foundation above; every other non-production environment gets the full demo dataset.
        if (! app()->environment('testing')) {
            // Demo builds show every AI control; tests opt in per case and production keeps the defaults above.
            foreach (array_merge(['ai_enabled'], array_map(fn ($feature) => 'ai_'.$feature, \App\Services\AiService::FEATURES)) as $key) { Setting::updateOrCreate(['key' => $key], ['value' => '1']); }
            $this->call(self::DEMO);
        }
    }

    /** Domain demo seeders in dependency order (rules before captures, orders before shipments and tickets). */
    public const DEMO = [BillingDemoSeeder::class, PaymentDemoSeeder::class, LogisticsDemoSeeder::class, SupportDemoSeeder::class, BillingHistoryDemoSeeder::class, IntegrationDemoSeeder::class];
}
