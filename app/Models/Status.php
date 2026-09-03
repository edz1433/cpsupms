<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Status extends Model
{
    /**
     * Ids are fixed reference data: HRIS employees.emp_status stores exactly these
     * values, so they double as the HRIS status codes.
     */
    public const REGULAR = 1;

    public const FULLTIME_PARTTIME = 2;

    public const PARTTIME_PARTTIME = 3;

    public const JOB_ORDER = 4;

    protected $fillable = [
        'status_name',
        'qualifications',
    ];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * HRIS sends emp_status as the status id. A blank or unknown value falls back to
     * Regular, which is how payroll has always read an unrecognised status.
     */
    public static function resolveFromHrisStatus(mixed $value): int
    {
        $value = trim((string) $value);

        if ($value !== '' && ctype_digit($value) && static::whereKey((int) $value)->exists()) {
            return (int) $value;
        }

        return static::resolveByName($value) ?? self::REGULAR;
    }

    /**
     * Resolve a status written as a label rather than an id.
     */
    public static function resolveByName(mixed $value): ?int
    {
        $normalized = static::normalizeText($value);

        if ($normalized === '') {
            return null;
        }

        foreach (static::query()->get() as $status) {
            if (static::normalizeText($status->status_name) === $normalized) {
                return (int) $status->id;
            }
        }

        return match (true) {
            in_array($normalized, ['1', 'permanent', 'full time', 'full-time', 'fulltime', 'plantilla'], true) => self::REGULAR,
            in_array($normalized, ['2', 'fulltime/parttime', 'full-time part-time', 'fulltime parttime', 'fulltime-parttime'], true) => self::FULLTIME_PARTTIME,
            in_array($normalized, ['3', 'parttime/parttime', 'part-time part-time', 'parttime parttime', 'partime/partime'], true) => self::PARTTIME_PARTTIME,
            in_array($normalized, ['4', 'job order', 'job-order', 'jo', 'j.o.', 'contractual', 'contract of service', 'cos'], true) => self::JOB_ORDER,
            default => null,
        };
    }

    private static function normalizeText(mixed $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['_', '\\'], ['-', '/'], (string) $value))));
    }
}
