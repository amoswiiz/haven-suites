@extends('layouts/main')
@section('styles')
<style>
.hs-invoices-page{--hs-navy:#294780;--hs-orange:#e98d50;--hs-line:#e4eaf2;color:#233550}
.hs-invoices-page .hs-invoice-heading{color:#203454}
.hs-invoices-page .hs-invoice-card{border:1px solid var(--hs-line)!important;border-radius:14px;box-shadow:0 3px 14px rgba(28,50,83,.035)!important}
.hs-invoices-page .hs-invoice-card .card-body{padding:22px}
.hs-invoices-page .hs-invoice-toolbar{display:grid;grid-template-columns:minmax(210px,2fr) repeat(3,minmax(135px,1fr));gap:10px;align-items:end}
.hs-invoices-page .hs-invoice-toolbar .form-control,.hs-invoices-page .hs-invoice-toolbar .form-select,.hs-invoices-page .hs-invoice-advanced .form-control,.hs-invoices-page .hs-invoice-advanced .form-select{min-width:0;width:100%;border:1px solid #dbe3ef;border-radius:9px;min-height:42px;font-size:13px;color:#263956}
.hs-invoices-page .hs-filter-label{display:block;font-size:11px;color:#687993;font-weight:650;margin-bottom:5px}
.hs-invoices-page .hs-filter-more{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin:12px 0 17px}
.hs-invoices-page .hs-filter-toggle{border:0;background:transparent;color:var(--hs-navy);font-size:13px;font-weight:700;padding:5px 0;cursor:pointer}
.hs-invoices-page .hs-filter-toggle:hover{text-decoration:underline}
.hs-invoices-page .hs-invoice-advanced[hidden]{display:none!important}
.hs-invoices-page .hs-invoice-advanced{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:14px;margin-bottom:16px;background:#f7f9fd;border:1px solid var(--hs-line);border-radius:11px}
.hs-invoices-page .hs-invoice-table{min-width:1060px;font-size:13px;margin-bottom:0}
.hs-invoices-page .hs-invoice-table thead th{background:#f0f4fa;color:#294780;font-size:11px;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap;padding:12px 10px;border-bottom:2px solid #dce5f0}
.hs-invoices-page .hs-invoice-table td{vertical-align:middle;padding:13px 10px;border-color:#edf1f6}
.hs-invoices-page .hs-invoice-table tbody tr:hover td{background:#f8fafd}
.hs-invoices-page .hs-invoice-number{color:#294780;font-weight:750;overflow-wrap:anywhere}
.hs-invoices-page .hs-invoice-number:hover{text-decoration:underline!important}
.hs-invoices-page .hs-invoice-balance{color:#bd5c26;font-weight:750}
.hs-invoices-page .hs-invoice-status,.hs-invoices-page .hs-payment-status{border-radius:50rem;padding:.4rem .65rem;font-size:11px;font-weight:700;display:inline-block;white-space:nowrap}
.hs-invoices-page .hs-invoice-status.issued,.hs-invoices-page .hs-payment-status.paid{background:#e6f5ec;color:#187547}
.hs-invoices-page .hs-invoice-status.draft,.hs-invoices-page .hs-payment-status.na{background:#eef1f5;color:#5b6677}
.hs-invoices-page .hs-invoice-status.void,.hs-invoices-page .hs-payment-status.unpaid{background:#fbe9e8;color:#ad342c}
.hs-invoices-page .hs-payment-status.partial{background:#fff0df;color:#99521f}
.hs-invoices-page .hs-overdue{color:#b42318;font-weight:700;font-size:11px;margin-top:3px}
.hs-invoices-page .hs-invoice-actions{display:flex;flex-direction:column;align-items:flex-start;gap:5px}
.hs-invoices-page .hs-invoice-actions .btn{white-space:nowrap;font-size:12px;border-radius:7px;padding:5px 9px}
.hs-invoices-page .hs-invoice-actions .btn-outline-primary{border-color:#b8c8e5;color:#294780;background:#fff}
.hs-invoices-page .hs-invoice-actions .btn-outline-primary:hover{background:#294780;color:#fff}
.hs-invoices-page .hs-invoice-actions .btn-outline-secondary{border-color:#d6dfeb;color:#425675;background:#fff}
.hs-invoices-page .hs-invoice-actions .btn-outline-secondary:hover{background:#eaf0fa;color:#294780}
.hs-invoices-page .hs-invoice-help{border-top:1px solid var(--hs-line);padding-top:12px;line-height:1.6}
@media(max-width:1100px){.hs-invoices-page .hs-invoice-toolbar{grid-template-columns:repeat(3,minmax(0,1fr))}.hs-invoices-page .hs-invoice-search-wrap{grid-column:1/-1}}
@media(max-width:650px){.hs-invoices-page .hs-invoice-toolbar{grid-template-columns:1fr 1fr}.hs-invoices-page .hs-invoice-search-wrap{grid-column:1/-1}.hs-invoices-page .hs-invoice-advanced{grid-template-columns:1fr}.hs-invoices-page .hs-invoice-card .card-body{padding:15px}}
</style>
@endsection
@section('content')
<div class="hs-invoices-page">
<?php
$today = date('Y-m-d');
$totalIssuedAmount = 0.0;
$overdueCount = 0;
foreach ($invoices as $inv) {
    if (($inv['status'] ?? '') !== 'issued') continue;
    $totalIssuedAmount += (float) ($inv['total_amount'] ?? 0);
    if (!empty($inv['due_date']) && $inv['due_date'] < $today && (float) ($inv['balance'] ?? 0) > 0.005) $overdueCount++;
}
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
 <div><h3 class="fw-bold mb-1 hs-invoice-heading">Invoices</h3><p class="text-muted mb-0">Reservation and standalone invoices for Haven Suites.</p></div>
 <?php if (haven_auth()->user()?->can('finance.manage')): ?><a href="<?= site_url('invoices/create') ?>" class="btn btn-primary" style="background:#294780;border-color:#294780">+ Create Invoice</a><?php endif; ?>
</div>
<div class="row g-3 mb-4">
 <?php foreach ([
   ['Issued Invoices', number_format((int)($summary['issued'] ?? 0)), 'file-check'],
   ['Draft Invoices', number_format((int)($summary['draft'] ?? 0)), 'file-pen-line'],
   ['Total Invoiced (Issued)', 'KES '.number_format($totalIssuedAmount, 2), 'files'],
   ['Allocated Payments', 'KES '.number_format((float)($summary['paid'] ?? 0), 2), 'wallet'],
   ['Outstanding (Issued)', 'KES '.number_format((float)($summary['outstanding'] ?? 0), 2), 'receipt'],
   ['Overdue Invoices', number_format($overdueCount), 'alert-circle'],
 ] as [$label,$value,$icon]): ?>
 <div class="col-xl-4 col-md-6"><div class="hs-stat-card h-100"><div class="d-flex justify-content-between align-items-start"><div><div class="hs-stat-label"><?= esc($label) ?></div><div class="hs-stat-value" style="font-size:23px"><?= esc($value) ?></div></div><div class="hs-stat-icon"><i data-lucide="<?= esc($icon,'attr') ?>"></i></div></div></div></div>
 <?php endforeach; ?>
</div>
<div class="card border-0 shadow-sm hs-invoice-card"><div class="card-body">
 <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
  <div><h5 class="fw-bold mb-1 hs-invoice-heading">Invoice Directory</h5><p class="small text-muted mb-0">Find invoices and manage their payments.</p></div>
  <span class="small text-muted" id="invoice-total-label"><?= count($invoices) ?> total invoices</span>
 </div>
 <div class="hs-invoice-toolbar">
  <div class="hs-invoice-search-wrap"><label class="hs-filter-label" for="invoice-search">Search</label><input id="invoice-search" type="search" class="form-control" placeholder="Invoice number, customer or booking..." aria-label="Search invoices"></div>
  <div><label class="hs-filter-label" for="invoice-type">Invoice type</label><select id="invoice-type" class="form-select"><option value="">All types</option><option value="reservation">Reservation</option><option value="standalone">Standalone</option></select></div>
  <div><label class="hs-filter-label" for="invoice-status">Invoice status</label><select id="invoice-status" class="form-select"><option value="">All statuses</option><option value="draft">Draft</option><option value="issued">Issued</option><option value="void">Void</option></select></div>
  <div><label class="hs-filter-label" for="invoice-payment-status">Payment status</label><select id="invoice-payment-status" class="form-select"><option value="">All payments</option><option value="unpaid">Unpaid</option><option value="partial">Partially paid</option><option value="paid">Paid</option><option value="na">Not applicable</option></select></div>
 </div>
 <div class="hs-filter-more">
  <button id="invoice-advanced-toggle" type="button" class="hs-filter-toggle" aria-expanded="false" aria-controls="invoice-advanced">More filters <span id="invoice-advanced-arrow" aria-hidden="true">▾</span></button>
  <button id="invoice-reset" type="button" class="btn btn-sm btn-outline-secondary">Clear filters</button>
 </div>
 <div class="hs-invoice-advanced" id="invoice-advanced" hidden>
  <div><label class="hs-filter-label" for="invoice-due">Due date</label><select id="invoice-due" class="form-select"><option value="">All due dates</option><option value="overdue">Overdue</option><option value="not-overdue">Not overdue</option></select></div>
  <div><label class="hs-filter-label" for="invoice-date-from">Issued from</label><input id="invoice-date-from" type="date" class="form-control"></div>
  <div><label class="hs-filter-label" for="invoice-date-to">Issued to</label><input id="invoice-date-to" type="date" class="form-control"></div>
 </div>
<div class="table-responsive"><table class="table table-hover align-middle hs-invoice-table"><thead><tr><th>Invoice</th><th>Customer</th><th>Type</th><th>Issued</th><th>Due</th><th>Invoice status</th><th>Payment status</th><th class="text-end">Total</th><th class="text-end">Allocated</th><th class="text-end">Balance</th><th>Actions</th></tr></thead><tbody>
 <?php foreach ($invoices as $invoice): ?>
 <?php
  $id = (int) $invoice['id'];
  $status = (string) ($invoice['status'] ?? '');
  $balance = (float) ($invoice['balance'] ?? 0);
  $paid = (float) ($invoice['paid_amount'] ?? 0);
  $paymentStatus = $status !== 'issued' ? 'na' : ($balance <= 0.005 ? 'paid' : ($paid > 0.005 ? 'partial' : 'unpaid'));
  $paymentLabel = ['na'=>'N/A','paid'=>'Paid','partial'=>'Partially paid','unpaid'=>'Unpaid'][$paymentStatus];
  $overdue = $status === 'issued' && $balance > 0.005 && !empty($invoice['due_date']) && $invoice['due_date'] < $today;
  $invoiceUrl = site_url('invoices/'.$id);
  $paymentsUrl = site_url('invoices/'.$id.'/payments');
 ?>
 <tr class="invoice-row" data-type="<?= esc($invoice['invoice_type'],'attr') ?>" data-status="<?= esc($status,'attr') ?>" data-payment="<?= esc($paymentStatus,'attr') ?>" data-overdue="<?= $overdue ? 'yes' : 'no' ?>" data-issue-date="<?= esc((string)($invoice['issue_date'] ?? ''),'attr') ?>">
  <td><a class="hs-invoice-number text-decoration-none" href="<?= $invoiceUrl ?>"><?= esc($invoice['invoice_number'] ?: 'Draft #'.$id) ?></a></td>
  <td><?= esc($invoice['customer_name']) ?></td>
  <td><?= esc(ucfirst($invoice['invoice_type'])) ?><?php if ($invoice['reservation_id']): ?><div class="small text-muted">Booking #<?= (int)$invoice['reservation_id'] ?></div><?php endif; ?></td>
  <td class="text-nowrap"><?= esc($invoice['issue_date'] ?: '—') ?></td>
  <td class="text-nowrap"><?= esc($invoice['due_date'] ?: '—') ?><?php if ($overdue): ?><div class="hs-overdue">Overdue</div><?php endif; ?></td>
  <td><span class="hs-invoice-status <?= esc($status,'attr') ?>"><?= esc(ucfirst($status)) ?></span></td>
  <td><span class="hs-payment-status <?= esc($paymentStatus,'attr') ?>"><?= esc($paymentLabel) ?></span></td>
  <td class="text-end text-nowrap"><?= esc($invoice['currency']) ?> <?= number_format((float)$invoice['total_amount'],2) ?></td>
  <td class="text-end text-nowrap"><?= esc($invoice['currency']) ?> <?= number_format($paid,2) ?></td>
  <td class="text-end text-nowrap hs-invoice-balance"><?= esc($invoice['currency']) ?> <?= number_format($balance,2) ?></td>
  <td><div class="hs-invoice-actions"><a class="btn btn-sm btn-outline-primary" href="<?= $invoiceUrl ?>">View Invoice</a><?php if ($status === 'issued'): ?><a class="btn btn-sm btn-outline-secondary" href="<?= $paymentsUrl ?>">Manage Payments / Receipts</a><?php endif; ?></div></td>
 </tr>
 <?php endforeach; ?>
 <?php if (!$invoices): ?><tr><td colspan="11" class="text-center text-muted py-5">No invoices yet. Select Create Invoice to save your first draft.</td></tr><?php endif; ?>
 <tr id="invoice-no-match" style="display:none"><td colspan="11" class="text-center text-muted py-4">No invoices match these filters.</td></tr>
 </tbody></table></div>
 <div class="small text-muted" id="invoice-count" aria-live="polite"></div>
 <p class="small text-muted mt-3 mb-0 hs-invoice-help">Allocated payments reflect invoice allocations only, not all guest payments. Financial summaries exclude draft and void invoices. Receipt links are available from the payment history on each issued invoice.</p>
</div></div>
</div><!-- /hs-invoices-page -->
@endsection
@section('scripts')
<script>
document.addEventListener('DOMContentLoaded',function(){
 const search=document.getElementById('invoice-search'),type=document.getElementById('invoice-type'),status=document.getElementById('invoice-status'),payment=document.getElementById('invoice-payment-status'),due=document.getElementById('invoice-due'),from=document.getElementById('invoice-date-from'),to=document.getElementById('invoice-date-to'),reset=document.getElementById('invoice-reset');
 const rows=Array.from(document.querySelectorAll('.invoice-row')),count=document.getElementById('invoice-count'),noMatch=document.getElementById('invoice-no-match');
 function filter(){const term=search.value.trim().toLocaleLowerCase();let visible=0;rows.forEach(function(row){const d=row.dataset.issueDate||'';const show=(!term||row.textContent.toLocaleLowerCase().includes(term))&&(!type.value||row.dataset.type===type.value)&&(!status.value||row.dataset.status===status.value)&&(!payment.value||row.dataset.payment===payment.value)&&(!due.value||(due.value==='overdue'?row.dataset.overdue==='yes':row.dataset.overdue==='no'))&&(!from.value||(d&&d>=from.value))&&(!to.value||(d&&d<=to.value));row.style.display=show?'':'none';if(show)visible++});noMatch.style.display=rows.length&&!visible?'':'none';count.textContent='Showing '+visible+' of '+rows.length+' invoices'}
 [search,type,status,payment,due,from,to].forEach(function(el){el.addEventListener(el===search?'input':'change',filter)});
 reset.addEventListener('click',function(){search.value='';type.value='';status.value='';payment.value='';due.value='';from.value='';to.value='';filter()});filter();
});
</script>
@endsection
