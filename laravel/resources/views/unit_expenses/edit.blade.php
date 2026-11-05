@extends('layouts/main')
@section('content')
@php
    $unitNames = $units->mapWithKeys(fn ($unit) => [$unit->id => $unit->property_name.' — '.$unit->unit_number]);
    $categoryNames = $categories->pluck('name', 'id');
    $fields = ['unit_id' => 'Apartment', 'expense_date' => 'Expense date', 'category_id' => 'Category', 'description' => 'Description', 'supplier_name' => 'Supplier', 'amount' => 'Amount', 'notes' => 'Notes'];
    $display = static function ($field, $value) use ($unitNames, $categoryNames) {
        if ($field === 'unit_id') { return $value === null ? 'General company expense' : ($unitNames->get($value) ?? 'Apartment #'.$value); }
        if ($field === 'category_id') { return $categoryNames->get($value) ?? 'Category #'.$value; }
        if ($field === 'amount') { return 'KES '.number_format((float) $value, 2); }
        return $value ?? '—';
    };
@endphp
<div style="max-width:1000px" class="mx-auto">
    <a href="{{ site_url('expenses') }}" class="d-inline-block mb-3">&larr; Expense register</a>
    <h1 class="h3 fw-bold">Edit expense #{{ $expense->id }}</h1>
    <p class="text-muted">Correct the entry and explain the change. Previous values remain in its correction history.</p>
    @if($legacyPaid)<div class="alert alert-info">This historical expense records KES {{ number_format((float) $expense->amount_paid, 2) }} paid without individual payment entries. That paid balance and its payment details will be preserved. The total cannot be reduced below it. If you increase the total, finance must reconcile the historical paid balance before recording further payments.</div>@endif
    @if($ledgerMismatch)<div class="alert alert-warning">The stored paid amount differs from its payment ledger. You can correct the description, supplier and notes. Reconcile its payments before changing the apartment, date, category or amount.</div>@endif
    <form method="post" action="{{ route('expenses.update', [app('currentTenant')->slug, $expense->id]) }}" class="card border-0 shadow-sm mb-4">
        @csrf
        <input type="hidden" name="snapshot" value="{{ old('snapshot', $snapshot) }}">
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6"><label for="unit_id" class="form-label">Apartment</label><select id="unit_id" name="unit_id" class="form-select"><option value="">General company expense / shared equipment</option>@foreach($units as $unit)<option value="{{ $unit->id }}" @selected(old('unit_id', $expense->unit_id) == $unit->id)>{{ $unitNames->get($unit->id) }}</option>@endforeach</select></div>
                <div class="col-md-6"><label for="expense_date" class="form-label">Expense date</label><input id="expense_date" name="expense_date" type="date" class="form-control" value="{{ old('expense_date', $expense->expense_date) }}" required></div>
                <div class="col-md-6"><label for="category_id" class="form-label">Category</label><select id="category_id" name="category_id" class="form-select" required>
                    @foreach($categories as $category)
                        @if($category->is_active || (int) $category->id === (int) $expense->category_id)
                            <option value="{{ $category->id }}" @selected(old('category_id', $expense->category_id) == $category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' (inactive)' }}</option>
                        @endif
                    @endforeach
                </select></div>
                <div class="col-md-6"><label for="amount" class="form-label">Expense total (KES)</label><input id="amount" name="amount" type="number" min="0.01" step="0.01" class="form-control" value="{{ old('amount', number_format((float) $expense->amount, 2, '.', '')) }}" required><div class="form-text">Recorded paid balance: KES {{ number_format((float) $expense->amount_paid, 2) }}. If that was entered incorrectly, <a href="{{ route('expenses.payments', [app('currentTenant')->slug, $expense->id]) }}">correct the payment first</a>.</div></div>
                <div class="col-12"><label for="description" class="form-label">Description</label><input id="description" name="description" class="form-control" maxlength="255" value="{{ old('description', $expense->description) }}" required></div>
                <div class="col-12"><label for="supplier_name" class="form-label">Supplier / shop</label><input id="supplier_name" name="supplier_name" class="form-control" maxlength="150" value="{{ old('supplier_name', $expense->supplier_name) }}"></div>
                <div class="col-12"><label for="notes" class="form-label">Notes</label><textarea id="notes" name="notes" rows="3" maxlength="10000" class="form-control">{{ old('notes', $expense->notes) }}</textarea></div>
                <div class="col-12"><label for="reason" class="form-label">Reason for correction</label><textarea id="reason" name="reason" rows="2" maxlength="1000" class="form-control" placeholder="e.g. Wrong apartment selected when entering the bill" required>{{ old('reason') }}</textarea></div>
            </div>
            <div class="d-flex gap-3 flex-wrap align-items-center mt-4"><button type="submit" class="btn btn-primary">Save correction</button><a href="{{ site_url('expenses/'.$expense->id.'/payments') }}">View payments</a></div>
        </div>
    </form>
    <section class="card border-0 shadow-sm"><div class="card-body p-4"><h2 class="h5 fw-bold">Correction history</h2>
        @forelse($history as $change)
            @php($before = json_decode($change->before_snapshot, true, 512, JSON_THROW_ON_ERROR))
            @php($after = json_decode($change->after_snapshot, true, 512, JSON_THROW_ON_ERROR))
            <article class="border-top pt-3 mt-3"><p class="mb-1"><strong>{{ $change->actor_name }}</strong> · {{ $change->created_at }}</p><p>{{ $change->reason }}</p><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Field</th><th>Before</th><th>After</th></tr></thead><tbody>
                @foreach($fields as $field => $label)
                    @if((string) ($before[$field] ?? '') !== (string) ($after[$field] ?? ''))
                        <tr><th>{{ $label }}</th><td style="white-space:pre-wrap">{{ $display($field, $before[$field] ?? null) }}</td><td style="white-space:pre-wrap">{{ $display($field, $after[$field] ?? null) }}</td></tr>
                    @endif
                @endforeach
            </tbody></table></div></article>
        @empty<p class="text-muted mb-0">No corrections have been recorded.</p>@endforelse
    </div></section>
</div>
@endsection
