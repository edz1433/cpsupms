{{--
    Blocking overlay for long HRIS-backed runs. It is a <dialog> so it lands in the
    browser's top layer and covers the Generate Payroll modal, and so the page behind
    it cannot be clicked while the run is in flight.
--}}
<dialog class="process-overlay" data-process-overlay aria-labelledby="process-overlay-title">
    <div class="process-overlay-panel" role="status" aria-live="assertive" aria-busy="true">
        <div class="process-spinner" aria-hidden="true">
            <span class="process-spinner-track"></span>
            <span class="process-spinner-arc"></span>
            <span class="process-spinner-arc alt"></span>
            <span class="process-spinner-mark">CPSU</span>
        </div>

        <h2 id="process-overlay-title" data-process-overlay-title>Working</h2>
        <p class="process-overlay-message" data-process-overlay-message>This may take a moment.</p>

        <div class="process-progress" aria-hidden="true"><span></span></div>

        <p class="process-overlay-hint">
            <x-icon name="lock" />
            <span>Keep this tab open. Closing or refreshing now can leave the run incomplete.</span>
        </p>
    </div>
</dialog>
