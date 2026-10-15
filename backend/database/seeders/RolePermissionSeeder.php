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
        $view = fn (array $items) => array_map(fn ($module) => 'view '.$module, $items);
        // The org chart's roles; retire the names the old demo used so a reseed leaves only this set. Permissions
        // are a starting point: adjust any role under Users & Roles.
        $matrix = [
            'Admin' => Permission::pluck('name')->all(),
            'General Manager' => array_merge(Permission::where('name', 'like', 'view %')->pluck('name')->all(), ['view audit logs', 'approve refunds', 'finalize settlements', 'pay settlements']),
            'Operations Manager' => array_merge($crud(['support', 'tickets', 'knowledge base', 'canned responses', 'sla policies', 'logistics', 'shipments', 'couriers', 'zones', 'rates', 'warehouses', 'locations', 'subscriptions', 'plans', 'invoices', 'commission rules', 'commissions', 'settlements', 'payouts']), $view(['dashboard', 'payments', 'refunds', 'ledger', 'reconciliation', 'risk review', 'analytics', 'reports'])),
            'Production Supervisor' => $view(['dashboard', 'support', 'tickets', 'logistics', 'shipments', 'warehouses', 'analytics']),
            'Production Operator' => $view(['dashboard', 'logistics', 'shipments']),
            'Chemical Mixer' => $view(['dashboard']),
            'Quality Control Analyst' => $view(['dashboard', 'analytics', 'reports']),
            'Laboratory Technician' => $view(['dashboard']),
            'Safety Officer' => array_merge($view(['dashboard', 'support', 'tickets']), ['create tickets']),
            'Maintenance Technician' => $view(['dashboard', 'logistics', 'shipments', 'warehouses']),
            'Warehouseman' => array_merge($crud(['logistics', 'shipments', 'couriers', 'zones', 'rates', 'warehouses', 'locations']), $view(['dashboard', 'support', 'tickets'])),
            'Inventory Controller' => array_merge($crud(['warehouses', 'zones', 'locations']), $view(['dashboard', 'logistics', 'shipments'])),
            'Purchasing Officer' => $view(['dashboard', 'subscriptions', 'invoices', 'settlements']),
            'Sales Representative' => array_merge($view(['dashboard', 'support', 'tickets', 'logistics', 'shipments', 'subscriptions']), ['create tickets']),
            'Accounting Staff' => array_merge($crud(['payments', 'refunds', 'ledger', 'reconciliation', 'risk review', 'subscriptions', 'plans', 'invoices', 'commission rules', 'commissions', 'settlements', 'payouts']), $view(['dashboard', 'support', 'tickets', 'logistics', 'shipments']), ['approve refunds', 'finalize settlements', 'pay settlements']),
            'HR Staff' => array_merge($crud(['users']), $view(['dashboard'])),
            'Administrative Assistant' => array_merge($crud(['support', 'tickets', 'knowledge base', 'canned responses']), $view(['dashboard', 'payments', 'refunds', 'logistics', 'shipments', 'sla policies']), ['create refunds']),
            'Delivery Driver' => $view(['dashboard', 'logistics', 'shipments']),
            'Utility Worker' => $view(['dashboard']),
        ];
        foreach ($matrix as $name => $permissions) { Role::findOrCreate($name, 'web')->syncPermissions(array_values(array_unique($permissions))); }
        Role::whereIn('name', ['Finance', 'Support Agent', 'Logistics Coordinator', 'Analyst'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
