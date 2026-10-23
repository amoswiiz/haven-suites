<?php
$currentPath = trim(uri_string(), '/');
$user = auth()->user();

$groups = [
    ['label' => 'Reservations & Guests', 'icon' => 'calendar-days', 'items' => [
        ['label' => 'Reservations', 'path' => 'reservations', 'icon' => 'calendar', 'permission' => 'reservations.view'],
        ['label' => 'Quotations', 'path' => 'quotations', 'icon' => 'file-text', 'permission' => 'reservations.view'],
        ['label' => 'Availability', 'path' => 'availability', 'icon' => 'calendar-days', 'permission' => 'availability.view'],
        ['label' => 'Customers', 'path' => 'customers', 'icon' => 'contact', 'permission' => 'reservations.view'],
        ['label' => 'Guest KYC', 'path' => 'staff/kyc', 'icon' => 'id-card', 'permission' => 'reservations.view'],
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

// Test the more specific path first so the Housekeeping parent does not
// highlight when the cleaner entry page is open.
$isActive = static function (string $path) use ($currentPath): bool {
    if ($path === 'housekeeping') {
        return $currentPath === 'housekeeping' || (
            str_starts_with($currentPath, 'housekeeping/')
            && !str_starts_with($currentPath, 'housekeeping/my-cleanings/')
        );
    }
    return $currentPath === $path || str_starts_with($currentPath, $path . '/');
};

$canAccess = static function (array $item) use ($user): bool {
    if ($user === null) return false;
    if (($item['access'] ?? '') === 'cleaner_module') {
        // Navigation visibility only. Controllers and routes still enforce access.
        return $user->inGroup('cleaner')
            || $user->inGroup('admin')
            || $user->inGroup('superadmin')
            || $user->can('housekeeping.manage');
    }
    return $user->can($item['permission']);
};
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
@media print { .hs-sidebar { display: none !important; } }
</style>
<aside class="hs-sidebar haven-sidebar-scroll">
    <div class="hs-brand">
        <div class="hs-brand-wordmark" aria-label="Haven Suites, BNBs and STR Homes">
            <span class="hs-brand-name">HAVEN <span>SUITES</span></span>
            <span class="hs-brand-tagline">BNBs AND STR HOMES</span>
        </div>
    </div>
    <div class="hs-menu-heading">MAIN MENU</div>
    <nav class="hs-nav" aria-label="Main navigation">
        <?php if ($user !== null && $user->can('dashboard.view')): ?>
            <a href="<?= site_url('dashboard') ?>" class="hs-nav-link <?= $isActive('dashboard') ? 'active' : '' ?>">
                <i data-lucide="layout-dashboard"></i><span>Dashboard</span>
            </a>
        <?php endif; ?>
        <?php foreach ($groups as $group): ?>
            <?php
                $visible = array_values(array_filter($group['items'], $canAccess));
                if (!$visible) continue;
                $groupActive = false;
                foreach ($visible as $item) {
                    if ($isActive($item['path'])) { $groupActive = true; break; }
                }
                if ($group['label'] === 'Finance' && $isActive('receipts')) $groupActive = true;
            ?>
            <details class="hs-nav-group" <?= $groupActive ? 'open' : '' ?>>
                <summary aria-label="<?= esc($group['label'], 'attr') ?> submenu">
                    <i data-lucide="<?= esc($group['icon'], 'attr') ?>"></i>
                    <span><?= esc($group['label']) ?></span>
                    <i data-lucide="chevron-right" class="hs-group-chevron"></i>
                </summary>
                <div class="hs-subnav">
                    <?php foreach ($visible as $item): ?>
                        <a href="<?= site_url($item['path']) ?>" class="hs-nav-link <?= $isActive($item['path']) ? 'active' : '' ?>" <?= $isActive($item['path']) ? 'aria-current="page"' : '' ?>>
                            <i data-lucide="<?= esc($item['icon'], 'attr') ?>"></i>
                            <span><?= esc($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </nav>
    <div class="hs-sidebar-footer">
        <strong>HAVEN SUITES</strong>
        <div class="small opacity-75">BNBs and STR Homes<br>Operations Platform</div>
    </div>
</aside>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const groups = Array.from(document.querySelectorAll('.hs-sidebar .hs-nav-group'));
    groups.forEach(function (group) {
        group.addEventListener('toggle', function () {
            if (!group.open) return;
            groups.forEach(function (other) { if (other !== group) other.open = false; });
        });
    });
});
</script>
