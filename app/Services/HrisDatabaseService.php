<?php

namespace App\Services;

use App\Models\HrisSyncLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrisDatabaseService
{
    private ?bool $hasMonthlySalary = null;

    public function checkConnection(?User $user = null): array
    {
        $started = microtime(true);

        try {
            DB::connection('hris')->select('SELECT 1');
            $this->log($user, 'connection_check', 'connected', $started);

            return [
                'status' => 'connected',
                'message' => 'Connected directly to the HRIS database.',
            ];
        } catch (\Throwable $exception) {
            $this->log($user, 'connection_check', 'unavailable', $started, $exception->getMessage());

            return [
                'status' => 'unavailable',
                'message' => 'The HRIS database is unavailable.',
            ];
        }
    }

    public function employees(array $filters = [], ?User $user = null): array
    {
        $started = microtime(true);

        $columns = [
            'emp_ID',
            'fname',
            'mname',
            'lname',
            'prefix',
            'suffix',
            'position',
            'emp_dept',
            'emp_status',
            'camp_id',
        ];

        // Older HRIS databases have no salary column; read it only where it exists so
        // the sync still runs against them.
        if ($this->hrisHasMonthlySalary()) {
            $columns[] = 'monthly_salary';
        }

        try {
            $query = DB::connection('hris')
                ->table('employees')
                ->select($columns)
                ->where('stat_1', 1)
                ->whereNotNull('emp_ID')
                ->where('emp_ID', '<>', '');

            if (filled($filters['campus_id'] ?? null)) {
                $query->where('camp_id', $filters['campus_id']);
            }

            if (filled($filters['emp_status'] ?? null) && $filters['emp_status'] !== 'all') {
                $query->where('emp_status', $filters['emp_status']);
            }

            if (filled($filters['emp_id'] ?? null)) {
                $query->where('emp_ID', $filters['emp_id']);
            }

            $rows = $query
                ->orderBy('lname')
                ->orderBy('fname')
                ->get()
                ->map(fn (object $row) => (array) $row)
                ->all();

            $this->log($user, 'employees', 'connected', $started, null, $filters + ['records_read' => count($rows)]);

            return [
                'status' => 'connected',
                'data' => ['data' => $rows],
            ];
        } catch (\Throwable $exception) {
            $this->log($user, 'employees', 'unavailable', $started, $exception->getMessage(), $filters);

            return [
                'status' => 'unavailable',
                'message' => 'Unable to read employees from the HRIS database.',
            ];
        }
    }

    /**
     * Cached for the request: the column check costs a query and the sync reads
     * employees more than once per run.
     */
    private function hrisHasMonthlySalary(): bool
    {
        if ($this->hasMonthlySalary === null) {
            try {
                $this->hasMonthlySalary = Schema::connection('hris')->hasColumn('employees', 'monthly_salary');
            } catch (\Throwable) {
                $this->hasMonthlySalary = false;
            }
        }

        return $this->hasMonthlySalary;
    }

    private function log(
        ?User $user,
        string $type,
        string $status,
        float $started,
        ?string $error = null,
        array $payload = [],
    ): void {
        HrisSyncLog::create([
            'user_id' => $user?->id,
            'request_type' => $type,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'error_message' => $error ? str($error)->limit(240)->toString() : null,
            'payload_summary' => $payload,
        ]);
    }
}
