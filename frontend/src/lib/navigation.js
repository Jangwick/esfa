// Sidebar modules (order and icons from SPECIFICATION §1.2). `sections` are the tabs inside a module page: the sidebar
// lists them under the module, the page renders them as its tabs (moduleTabs), and each one opens directly at
// `${path}?tab=${tab}`. The first section is the page's default tab. `hint` tells the employee what the section is for
// and what to do next; `permission` / `visible` hide a section the signed-in user cannot open.
const financeAccess = ({ roles }) => !roles.includes('Administrative Assistant') || roles.some((role) => ['Admin', 'Accounting Staff'].includes(role))

export const navigation = [
  { name: 'Dashboard', path: '/dashboard', icon: 'fa-border-all', permission: 'view dashboard' },
  { name: 'Customer Support', path: '/support', icon: 'fa-headset', permission: 'view support', sections: [
    { name: 'Tickets', tab: 'tickets', hint: 'Customer requests. Open a ticket to reply, assign it or change its status. The SLA column shows which ones are due first.' },
    { name: 'Knowledge base', tab: 'articles', permission: 'view knowledge base', hint: 'Help articles agents can reuse. Save as draft while writing, then publish when it is ready.' },
    { name: 'Canned responses', tab: 'canned', permission: 'view canned responses', hint: 'Saved replies you can insert from the reply box of any ticket.' },
    { name: 'SLA policies', tab: 'policies', permission: 'view sla policies', hint: 'Response and resolution targets for each priority. Tickets use them to show On track, At risk or Breached.' },
  ] },
  { name: 'Payments & Transactions', path: '/payments', icon: 'fa-credit-card', permission: 'view payments', sections: [
    { name: 'Payments', tab: 'payments', hint: 'Customer and subscription payments. Open a reference to capture, cancel or print a receipt.' },
    { name: 'Refunds', tab: 'refunds', hint: 'Refund requests. An Admin can approve or reject any request, including their own. Other approvers must review a request made by someone else. Approved refunds are sent to the payment gateway. Tick several requests to approve them together; any the gateway declines are listed.' },
    { name: 'Ledger', tab: 'ledger', visible: financeAccess, hint: 'Read-only, balanced record of every money movement. Nothing to action here.' },
    { name: 'Reconciliation', tab: 'reconciliations', visible: financeAccess, hint: 'Import the gateway settlement file, then open it and resolve every unmatched row.' },
    { name: 'Risk review', tab: 'risk-reviews', visible: (auth) => financeAccess(auth) && auth.hasPermission('edit payments'), hint: 'Open risk reviews. Clear and capture lets a payment through and captures it (COD once its parcels are delivered); Block and cancel stops it at the gateway.' },
  ] },
  { name: 'Logistics & Locations', path: '/logistics', icon: 'fa-truck-fast', permission: 'view logistics', sections: [
    { name: 'Shipments', tab: 'shipments', hint: 'Deliveries. Open a shipment to follow its timeline and record the next status update.' },
    { name: 'Awaiting booking', tab: 'awaiting-booking', permission: 'create shipments', hint: 'Paid orders, and cash-on-delivery orders not held for review, whose seller has no shipment yet. Book opens the shipment form with the suggested origin, COD amount and last delivery address; add the weight and dimensions.' },
    { name: 'Couriers & rates', tab: 'routing', visible: ({ hasPermission }) => hasPermission('view couriers') || hasPermission('view rates'), hint: 'Couriers you ship with and the rates used for shipping quotes.' },
    { name: 'Zones', tab: 'zones', permission: 'view zones', hint: 'Groups of provinces and cities that share courier rates.' },
    { name: 'Warehouses', tab: 'warehouses', permission: 'view warehouses', hint: 'Hubs and pickup points that shipments leave from.' },
    { name: 'Locations', tab: 'locations', permission: 'view locations', hint: 'Official Philippine regions, provinces, cities and barangays (PSGC) used in every address.' },
  ] },
  { name: 'Subscriptions & Commissions', path: '/subscriptions', icon: 'fa-file-invoice-dollar', permission: 'view subscriptions', sections: [
    { name: 'Subscriptions', tab: 'subscriptions', hint: 'Seller plans. Open a subscription to change its plan, cancel it or resume it.' },
    { name: 'Plans', tab: 'plans', hint: 'The plans sellers can subscribe to, with monthly and annual prices.' },
    { name: 'Invoices', tab: 'invoices', hint: 'Subscription invoices. Open an open invoice to charge or void it; paid invoices can be printed.' },
    { name: 'Commission rules', tab: 'commission-rules', hint: 'Commission rates by seller, plan or category. Use Commission entries to see what each order earned.' },
    { name: 'Settlements & payouts', tab: 'settlements', hint: 'Seller statements. Finalize a draft settlement, then record its payout.' },
  ] },
  { name: 'Analytics & Reports', path: '/analytics', icon: 'fa-chart-pie', permission: 'view analytics' },
  { name: 'Users & Roles', path: '/users', icon: 'fa-user-shield', permission: 'view users', sections: [
    { name: 'Users', tab: 'users', hint: 'Staff accounts. Add a user and give them a role; deactivate accounts instead of deleting them.' },
    { name: 'Roles & permissions', tab: 'roles', hint: 'What each role can see and do.' },
  ] },
  { name: 'Audit Logs', path: '/audit-logs', icon: 'fa-shield-halved', permission: 'view audit logs' },
  // Demo tool, not a module: Admins only, and only on servers where the simulator is switched on.
  { name: 'Process Simulator', path: '/simulator', icon: 'fa-flask', role: 'Admin', simulator: true },
]

// The sidebar entries this user may open.
export function visibleNavigation({ user, hasPermission, hasRole }) {
  return navigation.filter((item) => item.simulator ? Boolean(user?.simulator_enabled) && hasRole(item.role) : hasPermission(item.permission))
}

// The sections of one module that this user may open, shaped for <Tabs>. Without auth, gated sections stay hidden.
export function moduleTabs(path, auth = {}) {
  const access = { hasPermission: auth.hasPermission || (() => false), roles: auth.roles || [] }
  return (navigation.find((item) => item.path === path)?.sections || [])
    .filter((section) => (!section.permission || access.hasPermission(section.permission)) && (!section.visible || section.visible(access)))
    .map(({ tab, name, hint }) => ({ key: tab, label: name, hint }))
}
