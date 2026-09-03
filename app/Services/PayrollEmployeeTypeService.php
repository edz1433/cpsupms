<?php

namespace App\Services;

use App\Models\Status;
use Illuminate\Database\Eloquent\Builder;

class PayrollEmployeeTypeService
{
    public const REGULAR = 'regular';

    public const FULLTIME_PARTTIME = 'fulltime_parttime';

    public const PARTTIME_PARTTIME = 'parttime_parttime';

    public const JOB_ORDER = 'job_order';

    /**
     * The payroll run vocabulary maps one-to-one onto the statuses table, whose ids are
     * also the HRIS emp_status codes.
     */
    public const STATUS_IDS = [
        self::REGULAR => Status::REGULAR,
        self::FULLTIME_PARTTIME => Status::FULLTIME_PARTTIME,
        self::PARTTIME_PARTTIME => Status::PARTTIME_PARTTIME,
        self::JOB_ORDER => Status::JOB_ORDER,
    ];

    /**
     * Fallback labels used only before the statuses table has been seeded. The table is
     * the source of truth once it has rows.
     */
    public const TYPES = [
        self::REGULAR => 'Regular',
        self::FULLTIME_PARTTIME => 'Full-time/Part-time',
        self::PARTTIME_PARTTIME => 'Part-time/Part-time',
        self::JOB_ORDER => 'Job Order',
    ];

    private ?array $labelsByStatusId = null;

    /**
     * Slug => label, with labels read from the statuses table.
     */
    public function options(): array
    {
        $labels = $this->labelsByStatusId();

        $options = [];

        foreach (self::STATUS_IDS as $key => $statusId) {
            $options[$key] = $labels[$statusId] ?? self::TYPES[$key];
        }

        return $options;
    }

    /**
     * Every status row, for pickers that should show exactly what the table holds.
     */
    public function statuses()
    {
        return Status::query()->orderBy('id')->get();
    }

    public function label(?string $type): string
    {
        return $this->options()[$type] ?? $this->options()[self::REGULAR];
    }

    public function statusId(?string $type): int
    {
        return self::STATUS_IDS[$type] ?? Status::REGULAR;
    }

    /**
     * Turn a status id, slug or any known label into its display label.
     */
    public function normalize(mixed $value): string
    {
        $labels = $this->labelsByStatusId();

        if (is_string($value) && array_key_exists($value, self::STATUS_IDS)) {
            return $labels[self::STATUS_IDS[$value]] ?? self::TYPES[$value];
        }

        $statusId = Status::resolveFromHrisStatus($value);

        return $labels[$statusId] ?? self::TYPES[self::REGULAR];
    }

    public function apply(Builder $query, ?string $type): Builder
    {
        return $query->where('status_id', $this->keyToStatusId($type));
    }

    private function keyToStatusId(?string $type): int
    {
        if (is_string($type) && array_key_exists($type, self::STATUS_IDS)) {
            return self::STATUS_IDS[$type];
        }

        return Status::resolveFromHrisStatus($type);
    }

    private function labelsByStatusId(): array
    {
        return $this->labelsByStatusId ??= Status::query()
            ->pluck('status_name', 'id')
            ->map(fn ($name) => (string) $name)
            ->all();
    }
}
