<?= $this->extend('layouts/main') ?>
<?= $this->section('styles') ?>
<style>
.hs-invoice-title{color:#294780}.hs-invoice-section{border-top:3px solid #294780}.hs-invoice-primary{background:#294780;border-color:#294780}.hs-invoice-primary:hover{background:#203963;border-color:#203963}.hs-invoice-items th{background:#f0f4fa;color:#294780}.hs-invoice-accent{color:#c46b32}
</style>
<?= $this->endSection() ?>
<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
 <div><a href="<?= site_url('invoices') ?>" class="small text-decoration-none">&larr; Invoice Directory</a><h3 class="fw-bold mt-2 hs-invoice-title">Create Invoice Draft</h3><p class="text-muted mb-0">Choose a reservation or bill a customer directly.</p></div>
</div>
<?php if (session()->getFlashdata('error')): ?><div class="alert alert-danger"><?= esc(session()->getFlashdata('error')) ?></div><?php endif; ?>
<form method="post" action="<?= site_url('invoices') ?>" id="invoice-create-form">
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
   <option value="<?= (int) $r['id'] ?>" <?= (string) old('reservation_id') === (string) $r['id'] ? 'selected' : '' ?>
    data-name="<?= esc($r['guest_name'], 'attr') ?>" data-phone="<?= esc($r['guest_phone'] ?? '', 'attr') ?>" data-email="<?= esc($r['guest_email'] ?? '', 'attr') ?>" data-total="<?= esc($r['total_amount'], 'attr') ?>" data-in="<?= esc($r['check_in'], 'attr') ?>" data-out="<?= esc($r['check_out'], 'attr') ?>">
    #<?= (int) $r['id'] ?> — <?= esc($r['guest_name']) ?> (<?= esc($r['check_in']) ?> to <?= esc($r['check_out']) ?>)
   </option><?php endforeach; ?>
   </select><div class="form-text">Guest details and accommodation amount are taken from the reservation when you save. Cancelled bookings are excluded.</div>
   <div class="mt-3 rounded p-3" style="background:#f0f4fa" id="reservation-preview">Select a reservation to preview its details.</div>
  </div>
  <div id="standalone-fields" style="display:none"><div class="row g-3">
   <div class="col-md-4"><label class="form-label" for="customer_name">Customer Name</label><input class="form-control" id="customer_name" name="customer_name" maxlength="180" value="<?= esc(old('customer_name') ?? '', 'attr') ?>"></div>
   <div class="col-md-4"><label class="form-label" for="customer_email">Customer Email (optional)</label><input class="form-control" id="customer_email" type="email" name="customer_email" maxlength="180" value="<?= esc(old('customer_email') ?? '', 'attr') ?>"></div>
   <div class="col-md-4"><label class="form-label" for="customer_phone">Phone (optional)</label><input class="form-control" id="customer_phone" name="customer_phone" maxlength="60" value="<?= esc(old('customer_phone') ?? '', 'attr') ?>"></div>
  </div></div>
 </div></div>
 <div class="card border-0 shadow-sm mb-4"><div class="card-body"><h5 class="fw-bold mb-3">Invoice Dates</h5><div class="row g-3">
  <div class="col-md-6"><label for="issue_date" class="form-label">Invoice Date</label><input type="date" id="issue_date" name="issue_date" class="form-control" required value="<?= esc(old('issue_date') ?? date('Y-m-d'), 'attr') ?>"></div>
  <div class="col-md-6"><label for="due_date" class="form-label">Due Date</label><input type="date" id="due_date" name="due_date" class="form-control" required value="<?= esc(old('due_date') ?? date('Y-m-d'), 'attr') ?>"></div>
 </div></div></div>
 <div class="card border-0 shadow-sm mb-4" id="standalone-items" style="display:none"><div class="card-body">
  <div class="d-flex justify-content-between gap-3 align-items-center mb-3"><h5 class="fw-bold mb-0">Invoice Items</h5><button type="button" class="btn btn-outline-primary btn-sm" id="add-item">+ Add Item</button></div>
  <div class="table-responsive"><table class="table align-middle hs-invoice-items"><thead><tr><th>Description</th><th style="width:110px">Quantity</th><th style="width:150px">Rate (KES)</th><th class="text-end" style="width:140px">Amount</th><th></th></tr></thead><tbody id="items-body"></tbody></table></div>
  <div class="text-end fw-bold hs-invoice-accent">Subtotal: KES <span id="items-total">0.00</span></div>
 </div></div>
 <div class="card border-0 shadow-sm mb-4"><div class="card-body"><label class="form-label" for="notes">Notes (optional)</label><textarea class="form-control" id="notes" name="notes" rows="3" maxlength="5000"><?= esc(old('notes') ?? '') ?></textarea></div></div>
 <div class="d-flex flex-wrap gap-2"><button class="btn btn-primary hs-invoice-primary" type="submit">Save Draft Invoice</button><a class="btn btn-outline-secondary" href="<?= site_url('invoices') ?>">Cancel</a></div>
 <p class="text-muted small mt-3">Drafts have no invoice number and do not allocate payments or alter booking totals. Issuing and PDF printing come in the next stage.</p>
</form>
<?= $this->endSection() ?>
<?= $this->section('scripts') ?>
<script>
document.addEventListener('DOMContentLoaded', function(){
 const form=document.getElementById('invoice-create-form'), reservation=document.getElementById('reservation_id');
 const body=document.getElementById('items-body'), total=document.getElementById('items-total');
 function updateType(){const standalone=document.querySelector('input[name="invoice_type"]:checked').value==='standalone';
 document.getElementById('reservation-fields').style.display=standalone?'none':'';
 document.getElementById('standalone-fields').style.display=standalone?'':'none';
 document.getElementById('standalone-items').style.display=standalone?'':'none';
 reservation.required=!standalone; document.getElementById('customer_name').required=standalone;
 body.querySelectorAll('input').forEach(input=>input.disabled=!standalone);
 }
 function preview(){const option=reservation.selectedOptions[0], p=document.getElementById('reservation-preview');
 p.textContent=option && option.value ? option.dataset.name+' | '+option.dataset.in+' to '+option.dataset.out+' | Booking total: KES '+Number(option.dataset.total||0).toLocaleString('en-KE',{minimumFractionDigits:2}) : 'Select a reservation to preview its details.';
 }
 function calculate(){let sum=0;body.querySelectorAll('tr').forEach(tr=>{const q=Number(tr.querySelector('[name="item_quantity[]"]').value)||0,p=Number(tr.querySelector('[name="item_price[]"]').value)||0;const value=Math.round(q*p*100)/100;sum+=value;tr.querySelector('.line-total').textContent=value.toFixed(2)});total.textContent=sum.toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});}
 function addRow(){if(body.children.length>=30)return;const tr=document.createElement('tr');tr.innerHTML='<td><input name="item_description[]" class="form-control" maxlength="1000" required placeholder="Description"></td><td><input name="item_quantity[]" class="form-control" type="number" min="0.01" step="0.01" value="1" required></td><td><input name="item_price[]" class="form-control" type="number" min="0" step="0.01" value="0" required></td><td class="text-end line-total">0.00</td><td><button type="button" class="btn btn-sm btn-outline-danger remove-item">Remove</button></td>';body.appendChild(tr);tr.querySelectorAll('input').forEach(input=>input.addEventListener('input',calculate));tr.querySelector('button').addEventListener('click',()=>{if(body.children.length>1){tr.remove();calculate()}});updateType();calculate()}
 document.querySelectorAll('input[name="invoice_type"]').forEach(el=>el.addEventListener('change',updateType));
 document.getElementById('add-item').addEventListener('click',addRow);reservation.addEventListener('change',preview);
 addRow();updateType();preview();
 form.addEventListener('submit',function(e){if(document.querySelector('input[name="invoice_type"]:checked').value==='standalone'&&body.children.length===0){e.preventDefault();alert('Add at least one invoice item.')}});
});
</script>
<?= $this->endSection() ?>
