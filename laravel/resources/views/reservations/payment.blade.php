@if (auth()->user()->can('finance.manage'))
<section id="reservation-payment" class="hs-card mb-4" aria-labelledby="payment-heading">
<h5 id="payment-heading">Record payment</h5>
@if ($status === 'cancelled')
<p>This booking is cancelled. Review its invoice before recording payment.</p>
@elseif ($paymentInvoice && strtoupper($paymentInvoice['currency']) === 'KES')
<p>Invoice <strong>{{ $paymentInvoice['invoice_number'] }}</strong> &middot; Outstanding <strong>KES {{ number_format($paymentInvoice['balance'], 2) }}</strong></p>
@if ($unallocatedPayments > 0)
<p class="alert alert-warning">KES {{ number_format($unallocatedPayments, 2) }} already recorded needs allocation. Allocate this money before recording another payment.</p>
<a class="btn btn-primary" href="{{ site_url('invoices/'.$paymentInvoice['id'].'/payments') }}">Allocate existing payment</a>
@else
<form method="post" action="{{ site_url('invoices/'.$paymentInvoice['id'].'/payments/record') }}" onsubmit="this.querySelector('button[type=submit]').disabled = true">
@csrf
<div class="row g-3">
<div class="col-md-4"><label for="booking-payment-amount" class="form-label">Amount (KES)</label><input id="booking-payment-amount" name="amount" type="number" class="form-control" min="0.01" max="{{ $paymentInvoice['balance'] }}" step="0.01" required></div>
<div class="col-md-4"><label for="booking-payment-date" class="form-label">Payment date</label><input id="booking-payment-date" name="payment_date" type="date" class="form-control" value="{{ date('Y-m-d') }}" required></div>
<div class="col-md-4"><label for="booking-payment-method" class="form-label">Payment method</label><select id="booking-payment-method" name="payment_method" class="form-select" required><option value="">Select method</option>@foreach (['Mpesa', 'Bank Transfer', 'Cash', 'Card', 'Other'] as $method)<option value="{{ $method }}">{{ $method }}</option>@endforeach</select></div>
<div class="col-md-6"><label for="booking-payment-reference" class="form-label">Transaction reference</label><input id="booking-payment-reference" name="reference" class="form-control" maxlength="100"><div class="form-text">Use the M-Pesa or bank reference when available.</div></div>
<div class="col-md-6"><label for="booking-payment-notes" class="form-label">Notes (optional)</label><textarea id="booking-payment-notes" name="notes" class="form-control" rows="2" maxlength="5000"></textarea></div>
<div class="col-12"><button type="submit" class="btn btn-primary">Save payment &amp; generate receipt</button></div>
</div></form>
@endif
@elseif ($paymentInvoice)
<p>Payment recording currently supports KES invoices only.</p>
@else
@php($activeInvoices = collect($invoices)->where('status', '!=', 'void'))
@php($draftInvoice = $activeInvoices->firstWhere('status', 'draft'))
@if ($draftInvoice)
<p>Issue the booking invoice before recording payment.</p><a class="btn btn-primary" href="{{ site_url('invoices/'.$draftInvoice['id']) }}">Review and issue invoice</a>
@elseif ($activeInvoices->isEmpty())
<p>Create and issue a booking invoice to record payments and generate receipts.</p><a class="btn btn-primary" href="{{ site_url('invoices/create?reservation_id='.$id) }}">Create invoice</a>
@else
<p class="text-success mb-0">No outstanding invoice balance.</p>
@endif
@endif
</section>
@endif
