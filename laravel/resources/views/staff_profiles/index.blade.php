@extends('saas.layout')
@section('title', 'Staff profiles')
@section('content')
<h1>Staff profiles</h1>
<p>Private biodata and identification submitted by your company's staff. A profile appears here after the staff member saves it.</p>
<section class="card"><div class="table-wrap"><table>
    <thead><tr><th>Full name</th><th>Phone</th><th>Last updated</th><th>Action</th></tr></thead>
    <tbody>@forelse($profiles as $profile)<tr><td>{{ $profile->legal_name }}</td><td>{{ $profile->phone }}</td><td>{{ date('d M Y', strtotime($profile->updated_at)) }}</td><td><a href="{{ route('staff-profile.show', [app('currentTenant')->slug, $profile->id]) }}">View profile</a></td></tr>
    @empty<tr><td colspan="4">No staff profiles have been submitted yet.</td></tr>@endforelse</tbody>
</table></div>{{ $profiles->links() }}</section>
@endsection
