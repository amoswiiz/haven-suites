@extends('layouts/main')
@section('styles')
<style>
.hs-invoice-sheet{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:32px;max-width:1000px;margin:auto;color:#243247}
.hs-invoice-title{color:#294780;font-weight:800}.hs-invoice-accent{border-top:4px solid #E98D50}.hs-invoice-sheet th{background:#eef2f8;color:#294780;font-size:12px;text-transform:uppercase}.hs-invoice-total{color:#294780;font-weight:800}.hs-invoice-meta{font-size:14px;color:#64748b}
.hs-payment-panel{max-width:1000px;margin:18px auto 0;background:#fff;border:1px solid #dfe5ef;border-left:4px solid #E98D50;border-radius:12px;padding:24px;color:#243247}.hs-payment-panel h5{color:#294780;font-weight:800}.hs-payment-submit{background:#294780;border-color:#294780}.hs-payment-submit:hover{background:#203966;border-color:#203966}.hs-customer-panel{max-width:1000px;margin:18px auto 0;background:#fff;border:1px solid #dfe5ef;border-left:4px solid #294780;border-radius:12px;padding:24px;color:#243247}.hs-customer-panel h5{color:#294780;font-weight:800}.hs-customer-panel .btn-primary{background:#294780;border-color:#294780;color:#fff}.hs-customer-panel .btn-primary:hover{background:#203966;border-color:#203966}.hs-invoice-logo{display:block;width:200px;max-width:100%;height:auto;object-fit:contain;margin-bottom:12px}
@media print{.hs-payment-panel,.hs-customer-panel{display:none!important}.sidebar,.hs-sidebar,nav,.navbar,.topbar,.hs-topbar,.no-print,header,footer{display:none!important}body,.main-content,.content-wrapper,.container-fluid{background:#fff!important;margin:0!important;padding:0!important;max-width:none!important}.hs-invoice-sheet{border:0;border-radius:0;box-shadow:none!important;padding:0;max-width:none}.hs-invoice-logo{width:200px!important}.hs-invoice-sheet th,.hs-invoice-accent{-webkit-print-color-adjust:exact;print-color-adjust:exact}@page{size:A4;margin:15mm}}
</style>
@endsection
@section('content')
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
 <?php if (haven_can_access(site_url('invoices'), 'GET')): ?><a href="<?= site_url('invoices') ?>" class="text-decoration-none">&larr; All Invoices</a><?php endif; ?>
 <div class="d-flex gap-2 flex-wrap">
  <?php if ($invoice['status'] === 'issued' && $canManage && (float)$balance > 0): ?><a class="btn btn-primary" style="background:#294780;border-color:#294780" href="#record-invoice-payment">+ Record Payment</a><?php endif; ?>
  <?php if ($invoice['status'] === 'issued'): ?><?php if ($canManage && !empty($invoice['reservation_id']) && haven_can_access(site_url('invoices/' . (int) $invoice['id'] . '/payments'), 'GET')): ?><a class="btn btn-outline-secondary" href="<?= site_url('invoices/' . (int) $invoice['id'] . '/payments') ?>">Manage Payments</a><?php endif; ?><a class="btn btn-primary" href="{{ site_url('invoices/'.$invoice['id'].'/pdf') }}">Download PDF</a><button class="btn btn-outline-primary" onclick="window.print()">Print</button><?php endif; ?>
  <?php if ($invoice['status'] === 'draft' && $canManage): ?>
   <?php if (haven_can_access(site_url('invoices/' . (int) $invoice['id'] . '/issue'), 'POST')): ?><form method="post" action="<?= site_url('invoices/' . (int) $invoice['id'] . '/issue') ?>" onsubmit="return confirm('Issue this invoice? It will receive an official invoice number and cannot be returned to draft.');">
    <?= csrf_field() ?><button class="btn btn-primary" style="background:#294780;border-color:#294780">Issue Invoice</button>
   </form><?php endif; ?>
  <?php endif; ?>
 </div>
</div>
<?php if (haven_session()->getFlashdata('success')): ?><div class="alert alert-success no-print" style="max-width:1000px;margin:0 auto 16px"><?= esc(haven_session()->getFlashdata('success')) ?></div><?php endif; ?>
<?php if (haven_session()->getFlashdata('error')): ?><div class="alert alert-danger no-print" style="max-width:1000px;margin:0 auto 16px"><?= esc(haven_session()->getFlashdata('error')) ?></div><?php endif; ?>
@if($invoice['status']==='draft' && empty($settings['id']))<div class="alert alert-warning no-print">Complete company invoice settings before issuing this draft. @if(haven_can_access(site_url('invoices/settings'),'GET'))<a href="{{ site_url('invoices/settings') }}">Open invoice settings</a>@endif</div>@endif
@include('invoices.document')

<?php if (($invoice['status'] ?? '') !== 'void'): ?>
<section class="hs-customer-panel no-print" aria-labelledby="hs-customer-heading">
 <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div><h5 id="hs-customer-heading" class="mb-1">Linked Customer</h5><p class="text-muted small mb-0">Connect this invoice to an existing customer profile. The invoice billing details and payments will not change.</p></div>
  <?php if (!empty($linkedCustomer)): ?><?php if (haven_can_access(site_url('customers/' . (int) $linkedCustomer['id']), 'GET')): ?><a class="btn btn-sm btn-outline-primary" href="<?= site_url('customers/' . (int) $linkedCustomer['id']) ?>">View Customer Profile</a><?php endif; ?><?php endif; ?>
 </div>
 <?php if (!empty($linkedCustomer)): ?>
  <div class="mb-3"><strong><?= esc($linkedCustomer['full_name'] ?? '') ?></strong>
   <div class="small text-muted"><?= esc($linkedCustomer['primary_phone'] ?? '') ?><?= !empty($linkedCustomer['email']) ? ' · ' . esc($linkedCustomer['email']) : '' ?></div>
  </div>
 <?php else: ?><p class="small text-muted mb-3">No customer profile is linked to this invoice yet.</p><?php endif; ?>
 <?php if ($canManage): ?>
  <?php if (haven_can_access(site_url('invoices/' . (int) $invoice['id'] . '/customer'), 'POST')): ?><form method="post" action="<?= site_url('invoices/' . (int) $invoice['id'] . '/customer') ?>">
   <?= csrf_field() ?>
   <label for="hs-customer-search" class="form-label fw-semibold">Find an existing customer</label>
   <input type="search" id="hs-customer-search" class="form-control mb-2" placeholder="Search name, phone or email..." autocomplete="off" aria-controls="hs-customer-id">
   <div class="d-flex flex-wrap gap-2 align-items-end">
    <div class="flex-grow-1">
     <label for="hs-customer-id" class="form-label small">Select customer</label>
     <select id="hs-customer-id" name="customer_id" class="form-select" required>
      <option value="">Choose a customer</option>
      <?php foreach (($customers ?? []) as $customer): ?>
       <option value="<?= (int) $customer['id'] ?>" <?= (int) ($invoice['customer_id'] ?? 0) === (int) $customer['id'] ? 'selected' : '' ?>><?= esc($customer['full_name'] . ' · ' . ($customer['primary_phone'] ?? '') . ' · ' . ($customer['email'] ?? '')) ?></option>
      <?php endforeach; ?>
     </select>
    </div>
    <button type="submit" class="btn btn-primary">Save Customer Link</button>
   </div>
  </form><?php endif; ?>
 <?php endif; ?>
</section>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
 const search = document.getElementById('hs-customer-search');
 const select = document.getElementById('hs-customer-id');
 if (!search || !select) return;
 const options = Array.from(select.options).slice(1);
 search.addEventListener('input', function () {
  const query = search.value.trim().toLowerCase();
  options.forEach(function (option) { option.hidden = !option.textContent.toLowerCase().includes(query); });
 });
});
</script>

<?php if ($invoice['status'] === 'issued' && $canManage && (float) $balance > 0): ?>
<section id="record-invoice-payment" class="hs-payment-panel no-print" aria-labelledby="record-payment-heading">
 <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
  <div><h5 id="record-payment-heading" class="mb-1">Record Payment</h5><p class="small text-muted mb-0">Record a new payment and automatically allocate it to this invoice. A separate receipt will be generated.</p></div>
  <div class="text-end"><div class="small text-muted">Outstanding balance</div><div class="fw-bold" style="color:#294780"><?= esc($invoice['currency'] ?? 'KES') ?> <?= number_format((float)$balance, 2) ?></div></div>
 </div>
 <?php if (haven_can_access(site_url('invoices/' . (int)$invoice['id'] . '/payments/record'), 'POST')): ?><form method="post" action="<?= site_url('invoices/' . (int)$invoice['id'] . '/payments/record') ?>" onsubmit="return confirm('Confirm this payment has actually been received? Recording it will create a payment and receipt.');">
  <?= csrf_field() ?>
  <div class="row g-3">
   <div class="col-md-4"><label for="hs-payment-amount" class="form-label fw-semibold">Amount (KES) *</label><input id="hs-payment-amount" name="amount" type="number" class="form-control" min="0.01" max="<?= number_format((float)$balance, 2, '.', '') ?>" step="0.01" inputmode="decimal" value="<?= esc(old('amount') ?? '', 'attr') ?>" placeholder="0.00" required></div>
   <div class="col-md-4"><label for="hs-payment-date" class="form-label fw-semibold">Payment Date *</label><input id="hs-payment-date" name="payment_date" type="date" class="form-control" value="<?= esc(old('payment_date') ?: date('Y-m-d'), 'attr') ?>" required></div>
   <div class="col-md-4"><label for="hs-payment-method" class="form-label fw-semibold">Payment Method *</label><select id="hs-payment-method" name="payment_method" class="form-select" required><option value="">Choose method</option><?php foreach (['Mpesa' => 'M-Pesa', 'Bank Transfer' => 'Bank Transfer', 'Cash' => 'Cash', 'Card' => 'Card', 'Other' => 'Other'] as $value => $label): ?><option value="<?= esc($value, 'attr') ?>" <?= old('payment_method') === $value ? 'selected' : '' ?>><?= esc($label) ?></option><?php endforeach; ?></select></div>
   <div class="col-md-6"><label for="hs-payment-reference" class="form-label fw-semibold">Transaction Reference</label><input id="hs-payment-reference" name="reference" type="text" class="form-control" maxlength="100" value="<?= esc(old('reference') ?? '', 'attr') ?>" placeholder="M-Pesa code or bank reference"><div class="form-text">Recommended for electronic payments to prevent duplicate entries.</div></div>
   <div class="col-md-6"><label for="hs-payment-notes" class="form-label fw-semibold">Notes (optional)</label><textarea id="hs-payment-notes" name="notes" class="form-control" rows="2" maxlength="5000" placeholder="Additional payment details"><?= esc(old('notes') ?? '') ?></textarea></div>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center mt-3"><button type="submit" class="btn btn-primary hs-payment-submit">Save Payment &amp; Generate Receipt</button><span class="small text-muted">Only record payments already received. Do not submit twice.</span></div>
 </form><?php endif; ?>
</section>
<?php endif; ?>
@endsection
