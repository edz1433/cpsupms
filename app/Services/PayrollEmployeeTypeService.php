<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class PayrollEmployeeTypeService
{
    public const REGULAR = 'regular';

    public const FULLTIME_PARTTIME = 'fulltime_parttime';

    public const PARTTIME_PARTTIME = 'parttime_parttime';

    public const JOB_ORDER = 'job_order';

    public const TYPES = [
        self::REGULAR => 'Regular',
        self::FULLTIME_PARTTIME => 'Full-time/Part-time',
        self::PARTTIME_PARTTIME => 'Part-time/Part-time',
        self::JOB_ORDER => 'Job Order',
    ];

    private const MATCHES = [
        self::REGULAR => ['1', 'regular', 'permanent', 'full time', 'full-time', 'fulltime', 'plantilla'],
        self::FULLTIME_PARTTIME => ['2', 'full-time/part-time', 'fulltime/parttime', 'full-time part-time', 'fulltime parttime', 'fulltime-parttime', 'full-time/parttime', 'fulltime/part-time'],
        self::PARTTIME_PARTTIME => ['3', 'part-time/part-time', 'parttime/parttime', 'part-time part-time', 'parttime parttime', 'parttime-parttime', 'partime/partime', 'part-time/part-tiem'],
        self::JOB_ORDER => ['4', 'job order', 'job-order', 'jo', 'j.o.', 'contractual', 'contract of service', 'cos'],
    ];

    public function options(): array
    {
        return self::TYPES;
    }

    public function label(?string $type): string
    {
        return self::TYPES[$type] ?? self::TYPES[self::REGULAR];
    }

    public function normalize(mixed $value): string
    {
        if (array_key_exists((string) $value, self::TYPES)) {
            return self::TYPES[(string) $value];
        }

        $normalized = $this->normalizeText($value);

        foreach (self::MATCHES as $key => $matches) {
            if (in_array($normalized, $matches, true)) {
                return self::TYPES[$key];
            }
        }

        if (array_key_exists($normalized, self::TYPES)) {
            return self::TYPES[$normalized];
        }

        return self::TYPES[self::REGULAR];
    }

    public function apply(Builder $query, ?string $type): Builder
    {
        $key = array_key_exists((string) $type, self::TYPES)
            ? (string) $type
            : $this->keyForLabel($type);
        $matches = self::MATCHES[$key] ?? self::MATCHES[self::REGULAR];
        $matches[] = $this->normalizeText(self::TYPES[$key] ?? self::TYPES[self::REGULAR]);

        return $query->where(function (Builder $query) use ($matches) {
            foreach ($matches as $match) {
                $query->orWhereRaw('LOWER(TRIM(employment_type)) = ?', [$match]);
            }
        });
    }

    private function keyForLabel(mixed $label): string
    {
        $normalized = $this->normalizeText($label);

        foreach (self::TYPES as $key => $typeLabel) {
            if ($this->normalizeText($typeLabel) === $normalized) {
                return $key;
            }
        }

        return self::REGULAR;
    }

    private function normalizeText(mixed $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['_', '\\'], ['-', '/'], (string) $value))));
    }
}
