@extends('layouts.main')
@section('content')
<div class="d-flex justify-content-between flex-wrap gap-2 mb-4"><div><a href="{{ site_url('quotations') }}">&larr; Quotations</a><h1 class="h3 mt-2">New independent quotation</h1><p class="text-muted">Prepare a proposal for a customer or company. No reservation is needed.</p></div></div>
<form method="post" action="{{ site_url('quotations') }}" id="quotation-form">@csrf
<section class="card p-4 mb-3"><h2 class="h5">Customer details</h2><div class="row g-3">
@foreach(['customer_name'=>'Customer or company name','customer_email'=>'Email (optional)','customer_phone'=>'Phone (optional)'] as $field=>$label)<div class="col-md-4"><label class="form-label" for="{{ $field }}">{{ $label }}</label><input class="form-control" id="{{ $field }}" name="{{ $field }}" type="{{ $field==='customer_email'?'email':'text' }}" maxlength="{{ $field==='customer_phone'?60:180 }}" value="{{ old($field) }}" @required($field==='customer_name')></div>@endforeach
<div class="col-12"><label class="form-label" for="customer_address">Billing address (optional)</label><textarea class="form-control" name="customer_address" id="customer_address" maxlength="1000" rows="2">{{ old('customer_address') }}</textarea></div>
<div class="col-md-6"><label class="form-label" for="issue_date">Quotation date</label><input class="form-control" id="issue_date" name="issue_date" type="date" value="{{ old('issue_date',date('Y-m-d')) }}" required></div><div class="col-md-6"><label class="form-label" for="valid_until">Valid until</label><input class="form-control" id="valid_until" name="valid_until" type="date" value="{{ old('valid_until',date('Y-m-d',strtotime('+7 days'))) }}" required></div>
</div></section>
<section class="card p-4 mb-3"><div class="d-flex justify-content-between gap-2"><h2 class="h5">Quotation items</h2><button type="button" id="add-quote-item" class="btn btn-outline-primary btn-sm">+ Add item</button></div><p class="text-muted small">Describe the accommodation, dates or services being offered. Prices are in {{ app('currentTenant')->currency ?: 'KES' }}.</p>
<div class="table-responsive"><table class="table align-middle"><thead><tr><th>Description</th><th>Quantity</th><th>Unit price</th><th>Amount</th><th>Action</th></tr></thead><tbody id="quote-items">
@foreach(old('items',[['description'=>'','quantity'=>'1','price'=>'0']]) as $index=>$item)
<tr><td><input class="form-control" name="items[{{ $index }}][description]" aria-label="Description" maxlength="1000" value="{{ $item['description'] ?? '' }}" required></td><td><input class="form-control" name="items[{{ $index }}][quantity]" aria-label="Quantity" type="number" min="0.01" max="100000" step="0.01" value="{{ $item['quantity'] ?? 1 }}" required></td><td><input class="form-control" name="items[{{ $index }}][price]" aria-label="Unit price" type="number" min="0" max="100000000" step="0.01" value="{{ $item['price'] ?? 0 }}" required></td><td class="line-amount text-nowrap">0.00</td><td><button type="button" class="btn btn-outline-danger btn-sm remove-quote-item">Remove</button></td></tr>
@endforeach
</tbody></table></div><div class="text-end fw-bold">Total: {{ app('currentTenant')->currency ?: 'KES' }} <span id="quote-total">0.00</span></div></section>
<section class="card p-4 mb-3"><label class="form-label" for="notes">Notes (optional)</label><textarea id="notes" name="notes" class="form-control mb-3" rows="3" maxlength="5000">{{ old('notes') }}</textarea><label class="form-label" for="terms">Terms and conditions</label><textarea id="terms" name="terms" class="form-control" rows="3" maxlength="5000">{{ old('terms',$settings->default_terms ?? '') }}</textarea></section>
<button class="btn btn-primary" type="submit">Save quotation</button> <a class="btn btn-outline-secondary" href="{{ site_url('quotations') }}">Cancel</a><p class="text-muted small mt-3">Saving creates a numbered quotation. It does not reserve an apartment or create an invoice or payment.</p>
</form>
@endsection
@section('scripts')
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const body=document.getElementById('quote-items');let index=Math.max(0,...Array.from(body.querySelectorAll('input')).map(i=>Number(i.name.match(/\[(\d+)\]/)?.[1])||0))+1;
 function calculate(){let total=0;const rows=body.querySelectorAll('tr');rows.forEach(row=>{const quantity=row.querySelector('[name$="[quantity]"]').value,price=row.querySelector('[name$="[price]"]').value;const value=Math.round(Math.round(Number(quantity)*100)*Math.round(Number(price)*100)/100);total+=value;row.querySelector('.line-amount').textContent=(value/100).toFixed(2);row.querySelector('button').disabled=rows.length===1});document.getElementById('quote-total').textContent=(total/100).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});document.getElementById('add-quote-item').disabled=rows.length>=30;}
 body.addEventListener('input',calculate);body.addEventListener('click',e=>{if(e.target.classList.contains('remove-quote-item')&&body.rows.length>1){e.target.closest('tr').remove();calculate()}});
 document.getElementById('add-quote-item').addEventListener('click',()=>{if(body.rows.length>=30)return;const row=body.rows[0].cloneNode(true);row.querySelectorAll('input').forEach(input=>{input.name=input.name.replace(/\[\d+\]/,'['+index+']');input.value=input.name.endsWith('[quantity]')?'1':input.name.endsWith('[price]')?'0':''});index++;body.appendChild(row);calculate();row.querySelector('input').focus()});calculate();
});
</script>
@endsection
