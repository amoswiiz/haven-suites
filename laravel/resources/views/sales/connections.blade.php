@extends('saas.layout')
@section('title','Messaging connections')
@section('content')
<h1>Messaging connections</h1><p class="muted">Module activation and channel connection are separate. No external accounts are connected by this release.</p><div class="grid">
@foreach(['whatsapp'=>'WhatsApp Business','instagram'=>'Instagram messaging'] as $key=>$label)<section class="card"><h2>{{ $label }}</h2><span class="pill">{{ ucfirst($connections->firstWhere('channel',$key)?->status ?? 'disconnected') }}</span><p>Account authorization and automatic message capture will be added next. Record conversations on an enquiry in the meantime.</p></section>@endforeach
</div><a href="{{ route('sales.index',app('currentTenant')->slug) }}">Back to sales pipeline</a>
@endsection
