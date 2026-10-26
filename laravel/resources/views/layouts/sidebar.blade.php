<?php
$currentPath = trim(uri_string(), '/');
$user = haven_auth()->user();

$groups = [
    ['label' => 'Communications', 'icon' => 'message-circle', 'items' => [
        ['label' => 'Compose SMS', 'path' => 'communications/sms', 'permission' => 'messaging.view', 'icon' => 'mail'],
        ['label' => 'Channel connections', 'path' => 'sales/connections', 'permission' => 'messaging.view', 'icon' => 'message-circle'],
        ['label' => 'System notifications', 'path' => 'investor-notifications', 'permission' => 'notifications.view', 'icon' => 'mail'],
    ]],
    ['label' => 'Investors & Owners', 'icon' => 'users', 'items' => [
        ['label' => 'Investor directory', 'path' => 'investors', 'permission' => 'investors.view', 'icon' => 'users'],
        ['label' => 'My investor portal', 'path' => 'investor-portal', 'permission' => 'investor.portal', 'icon' => 'house'],
        ['label' => 'Investor reports', 'path' => 'investor-portal/reports', 'permission' => 'investor.portal', 'icon' => 'file-text'],
        ['label' => 'Email & SMS notifications', 'path' => 'investor-notifications', 'permission' => 'notifications.view', 'icon' => 'mail'],
    ]],
    ['label' => 'Sales & Enquiries', 'icon' => 'contact', 'items' => [
        ['label' => 'Sales pipeline', 'path' => 'sales', 'permission' => 'sales.view', 'icon' => 'contact'],
        ['label' => 'New enquiry', 'path' => 'sales/new', 'permission' => 'sales.manage', 'icon' => 'plus'],
        ['label' => 'Referral agents', 'path' => 'sales/agents', 'permission' => 'agents.view', 'icon' => 'users'],
        ['label' => 'Messaging connections', 'path' => 'sales/connections', 'permission' => 'messaging.view', 'icon' => 'message-circle'],
    ]],
    ['label' => 'Reservations & Guests', 'icon' => 'calendar-days', 'items' => [
        ['label' => 'Reservations', 'path' => 'reservations', 'icon' => 'calendar', 'permission' => 'reservations.view'],
        ['label' => 'Quotations', 'path' => 'quotations', 'icon' => 'file-text', 'permission' => 'reservations.view'],
        ['label' => 'Availability', 'path' => 'availability', 'icon' => 'calendar-days', 'permission' => 'availability.view'],
        ['label' => 'Customers', 'path' => 'customers', 'icon' => 'contact', 'permission' => 'finance.view'],
        ['label' => 'Guest KYC', 'path' => 'staff/kyc', 'icon' => 'id-card', 'permission' => 'finance.view'],
    ]],
    ['label' => 'Properties', 'icon' => 'building-2', 'items' => [
        ['label' => 'Apartment Units', 'path' => 'units', 'icon' => 'house', 'permission' => 'units.view'],
        ['label' => 'Host Agreements', 'path' => 'host-agreements', 'icon' => 'handshake', 'permission' => 'finance.view'],
    ]],
    ['label' => 'Operations', 'icon' => 'clipboard-list', 'items' => [
        ['label' => 'Housekeeping', 'path' => 'housekeeping', 'icon' => 'clipboard-check', 'permission' => 'housekeeping.view'],
        ['label' => 'Record Cleaning', 'path' => 'housekeeping/my-cleanings/new', 'icon' => 'plus-circle', 'access' => 'cleaner_module'],
        ['label' => 'Cleaner Earnings', 'path' => 'payments/cleaners/earnings', 'icon' => 'wallet', 'access' => 'cleaner_module'],
        ['label' => 'Maintenance', 'path' => 'maintenance', 'icon' => 'wrench', 'permission' => 'maintenance.view'],
    ]],
    ['label' => 'Finance', 'icon' => 'wallet', 'items' => [
        ['label' => 'Unit accounting & rent', 'path' => 'accounting/units', 'icon' => 'receipt', 'permission' => 'finance.view'],
        ['label' => 'Invoices', 'path' => 'invoices', 'icon' => 'file-text', 'permission' => 'finance.view'],
        ['label' => 'Guest Payments', 'path' => 'payments/guests', 'icon' => 'wallet', 'permission' => 'finance.view'],
        ['label' => 'Partner Owner Payments', 'path' => 'payments/partners', 'icon' => 'hand-coins', 'permission' => 'finance.view'],
        ['label' => 'Partner Owner Balances', 'path' => 'payments/partners/owners', 'icon' => 'file-text', 'permission' => 'finance.view'],
        ['label' => 'Host Payments', 'path' => 'payments/hosts', 'icon' => 'wallet', 'permission' => 'finance.view'],
        ['label' => 'Owner Remittances', 'path' => 'owner-remittances', 'icon' => 'hand-coins', 'permission' => 'finance.view'],
        ['label' => 'Expense Register', 'path' => 'expenses', 'icon' => 'receipt', 'permission' => 'finance.view'],
        ['label' => 'Cleaner Payments', 'path' => 'payments/cleaners', 'icon' => 'wallet-cards', 'permission' => 'finance.view'],
        ['label' => 'Cleaner Balances', 'path' => 'payments/cleaners/balances', 'icon' => 'wallet', 'permission' => 'finance.view'],
        ['label' => 'Cleaner Statements', 'path' => 'payments/cleaners/statements', 'icon' => 'file-text', 'permission' => 'finance.view'],
    ]],
    ['label' => 'Reports', 'icon' => 'chart-column', 'items' => [
        ['label' => 'Investor Reports', 'path' => 'reports/investor', 'icon' => 'file-chart-column-increasing', 'permission' => 'finance.view'],
        ['label' => 'Monthly Company Income', 'path' => 'reports/monthly-company-income', 'icon' => 'chart-no-axes-combined', 'permission' => 'finance.view'],
        ['label' => 'Monthly Host Commissions', 'path' => 'reports/monthly-host-commissions', 'icon' => 'hand-coins', 'permission' => 'finance.view'],
        ['label' => 'Monthly Unit Expenses', 'path' => 'reports/monthly-unit-expenses', 'icon' => 'calendar-range', 'permission' => 'finance.view'],
        ['label' => 'Housekeeping Financial Report', 'path' => 'reports/housekeeping', 'icon' => 'chart-column', 'permission' => 'finance.view'],
    ]],
    ['label' => 'Team & Settings', 'icon' => 'settings', 'items' => [
        ['label' => 'Staff Management', 'path' => 'staff', 'icon' => 'users', 'permission' => 'staff.view'],
        ['label' => 'Owner Statement Closing', 'path' => 'owner-statement-closing', 'icon' => 'clipboard-check', 'permission' => 'finance.manage'],
        ['label' => 'Cleaning Rates', 'path' => 'settings/cleaning-rates', 'icon' => 'wallet', 'permission' => 'settings.manage'],
    ]],
];

$byLabel = array_column($groups, null, 'label');
$groups = [];
foreach (['Reservations & Guests' => 'Bookings & Guests', 'Operations' => 'Operations', 'Sales & Enquiries' => 'Sales', 'Properties' => 'Properties & Owners', 'Finance' => 'Finance', 'Communications' => 'Messages', 'Reports' => 'Reports', 'Team & Settings' => 'Settings'] as $old => $label) {
    $group = $byLabel[$old];
    $group['label'] = $label;
    if ($label === 'Bookings & Guests') {
        $group['items'] = array_values(array_filter($group['items'], static fn (array $item): bool => $item['path'] !== 'quotations'));
    }
    if ($label === 'Sales') {
        $group['items'] = array_values(array_filter($group['items'], static fn (array $item): bool => !in_array($item['path'], ['sales/new', 'sales/connections'], true)));
        $group['items'][] = $byLabel['Reservations & Guests']['items'][1];
    }
    if ($label === 'Properties & Owners') {
        foreach ($byLabel['Investors & Owners']['items'] as $item) {
            if ($item['path'] !== 'investor-notifications') $group['items'][] = $item;
        }
    }
    if ($label === 'Settings') {
        $group['items'] = array_values(array_filter($group['items'], static fn (array $item): bool => $item['path'] !== 'owner-statement-closing'));
        foreach (['invoices/settings' => 'Invoice settings', 'company/settings' => 'Company settings', 'company/team' => 'Team & access', 'company/subscription' => 'Subscription'] as $path => $name) {
            $group['items'][] = ['label' => $name, 'path' => $path, 'access' => 'company_admin', 'icon' => 'settings'];
        }
    }
    if ($label === 'Finance') $group['items'][] = $byLabel['Team & Settings']['items'][1];
    $groups[] = $group;
}
$renamed = ['reservations' => 'Bookings', 'availability' => 'Availability calendar', 'customers' => 'Guest directory', 'staff/kyc' => 'Guest verification', 'units' => 'Apartments', 'host-agreements' => 'Management agreements', 'investors' => 'Owners & investors', 'communications/sms' => 'SMS messages & history', 'investor-notifications' => 'Email & SMS notifications', 'accounting/units' => 'Apartment accounting & rent', 'owner-remittances' => 'Owner & partner payouts', 'expenses' => 'Expenses', 'payments/cleaners' => 'Cleaner payments', 'owner-statement-closing' => 'Close accounting month'];
$secondary = ['payments/partners', 'payments/partners/owners', 'payments/hosts', 'payments/cleaners/balances', 'payments/cleaners/statements'];
foreach ($groups as &$group) {
    foreach ($group['items'] as &$item) {
        $item['keywords'] = $item['label'];
        $item['label'] = $renamed[$item['path']] ?? $item['label'];
        $item['secondary'] = in_array($item['path'], $secondary, true);
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
        ['label' => 'Reports', 'path' => 'investor-portal/reports', 'permission' => 'investor.portal', 'icon' => 'file-text'],
        ['label' => 'Statements', 'path' => 'investor-portal/statements', 'permission' => 'investor.portal', 'icon' => 'file-text'],
        ['label' => 'Payouts', 'path' => 'investor-portal/payouts', 'permission' => 'investor.portal', 'icon' => 'wallet'],
        ['label' => 'Agreements', 'path' => 'investor-portal/agreements', 'permission' => 'investor.portal', 'icon' => 'handshake'],
        ['label' => 'Settings', 'path' => 'investor-portal/settings', 'permission' => 'investor.portal', 'icon' => 'settings'],
    ]]];
}

$canAccess = static function (array $item) use ($user): bool {
    if ($user === null) return false;
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
$activePath = null;
foreach ($groups as $group) {
    foreach ($group['items'] as $item) {
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
.hs-sidebar [hidden]{display:none!important}.hs-sidebar .hs-nav-link,.hs-sidebar summary{min-height:44px}.hs-sidebar #menu-search-status:empty{display:none}.hs-sidebar :is(a,button,summary):focus-visible { outline:3px solid #ffbd8b; outline-offset:2px; }
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
    <div class="px-3 mb-3"><a class="hs-nav-link" href="{{ url('/') }}" aria-label="Switch company">{{ app('currentTenant')->name }} <span class="small">&middot; Switch company</span></a><label for="menu-search" class="form-label mt-2">Find a page</label><input id="menu-search" type="search" class="form-control" placeholder="Booking, rent, receipt" autocomplete="off" aria-controls="sidebar-links"><p id="menu-search-status" class="small mt-2" role="status" aria-live="polite"></p></div><div class="hs-menu-heading">MAIN MENU</div>
    @if(!$investorNavigation)
    @php($createActions = ['reservations/create' => 'New booking', 'sales/new' => 'New enquiry', 'quotations/create' => 'New quotation', 'invoices/create' => 'New invoice', 'communications/sms' => 'Send SMS'])
    @php($createActions = array_filter($createActions, static fn (string $path): bool => haven_can_access(site_url($path), 'GET'), ARRAY_FILTER_USE_KEY))
    @if($createActions)
    <details class="mx-3 mb-3"><summary class="p-2">+ Create</summary>
        @foreach($createActions as $path => $label)<a class="hs-nav-link" href="{{ site_url($path) }}">{{ $label }}</a>@endforeach
    </details>
    @endif
    @endif
    <nav id="sidebar-links" class="hs-nav" aria-label="Main navigation">

        <?php if (!$investorNavigation && $user !== null && $user->can('dashboard.view')): ?>
            <?php if (haven_can_access(site_url('dashboard'), 'GET')): ?><a href="<?= site_url('dashboard') ?>" data-menu-item data-keywords="home overview today" class="hs-nav-link <?= $isActive('dashboard') ? 'active' : '' ?>" <?= $isActive('dashboard') ? 'aria-current="page"' : '' ?>>
                <i data-lucide="layout-dashboard"></i><span>Dashboard</span>
            </a><?php endif; ?>
        <?php endif; ?>
        <?php foreach ($groups as $group): ?>
            <?php
                $visible = array_values(array_filter($group['items'], static fn ($item) => $canAccess($item) && haven_can_access(site_url($item['path']), 'GET')));
                if (!$visible) continue;
                $groupActive = false;
                foreach ($visible as $item) {
                    if ($isActive($item['path'])) { $groupActive = true; break; }
                }
                if ($group['label'] === 'Finance' && $isActive('receipts')) $groupActive = true;
            ?>
            <details class="hs-nav-group" data-group="<?= esc($group['label'], 'attr') ?>" <?= $groupActive ? 'open' : '' ?>>
                <summary aria-label="<?= esc($group['label'], 'attr') ?> submenu">
                    <i data-lucide="<?= esc($group['icon'], 'attr') ?>"></i>
                    <span><?= esc($group['label']) ?></span>
                    <i data-lucide="chevron-right" class="hs-group-chevron"></i>
                </summary>
                <div class="hs-subnav">
                    <?php foreach ($visible as $item): ?>
                        <?php if (haven_can_access(site_url($item['path']), 'GET')): ?><a data-menu-item data-secondary="<?= !empty($item['secondary']) ? 'true' : 'false' ?>" <?= !empty($item['secondary']) ? 'hidden' : '' ?> data-keywords="<?= esc(($item['keywords'] ?? '').' '.$group['label'], 'attr') ?>" href="<?= site_url($item['path']) ?>" class="hs-nav-link <?= $isActive($item['path']) ? 'active' : '' ?>" <?= $isActive($item['path']) ? 'aria-current="page"' : '' ?>>
                            <i data-lucide="<?= esc($item['icon'], 'attr') ?>"></i>
                            <span><?= esc($item['label']) ?></span>
                        </a><?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </nav>
    <div class="hs-sidebar-footer"><div class="mb-2"><?= esc(app("currentTenant")->name) ?></div><form method="post" action="<?= url("/logout") ?>"><?= csrf_field() ?><button type="submit" class="btn btn-sm btn-outline-light mb-3">Sign out</button></form>
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
 document.getElementById('menu-search-status').textContent=query?(count?count+' matching pages':'No matching pages. Try booking, payment or cleaning.') : '';if(!query){snapshot=null;filtering=false}
 });
 search.addEventListener('keydown',e=>{if(e.key==='Escape'){search.value='';search.dispatchEvent(new Event('input'));e.stopPropagation()}});
 let position=0;try{position=Number(sessionStorage.getItem(key+'.scroll')||0)}catch(e){}nav.scrollTop=position;
 nav.addEventListener('scroll',()=>{try{sessionStorage.setItem(key+'.scroll',String(nav.scrollTop))}catch(e){}},{passive:true});
});
</script>
