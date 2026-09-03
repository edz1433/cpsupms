<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\FundCluster;
use App\Services\PayrollFundTypeService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Development helper. Payroll generation fans a run out into one draft per fund, but
 * that only shows up once employees actually carry a fund cluster. This spreads them
 * across the seven funds so the fan-out and the fund tabs can be checked end to end.
 * Real assignments belong on the Employees page; run --reset to undo this.
 */
class AssignRandomFundClusters extends Command
{
    protected $signature = 'payroll:random-fund-clusters
                            {--reset : Clear every employee fund cluster instead of assigning one}';

    protected $description = 'Spread employees randomly across the seven payroll funds so generation can be checked (development only)';

    public function handle(PayrollFundTypeService $fundTypes): int
    {
        if ($this->option('reset')) {
            $cleared = Employee::query()->whereNotNull('fund_cluster_id')->update(['fund_cluster_id' => null]);
            $this->info($cleared.' employees returned to no fund cluster.');

            return self::SUCCESS;
        }

        $fundTypes->ensureMainFundClusters();
        $pools = $this->clusterPools();

        if ($pools->isEmpty()) {
            $this->error('No active fund clusters to assign.');

            return self::FAILURE;
        }

        $types = $pools->keys();
        $assigned = 0;

        // Pick the fund first, then a cluster inside it, so every fund gets employees.
        Employee::query()->select(['id', 'fund_cluster_id'])->chunkById(500, function ($employees) use ($pools, $types, &$assigned) {
            foreach ($employees as $employee) {
                $pool = $pools->get($types->random());
                $employee->update(['fund_cluster_id' => $pool[array_rand($pool)]]);
                $assigned++;
            }
        });

        $this->info($assigned.' employees assigned a random fund cluster.');
        $this->newLine();
        $this->table(['Fund', 'Employees'], $this->distribution());

        return self::SUCCESS;
    }

    /**
     * Specific fund sources per fund, the way the workbook names them. A fund with no
     * specific source (PT) falls back to its own main cluster.
     */
    private function clusterPools(): Collection
    {
        $clusters = FundCluster::query()
            ->whereNull('campus_id')
            ->where('is_active', true)
            ->whereIn('payroll_template_type', PayrollFundTypeService::TYPES)
            ->get();

        return collect(PayrollFundTypeService::TYPES)
            ->mapWithKeys(function (string $type) use ($clusters) {
                $ofType = $clusters->where('payroll_template_type', $type);
                $specific = $ofType->filter(fn (FundCluster $cluster) => $cluster->code !== $type);

                return [$type => ($specific->isNotEmpty() ? $specific : $ofType)->pluck('id')->all()];
            })
            ->filter(fn (array $ids) => $ids !== []);
    }

    private function distribution(): array
    {
        return FundCluster::query()
            ->rightJoin('employees', 'employees.fund_cluster_id', '=', 'fund_clusters.id')
            ->selectRaw('coalesce(fund_clusters.payroll_template_type, "UNASSIGNED") as fund, count(*) as total')
            ->groupBy('fund')
            ->orderBy('fund')
            ->pluck('total', 'fund')
            ->map(fn ($total, $fund) => [$fund, number_format($total)])
            ->values()
            ->all();
    }
}
