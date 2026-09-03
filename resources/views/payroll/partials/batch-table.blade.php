@php($showCampus = $showCampus ?? true)
<div class="table-wrap">
    <table class="table">
        <thead><tr><th>Batch</th>@if($showCampus)<th>Campus</th>@endif<th>Period</th><th>Group</th><th>Fund</th><th>Status</th><th class="num">Net</th><th>Action</th></tr></thead>
        <tbody>
        @forelse($batches as $batch)
            <tr>
                <td><strong>{{ $batch->batch_no }}</strong></td>
                @if($showCampus)<td>{{ $batch->campus?->code }}</td>@endif
                <td>{{ $batch->period?->name }}</td>
                <td><span class="badge gray">{{ $batch->snapshot['payroll_employee_type_label'] ?? 'Regular' }}</span></td>
                <td>{{ $batch->fundCluster?->fund_source_name }}</td>
                <td><span class="badge {{ $batch->status === 'Approved for Printing' ? 'green' : ($batch->status === 'Returned for Correction' ? 'red' : 'gold') }}">{{ $batch->status }}</span></td>
                <td class="num">{{ number_format($batch->total_net, 2) }}</td>
                <td>
                    <div class="actions">
                        <a class="ghost-btn" href="{{ route('payroll.show', $batch) }}"><x-icon name="open" /> Open</a>
                        @if($batch->status === \App\Models\PayrollBatch::DRAFT)
                            <form
                                method="POST"
                                action="{{ route('payroll.destroy', $batch) }}"
                                data-confirm-delete
                                data-confirm-title="Delete draft payroll?"
                                data-confirm-text="This will permanently delete {{ $batch->batch_no }} and its payroll lines."
                                data-confirm-button="Delete Draft"
                            >
                                @csrf
                                @method('DELETE')
                                <button class="danger-btn icon-btn" type="submit" title="Delete draft" aria-label="Delete draft payroll batch"><x-icon name="trash" /></button>
                            </form>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="{{ $showCampus ? 8 : 7 }}"><div class="table-empty">No payroll batches for this campus yet.</div></td></tr>
        @endforelse
        </tbody>
    </table>
</div>
