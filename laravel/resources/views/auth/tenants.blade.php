<!doctype html><html lang="en"><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Your companies | Haven Suites</title></head><body><main><h1>Your companies</h1>
@forelse($tenants as $tenant)<p><a href="{{ url('/t/'.$tenant->slug.'/home') }}">{{ $tenant->name }}</a></p>@empty<p>No active company is assigned to your account. Contact your administrator.</p>@endforelse
<form method="post" action="{{ url('/logout') }}">@csrf<button>Sign out</button></form></main></body></html>
