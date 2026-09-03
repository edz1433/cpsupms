<x-layouts.app page-title="Generate Payroll" page-subtitle="Select campus, period, and employee group - a draft is generated for every payroll fund">
    <form class="card" method="POST" action="{{ route('payroll.store') }}">
        @csrf
        <div class="card-header">
            <div><h2>Payroll Generation Details</h2><div class="card-kicker">One run creates a draft per payroll fund, each storing computed line snapshots for review.</div></div>
        </div>
        @include('payroll.partials.create-form')
        <div class="actions" style="margin-top:18px"><button class="primary-btn" type="submit"><x-icon name="check" /> Compute Drafts For All Funds</button><a class="ghost-btn" href="{{ route('payroll.index', $selectedCampusId ? ['campus' => $selectedCampusId] : []) }}">Cancel</a></div>
    </form>
</x-layouts.app>
