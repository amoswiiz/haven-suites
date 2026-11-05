@extends('layouts/main')
@section('content')
@php
    $historical = $payment === null;
    $amount = $historical ? $expense->amount_paid : $payment->amount;
    $date = $historical ? ($expense->paid_at ?: $expense->expense_date) : $payment->payment_date;
    $method = $historical ? $expense->payment_method : $payment->payment_method;
    $reference = $historical ? $expense->payment_reference : $payment->reference;
@endphp
<style>.hs-payment-correction :is(.form-control,.form-select,.btn){min-height:44px}</style>
<div class="mx-auto hs-payment-correction" style="max-width:850px">
    <a href="{{ route('expenses.payments', [app('currentTenant')->slug, $expense->id]) }}" class="d-inline-block mb-3">&larr; Expense payments</a>
    <h1 class="h3 fw-bold">{{ $historical ? 'Correct historical paid balance' : 'Correct payment #'.$payment->id }}</h1>
    <p class="text-muted">Expense #{{ $expense->id }}: {{ $expense->description }}</p>
    <div class="alert alert-info">Use this form to correct a data entry error. It changes the recorded payment and keeps the previous values in its correction history. It does not transfer or refund money.</div>
    @if($historical)<p>This expense has a historical paid balance without individual payment entries. Correcting it will preserve that history without creating a payment entry. Enter zero only if no payment was actually made.</p>@endif
    <form method="post" action="{{ $historical ? route('expenses.payments.historical.update', [app('currentTenant')->slug, $expense->id]) : route('expenses.payments.update', [app('currentTenant')->slug, $expense->id, $payment->id]) }}" class="card border-0 shadow-sm">
        @csrf
        <input type="hidden" name="snapshot" value="{{ old('snapshot', $snapshot) }}">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="actual-amount" class="form-label">Actual amount paid (KES)</label>
                    <input id="actual-amount" name="amount" type="number" min="{{ $historical ? '0' : '0.01' }}" step="0.01" class="form-control" value="{{ old('amount', number_format((float) $amount, 2, '.', '')) }}" required>
                    <div class="form-text">Currently recorded: KES {{ number_format((float) $amount, 2) }}. Bill total: KES {{ number_format((float) $expense->amount, 2) }}.</div>
                </div>
                <div class="col-md-6">
                    <label for="actual-date" class="form-label">Actual payment date</label>
                    <input id="actual-date" name="payment_date" type="date" class="form-control" value="{{ old('payment_date', $date) }}" required>
                </div>
                <div class="col-md-6">
                    <label for="actual-method" class="form-label">Payment method</label>
                    <select id="actual-method" name="payment_method" class="form-select" @required(!$historical)>
                        <option value="">{{ $historical ? 'Not recorded' : 'Select payment method' }}</option>
                        @foreach(['mpesa' => 'M-Pesa', 'bank' => 'Bank transfer', 'cash' => 'Cash', 'other' => 'Other'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('payment_method', $method) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="actual-reference" class="form-label">Transaction reference</label>
                    <input id="actual-reference" name="reference" class="form-control" maxlength="100" value="{{ old('reference', $reference) }}">
                </div>
                @if(!$historical)<div class="col-12"><label for="payment-notes" class="form-label">Payment notes</label><textarea id="payment-notes" name="notes" class="form-control" rows="3" maxlength="10000">{{ old('notes', $payment->notes) }}</textarea></div>@endif
                <div class="col-12">
                    <label for="payment-reason" class="form-label">Reason for correction</label>
                    <textarea id="payment-reason" name="reason" class="form-control" rows="3" minlength="3" maxlength="1000" placeholder="e.g. Actual payment was KES 3,000; KES 4,000 was entered by mistake" required>{{ old('reason') }}</textarea>
                </div>
            </div>
            <p class="text-muted mt-3">If the bill total is also wrong, correct the payment here first, then choose Edit expense.</p>
            <div class="d-flex gap-3 flex-wrap align-items-center"><button type="submit" class="btn btn-primary">Save payment correction</button><a href="{{ route('expenses.payments', [app('currentTenant')->slug, $expense->id]) }}">Cancel</a></div>
        </div>
    </form>
</div>
@endsection
