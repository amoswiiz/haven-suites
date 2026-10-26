@php
    $workspacePath = trim(uri_string(), '/');
    $paymentWorkspaces = [
        ['title' => 'Cleaner payments', 'description' => 'Record cleaning payments, review unpaid balances and download statements.', 'links' => ['payments/cleaners' => 'Payments', 'payments/cleaners/balances' => 'Balances', 'payments/cleaners/statements' => 'Statements']],
        ['title' => 'Owner & partner payouts', 'description' => 'Review and record payouts. Each tab keeps its own payment records and calculations.', 'links' => ['owner-remittances' => 'Owner payouts', 'payments/hosts' => 'Host payments', 'payments/partners' => 'Partner payments', 'payments/partners/owners' => 'Partner balances']],
    ];
@endphp
@foreach ($paymentWorkspaces as $workspace)
    @php
        $workspaceActive = null;
        foreach ($workspace['links'] as $path => $label) {
            if (($workspacePath === $path || str_starts_with($workspacePath, $path.'/')) && strlen($path) > strlen($workspaceActive ?? '')) {
                $workspaceActive = $path;
            }
        }
    @endphp
    @if ($workspaceActive && auth()->user()->can('finance.view'))
        <section class="card p-3 mb-4 no-print" aria-label="{{ $workspace['title'] }} workspace">
            <h2 class="h5">{{ $workspace['title'] }}</h2><p class="text-muted small">{{ $workspace['description'] }}</p>
            <nav class="nav nav-pills gap-2" aria-label="{{ $workspace['title'] }} pages">
            @foreach ($workspace['links'] as $path => $label)
                @if (haven_can_access(site_url($path), 'GET'))
                <a class="nav-link {{ $workspaceActive === $path ? 'active' : '' }}" href="{{ site_url($path) }}" @if($workspaceActive === $path) aria-current="page" @endif>{{ $label }}</a>
                @endif
            @endforeach
            </nav>
        </section>
    @endif
@endforeach
