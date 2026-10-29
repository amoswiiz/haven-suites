<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= esc($pageTitle ?? 'Haven Suites') ?> | Haven Suites</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?= base_url('assets/css/dashboard.css') ?>" rel="stylesheet">
    <link href="<?= base_url('assets/css/haven-brand.css') ?>?v=4" rel="stylesheet">

    <style>
        @media (min-width: 992px) {
            .haven-sidebar-scroll {
                position: fixed;
                top: 0;
                bottom: 0;
                left: 0;
                height: 100vh;
                overflow-y: auto;
                overflow-x: hidden;
                overscroll-behavior: contain;
                scrollbar-width: thin;
                scrollbar-color: #aeb8c5 transparent;
            }
        }
        .haven-sidebar-scroll::-webkit-scrollbar { width: 6px; }
        .haven-sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
        .haven-sidebar-scroll::-webkit-scrollbar-thumb { background: #aeb8c5; border-radius: 10px; }
    </style>
    @yield('styles')
</head>
<body>
@include('layouts/sidebar')
<a class="hs-skip-link" href="#main-content">Skip to content</a>
<main class="hs-main" id="main-content" tabindex="-1">
    <header class="hs-topbar hs-branded-topbar">
        <div>
            <button type="button" class="btn btn-outline-primary hs-menu-toggle" aria-controls="company-navigation" aria-expanded="false">Menu</button>
            <h5 class="mb-1"><?= esc($pageTitle ?? 'Dashboard') ?></h5>
            <div class="text-muted small">BNBs and STR Homes · Management System</div>
        </div>
        <?php $currentStaff = haven_auth()->user(); ?>
        <div class="hs-topbar-meta">
            <span class="hs-topbar-date"><?= esc(date('d M Y')) ?></span>
            <?php if ($currentStaff !== null): ?>
                <span class="hs-topbar-staff">
                    <i data-lucide="user-round" aria-hidden="true"></i>
                    <span><?= esc(app('currentTenant')->name) ?> &middot; <?= esc(ucwords(implode(', ', auth()->user()->roles()))) ?></span>
                </span>
            <?php endif; ?>
        </div>
    </header>
    <div class="hs-content">
        @include('impersonation.banner')
        @if(auth()->user()?->inGroup('superadmin','admin') && app('currentTenant')->plan !== 'legacy' && !app('currentTenant')->onboarding_completed_at)
        <p><a href="{{ route('onboarding.property', app('currentTenant')->slug) }}">Finish company setup</a></p>
        @endif
        @include('layouts.finance-tabs')
        <?php $sharedErrors = session('errors'); if ($sharedErrors instanceof \Illuminate\Support\ViewErrorBag && $sharedErrors->any()): ?><div class="alert alert-danger" role="alert"><ul class="mb-0"><?php foreach ($sharedErrors->all() as $error): ?><li><?= esc($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        <?php if (haven_session()->getFlashdata('success')): ?>
            <div class="alert alert-success"><?= esc(haven_session()->getFlashdata('success')) ?></div>
        <?php endif; ?>
        <?php if (haven_session()->getFlashdata('error')): ?>
            <div class="alert alert-danger"><?= esc(haven_session()->getFlashdata('error')) ?></div>
        <?php endif; ?>
        @yield('content')
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
    const menuToggle = document.querySelector('.hs-topbar .hs-menu-toggle');
    const navigation = document.getElementById('company-navigation');
    const mobileMenu = window.matchMedia('(max-width: 900px)');
    function updateMenu(open) {
        menuToggle.setAttribute('aria-expanded', String(open));
        navigation.classList.toggle('hs-mobile-open', open);
        navigation.inert = mobileMenu.matches && !open;
    }
    menuToggle.addEventListener('click', () => updateMenu(menuToggle.getAttribute('aria-expanded') !== 'true'));
    document.querySelector('.hs-menu-close').addEventListener('click', () => {
        updateMenu(false);
        menuToggle.focus();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && menuToggle.getAttribute('aria-expanded') === 'true') {
            updateMenu(false);
            menuToggle.focus();
        }
    });
    mobileMenu.addEventListener('change', () => updateMenu(false));
    updateMenu(false);
</script>
@yield('scripts')
</body>
</html>
