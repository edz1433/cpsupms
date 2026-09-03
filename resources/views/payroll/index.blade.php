@php
    $scopeName = $selectedCampus ? $selectedCampus->name : 'All campuses';
    // A single-campus user has nothing to switch between; give the table the full width.
    $showCampusMenu = $isUniversityWide || $campuses->count() > 1;
    // A failed generation redirects back here, so reopen the modal with the entered values.
    $reopenCreate = $errors->hasAny([
        'generate', 'campus_id', 'payroll_period_id',
        'payroll_employee_type', 'remarks', 'signatories',
        'signatories.prepared_by', 'signatories.certified_correct_by',
        'signatories.approved_by', 'signatories.certified_payment_by',
    ]);
@endphp
<x-layouts.app page-title="Payroll Batches" page-subtitle="Draft, submitted, returned, approved, and printed payroll">
    <div class="payroll-campus-layout {{ $showCampusMenu ? '' : 'is-single-campus' }}">
        @if($showCampusMenu)
            @include('payroll.partials.campus-menu')
        @endif

        <div class="card">
            <div class="card-header">
                <div>
                    <h2>{{ $scopeName }}</h2>
                    <div class="card-kicker">
                        {{ $selectedCampus ? 'Payroll runs filed under '.$selectedCampus->code : 'Payroll runs across every campus you can access' }}
                    </div>
                </div>
                @if($canManagePayroll)
                    <button class="primary-btn" type="button" data-open-payroll-create>
                        <x-icon name="plus" /> Generate Payroll{{ $selectedCampus ? ' - '.$selectedCampus->code : '' }}
                    </button>
                @endif
            </div>

            <div class="campus-summary">
                <div class="campus-stat"><small>Batches</small><strong>{{ number_format($summary['total']) }}</strong></div>
                <div class="campus-stat"><small>Draft</small><strong>{{ number_format($summary['draft']) }}</strong></div>
                <div class="campus-stat"><small>For review</small><strong>{{ number_format($summary['for_review']) }}</strong></div>
                <div class="campus-stat {{ $summary['returned'] > 0 ? 'flagged' : '' }}"><small>Returned</small><strong>{{ number_format($summary['returned']) }}</strong></div>
                <div class="campus-stat"><small>Approved / printed</small><strong>{{ number_format($summary['cleared']) }}</strong></div>
                <div class="campus-stat net"><small>Total net</small><strong>{{ number_format($summary['net'], 2) }}</strong></div>
            </div>

            @include('payroll.partials.batch-table', ['batches' => $batches, 'showCampus' => ! $selectedCampus])

            <div style="margin-top:16px">{{ $batches->links() }}</div>
        </div>
    </div>

    @if($canManagePayroll)
        <dialog class="modal" id="payroll-create-modal" @if($reopenCreate) data-auto-open="1" @endif>
            <form class="modal-panel" method="POST" action="{{ route('payroll.store') }}"
                data-process-overlay-trigger
                data-process-title="Generating payroll"
                data-process-message="Reading attendance from HRIS and computing a draft for every payroll fund. This can take a few minutes for a full campus.">
                @csrf
                <div class="modal-header">
                    <div>
                        <h2>Generate Payroll</h2>
                    </div>
                    <button class="ghost-btn icon-btn" type="button" data-close-payroll-create title="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <div class="modal-body">
                    @if($reopenCreate)
                        <div class="alert danger"><x-icon name="alert" /> <span>{{ $errors->first() }}</span></div>
                    @endif
                    @include('payroll.partials.create-form')
                </div>

                <div class="modal-footer">
                    <button class="ghost-btn" type="button" data-close-payroll-create>Cancel</button>
                    <button class="primary-btn" type="submit"><x-icon name="check" /> Compute Drafts For All Funds</button>
                </div>
            </form>
        </dialog>

        <script>
            (function () {
                const modal = document.getElementById('payroll-create-modal');

                if (!modal) {
                    return;
                }

                document.querySelectorAll('[data-open-payroll-create]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        modal.showModal();
                    });
                });

                document.querySelectorAll('[data-close-payroll-create]').forEach(function (button) {
                    button.addEventListener('click', function () {
                        modal.close();
                    });
                });

                modal.addEventListener('click', function (event) {
                    if (event.target === modal) {
                        modal.close();
                    }
                });

                if (modal.dataset.autoOpen === '1') {
                    modal.showModal();
                }
            })();
        </script>
    @endif
</x-layouts.app>
