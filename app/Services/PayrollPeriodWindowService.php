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
            PayrollPeriod::updateOrCreate(
                [
                    'campus_id' => null,
                    'date_from' => $from->toDateString(),
                    'date_to' => $to->toDateString(),
                ],
                [
                    'name' => $this->name($from, $to),
                    'period_type' => 'semi-monthly',
                    'payroll_type' => null,
                    'is_locked' => false,
                ]
            );
        }
    }

    private function windows(?CarbonImmutable $today = null): array
    {
        $current = ($today ?: CarbonImmutable::now(config('app.timezone')))->startOfMonth();
        $previous = $current->subMonthNoOverflow();

        return collect([$previous, $current])
            ->flatMap(fn (CarbonImmutable $month) => [
                [$month->startOfMonth(), $month->setDay(15)],
                [$month->setDay(16), $month->endOfMonth()],
            ])
            ->all();
    }

    private function bounds(?CarbonImmutable $today = null): array
    {
        $windows = $this->windows($today);

        return [$windows[0][0]->toDateString(), $windows[3][1]->toDateString()];
    }

    private function name(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->format('F ').$from->day.'-'.$to->day.', '.$from->year;
    }
}
