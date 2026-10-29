@if(session()->has('haven.impersonation'))
<div role="status" style="padding:16px;background:#fff3cd;color:#513c06;border:1px solid #e0bf57;margin-bottom:16px">
    <strong>Viewing as {{ auth()->user()?->name }}.</strong> Actions affect this user's company data and are logged.
    <form method="post" action="{{ url('/impersonation/stop') }}" style="display:inline-block;margin-left:12px">@csrf<button type="submit">Return to my account</button></form>
</div>
@elseif(auth()->user()?->is_platform_admin || (app()->bound('currentTenant') && auth()->user()?->inGroup('superadmin')))
<p><a href="{{ url('/impersonation') }}">Log in as another user</a></p>
@endif
