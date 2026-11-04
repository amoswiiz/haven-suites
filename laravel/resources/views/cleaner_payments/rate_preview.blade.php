@extends('layouts/main')
@section('content')
<div class="container-fluid py-3">
    <h3>Review cleaning rates — {{ $input['month'] }}</h3>
    <p>Rates use the bedrooms actually cleaned. Verification status and payment allocations stay unchanged. This preview expires after 15 minutes.</p>
    <div class="table-responsive">
        <table class="table table-striped">
            <thead><tr><th>Job</th><th>Staff ID</th><th>Completed</th><th>Bedrooms</th><th>Saved fee</th><th>Current rate</th><th>Difference</th><th>Result</th></tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>#{{ $row['job']['id'] }}</td>
                    <td>{{ $row['job']['assigned_staff_id'] }}</td>
                    <td>{{ $row['job']['completed_at'] }}</td>
                    <td>{{ $row['job']['bedrooms_cleaned'] }}</td>
                    <td>KES {{ number_format((float) $row['job']['cleaning_charge'], 2) }}</td>
                    <td>{{ $row['new_amount'] === null ? 'Unavailable' : 'KES '.number_format((float) $row['new_amount'], 2) }}</td>
                    <td>{{ $row['reason'] === null ? 'KES '.number_format((float) $row['new_amount'] - (float) $row['job']['cleaning_charge'], 2) : '—' }}</td>
                    <td>{{ $row['reason'] ?? 'Will update fee and linked expense' }}</td>
                </tr>
            @empty
                <tr><td colspan="8">No completed or verified jobs for this selection.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if(count($changes))
        <form method="post" action="{{ site_url('housekeeping/earnings-rates/apply') }}">
            @csrf
            <input type="hidden" name="preview_token" value="{{ $token }}">
            <button type="submit" class="btn btn-primary">Apply updated rates to {{ count($changes) }} jobs</button>
        </form>
    @endif
    <a class="btn btn-outline-secondary mt-3" href="{{ site_url('payments/cleaners/earnings').'?'.http_build_query(['month' => $input['month'], 'staff_id' => $input['staff_id'] ?? '']) }}">Back without applying</a>
</div>
@endsection
