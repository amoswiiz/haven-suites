@extends('layouts.main')
@section('content')
<div class="container py-3" style="max-width:720px">
<h1>{{ $bill ? 'Correct your bill' : 'Submit a bill' }}</h1>
<p>For company purchases, equipment, supplies and utility refills. Choose an apartment only when the cost belongs to that apartment.</p>
<form method="post" enctype="multipart/form-data" action="{{ $bill ? route('staff-bills.update',[app('currentTenant')->slug,$bill->id]) : route('staff-bills.store',app('currentTenant')->slug) }}">
@csrf
<input type="hidden" name="submission_token" value="{{ $token }}">
@if($bill)<input type="hidden" name="version" value="{{ $bill->version }}">@endif
<label class="form-label" for="unit_id">Apartment (optional)</label><select class="form-select mb-3" id="unit_id" name="unit_id"><option value="">General company expense / shared equipment</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected(old('unit_id',$bill?->unit_id)==$unit->id)>{{ $unit->property_name }} — {{ $unit->unit_number }}</option>@endforeach</select>
<label class="form-label" for="purchase_date">Purchase date</label><input class="form-control mb-3" id="purchase_date" name="purchase_date" type="date" max="{{ now()->toDateString() }}" value="{{ old('purchase_date',$bill?->purchase_date ?? now()->toDateString()) }}" required>
<label class="form-label" for="category">Category</label><select class="form-select mb-3" id="category" name="category" required>@foreach($categories as $category)<option @selected(old('category',$bill?->category)===$category)>{{ $category }}</option>@endforeach</select>
<label class="form-label" for="description">What did you buy?</label><input class="form-control mb-3" id="description" name="description" maxlength="255" value="{{ old('description',$bill?->description) }}" placeholder="e.g. Two water refills and hand soap" required>
<label class="form-label" for="supplier">Supplier / shop</label><input class="form-control mb-3" id="supplier" name="supplier" maxlength="150" value="{{ old('supplier',$bill?->supplier) }}" required>
<label class="form-label" for="amount">Total amount (KES)</label><input class="form-control mb-3" id="amount" name="amount" type="number" step="0.01" min="0.01" max="9999999.99" value="{{ old('amount',$bill ? number_format($bill->amount_minor/100,2,'.','') : '') }}" required>
<label class="form-label" for="payer">Who paid?</label><select class="form-select mb-3" id="payer" name="payer" required>@foreach(['personal'=>'I paid — reimbursement needed','company'=>'Paid using company funds','supplier'=>'Not paid — supplier needs payment'] as $value=>$label)<option value="{{ $value }}" @selected(old('payer',$bill?->payer)===$value)>{{ $label }}</option>@endforeach</select>
<label class="form-label" for="receipt">Receipt photo or bill (JPG, PNG or PDF, maximum 5 MB)</label><input class="form-control mb-3" id="receipt" name="receipt" type="file" accept="image/jpeg,image/png,application/pdf" @required(!$bill)>
@if($bill)<p>Leave the upload empty to keep your previous receipt. Earlier versions remain in the review history.</p>@endif
<button class="btn btn-primary">Submit for review</button>
<a class="btn btn-link" href="{{ route('staff-bills.index',app('currentTenant')->slug) }}">Back to bills</a>
</form></div>
@endsection
