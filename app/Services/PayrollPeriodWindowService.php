<?php

namespace App\Services;

use App\Models\PayrollPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class PayrollPeriodWindowService
{
    public function query(?CarbonImmutable $today = null): Builder
    {
        $this->ensure($today);

        [$start, $end] = $this->bounds($today);

        return PayrollPeriod::query()
            ->with('campus')
            ->whereNull('campus_id')
            ->whereDate('date_from', '>=', $start)
            ->whereDate('date_from', '<=', $end)
            ->orderBy('date_from')
            ->orderBy('date_to');
    }

    public function ensure(?CarbonImmutable $today = null): void
    {
        foreach ($this->windows($today) as [$from, $to]) {
            // Keyed on the window name: the date columns are cast to dates, so matching
            // on a plain Y-m-d string never hits a stored "Y-m-d 00:00:00" and would
            // create a second copy of every period on each run.
            PayrollPeriod::updateOrCreate(
                [
                    'campus_id' => null,
                    'name' => $this->name($from, $to),
                ],
                [
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                    'period_type' => 'semi-monthly',
                    'payroll_type' => null,
                    'is_locked' => false,
                ]
            );
        }
    }

    /**
     * Every semi-monthly window of the current year - day 1 to 15 and day 16 to the end
     * of each month - so periods never have to be created by hand.
     */
    private function windows(?CarbonImmutable $today = null): array
    {
        $january = ($today ?: CarbonImmutable::now(config('app.timezone')))->startOfYear();

        return collect(range(0, 11))
            ->map(fn (int $offset) => $january->addMonthsNoOverflow($offset))
            ->flatMap(fn (CarbonImmutable $month) => [
                [$month->startOfMonth(), $month->setDay(15)],
                [$month->setDay(16), $month->endOfMonth()],
            ])
            ->all();
    }

    private function bounds(?CarbonImmutable $today = null): array
    {
        $year = ($today ?: CarbonImmutable::now(config('app.timezone')));

        return [$year->startOfYear()->toDateString(), $year->endOfYear()->toDateString()];
    }

    private function name(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->format('F ').$from->day.'-'.$to->day.', '.$from->year;
    }
}
