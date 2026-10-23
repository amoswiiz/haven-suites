@extends('layouts/main')
@section('content')
<section class="card p-4"><h1 class="h4">Your company account</h1>
<p>Company: {{ app('currentTenant')->name }}</p>
<p>Signed in as {{ auth()->user()->email }}</p>
<p>Role: {{ implode(', ', auth()->user()->roles()) }}</p>
<p>No operational pages are assigned to this role. Contact your company administrator if you need additional access.</p>
@if(auth()->user()->inGroup('owner'))<p>Owner-specific property reporting has not been configured. Company-wide financial records are restricted.</p>@endif
</section>
@endsection
