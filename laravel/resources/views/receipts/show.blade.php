<?php
/** Haven Suites receipt view — app/Views/receipts/show.php */
$r = $receipt;
$currency = esc($r['currency'] ?: 'KES');
$money = static fn ($value) => number_format((float) $value, 2);
$paymentDate = !empty($r['payment_date']) ? date('d M Y', strtotime($r['payment_date'])) : '—';
$allocatedDate = !empty($r['allocated_at']) ? date('d M Y, H:i', strtotime($r['allocated_at'])) : '—';
$businessName = $settings['business_name'] ?? 'Haven Suites BNB';
$businessEmail = $settings['business_email'] ?? '';
$businessWebsite = $settings['business_website'] ?? '';
$businessPhone = $settings['business_phone'] ?? '';
$logoUrl = base_url('assets/images/haven-suites-logo.png');
?>
@extends('layouts/main')
@section('content')
<style>
.hs-receipt-shell{max-width:920px;margin:0 auto}
.hs-receipt-paper{background:#fff;border:1px solid #e3e8f0;border-radius:16px;overflow:hidden;box-shadow:0 8px 28px rgba(26,41,70,.05)}
.hs-receipt-header{background:#fff;color:#203d7e;padding:27px 36px 23px;border-bottom:4px solid #e78b4b;display:flex;justify-content:space-between;align-items:flex-start;gap:24px;flex-wrap:wrap}
.hs-receipt-brand{flex:1 1 300px;min-width:0}
.hs-receipt-logo{display:block;width:200px;max-width:100%;height:auto;max-height:none;object-fit:contain;object-position:left center}
.hs-receipt-contact{font-size:12px;line-height:1.65;color:#5f6f85;margin-top:12px;overflow-wrap:anywhere}
.hs-receipt-document-title{text-align:right;flex:0 1 245px}
.hs-receipt-document-title h2{font-size:19px;letter-spacing:.08em;text-transform:uppercase;font-weight:800;margin:0;color:#203d7e}
.hs-receipt-document-title .number{font-size:16px;font-weight:700;margin-top:9px;color:#263b61}
.hs-receipt-body{padding:32px 36px}
.hs-receipt-muted{color:#69788f;font-size:13px}
.hs-receipt-label{color:#64748b;font-size:12px;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px}
.hs-receipt-amount{font-size:32px;font-weight:800;color:#1f7b50}
.hs-receipt-info{border-bottom:1px solid #e9edf4;padding-bottom:22px;margin-bottom:22px}
.hs-receipt-total{background:#f4f7fb;border-radius:12px;padding:18px 22px}
.hs-receipt-footer{border-top:1px solid #e9edf4;margin-top:28px;padding-top:18px;color:#66758d;font-size:13px}
.hs-receipt-badge{display:inline-block;background:#e4f4ea;color:#207448;padding:5px 11px;border-radius:20px;font-weight:700;font-size:12px}
@media(max-width:600px){.hs-receipt-header,.hs-receipt-body{padding:22px}.hs-receipt-document-title{text-align:left}.hs-receipt-logo{width:200px;max-width:100%}}
@media print{
  .hs-receipt-actions,.sidebar,.hs-sidebar,nav,.navbar,header,.topbar,.app-sidebar{display:none!important}
  .main-content,.content-wrapper,main{margin:0!important;padding:0!important;max-width:none!important}
  body{background:#fff!important}
  .hs-receipt-shell{max-width:none;margin:0}
  .hs-receipt-paper{box-shadow:none;border:0;border-radius:0}
  .hs-receipt-header,.hs-receipt-total,.hs-receipt-badge{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .hs-receipt-logo{width:200px;max-width:100%;height:auto;max-height:none}
  .hs-receipt-info,.hs-receipt-total,.hs-receipt-footer{break-inside:avoid}
  @page{size:A4;margin:12mm}
}
</style>
<div class="hs-receipt-shell">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3 hs-receipt-actions">
        <?php if (haven_can_access(site_url('invoices/' . (int) $r['invoice_id'] . '/payments'), 'GET')): ?><a href="<?= site_url('invoices/' . (int) $r['invoice_id'] . '/payments') ?>" class="btn btn-outline-secondary">&larr; Invoice Payments</a><?php endif; ?>
        <button type="button" onclick="window.print()" class="btn btn-primary">Print / Save as PDF</button>
    </div>
    <article class="hs-receipt-paper">
        <div class="hs-receipt-header">
            <div class="hs-receipt-brand">
                <img src="<?= esc($logoUrl, 'attr') ?>" alt="Haven Suites — BNBs and STR Homes" class="hs-receipt-logo">
                <?php if ($businessPhone || $businessEmail || $businessWebsite): ?>
                    <div class="hs-receipt-contact">
                        <?php if ($businessPhone): ?><div><?= esc($businessPhone) ?></div><?php endif; ?>
                        <?php if ($businessEmail): ?><div><?= esc($businessEmail) ?></div><?php endif; ?>
                        <?php if ($businessWebsite): ?><div><?= esc($businessWebsite) ?></div><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="hs-receipt-document-title">
                <h2>Payment Receipt</h2>
                <div class="number"><?= esc($receiptNumber) ?></div>
                <div class="hs-receipt-muted mt-1">Allocated: <?= esc($allocatedDate) ?></div>
            </div>
        </div>
        <div class="hs-receipt-body">
            <div class="d-flex justify-content-between align-items-center gap-3 flex-wrap mb-4">
                <div><div class="hs-receipt-label">Amount Received / Allocated</div><div class="hs-receipt-amount"><?= $currency ?> <?= $money($r['receipt_amount']) ?></div></div>
                <span class="hs-receipt-badge">PAYMENT RECEIVED</span>
            </div>
            <div class="row g-4 hs-receipt-info">
                <div class="col-md-6"><div class="hs-receipt-label">Received From</div><div class="fw-bold"><?= esc($r['customer_name']) ?></div>
                    <?php if (!empty($r['customer_phone'])): ?><div class="hs-receipt-muted"><?= esc($r['customer_phone']) ?></div><?php endif; ?>
                    <?php if (!empty($r['customer_email'])): ?><div class="hs-receipt-muted"><?= esc($r['customer_email']) ?></div><?php endif; ?>
                </div>
                <div class="col-md-6"><div class="hs-receipt-label">Payment Details</div>
                    <div>Payment date: <strong><?= esc($paymentDate) ?></strong></div>
                    <div>Method: <strong><?= esc(ucwords(str_replace('_', ' ', (string) $r['payment_method']))) ?></strong></div>
                    <div>Reference: <strong><?= esc($r['payment_reference'] ?: 'Not provided') ?></strong></div>
                </div>
            </div>
            <div class="table-responsive"><table class="table align-middle mb-4">
                <thead><tr><th>Description</th><th class="text-end">Amount</th></tr></thead>
                <tbody><tr><td>Payment applied to invoice <strong><?= esc($r['invoice_number'] ?: ('#' . $r['invoice_id'])) ?></strong><div class="hs-receipt-muted">Reservation #<?= (int) $r['reservation_id'] ?> · Guest payment #<?= (int) $r['guest_payment_id'] ?></div></td><td class="text-end fw-bold"><?= $currency ?> <?= $money($r['receipt_amount']) ?></td></tr></tbody>
            </table></div>
            <div class="hs-receipt-total">
                <div class="d-flex justify-content-between gap-2 mb-2"><span>Original invoice total</span><strong><?= $currency ?> <?= $money($r['invoice_total']) ?></strong></div>
                <div class="d-flex justify-content-between gap-2 mb-2"><span>This receipt</span><strong><?= $currency ?> <?= $money($r['receipt_amount']) ?></strong></div>
                <div class="d-flex justify-content-between gap-2 pt-2 border-top"><span class="fw-bold">Invoice balance after this payment</span><strong><?= $currency ?> <?= $money($balanceAfterAllocation) ?></strong></div>
            </div>
            <p class="hs-receipt-muted mt-3 mb-0">This receipt acknowledges only the payment amount shown above. It does not indicate that the entire invoice is paid unless the balance after this payment is zero.</p>
            <div class="hs-receipt-footer text-center">Thank you for choosing <?= esc($businessName) ?>.<div class="mt-1">Generated from an existing recorded guest payment; no additional payment was created.</div></div>
        </div>
    </article>
</div>
@endsection
