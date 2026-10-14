<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $modules = ['dashboard', 'support', 'payments', 'logistics', 'subscriptions', 'analytics', 'users', 'integrations', 'tickets', 'knowledge base', 'canned responses', 'sla policies', 'refunds', 'ledger', 'reconciliation', 'risk review', 'shipments', 'couriers', 'zones', 'rates', 'warehouses', 'locations', 'plans', 'invoices', 'commission rules', 'commissions', 'settlements', 'payouts', 'reports'];
        foreach ($modules as $module) {
            foreach (['view', 'create', 'edit', 'delete'] as $action) { Permission::findOrCreate($action.' '.$module, 'web'); }
        }
        foreach (['approve refunds', 'finalize settlements', 'pay settlements', 'manage integrations', 'view audit logs', 'manage settings'] as $permission) { Permission::findOrCreate($permission, 'web'); }
        $crud = fn (array $items) => collect($items)->flatMap(fn ($module) => array_map(fn ($action) => $action.' '.$module, ['view', 'create', 'edit', 'delete']))->all();
        $matrix = [
            'Admin' => Permission::pluck('name')->all(),
            'Finance' => array_merge($crud(['payments', 'refunds', 'ledger', 'reconciliation', 'risk review', 'subscriptions', 'plans', 'invoices', 'commission rules', 'commissions', 'settlements', 'payouts']), ['view dashboard', 'view support', 'view tickets', 'view logistics', 'view shipments', 'approve refunds', 'finalize settlements', 'pay settlements']),
            'Support Agent' => array_merge($crud(['support', 'tickets', 'knowledge base', 'canned responses']), ['view dashboard', 'view payments', 'view refunds', 'create refunds', 'view logistics', 'view shipments', 'view sla policies']),
            'Logistics Coordinator' => array_merge($crud(['logistics', 'shipments', 'couriers', 'zones', 'rates', 'warehouses', 'locations']), ['view dashboard', 'view support', 'view tickets']),
            'Analyst' => ['view dashboard', 'view analytics', 'view reports'],
        ];
        foreach ($matrix as $name => $permissions) { Role::findOrCreate($name, 'web')->syncPermissions(array_values(array_unique($permissions))); }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
