@extends('layouts/main')
@section('styles')
<style>
.hs-invoice-title{color:#294780}.hs-invoice-section{border-top:3px solid #294780}.hs-invoice-primary{background:#294780;border-color:#294780}.hs-invoice-primary:hover{background:#203963;border-color:#203963}.hs-invoice-items th{background:#f0f4fa;color:#294780}.hs-invoice-accent{color:#c46b32}
</style>
@endsection
@section('content')
@if(auth()->user()->inGroup('admin','superadmin') && haven_can_access(site_url('invoices/settings'),'GET'))<div class="text-end mb-3"><a class="btn btn-outline-secondary btn-sm" href="{{ site_url('invoices/settings') }}">Invoice settings</a></div>@endif
@if(empty($settings['id']))<div class="alert alert-warning">Invoice numbering is not configured for this company. @if(haven_can_access(site_url('invoices/settings'),'GET'))<a href="{{ site_url('invoices/settings') }}">Complete invoice settings</a>@else Ask an administrator to complete invoice settings.@endif You can prepare a draft, but it cannot be issued yet.</div>@endif
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
 <div><?php if (haven_can_access(site_url('invoices'), 'GET')): ?><a href="<?= site_url('invoices') ?>" class="small text-decoration-none">&larr; Invoice Directory</a><?php endif; ?><h3 class="fw-bold mt-2 hs-invoice-title">Create Invoice Draft</h3><p class="text-muted mb-0">Choose a reservation or bill a customer directly.</p></div>
</div>
<?php if (haven_session()->getFlashdata('error')): ?><div class="alert alert-danger"><?= esc(haven_session()->getFlashdata('error')) ?></div><?php endif; ?>
<?php if (haven_can_access(site_url('invoices'), 'POST')): ?><form method="post" action="<?= site_url('invoices') ?>" id="invoice-create-form">
 <?= csrf_field() ?>
 <div class="card border-0 shadow-sm hs-invoice-section mb-4"><div class="card-body">
  <h5 class="fw-bold mb-3">Invoice Type</h5>
  <div class="d-flex gap-4 flex-wrap mb-3">
   <label><input type="radio" name="invoice_type" value="reservation" <?= old('invoice_type', 'reservation') === 'reservation' ? 'checked' : '' ?>> Reservation Invoice</label>
   <label><input type="radio" name="invoice_type" value="standalone" <?= old('invoice_type') === 'standalone' ? 'checked' : '' ?>> Standalone Invoice</label>
  </div>
  <div id="reservation-fields"><label class="form-label" for="reservation_id">Select Reservation</label>
   <select class="form-select" name="reservation_id" id="reservation_id"><option value="">Choose a booking</option>
   <?php foreach ($reservations as $r): ?>
   <option value="<?= (int) $r['id'] ?>" <?= (string) old('reservation_id', $selectedReservation ?? '') === (string) $r['id'] ? 'selected' : '' ?>
    data-name="<?= esc($r['guest_name'], 'attr') ?>" data-phone="<?= esc($r['guest_phone'] ?? '', 'attr') ?>" data-email="<?= esc($r['guest_email'] ?? '', 'attr') ?>" data-total="<?= esc($r['total_amount'], 'attr') ?>" data-in="<?= esc($r['check_in'], 'attr') ?>" data-out="<?= esc($r['check_out'], 'attr') ?>">
    #<?= (int) $r['id'] ?> — <?= esc($r['guest_name']) ?> (<?= esc($r['check_in']) ?> to <?= esc($r['check_out']) ?>)
   </option><?php endforeach; ?>
   </select><div class="form-text">Billing details are filled from the booking for review below. Accommodation amounts remain linked to the reservation.</div>
   <div class="mt-3 rounded p-3" style="background:#f0f4fa" id="reservation-preview">Select a reservation to preview its details.</div>
  </div>
  <div id="billing-fields" class="mt-4"><h5 class="fw-bold mb-3">Bill to</h5><div class="row g-3">
   <div class="col-md-4"><label class="form-label" for="customer_name">Customer Name</label><input class="form-control" id="customer_name" name="customer_name" maxlength="180" value="<?= esc(old('customer_name') ?? '', 'attr') ?>"></div>
   <div class="col-md-4"><label class="form-label" for="customer_email">Customer Email (optional)</label><input class="form-control" id="customer_email" type="email" name="customer_email" maxlength="180" value="<?= esc(old('customer_email') ?? '', 'attr') ?>"></div>
   <div class="col-md-4"><label class="form-label" for="customer_phone">Phone (optional)</label><input class="form-control" id="customer_phone" name="customer_phone" maxlength="60" value="<?= esc(old('customer_phone') ?? '', 'attr') ?>"></div>
   <div class="col-12"><label for="customer_address" class="form-label">Billing address (optional)</label><textarea id="customer_address" name="customer_address" class="form-control" maxlength="1000" rows="2">{{ old('customer_address') }}</textarea></div>
  </div></div>
 </div></div>
 <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h5 class="fw-bold mb-3">Invoice Dates</h5><div class="row g-3">
  <div class="col-md-6"><label for="issue_date" class="form-label">Invoice Date</label><input type="date" id="issue_date" name="issue_date" class="form-control" required value="<?= esc(old('issue_date') ?? date('Y-m-d'), 'attr') ?>"></div>
  <div class="col-md-6"><label for="due_date" class="form-label">Due Date</label><input type="date" id="due_date" name="due_date" class="form-control" required value="<?= esc(old('due_date') ?? date('Y-m-d'), 'attr') ?>"></div>
 </div></div></div>
 <div class="card border-0 shadow-sm mb-4" id="invoice-items"><div class="card-body">
  <div class="d-flex justify-content-between gap-3 align-items-center mb-3"><h5 class="fw-bold mb-0">Invoice Items</h5><button type="button" class="btn btn-outline-primary btn-sm" id="add-item">+ Add Item</button></div>
  <div class="table-responsive"><table class="table align-middle hs-invoice-items"><thead><tr><th>Description</th><th style="width:110px">Quantity</th><th style="width:150px">Rate (KES)</th><th class="text-end" style="width:140px">Amount</th><th></th></tr></thead><tbody id="items-body"></tbody></table></div>
  <p id="booking-items-help" class="form-text">Accommodation amounts come from the booking. Update the reservation to change its charges.</p><div class="text-end">Subtotal: KES <span id="items-total">0.00</span><br>Discount: KES 0.00<br>Tax: KES 0.00<br><strong>Total: KES <span id="invoice-total">0.00</span></strong></div>
 </div></div>
 <div class="card border-0 shadow-sm mb-4"><div class="card-body"><label class="form-label" for="notes">Notes &amp; payment details</label><p class="form-text">Prefilled for this company. You can edit or clear this text for this invoice.</p><textarea class="form-control" id="notes" name="notes" rows="3" maxlength="5000"><?= esc(old('notes', $invoiceDefaults['notes']) ?? '') ?></textarea></div></div>
 <div class="card border-0 shadow-sm mb-4"><div class="card-body"><label for="terms" class="form-label">Terms and conditions</label><textarea id="terms" name="terms" class="form-control" rows="12" maxlength="5000">{{ old("terms", $invoiceDefaults["terms"]) }}</textarea><div class="form-text">Review the deposit, arrival times and cancellation terms before saving. Changes apply to this invoice.</div></div></div>
 <div class="d-flex flex-wrap gap-2"><button class="btn btn-primary hs-invoice-primary" type="submit">Save Draft Invoice</button><?php if (haven_can_access(site_url('invoices'), 'GET')): ?><a class="btn btn-outline-secondary" href="<?= site_url('invoices') ?>">Cancel</a><?php endif; ?></div>
 <p class="text-muted small mt-3">Drafts have no invoice number and do not allocate payments or alter booking totals. Issuing and PDF printing come in the next stage.</p>
</form><?php endif; ?>
@endsection
@section('scripts')
<script>
document.addEventListener('DOMContentLoaded', function(){
 const form=document.getElementById('invoice-create-form'), reservation=document.getElementById('reservation_id');
 const body=document.getElementById('items-body'), total=document.getElementById('items-total');
 const oldItems = @json(old('item_description', []));
 const oldQuantities = @json(old('item_quantity', []));
 const oldPrices = @json(old('item_price', []));
 const hasOldBilling = @json(session()->hasOldInput('customer_name'));
 let standaloneRows=oldItems.map((d,i)=>[d,oldQuantities[i],oldPrices[i]]);
 function standalone(){return document.querySelector('input[name="invoice_type"]:checked').value==='standalone'}
 function calculate(){let sum=0;body.querySelectorAll('tr').forEach(tr=>{const inputs=tr.querySelectorAll('input');const amount=Math.round(Number(inputs[1].value)*Number(inputs[2].value)*100)/100;sum+=amount;tr.querySelector('.line-total').textContent=amount.toFixed(2)});total.textContent=sum.toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});document.getElementById('invoice-total').textContent=total.textContent;}
 function addRow(values=['','1','0'],readonly=false){if(body.children.length>=30)return;const tr=document.createElement('tr');tr.innerHTML='<td><input name="item_description[]" class="form-control" maxlength="1000" required aria-label="Item description"></td><td><input name="item_quantity[]" class="form-control" type="number" min="0.01" step="0.01" required aria-label="Quantity"></td><td><input name="item_price[]" class="form-control" type="number" min="0" step="0.01" required aria-label="Rate"></td><td class="text-end line-total"></td><td><button type="button" class="btn btn-sm btn-outline-danger">Remove</button></td>';body.appendChild(tr);tr.querySelectorAll('input').forEach((input,i)=>{input.value=values[i]??'';input.readOnly=readonly;input.disabled=readonly;input.addEventListener('input',calculate)});tr.querySelector('button').hidden=readonly;tr.querySelector('button').addEventListener('click',()=>{if(body.children.length>1){tr.remove();calculate()}});calculate();}
 function preview(fill=true){const o=reservation.selectedOptions[0];document.getElementById('reservation-preview').textContent=o?.value?o.dataset.name+' | '+o.dataset.in+' to '+o.dataset.out:'Select a reservation to preview its details.';if(!standalone()){if(fill){['name','email','phone'].forEach(k=>document.getElementById('customer_'+k).value=o?.dataset[k]||'')}body.innerHTML='';if(o?.value)addRow(['Accommodation ? booking #'+o.value+' ('+o.dataset.in+' to '+o.dataset.out+')','1',o.dataset.total],true);calculate()}}
 function updateType(){const direct=standalone();document.getElementById('reservation-fields').hidden=direct;document.getElementById('add-item').hidden=!direct;document.getElementById('booking-items-help').hidden=direct;reservation.required=!direct;document.getElementById('customer_name').required=true;body.innerHTML='';if(direct){(standaloneRows.length?standaloneRows:[['','1','0']]).forEach(v=>addRow(v))}else{preview(false)}calculate()}
 document.querySelectorAll('input[name="invoice_type"]').forEach(el=>el.addEventListener('change',()=>{if(!standalone())standaloneRows=Array.from(body.querySelectorAll('tr')).map(tr=>Array.from(tr.querySelectorAll('input')).map(i=>i.value));updateType()}));
 document.getElementById('add-item').addEventListener('click',()=>addRow());reservation.addEventListener('change',()=>preview());updateType();preview(!hasOldBilling);
});
</script>
@endsection
