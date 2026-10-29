@extends('layouts.main')
@section('content')
<h1 class="h3">Company setup checklist</h1><p class="text-muted">Review these before daily work. A check confirms configuration exists; it does not verify provider credentials or guarantee every apartment is configured.</p>
<div class="card p-4">@foreach($checks as $check)@if(haven_can_access(site_url($check['path']),'GET'))<div class="d-flex justify-content-between gap-3 border-bottom py-3"><div><strong>{{ $check['label'] }}</strong><div>{{ $check['done']?'Configuration present - review details':'Needs setup' }}</div></div><a href="{{ site_url($check['path']) }}">{{ $check['done']?'Review':'Set up' }}</a></div>@endif @endforeach</div>
@endsection
