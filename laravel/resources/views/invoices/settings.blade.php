@extends('layouts.main')
@section('content')
<div class="mb-4"><a href="{{ site_url('invoices') }}">&larr; Invoices</a><h1 class="h3 mt-2">Invoice settings</h1><p class="text-muted">Company information shown on invoices and default text for new invoices.</p></div>
<form method="post" action="{{ site_url('invoices/settings') }}">@csrf
<section class="card p-4 mb-4"><h2 class="h5">Business &amp; contact details</h2><div class="row g-3">
@foreach(['business_name'=>'Business name','business_email'=>'Email','business_website'=>'Website'] as $field=>$label)<div class="col-md-4"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-control" id="{{ $field }}" name="{{ $field }}" type="{{ $field==='business_email'?'email':($field==='business_website'?'url':'text') }}" value="{{ old($field,$settings[$field] ?? '') }}" maxlength="180" @required($field==='business_name')></div>@endforeach
<div class="col-md-6"><label class="form-label" for="business_phone">Contact numbers &amp; names</label><textarea id="business_phone" name="business_phone" class="form-control" rows="3" maxlength="500">{{ old('business_phone',$settings['business_phone'] ?? '') }}</textarea><div class="form-text">One contact per line, for example: 0702 898 694 - Winnie.</div></div>
<div class="col-md-6"><label class="form-label" for="business_address">Business address</label><textarea id="business_address" name="business_address" class="form-control" rows="3" maxlength="1000">{{ old('business_address',$settings['business_address'] ?? '') }}</textarea></div>
</div></section>
<section class="card p-4 mb-4"><h2 class="h5">Payment information &amp; default invoice text</h2><p class="text-muted small">Company contact and payment information is used when displaying invoices. Notes and terms prefill new invoices; saved invoice notes and terms are kept.</p>
@foreach(['payment_instructions'=>['Payment details',$settings['payment_instructions'] ?? '',3],'default_notes'=>['Default notes',$defaults['notes'],3],'default_terms'=>['Default terms & conditions',$defaults['terms'],12]] as $field=>$options)<label class="form-label" for="{{ $field }}">{{ $options[0] }}</label><textarea id="{{ $field }}" name="{{ $field }}" class="form-control mb-3" rows="{{ $options[2] }}" maxlength="5000">{{ old($field,$options[1]) }}</textarea>@endforeach
<div class="form-text">Separate groups of terms with a blank line. They will be arranged in two boxes on invoices, with additional rows for longer terms.</div></section>
<button class="btn btn-primary" type="submit">Save invoice settings</button><a class="btn btn-outline-secondary" href="{{ site_url('invoices') }}">Cancel</a>
</form>
@endsection
