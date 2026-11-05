@extends('layouts.main')
@section('content')
<div class="container py-3">
<h1>{{ $canReview ? 'Bill Approvals' : 'My Bills & Reimbursements' }}</h1>
<p>Track refill purchases and bills separately from cleaning earnings.</p>
@if(auth()->user()->can('bills.submit'))<a class="btn btn-primary mb-3" href="{{ route('staff-bills.create', app('currentTenant')->slug) }}">Submit a bill</a>@endif
<form method="get" class="mb-3"><label for="bill-status">Review status</label> <select id="bill-status" name="status"><option value="">All</option>@foreach(['pending'=>'Awaiting review','returned'=>'Returned for correction','rejected'=>'Rejected','approved'=>'Approved'] as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select> <button class="btn btn-outline-primary">Filter</button></form>
<div class="table-responsive"><table class="table"><thead><tr><th>Bill</th><th>Date</th><th>Apartment</th>@if($canReview)<th>Cleaner</th>@endif<th>Description</th><th>Amount</th><th>Review</th><th>Payment</th></tr></thead><tbody>
@forelse($bills as $bill)
<tr><td><a href="{{ route('staff-bills.show',[app('currentTenant')->slug,$bill->id]) }}">#{{ $bill->id }}</a></td><td>{{ $bill->purchase_date }}</td><td>{{ $bill->unit_number }}</td>@if($canReview)<td>{{ $bill->full_name }}</td>@endif<td>{{ $bill->description }}</td><td>KES {{ number_format($bill->amount_minor/100,2) }}</td><td>{{ ucfirst($bill->status) }}</td><td>@include('staff_bills.payment_status',['paid'=>$bill->amount_paid ?? 0])</td></tr>
@empty<tr><td colspan="8">No bills found.</td></tr>@endforelse
</tbody></table></div>{{ $bills->links() }}
</div>
@endsection
