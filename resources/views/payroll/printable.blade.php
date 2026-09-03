<x-layouts.app page-title="Printable Payroll" page-subtitle="{{ $batch->batch_no }}">
    <section class="card">
        <div class="no-print actions" style="justify-content:flex-end;margin-bottom:12px"><button class="primary-btn" onclick="window.print()"><x-icon name="printer" /> Print</button></div>
        <div class="print-header">
            <img src="{{ asset('images/cpsu-logo.png') }}" alt="CPSU Seal">
            <h2 style="margin:6px 0 0">CENTRAL PHILIPPINES STATE UNIVERSITY</h2>
            <p style="margin:2px 0">Payroll Register - {{ $batch->template->name }}</p>
            <p style="margin:2px 0">{{ $batch->campus->name }} | {{ $batch->period->date_from->format('F d') }} - {{ $batch->period->date_to->format('F d, Y') }} | {{ $batch->fundCluster->fund_source_name }}</p>
        </div>
        @include('payroll.partials.lines-table', ['lines' => $batch->lines])
        <div class="totals" style="margin-top:14px">
            <div class="total-box">Total Gross<br><strong>{{ number_format($batch->total_gross, 2) }}</strong></div>
            <div class="total-box">Total Deductions<br><strong>{{ number_format($batch->total_deductions, 2) }}</strong></div>
            <div class="total-box">Total Net<br><strong>{{ number_format($batch->total_net, 2) }}</strong></div>
            <div class="total-box">Status<br><strong>{{ $batch->status }}</strong></div>
        </div>
        <div class="signatories">
            <div class="signature-box">Prepared by<br><strong>Campus Payroll Administrator</strong></div>
            <div class="signature-box">Checked by<br><strong>University Payroll Administrator</strong></div>
            <div class="signature-box">Approved by<br><strong>Authorized Signatory</strong></div>
            <div class="signature-box">Received by<br><strong>Accounting</strong></div>
        </div>
    </section>
</x-layouts.app>
