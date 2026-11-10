@once
    @push('styles')<link rel="stylesheet" href="{{ asset('assets/css/listing-management.css') }}?v=1">@endpush
    @push('scripts')<script src="{{ asset('assets/js/listing-management.js') }}?v=1" defer></script>@endpush
@endonce
<div class="lm-save-status" data-listing-save-status hidden>
    <p class="lm-save-label" data-save-label role="status" aria-live="polite"></p>
    <progress data-save-progress max="100" aria-label="Upload progress"></progress>
    <p class="lm-save-help" data-save-help></p>
</div>
<div class="lm-save-errors" data-listing-save-errors role="alert" tabindex="-1" hidden></div>
<p class="lm-file-summary" data-listing-file-summary role="status" aria-live="polite"></p>
