<?php
$currentPath = trim(uri_string(), '/');
$user = haven_auth()->user();
$cleanerNavigation = $user !== null && $user->inGroup('cleaner') && ! array_diff(auth()->user()->roles(), ['cleaner', 'user']);
$taskUnread = $user && ($user->can('tasks.view') || $user->can('tasks.owner')) ? \Illuminate\Support\Facades\DB::table('task_notices')->where('tenant_id',app('currentTenant')->id)->where('user_id',auth()->id())->whereNull('read_at')->count() : 0;

// Ordered by the working day: win the enquiry, take the booking, run the stay, talk to guests, then manage
// properties, the website, money and reporting. "keywords" keep older page names findable in the menu search.
$groups = [
    ['label' => 'Sales & enquiries', 'icon' => 'contact', 'items' => [
        ['label' => 'Sales pipeline', 'path' => 'sales', 'permission' => 'sales.view', 'icon' => 'contact', 'keywords' => 'enquiries leads'],
        ['label' => 'Quotations', 'path' => 'quotations', 'permission' => 'reservations.view', 'icon' => 'file-text', 'keywords' => 'quotes'],
        ['label' => 'Referral agents', 'path' => 'sales/agents', 'permission' => 'agents.view', 'icon' => 'users', 'keywords' => 'agents commission'],
    ]],
    ['label' => 'Bookings & guests', 'icon' => 'calendar-days', 'items' => [
        ['label' => 'Bookings', 'path' => 'reservations', 'permission' => 'reservations.view', 'icon' => 'calendar', 'keywords' => 'reservations stays'],
        ['section' => 'Availability', 'label' => 'Availability calendar', 'path' => 'availability', 'permission' => 'availability.view', 'icon' => 'calendar-days', 'keywords' => 'availability'],
        ['section' => 'Availability', 'label' => 'Apartment closures', 'path' => 'availability/closures', 'permission' => 'properties.manage', 'icon' => 'calendar-off', 'keywords' => 'block dates owner use repairs'],
        ['section' => 'Availability', 'label' => 'Partner apartments', 'path' => 'partners', 'permission' => 'availability.view', 'icon' => 'handshake', 'keywords' => 'partners owners agents units whatsapp'],
        ['section' => 'Availability', 'label' => 'External calendars', 'path' => 'availability/external', 'permission' => 'availability.view', 'icon' => 'calendar-sync', 'keywords' => 'ical airbnb booking.com sync'],
        ['section' => 'Guests', 'label' => 'Guest directory', 'path' => 'customers', 'permission' => 'finance.view', 'icon' => 'contact', 'keywords' => 'customers'],
        ['section' => 'Guests', 'label' => 'Guest verification', 'path' => 'staff/kyc', 'permission' => 'finance.view', 'icon' => 'id-card', 'keywords' => 'guest kyc identification'],
        ['section' => 'Guests', 'label' => 'Send guest KYC link', 'path' => 'staff/kyc/invite', 'permission' => 'finance.manage', 'icon' => 'mail', 'keywords' => 'kyc invite'],
    ]],
    ['label' => 'Operations', 'icon' => 'clipboard-list', 'items' => [
        ['label' => 'Housekeeping', 'path' => 'housekeeping', 'permission' => 'housekeeping.view', 'icon' => 'clipboard-check', 'keywords' => 'cleaning'],
        ['label' => 'Record cleaning', 'path' => 'housekeeping/my-cleanings/new', 'access' => 'cleaner_module', 'icon' => 'plus-circle', 'keywords' => 'cleaning'],
        ['label' => 'Maintenance', 'path' => 'maintenance', 'permission' => 'maintenance.view', 'icon' => 'wrench', 'keywords' => 'repairs issues'],
        ['label' => 'My bills & reimbursements', 'path' => 'staff-bills', 'permission' => 'bills.submit', 'icon' => 'receipt', 'keywords' => 'my bills reimbursements'],
        ['label' => 'Cleaner earnings', 'path' => 'payments/cleaners/earnings', 'access' => 'cleaner_module', 'icon' => 'wallet', 'keywords' => 'cleaner earnings'],
    ]],
    ['label' => 'Messages', 'icon' => 'message-circle', 'items' => [
        ['label' => 'Communications due today', 'path' => 'communications/due', 'permission' => 'messaging.view', 'icon' => 'calendar-clock', 'keywords' => 'communications due'],
        ['label' => 'Guest messages', 'path' => 'communications/guests', 'permission' => 'messaging.manage', 'icon' => 'message-circle', 'keywords' => 'guest messages'],
        ['label' => 'SMS messages & history', 'path' => 'communications/sms', 'permission' => 'messaging.view', 'icon' => 'mail', 'keywords' => 'compose sms'],
        ['label' => 'Message templates', 'path' => 'communications/templates', 'permission' => 'messaging.view', 'icon' => 'file-text', 'keywords' => 'templates'],
        ['label' => 'Channel connections', 'path' => 'sales/connections', 'permission' => 'messaging.view', 'icon' => 'message-circle', 'keywords' => 'messaging connections whatsapp instagram facebook'],
        ['label' => 'Email & SMS notifications', 'path' => 'investor-notifications', 'permission' => 'notifications.view', 'icon' => 'mail', 'keywords' => 'system notifications'],
    ]],
    ['label' => 'Properties & owners', 'icon' => 'building-2', 'items' => [
        ['label' => 'Apartments', 'path' => 'units', 'permission' => 'units.view', 'icon' => 'house', 'keywords' => 'apartment units'],
        ['label' => 'Owners & investors', 'path' => 'investors', 'permission' => 'investors.view', 'icon' => 'users', 'keywords' => 'investor directory'],
        ['label' => 'Management agreements', 'path' => 'host-agreements', 'permission' => 'finance.view', 'icon' => 'handshake', 'keywords' => 'host agreements'],
        ['label' => 'My investor portal', 'path' => 'investor-portal', 'permission' => 'investor.portal', 'icon' => 'house', 'keywords' => 'investor portal'],
        ['label' => 'Investor portal reports', 'path' => 'investor-portal/reports', 'permission' => 'investor.portal', 'icon' => 'file-text', 'keywords' => 'investor reports'],
    ]],
    ['label' => 'Website & listings', 'icon' => 'globe', 'items' => [
        ['label' => 'Manage listings', 'path' => 'public-listings', 'permission' => 'properties.manage', 'icon' => 'building-2', 'keywords' => 'listings create listing'],
        ['label' => 'Website settings', 'path' => 'public-listings/settings', 'permission' => 'properties.manage', 'icon' => 'settings', 'keywords' => 'website seo whatsapp logo menu'],
        ['label' => 'Public website', 'path' => 'public-listings/public-website', 'access_path' => 'public-listings', 'public_site' => true, 'permission' => 'properties.manage', 'icon' => 'external-link', 'keywords' => 'public website stays'],
    ]],
    ['label' => 'Finance', 'icon' => 'wallet', 'items' => [
        ['section' => 'Money in', 'label' => 'Invoices & receipts', 'path' => 'invoices', 'permission' => 'invoices.view', 'icon' => 'file-text', 'keywords' => 'invoices receipts'],
        ['section' => 'Money in', 'label' => 'Guest payments', 'path' => 'payments/guests', 'permission' => 'finance.view', 'icon' => 'wallet', 'keywords' => 'guest payments'],
        ['section' => 'Money out', 'label' => 'Owner & partner payouts', 'path' => 'owner-remittances', 'permission' => 'finance.view', 'icon' => 'hand-coins', 'keywords' => 'owner remittances payouts'],
        ['section' => 'Money out', 'label' => 'Partner owner payments', 'path' => 'payments/partners', 'permission' => 'finance.view', 'icon' => 'hand-coins', 'keywords' => 'partner owner payments', 'secondary' => true],
        ['section' => 'Money out', 'label' => 'Partner owner balances', 'path' => 'payments/partners/owners', 'permission' => 'finance.view', 'icon' => 'file-text', 'keywords' => 'partner owner balances', 'secondary' => true],
        ['section' => 'Money out', 'label' => 'Host payments', 'path' => 'payments/hosts', 'permission' => 'finance.view', 'icon' => 'wallet', 'keywords' => 'host payments', 'secondary' => true],
        ['section' => 'Money out', 'label' => 'Cleaner payments', 'path' => 'payments/cleaners', 'permission' => 'finance.view', 'icon' => 'wallet-cards', 'keywords' => 'cleaner payments'],
        ['section' => 'Money out', 'label' => 'Cleaner balances', 'path' => 'payments/cleaners/balances', 'permission' => 'finance.view', 'icon' => 'wallet', 'keywords' => 'cleaner balances', 'secondary' => true],
        ['section' => 'Money out', 'label' => 'Cleaner statements', 'path' => 'payments/cleaners/statements', 'permission' => 'finance.view', 'icon' => 'file-text', 'keywords' => 'cleaner statements', 'secondary' => true],
        ['section' => 'Money out', 'label' => 'Bill approvals', 'path' => 'staff-bills', 'permission' => 'finance.manage', 'icon' => 'receipt', 'keywords' => 'bill approvals reimbursements'],
        ['section' => 'Money out', 'label' => 'Expenses', 'path' => 'expenses', 'permission' => 'finance.view', 'icon' => 'receipt', 'keywords' => 'expense register'],
        ['section' => 'Accounting', 'label' => 'Apartment accounting & rent', 'path' => 'accounting/units', 'permission' => 'finance.view', 'icon' => 'receipt', 'keywords' => 'unit accounting rent'],
        ['section' => 'Accounting', 'label' => 'Close accounting month', 'path' => 'owner-statement-closing', 'permission' => 'finance.manage', 'icon' => 'clipboard-check', 'keywords' => 'owner statement closing month end'],
    ]],
    ['label' => 'Reports', 'icon' => 'chart-column', 'items' => [
        ['label' => 'Investor reports', 'path' => 'reports/investor', 'permission' => 'finance.view', 'icon' => 'file-chart-column-increasing', 'keywords' => 'investor reports'],
        ['label' => 'Monthly company income', 'path' => 'reports/monthly-company-income', 'permission' => 'finance.view', 'icon' => 'chart-no-axes-combined', 'keywords' => 'company income'],
        ['label' => 'Monthly host commissions', 'path' => 'reports/monthly-host-commissions', 'permission' => 'finance.view', 'icon' => 'hand-coins', 'keywords' => 'host commissions'],
        ['label' => 'Monthly apartment expenses', 'path' => 'reports/monthly-unit-expenses', 'permission' => 'finance.view', 'icon' => 'calendar-range', 'keywords' => 'monthly unit expenses'],
        ['label' => 'Housekeeping financial report', 'path' => 'reports/housekeeping', 'permission' => 'finance.view', 'icon' => 'chart-column', 'keywords' => 'housekeeping report cleaning'],
    ]],
    ['label' => 'Team', 'icon' => 'users', 'items' => [
        ['label' => 'Staff management', 'path' => 'staff', 'permission' => 'staff.view', 'icon' => 'users', 'keywords' => 'staff'],
        ['label' => 'Staff profiles & identification', 'path' => 'staff-profiles', 'permission' => 'staff-profile.review', 'icon' => 'id-card', 'keywords' => 'staff profiles identification'],
        ['label' => 'Team & access', 'path' => 'company/team', 'access' => 'company_admin', 'icon' => 'shield', 'keywords' => 'team access invitations roles'],
    ]],
    ['label' => 'Settings', 'icon' => 'settings', 'items' => [
        ['label' => 'Company settings', 'path' => 'company/settings', 'access' => 'company_admin', 'icon' => 'settings', 'keywords' => 'company logo reference prefix'],
        ['label' => 'Invoice settings', 'path' => 'invoices/settings', 'access' => 'company_admin', 'icon' => 'file-text', 'keywords' => 'invoice settings'],
        ['label' => 'Cleaning rates', 'path' => 'settings/cleaning-rates', 'permission' => 'settings.manage', 'icon' => 'wallet', 'keywords' => 'cleaning rates'],
        ['label' => 'Subscription', 'path' => 'company/subscription', 'access' => 'company_admin', 'icon' => 'wallet-cards', 'keywords' => 'plan billing subscription'],
        ['label' => 'Setup checklist', 'path' => 'company/setup', 'access' => 'company_admin', 'icon' => 'clipboard-check', 'keywords' => 'setup onboarding'],
    ]],
];
foreach ($groups as &$group) {
    foreach ($group['items'] as &$item) {
        $item['keywords'] = trim($item['label'].' '.($item['keywords'] ?? ''));
        $item['secondary'] = (bool) ($item['secondary'] ?? false);
    }
    unset($item);
}
unset($group);
$investorNavigation = $user !== null && $user->can('investor.portal') && ! $user->can('investors.view')
    && \Illuminate\Support\Facades\DB::table('investors')->where('tenant_id', app('currentTenant')->id)->where('user_id', auth()->id())->where('active', true)->exists();
if ($investorNavigation) {
    $groups = [['label' => 'Investor workspace', 'icon' => 'house', 'items' => [
        ['label' => 'Dashboard', 'path' => 'investor-portal', 'permission' => 'investor.portal', 'icon' => 'house'],
        ['label' => 'Properties', 'path' => 'investor-portal/properties', 'permission' => 'investor.portal', 'icon' => 'building-2'],
        ['label' => 'Property tasks'.($taskUnread ? ' ('.$taskUnread.')' : ''), 'path' => 'tasks', 'permission' => 'tasks.owner', 'icon' => 'clipboard-list'],
        ['label' => 'Bookings', 'path' => 'investor-portal/bookings', 'permission' => 'investor.portal', 'icon' => 'calendar-days'],
        ['label' => 'Reports', 'path' => 'investor-portal/reports', 'permission' => 'investor.portal', 'icon' => 'file-text'],
        ['label' => 'Statements & Payments', 'path' => 'investor-portal/money', 'permission' => 'investor.portal', 'icon' => 'file-text'],
        ['label' => 'Agreements', 'path' => 'investor-portal/agreements', 'permission' => 'investor.portal', 'icon' => 'handshake'],
        ['label' => 'Settings', 'path' => 'investor-portal/settings', 'permission' => 'investor.portal', 'icon' => 'settings'],
    ]]];
}

if ($cleanerNavigation) {
    $groups = [['label' => 'My work', 'icon' => 'clipboard-list', 'items' => [
        ['label' => 'My cleanings', 'path' => 'housekeeping', 'icon' => 'clipboard-check', 'permission' => 'housekeeping.view'],
        ['label' => 'Record cleaning', 'path' => 'housekeeping/my-cleanings/new', 'icon' => 'plus-circle', 'access' => 'cleaner_module'],
        ['label' => 'Maintenance issues', 'path' => 'maintenance', 'icon' => 'wrench', 'permission' => 'maintenance.view'],
        ['label' => 'Report maintenance', 'path' => 'maintenance/report', 'icon' => 'plus-circle', 'permission' => 'maintenance.report'],
        ['label' => 'My bills & reimbursements', 'path' => 'staff-bills', 'icon' => 'receipt', 'permission' => 'bills.submit'],
        ['label' => 'My earnings', 'path' => 'payments/cleaners/earnings', 'icon' => 'wallet', 'access' => 'cleaner_module'],
    ]]];
}

$canAccess = static function (array $item) use ($user): bool {
    if ($user === null) return false;
    if (($item['access'] ?? '') === 'daily_work') { return $user->can('units.view') && $user->can('reservations.view'); }
    if (($item['access'] ?? '') === 'company_admin') {
        return $user->inGroup('admin', 'superadmin');
    }
    if (($item['access'] ?? '') === 'cleaner_module') {
        // Navigation visibility only. Controllers and routes still enforce access.
        return $user->inGroup('cleaner')
            || $user->inGroup('admin')
            || $user->inGroup('superadmin')
            || $user->can('housekeeping.manage');
    }
    return $user->can($item['permission']);
};
$isVisible = static fn (array $item): bool => $canAccess($item) && haven_can_access(site_url($item['access_path'] ?? $item['path']), 'GET');
$publicWebsiteEnabled = $user !== null && $user->can('properties.manage')
    && \Illuminate\Support\Facades\Schema::hasTable('public_sites')
    && (bool) \Illuminate\Support\Facades\DB::table('public_sites')->where('tenant_id', app('currentTenant')->id)->value('enabled');
$activePath = null;
foreach ($groups as $group) {
    foreach ($group['items'] as $item) {
        if (!empty($item['public_site'])) continue;
        $path = $item['path'];
        if ($canAccess($item) && haven_can_access(site_url($path), 'GET') && ($currentPath === $path || str_starts_with($currentPath, $path.'/')) && strlen($path) > strlen($activePath ?? '')) $activePath = $path;
    }
}
$activePath = match ($activePath) {
    'payments/partners', 'payments/partners/owners', 'payments/hosts' => 'owner-remittances',
    'payments/cleaners/balances', 'payments/cleaners/statements' => 'payments/cleaners',
    default => $activePath,
};
if (str_starts_with($currentPath, 'receipts/')) $activePath = 'invoices';
if ($currentPath === 'dashboard') $activePath = 'dashboard';
if ($currentPath === 'cleaner/dashboard') $activePath = 'cleaner/dashboard';
if (str_starts_with($currentPath,'work')) $activePath = 'work';
if ($currentPath === 'my-profile' || str_starts_with($currentPath, 'my-profile/')) $activePath = 'my-profile';
$isActive = static fn (string $path): bool => $activePath === $path;
?>
<style>
.hs-nav-group { margin: 3px 9px; }
.hs-nav-group > summary { list-style: none; cursor: pointer; display: flex; align-items: center; gap: 11px; padding: 11px 13px; border-radius: 9px; color: inherit; font-weight: 600; }
.hs-nav-group > summary::-webkit-details-marker { display: none; }
.hs-nav-group > summary:hover, .hs-nav-group[open] > summary { background: rgba(255,255,255,.09); }
.hs-nav-group > summary svg { width: 19px; height: 19px; flex-shrink: 0; }
.hs-nav-group > summary .hs-group-chevron { margin-left: auto; width: 15px; height: 15px; transition: transform .2s; }
.hs-nav-group[open] > summary .hs-group-chevron { transform: rotate(90deg); }
.hs-subnav { margin: 3px 0 8px 18px; padding-left: 9px; border-left: 1px solid rgba(255,255,255,.18); }
.hs-subnav .hs-nav-link { padding: 9px 10px; font-size: 13px; gap: 10px; }
.hs-subnav .hs-nav-link svg { width: 16px; height: 16px; }
.hs-sidebar .hs-nav-group .hs-nav-link { display: flex; align-items: center; }
.hs-nav-group > summary::after { content: '\203A'; margin-left:auto; font-size:24px; line-height:1; transition:transform .15s; }
.hs-nav-group[open] > summary::after { transform:rotate(90deg); }
.hs-group-chevron { display:none; }
.hs-subnav-heading{margin:10px 0 2px 10px;font-size:10.5px;font-weight:700;letter-spacing:.09em;text-transform:uppercase;opacity:.6}.hs-account-links{margin:0 0 10px}.hs-account-links .hs-nav-link{padding:7px 0;min-height:36px;font-size:13px}.hs-sidebar [hidden]{display:none!important}.hs-sidebar .hs-nav-link,.hs-sidebar summary{min-height:44px}.hs-sidebar #menu-search-status:empty{display:none}.hs-sidebar :is(a,button,summary):focus-visible { outline:3px solid #ffbd8b; outline-offset:2px; }
@media print { .hs-sidebar { display: none !important; } }
</style>
<aside id="company-navigation" class="hs-sidebar haven-sidebar-scroll" aria-label="Company navigation">
    <button type="button" class="btn btn-outline-light hs-menu-toggle hs-menu-close m-3">Close menu</button>
    <div class="hs-brand">
        <div class="hs-brand-wordmark" aria-label="Haven Suites, BNBs and STR Homes">
            @if($investorNavigation)
            <span class="hs-brand-name">{{ app('currentTenant')->name }}</span>
            <span class="hs-brand-tagline">INVESTOR PORTAL</span>
            @else
            <span class="hs-brand-name">HAVEN <span>SUITES</span></span>
            <span class="hs-brand-tagline">BNBs AND STR HOMES</span>
            @endif
        </div>
    </div>
    <div class="px-3 mb-3"><div class="py-2 fw-semibold">{{ app('currentTenant')->name }}</div>
    @if(auth()->user()->is_platform_admin)
    <a class="hs-nav-link" href="{{ route('platform.index') }}">Platform administration</a>
    @elseif(auth()->user()->hasMultipleCompanies())
    <a class="hs-nav-link" href="{{ url('/') }}">Switch company</a>
    @endif
    <label for="menu-search" class="form-label mt-2">Find a page</label><input id="menu-search" type="search" class="form-control" placeholder="{{ $cleanerNavigation ? 'Cleaning, maintenance, bills' : 'Booking, rent, receipt' }}" autocomplete="off" aria-controls="sidebar-links"><p id="menu-search-status" class="small mt-2" role="status" aria-live="polite"></p></div><div class="hs-menu-heading">MAIN MENU</div>
    @if(!$investorNavigation)
    @php($createActions = ['sales/new' => 'New enquiry', 'reservations/create' => 'New booking', 'quotations/create' => 'New quotation', 'invoices/create' => 'New invoice', 'communications/sms' => 'Send SMS', 'public-listings/new' => 'New listing', 'partners/new' => 'New partner apartment'])
    @php($createActions = array_filter($createActions, static fn (string $path): bool => haven_can_access(site_url($path), 'GET'), ARRAY_FILTER_USE_KEY))
    @if($createActions)
    <details class="mx-3 mb-3"><summary class="p-2">+ Create</summary>
        @foreach($createActions as $path => $label)<a class="hs-nav-link" href="{{ site_url($path) }}">{{ $label }}</a>@endforeach
    </details>
    @endif
    @endif
    <nav id="sidebar-links" class="hs-nav" aria-label="Main navigation">
        @if(!$investorNavigation && $user->can('cleaner.dashboard'))<a data-menu-item data-keywords="my dashboard summary today overview" class="hs-nav-link {{ $isActive('cleaner/dashboard') ? 'active' : '' }}" href="{{ route('cleaner.dashboard', app('currentTenant')->slug) }}" @if($isActive('cleaner/dashboard')) aria-current="page" @endif><i data-lucide="layout-dashboard"></i><span>My dashboard</span></a>@endif

        <?php if (!$investorNavigation && $user !== null && $user->can('dashboard.view')): ?>
            <?php if (haven_can_access(site_url('dashboard'), 'GET')): ?><a href="<?= site_url('dashboard') ?>" data-menu-item data-keywords="home overview today" class="hs-nav-link <?= $isActive('dashboard') ? 'active' : '' ?>" <?= $isActive('dashboard') ? 'aria-current="page"' : '' ?>>
                <i data-lucide="layout-dashboard"></i><span>Dashboard</span>
            </a><?php endif; ?>
        <?php endif; ?>
        @if(!$investorNavigation && $canAccess(['access' => 'daily_work']) && haven_can_access(site_url('today'), 'GET'))<a data-menu-item data-keywords="today daily arrivals departures cleaning work" href="{{ site_url('today') }}" class="hs-nav-link {{ $currentPath === 'today' ? 'active' : '' }}" @if($currentPath === 'today') aria-current="page" @endif><i data-lucide="calendar-days"></i><span>Today's work</span></a>@endif
        @if(!$investorNavigation && ($user->can('sales.view') || $user->can('reservations.view') || $user->can('finance.view')))<a data-menu-item data-keywords="approvals requests tasks finance reservations" href="{{ site_url('work') }}" class="hs-nav-link {{ $isActive('work')?'active':'' }}" @if($isActive('work')) aria-current="page" @endif><i data-lucide="inbox"></i><span>Work inbox &amp; approvals</span></a>@endif
        @if(!$investorNavigation && $user->can('tasks.view'))<a data-menu-item data-keywords="tasks assignments deadlines work" class="hs-nav-link {{ str_starts_with($currentPath,'tasks') ? 'active' : '' }}" href="{{ route('tasks.index',app('currentTenant')->slug) }}" @if(str_starts_with($currentPath,'tasks')) aria-current="page" @endif><i data-lucide="list-checks"></i><span>Tasks</span> @if($taskUnread)<span class="pill">{{ $taskUnread }} unread</span>@endif</a>@endif
        <?php $currentMarked = false; ?>
        <?php foreach ($groups as $group): ?>
            <?php
                $visible = array_values(array_filter($group['items'], $isVisible));
                if (!$visible) continue;
                $groupActive = false;
                foreach ($visible as $item) {
                    if ($isActive($item['path'])) { $groupActive = true; break; }
                }
                if ($group['label'] === 'Finance' && $isActive('receipts')) $groupActive = true;
            ?>
            <details class="hs-nav-group" data-group="<?= esc($group['label'], 'attr') ?>" <?= $groupActive || $cleanerNavigation ? 'open' : '' ?>>
                <summary aria-label="<?= esc($group['label'], 'attr') ?> submenu">
                    <i data-lucide="<?= esc($group['icon'], 'attr') ?>"></i>
                    <span><?= esc($group['label']) ?></span>
                    <i data-lucide="chevron-right" class="hs-group-chevron"></i>
                </summary>
                <div class="hs-subnav">
                    <?php $lastSection = null; ?>
                    <?php foreach ($visible as $item): ?>
                        <?php if (($item['section'] ?? null) !== $lastSection && empty($item['secondary'])): $lastSection = $item['section'] ?? null; ?><?php if ($lastSection !== null): ?><div class="hs-subnav-heading" aria-hidden="true"><?= esc($lastSection) ?></div><?php endif; ?><?php endif; ?>
                        <?php if (!empty($item['public_site']) && !$publicWebsiteEnabled): ?>
                            <span data-menu-item data-keywords="<?= esc(($item['keywords'] ?? '').' '.$group['label'], 'attr') ?>" class="hs-nav-link" aria-disabled="true" title="Enable the public website in Website settings"><i data-lucide="<?= esc($item['icon'], 'attr') ?>"></i><span>Public website<small style="display:block;font-size:11px;opacity:.8">Website offline</small></span></span>
                            <?php continue; ?>
                        <?php endif; ?>
                        <?php if ($isVisible($item)): ?><?php $itemActive = empty($item['public_site']) && $isActive($item['path']) && empty($currentMarked); if ($itemActive) { $currentMarked = true; } ?><a data-menu-item data-secondary="<?= !empty($item['secondary']) ? 'true' : 'false' ?>" <?= !empty($item['secondary']) ? 'hidden' : '' ?> data-keywords="<?= esc(($item['keywords'] ?? '').' '.$group['label'], 'attr') ?>" href="<?= !empty($item['public_site']) ? esc(route('public-stays.index', ['company' => app('currentTenant')->slug]), 'attr') : site_url($item['path']) ?>" class="hs-nav-link <?= $itemActive ? 'active' : '' ?>" <?= $itemActive ? 'aria-current="page"' : '' ?><?= !empty($item['public_site']) ? ' target="_blank" rel="noopener noreferrer" aria-label="Public website (opens in a new tab)"' : '' ?>>
                            <i data-lucide="<?= esc($item['icon'], 'attr') ?>"></i>
                            <span><?= esc($item['label']) ?></span>
                        </a><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </nav>
    <div class="hs-sidebar-footer"><div class="mb-2"><?= esc(app("currentTenant")->name) ?></div>
    <nav class="hs-account-links" aria-label="My account">
        @if($user->can('staff-profile.self'))<a data-menu-item data-keywords="my profile biodata identification staff document" class="hs-nav-link {{ $isActive('my-profile') ? 'active' : '' }}" href="{{ route('staff-profile.edit', app('currentTenant')->slug) }}" @if($isActive('my-profile')) aria-current="page" @endif><i data-lucide="id-card"></i><span>My profile</span></a>@endif
        @unless(session()->has('haven.impersonation'))<a data-menu-item data-keywords="password account security login" class="hs-nav-link {{ request()->routeIs('account.password.*') ? 'active' : '' }}" href="{{ route('account.password.edit') }}" @if(request()->routeIs('account.password.*')) aria-current="page" @endif><i data-lucide="key-round"></i><span>Change password</span></a>@endunless
    </nav><form method="post" action="<?= url("/logout") ?>"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-light mb-3">Sign out</button></form>
        <strong>HAVEN SUITES</strong>
        <div class="small opacity-75">BNBs and STR Homes<br>Operations Platform</div>
    </div>
</aside>
<script>
document.addEventListener('DOMContentLoaded', function () {
 const nav=document.getElementById('company-navigation'), search=document.getElementById('menu-search');
 const groups=Array.from(nav.querySelectorAll('.hs-nav-group')), links=Array.from(nav.querySelectorAll('[data-menu-item]'));
 const key=@json('haven.navigation.'.app('currentTenant')->id.'.'.auth()->id());
 let state={};try{state=JSON.parse(localStorage.getItem(key)||'{}')}catch(e){}
 let filtering=false;
 groups.forEach(g=>{const name=g.dataset.group;if(state[name]!==undefined && !g.querySelector('[aria-current="page"]'))g.open=state[name];g.addEventListener('toggle',()=>{if(filtering)return;state[name]=g.open;try{localStorage.setItem(key,JSON.stringify(state))}catch(e){}})});
 const synonyms={booking:'reservation bookings',receipt:'invoices payments',rent:'accounting',kyc:'verification',remittance:'payouts',sms:'messages',cleaning:'housekeeping cleaner'};
 let snapshot=null;
 search.addEventListener('input',()=>{const query=search.value.trim().toLowerCase();filtering=!!query;if(query&&!snapshot)snapshot=groups.map(g=>g.open);let count=0;
 links.forEach(a=>{const text=(a.textContent+' '+a.dataset.keywords).toLowerCase();const match=query.split(/\s+/).every(word=>[word,...(synonyms[word]||'').split(' ')].filter(Boolean).some(term=>text.includes(term)));a.hidden=query?!match:a.dataset.secondary==='true';if(query&&match)count++});
 groups.forEach((g,i)=>{g.hidden=!!query&&!Array.from(g.querySelectorAll('[data-menu-item]')).some(a=>!a.hidden);if(query)g.open=!g.hidden;else if(snapshot)g.open=snapshot[i]});
 nav.querySelectorAll('.hs-subnav-heading').forEach(h=>{h.hidden=!!query});document.getElementById('menu-search-status').textContent=query?(count?count+' matching pages':'No matching pages. Try booking, payment or cleaning.') : '';if(!query){snapshot=null;filtering=false}
 });
 search.addEventListener('keydown',e=>{if(e.key==='Escape'){search.value='';search.dispatchEvent(new Event('input'));e.stopPropagation()}});
 let position=0;try{position=Number(sessionStorage.getItem(key+'.scroll')||0)}catch(e){}nav.scrollTop=position;
 nav.addEventListener('scroll',()=>{try{sessionStorage.setItem(key+'.scroll',String(nav.scrollTop))}catch(e){}},{passive:true});
});
</script>
