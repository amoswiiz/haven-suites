@if(!empty($listing['virtual_tour']) && empty($preview))
    <section class="stay-detail-section" id="stay-tour" aria-labelledby="stay-tour-heading">
        <h2 id="stay-tour-heading">360° virtual tour</h2>
        <p class="stay-muted">Walk through the apartment from your phone or computer. The tour loads from an outside service when you open it.</p>
        <div class="stay-tour" data-virtual-tour data-tour-url="{{ $listing['virtual_tour'] }}">
            <button type="button" class="stay-button" data-open-tour>Open the 360° tour</button>
        </div>
    </section>
@endif
