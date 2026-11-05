@extends('layouts.main')
@section('content')
<div class="container py-3" style="max-width:900px">
<a href="{{ route('staff-bills.index',app('currentTenant')->slug) }}">Back to bills</a>
<h1>Bill #{{ $bill->id }} — {{ ucfirst($bill->status) }}</h1>
<p>{{ $staff?->full_name }} · {{ $unit?->unit_number ?? 'General company expense' }} · {{ $bill->purchase_date }}</p>
<h2 class="h4">KES {{ number_format($bill->amount_minor/100,2) }}</h2>
<p>{{ $bill->category }}: {{ $bill->description }}<br>Supplier: {{ $bill->supplier }}</p>
<p>{{ ['personal'=>'Cleaner paid personally — reimburse the cleaner','company'=>'Paid using company funds — reconcile the existing expense','supplier'=>'Supplier has not been paid'][$bill->payer] }}</p>
<p><strong>@include('staff_bills.payment_status',['paid'=>$expense?->amount_paid ?? 0])</strong></p>
@if($expense)<p>Paid: KES {{ number_format($expense->amount_paid,2) }} · Outstanding: KES {{ number_format(max(0,$expense->amount-$expense->amount_paid),2) }}</p>@endif
@if($expense && $canReview && $bill->payer !== 'company')<a class="btn btn-primary mb-3" href="{{ site_url('expenses/'.$expense->id.'/payments') }}">Record {{ $bill->payer === 'personal' ? 'reimbursement' : 'supplier payment' }}</a>@endif
@if($bill->status==='returned' && (int)$bill->submitted_by===(int)auth()->id())<a class="btn btn-primary" href="{{ route('staff-bills.edit',[app('currentTenant')->slug,$bill->id]) }}">Correct and resubmit</a>@endif
@if($canReview && $bill->status==='pending' && (int)$bill->submitted_by!==(int)auth()->id())
<form method="post" action="{{ route('staff-bills.review',[app('currentTenant')->slug,$bill->id]) }}" class="card p-3 my-3">
@csrf<input type="hidden" name="version" value="{{ $bill->version }}"><h2 class="h4">Review bill</h2>
@if($bill->payer==='company')
<label for="expense_id">Existing paid expense ID</label><input class="form-control" id="expense_id" name="expense_id" type="number" min="1" value="{{ old('expense_id') }}"><p>Approval links a fully paid manual expense with the same apartment, purchase date and amount. Record or reconcile company advances in Finance first. No reimbursement or second expense is created.</p>
@else
<label for="category_id">Accounting category for approval</label><select class="form-select" id="category_id" name="category_id"><option value="">Select category</option>@foreach($categories as $category)@if(!in_array(strtolower($category->name),['rent','cleaning'],true))<option value="{{ $category->id }}" @selected(old('category_id')==$category->id)>{{ $category->name }}</option>@endif
@endforeach</select>
@endif
<label for="note">Review note (required for return or rejection)</label><textarea class="form-control mb-3" id="note" name="note" maxlength="2000">{{ old('note') }}</textarea>
<div><button class="btn btn-success" name="action" value="approved">Approve</button> <button class="btn btn-warning" name="action" value="returned">Return for correction</button> <button class="btn btn-danger" name="action" value="rejected">Reject</button></div>
</form>@endif
<h2 class="h4 mt-4">Submission and review history</h2>
@foreach($events as $event)
@php($snapshot=json_decode($event->snapshot,true))
<div class="card p-3 mb-2"><strong>{{ ucfirst($event->action) }} · {{ $event->created_at }}</strong><small>Staff login #{{ $event->actor_id }}</small><p class="mb-1">{{ $event->note }}</p><p>{{ $snapshot['description'] }} · KES {{ number_format($snapshot['amount_minor']/100,2) }} · {{ $snapshot['purchase_date'] }}</p><a href="{{ route('staff-bills.receipt',[app('currentTenant')->slug,$bill->id,$event->id]) }}">Download receipt for this version</a></div>
@endforeach
</div>
@endsection
