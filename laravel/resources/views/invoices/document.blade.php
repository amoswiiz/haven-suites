@php
    $currency = $invoice['currency'] ?? 'KES';
    $terms = $invoice['terms'] !== null ? $invoice['terms'] : ($settings['default_terms'] ?? '');
    $noteLines = preg_split('/\R/u', $invoice['notes'] ?? '');
    $paymentLines = [];
    $ordinaryNotes = [];
    foreach ($noteLines as $line) {
        if (preg_match('/^\s*(?:m-?pesa\s+payment|payment\s+instructions)\s*:/iu', $line)) {
            $paymentLines[] = $line;
        } else {
            $ordinaryNotes[] = $line;
        }
    }
    $paymentText = $paymentLines ? implode("\n", $paymentLines) : ($settings['payment_instructions'] ?? '');
    $paymentText = preg_replace('/^\h*M-?pesa\h+Payment:\h*Paybill\h+([\d ]+?)\h+Account\h+([\d ]+)\h*$/mi', "Mpesa Paybill: $1\nAccount No: $2", $paymentText);
    $paymentSegments = preg_split('/(\d[\d\h]*\d|\d)/u', $paymentText, -1, PREG_SPLIT_DELIM_CAPTURE);
    $notesText = trim(implode("\n", $ordinaryNotes));
    $closingNote = '';
    foreach ([$notesText, $terms] as $text) {
        if (preg_match('/^\h*We look forward to your stay!\h*$/mi', $text)) $closingNote = 'We look forward to your stay!';
    }
    $notesText = trim(preg_replace('/^\h*We look forward to your stay!\h*\R?/mi', '', $notesText));
    $terms = trim(preg_replace('/^\h*We look forward to your stay!\h*\R?/mi', '', $terms));
    $termBoxes = [];
    $targetLines = min(12, max(3, (int) ceil(count(preg_split('/\R/u', trim($terms))) / 2)));
    $box = [];
    foreach (preg_split('/\R\s*\R/u', trim($terms)) as $paragraph) {
        foreach (array_chunk(preg_split('/\R/u', $paragraph), $targetLines) as $lines) {
            if ($box && count($box) + count($lines) + 1 > $targetLines) {
                $termBoxes[] = implode("\n", $box);
                $box = [];
            }
            if ($box) $box[] = '';
            $box = array_merge($box, $lines);
        }
    }
    if ($box) $termBoxes[] = implode("\n", $box);
@endphp
<style>
.invoice-document{font-family:Arial,sans-serif;font-size:14px;line-height:1.45;overflow-wrap:anywhere}.invoice-document *{box-sizing:border-box}.invoice-document table{width:100%;border-collapse:collapse}.invoice-document .doc-header td{vertical-align:top;border:0;padding:0 0 20px}.invoice-document .doc-brand{width:58%}.invoice-document .doc-meta{text-align:right;width:42%;font-size:12px;color:#64748b}.invoice-document .doc-title{font-size:29px;letter-spacing:1px;color:#294780;margin:0 0 4px}.invoice-document .doc-number{font-size:13px;font-weight:bold;color:#243247}.invoice-document .doc-contact{font-size:12px;color:#64748b;line-height:1.45}.invoice-document .doc-label{text-transform:uppercase;letter-spacing:.8px;color:#64748b;font-size:11px;font-weight:bold}.invoice-document .doc-bill{padding:0 0 18px}.invoice-document .doc-customer{font-size:15px;font-weight:bold}.invoice-document .doc-stay{font-size:12px;color:#64748b;margin-top:8px}.invoice-document .doc-items{table-layout:fixed;margin-bottom:16px}.invoice-document .doc-items th{background:#eef2f8;color:#294780;font-size:11px;text-align:right;padding:9px 7px}.invoice-document .doc-items td{padding:10px 7px;border-bottom:1px solid #e2e8f0;vertical-align:top;text-align:right}.invoice-document .doc-items th:first-child,.invoice-document .doc-items td:first-child{text-align:left;width:46%}.invoice-document .doc-items th:nth-child(2){width:10%}.invoice-document .doc-items th:nth-child(3),.invoice-document .doc-items th:nth-child(4){width:22%}.invoice-document .doc-summary{width:44%;margin:0 0 20px auto;font-size:13px}.invoice-document .doc-summary td{padding:4px 0;border:0}.invoice-document .doc-summary td:last-child{text-align:right;white-space:nowrap}.invoice-document .doc-summary .doc-due td{border-top:2px solid #294780;padding-top:9px;font-size:19px;font-weight:bold;color:#294780}.invoice-document .doc-block{margin-top:16px}.invoice-document .doc-block h3{font-size:12px;color:#294780;margin:0 0 5px}.invoice-document .doc-small{font-size:12px;line-height:1.35;color:#4b5563}.invoice-document .doc-text{white-space:pre-line;margin:0;overflow-wrap:anywhere}.invoice-document .doc-payment{padding:12px 14px;background:#f2f5fa;border-left:3px solid #e98d50}.invoice-document .doc-payment h3{margin-bottom:4px}.invoice-document .doc-payment-text{font-size:14px;line-height:1.7;color:#243247}.invoice-document .doc-footer{margin-top:20px;border-top:1px solid #e2e8f0;padding-top:9px;font-size:11px;color:#64748b}.invoice-document .doc-history th,.invoice-document .doc-history td{font-size:12px;padding:6px;text-align:left;border-bottom:1px solid #e2e8f0}.invoice-document .doc-history th{background:#f2f5fa}.invoice-document .doc-history td:last-child{text-align:right}.invoice-document .doc-type{margin-top:6px;font-size:11px;color:#64748b}.invoice-document .doc-draft{border:1px solid #94a3b8;text-align:center;padding:8px;margin-top:16px}
.invoice-document .doc-terms-row{table-layout:fixed;margin-top:6px;border-collapse:separate;border-spacing:8px 0}.invoice-document .doc-terms-row td{width:50%;vertical-align:top;padding:10px}.invoice-document .doc-terms-row td+td{padding:10px}.invoice-document .doc-terms-box{border:1px solid #e2e8f0;border-radius:5px;padding:10px;background:#fafbfd}.invoice-document .doc-terms-row tr{break-inside:avoid;page-break-inside:avoid}@media(max-width:600px){.invoice-document.hs-invoice-sheet{padding:18px}.invoice-document .doc-summary{width:100%}.invoice-document .doc-terms-row td{display:block;width:100%;padding:10px!important}.invoice-document .doc-title{font-size:23px}.invoice-document .doc-items td,.invoice-document .doc-items th{padding:7px 4px;font-size:11px}}
@media print{
@page{size:A4;margin:14mm 15mm 16mm}
body,.hs-main,.hs-content{background:white!important;margin:0!important;padding:0!important;max-width:none!important}
.hs-topbar,.hs-sidebar,.hs-skip-link,.alert,.no-print,.hs-content>nav{display:none!important}
.invoice-document{font-size:10pt;line-height:1.35}.invoice-document.hs-invoice-sheet{border:0;border-radius:0;padding:0;max-width:none;box-shadow:none}.invoice-document .doc-title{font-size:23pt}.invoice-document .doc-header td{padding-bottom:14pt}.invoice-document .doc-contact,.invoice-document .doc-meta{font-size:9pt}.invoice-document .hs-invoice-logo{width:155pt!important;margin-bottom:7pt}.invoice-document .doc-customer{font-size:11pt}.invoice-document .doc-label{font-size:8.5pt}.invoice-document .doc-items th{font-size:8.5pt;padding:6pt}.invoice-document .doc-items td{font-size:10pt;padding:7pt 6pt}.invoice-document .doc-summary{width:44%;font-size:10pt;margin-bottom:12pt}.invoice-document .doc-summary .doc-due td{font-size:15pt}.invoice-document .doc-block{margin-top:10pt}.invoice-document .doc-block h3{font-size:9pt}.invoice-document .doc-payment-text{font-size:11pt;line-height:1.6}.invoice-document .doc-small,.invoice-document .doc-history th,.invoice-document .doc-history td{font-size:9pt;line-height:1.3}.invoice-document .doc-footer{font-size:8.5pt;margin-top:12pt}.invoice-document .doc-header,.invoice-document .doc-bill,.invoice-document .doc-summary,.invoice-document .doc-payment{break-inside:avoid;page-break-inside:avoid}.invoice-document tr{break-inside:avoid;page-break-inside:avoid}.invoice-document thead{display:table-header-group}.invoice-document .doc-block h3{break-after:avoid}.invoice-document .doc-text{orphans:3;widows:3}.invoice-document .doc-payment,.invoice-document th{-webkit-print-color-adjust:exact;print-color-adjust:exact}
}
</style>
<article class="hs-invoice-sheet hs-invoice-accent invoice-document" aria-label="Invoice document">
<table class="doc-header" role="presentation"><tr><td class="doc-brand">
@if(app('currentTenant')->slug === 'haven')<img src="{{ $logoDataUri ?? base_url('assets/images/haven-suites-logo.png') }}" alt="Haven Suites" class="hs-invoice-logo" style="width:200px;height:auto">@else<strong>{{ $settings['business_name'] ?? app('currentTenant')->name }}</strong>@endif
<div class="doc-contact">@foreach(['business_website','business_email','business_phone','business_address'] as $field)@if(!empty($settings[$field]))<div class="doc-text">{{ $field === 'business_website' ? preg_replace('~^https?://~i', '', $settings[$field]) : $settings[$field] }}</div>@endif @endforeach</div>
</td><td class="doc-meta"><h2 class="doc-title">INVOICE</h2><div class="doc-number">{{ $invoice['invoice_number'] ?: 'DRAFT #'.$invoice['id'] }}</div><div>Status: {{ ucfirst($invoice['status']) }}</div><div>Date: {{ $invoice['issue_date'] ? date('d M Y',strtotime($invoice['issue_date'])) : '-' }}</div><div>Due: {{ $invoice['due_date'] ? date('d M Y',strtotime($invoice['due_date'])) : '-' }}</div></td></tr></table>
<div class="doc-bill"><div class="doc-label">Bill to</div><div class="doc-customer">{{ $invoice['customer_name'] }}</div>@foreach(['customer_email','customer_phone','customer_address'] as $field)@if(!empty($invoice[$field]))<div class="doc-text">{{ $invoice[$field] }}</div>@endif @endforeach
<div class="doc-type no-print">Invoice type: {{ ucfirst($invoice['invoice_type']) }}</div>
@if(!empty($bookingSummary))<div class="doc-stay">{{ $bookingSummary['partner_name'] ?: trim(($bookingSummary['property_name'] ?? '').' / '.($bookingSummary['unit_number'] ?? ''),' /') }}<br>{{ date('d M Y',strtotime($bookingSummary['check_in'])) }} to {{ date('d M Y',strtotime($bookingSummary['check_out'])) }} &middot; Booking #{{ $invoice['reservation_id'] }}</div>@endif
</div>
<table class="doc-items"><thead><tr><th>Description</th><th>Qty</th><th>Unit price</th><th>Amount</th></tr></thead><tbody>@foreach($items as $item)<tr><td class="doc-text">{{ $item['description'] }}</td><td>{{ number_format($item['quantity'],2) }}</td><td>{{ $currency }} {{ number_format($item['unit_price'],2) }}</td><td>{{ $currency }} {{ number_format($item['line_total'],2) }}</td></tr>@endforeach</tbody></table>
<table class="doc-summary" aria-label="Invoice totals"><tr><td>Subtotal</td><td>{{ $currency }} {{ number_format($invoice['subtotal'],2) }}</td></tr>@if($invoice['discount_amount']>0)<tr><td>Discount</td><td>- {{ $currency }} {{ number_format($invoice['discount_amount'],2) }}</td></tr>@endif @if($invoice['tax_amount']>0)<tr><td>Tax</td><td>{{ $currency }} {{ number_format($invoice['tax_amount'],2) }}</td></tr>@endif<tr><td>Total</td><td>{{ $currency }} {{ number_format($invoice['total_amount'],2) }}</td></tr><tr><td>Payments received</td><td>{{ $currency }} {{ number_format($paid,2) }}</td></tr><tr class="doc-due"><td>Amount due</td><td>{{ $currency }} {{ number_format($balance,2) }}</td></tr></table>
@if($invoice['status']==='issued' && !empty($paymentHistory))<section class="doc-block"><h3>Payment history</h3><table class="doc-history"><thead><tr><th>Date</th><th>Method</th><th>Reference</th><th>Amount</th><th class="no-print">Receipt</th></tr></thead><tbody>@foreach($paymentHistory as $payment)<tr><td>{{ $payment['payment_date'] }}</td><td>{{ $payment['payment_method'] }}</td><td>{{ $payment['reference'] ?: '-' }}</td><td>{{ $currency }} {{ number_format($payment['amount'],2) }}</td><td class="no-print">@if(haven_can_access(site_url('receipts/'.$payment['id']),'GET'))<a href="{{ site_url('receipts/'.$payment['id']) }}">View Receipt</a>@endif</td></tr>@endforeach</tbody></table></section>@endif
@if($paymentText)<section class="doc-block doc-payment"><h3>Payment details</h3><p class="doc-text doc-payment-text">@foreach($paymentSegments as $segment)@if(preg_match('/^\d/u',$segment))<strong>{{ $segment }}</strong>@else{{ $segment }}@endif @endforeach</p></section>@endif
@if($notesText)<section class="doc-block"><h3>Notes</h3><p class="doc-text doc-small">{{ $notesText }}</p></section>@endif
@if($closingNote)<p class="doc-small" style="margin:5px 0 0">{{ $closingNote }}</p>@endif
@if($terms)<section class="doc-block doc-terms"><h3>Terms &amp; conditions</h3>@foreach(array_chunk($termBoxes,2) as $pair)<table class="doc-terms-row" role="presentation"><tr>@foreach($pair as $box)<td class="doc-terms-box"><p class="doc-text doc-small">{{ $box }}</p></td>@endforeach @if(count($pair)===1)<td></td>@endif</tr></table>@endforeach</section>@endif
@if($invoice['status']==='draft')<div class="doc-draft">DRAFT - NOT AN OFFICIAL INVOICE</div>@endif
</article>
