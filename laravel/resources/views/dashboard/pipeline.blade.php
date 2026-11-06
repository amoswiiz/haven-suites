@if(auth()->user()?->can('sales.view'))
<style>
.hs-pipeline{margin:0 0 26px;color:#23395f}.hs-pipeline .hs-section-head{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}.hs-pipeline h2{font-size:1.2rem;font-weight:750;line-height:1.4;margin:0}.hs-pipeline .hs-section-head p{color:#52627a;font-size:.875rem;margin:6px 0 0}.hs-pipeline .hs-actions{display:flex;gap:8px;flex-wrap:wrap}.hs-pipeline .hs-pipeline-button{min-height:44px;display:inline-flex;align-items:center;justify-content:center;font-size:.875rem;padding:9px 15px;border:1px solid #294780;border-radius:7px;background:#fff;color:#294780;text-decoration:none}.hs-pipeline .hs-pipeline-button.primary{background:#294780;color:#fff}.hs-pipeline .hs-pipeline-button:hover{background:#edf2fa}.hs-pipeline .hs-pipeline-button.primary:hover{background:#203456}.hs-pipeline-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:12px}.hs-pipeline-stage{background:#fff;border:1px solid #dce3ed;border-top:3px solid #8295ba;border-radius:10px;padding:16px 14px;display:flex;flex-direction:column;gap:10px;min-width:0;text-decoration:none;color:#23395f}.hs-pipeline-stage:hover,.hs-pipeline-stage[aria-current="page"]{background:#f0f5ff;border-color:#294780}.hs-pipeline-stage span{font-size:.8rem;font-weight:650;overflow-wrap:anywhere}.hs-pipeline-stage strong{font-size:1.8rem;line-height:1.1}.hs-pipeline-stage[data-stage="new"]{border-top-color:#e98d50}.hs-pipeline-stage[data-stage="won"]{border-top-color:#39815b}.hs-pipeline-stage[data-stage="lost"]{border-top-color:#8c97a8}.hs-pipeline-summary{display:flex;flex-wrap:wrap;gap:12px 24px;margin-bottom:14px;font-size:.875rem;color:#52627a}.hs-pipeline a:focus-visible{outline:3px solid #e98d50;outline-offset:4px}
@media(max-width:1199px){.hs-pipeline-grid{grid-template-columns:repeat(4,minmax(0,1fr))}}@media(max-width:767px){.hs-pipeline-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.hs-pipeline .hs-actions{width:100%}}
</style>
<section class="hs-pipeline" aria-labelledby="sales-pipeline-heading">
    <div class="hs-section-head">
        <div><h2 id="sales-pipeline-heading">Sales pipeline</h2><p class="mb-0 mt-1">Company-wide enquiries across all dates. Select a stage to see its guests.</p></div>
        <div class="hs-actions">
            <a class="hs-pipeline-button" href="{{ route('sales.index', app('currentTenant')->slug) }}">Open pipeline</a>
            @if(auth()->user()->can('sales.manage'))<a class="hs-pipeline-button primary" href="{{ route('sales.create', app('currentTenant')->slug) }}">New enquiry</a>@endif
        </div>
    </div>
    <div class="hs-pipeline-summary">
        <span><strong>{{ number_format($pipelineSummary['openCount']) }}</strong> open enquiries</span>
        <a href="{{ route('sales.index', [app('currentTenant')->slug, 'overdue' => 1]) }}"><strong>{{ number_format($pipelineSummary['overdueCount']) }}</strong> overdue follow-ups</a>
        <span>{{ number_format($pipelineSummary['total']) }} total enquiries</span>
    </div>
    <nav class="hs-pipeline-grid" aria-label="Enquiries by pipeline stage">
        @foreach($pipelineSummary['stages'] as $stage)
            <a class="hs-pipeline-stage" data-stage="{{ $stage['key'] }}" href="{{ route('sales.index', [app('currentTenant')->slug, 'stage' => $stage['key']]) }}" @if(($pipelineCurrentStage ?? null) === $stage['key']) aria-current="page" @endif>
                <span>{{ $stage['label'] }}</span><strong>{{ number_format($stage['count']) }}</strong>
            </a>
        @endforeach
    </nav>
</section>
@endif
