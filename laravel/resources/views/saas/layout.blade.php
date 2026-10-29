<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>@yield('title', 'Your business') | {{ app()->bound('currentTenant') ? app('currentTenant')->name : 'Haven Suites' }}</title>
@auth
<link rel="stylesheet" href="{{ asset('assets/css/dashboard.css') }}">
<link rel="stylesheet" href="{{ asset('assets/css/haven-brand.css') }}?v=4">
@endauth
<style>
:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f4f6fa;color:#203456;font:15px/1.6 Arial,sans-serif}a{color:#294780}header{background:#203456;color:white;padding:18px max(24px,calc((100vw - 1120px)/2));display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}header a{color:white;text-decoration:none}.brand{font-size:21px;font-weight:800;letter-spacing:.03em}.brand small{display:block;font-size:10px;color:#ffb782;letter-spacing:.16em}nav{display:flex;gap:16px;align-items:center;flex-wrap:wrap}main{max-width:1120px;margin:32px auto;padding:0 24px}h1{font-size:28px;line-height:1.2;margin:0 0 12px}h2{font-size:20px;margin-top:0}.muted{color:#64748b}.card{background:white;padding:28px;border:1px solid #dfe5ef;border-radius:14px;margin-bottom:22px;box-shadow:0 4px 20px #20345605}.narrow{max-width:600px;margin:0 auto}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px}label{display:block;font-weight:600;margin:15px 0 6px}input,select{font:inherit;padding:11px 12px;width:100%;border:1px solid #aebdd2;border-radius:7px;background:white;color:#203456}button,.button{font:inherit;display:inline-block;background:#294780;color:white!important;border:1px solid #294780;border-radius:8px;padding:10px 17px;text-decoration:none;cursor:pointer}button:hover,.button:hover{background:#203456}.secondary{background:white;color:#294780!important}.danger{background:#9b2222;border-color:#9b2222}.actions{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:22px}.alert{padding:14px 18px;border-radius:8px;margin-bottom:20px;background:#e5f5eb;color:#155b35}.error{background:#fff0ec;color:#923422}ul{padding-left:20px}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:12px;border-bottom:1px solid #e5eaf2;vertical-align:top}th{background:#edf2f9;font-size:12px}.pill{display:inline-block;padding:3px 10px;border-radius:20px;background:#edf2fa;font-size:12px}form.inline{display:inline}header button{background:transparent;border-color:#94a3b8;padding:6px 12px}input:focus-visible,select:focus-visible,button:focus-visible,a:focus-visible{outline:3px solid #e98d50;outline-offset:3px}.price{font-size:26px;font-weight:800}.checklist li{margin:10px 0}footer{font-size:12px;color:#64748b;margin:32px 0}.split{display:flex;justify-content:space-between;align-items:start;gap:20px;flex-wrap:wrap}@media(max-width:600px){main{padding:0 16px}.card{padding:20px}h1{font-size:24px}}
textarea{font:inherit;padding:11px 12px;width:100%;border:1px solid #aebdd2;border-radius:7px;resize:vertical;color:#203456}summary{cursor:pointer;padding:8px 0;font-weight:600}textarea:focus-visible,summary:focus-visible{outline:3px solid #e98d50;outline-offset:3px}caption{text-align:left;color:#52627a;padding:8px 0}input[type=checkbox]{min-width:20px;min-height:20px}nav[aria-label="Pagination Navigation"] svg{width:20px;height:20px}body.has-company-sidebar .hs-nav{display:block}
body.has-company-sidebar main{max-width:none;margin:0 0 0 250px;padding:0;min-width:0}
body.has-company-sidebar main>header{padding:20px 28px}
body:not(.has-company-sidebar) .saas-content{padding:0}.saas-content{max-width:1440px;margin:0 auto;padding:28px;min-width:0}
.hs-sidebar .hs-brand{display:block}.hs-sidebar button{background:transparent;border-color:#b9c8dc;color:white!important}
.hs-sidebar .hs-menu-toggle{display:none}
@media(max-width:900px){body.has-company-sidebar main{margin-left:0}.saas-content{padding:20px 16px}.hs-sidebar .hs-menu-toggle{display:block}}
@media print{.hs-sidebar,.company-menu-toggle,.hs-skip-link{display:none!important}body.has-company-sidebar main{margin:0}.saas-content{padding:0}}
</style></head><body @auth class="has-company-sidebar" @endauth>
@auth
@if(app()->bound('currentTenant'))
@include('layouts.sidebar')
@else
<aside id="company-navigation" class="hs-sidebar" aria-label="Account navigation">
<button type="button" class="secondary hs-menu-toggle hs-menu-close" style="margin:16px">Close menu</button>
<div class="hs-brand"><span class="hs-brand-name">HAVEN SUITES</span></div>
<nav class="hs-nav" aria-label="Main navigation"><a class="hs-nav-link" href="{{ url('/') }}">{{ auth()->user()->hasMultipleCompanies() ? 'My companies' : 'My workspace' }}</a>
@if(auth()->user()->is_platform_admin)
<a class="hs-nav-link {{ request()->is('platform*')?'active':'' }}" href="{{ route('platform.index') }}">Platform administration</a>
@endif
</nav><div class="hs-sidebar-footer"><form method="post" action="{{ url('/logout') }}">@csrf<button>Sign out</button></form></div>
</aside>
@endif
<a class="hs-skip-link" href="#saas-main">Skip to content</a>
@endauth
@auth
<main id="saas-main" tabindex="-1">
@endauth
<header>@auth<button type="button" class="hs-menu-toggle company-menu-toggle" aria-controls="company-navigation" aria-expanded="false">Menu</button>@endauth<a class="brand" href="{{ url('/') }}">{{ app()->bound('currentTenant') ? app('currentTenant')->name : 'HAVEN SUITES' }}<small>PROPERTY MANAGEMENT</small></a><nav>
@auth
@if(!app()->bound('currentTenant'))<a href="{{ url('/') }}">{{ auth()->user()->hasMultipleCompanies() ? 'My companies' : 'My workspace' }}</a>@endif
@if(auth()->user()->is_platform_admin)<a href="{{ route('platform.index') }}">Platform</a>@endif
<span aria-label="Current page">@yield('title','Workspace')</span>
<form class="inline" method="post" action="{{ url('/logout') }}">@csrf<button>Sign out</button></form>
@else<a href="{{ route('pricing') }}">Plans</a><a href="{{ url('/login') }}">Sign in</a><a href="{{ route('register') }}">Start free trial</a>@endauth
</nav></header>
@guest
<main id="saas-main" tabindex="-1">
@endguest
<div class="saas-content">
@include('impersonation.banner')
@if(session('success'))<div class="alert" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')<footer>Haven Suites · A separate workspace for every company.</footer></div></main>
@auth
<script>
(() => {
 const button=document.querySelector('.company-menu-toggle');
 const navigation=document.getElementById('company-navigation');
 const mobile=window.matchMedia('(max-width:900px)');
 function setOpen(open){button.setAttribute('aria-expanded',String(open));navigation.classList.toggle('hs-mobile-open',open);navigation.inert=mobile.matches&&!open;}
 button.addEventListener('click',()=>setOpen(button.getAttribute('aria-expanded')!=='true'));
 document.querySelector('.hs-menu-close').addEventListener('click',()=>{setOpen(false);button.focus();});
 document.addEventListener('keydown',event=>{if(event.key==='Escape'&&button.getAttribute('aria-expanded')==='true'){setOpen(false);button.focus();}});
 mobile.addEventListener('change',()=>setOpen(false));setOpen(false);
})();
</script>
@endauth
</body></html>
