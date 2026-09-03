<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Office extends Model
{
    protected $fillable = [
        'office_name',
        'office_abbr',
        'office_head_id',
        'oic_id',
        'orders',
        'group_by',
        'stat',
    ];

    protected $casts = [
        'oic_id' => 'integer',
        'group_by' => 'integer',
        'stat' => 'integer',
    ];

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * stat is a category rather than a plain on/off flag: 1 marks administrative
     * offices and 2 the colleges and campuses, both of which are live. Only 0 is
     * treated as retired.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('stat', '>', 0);
    }

    /**
     * Resolve an office by name, creating it when the name is new. HRIS sends
     * office names as free text, so sync relies on this to stay idempotent.
     */
    public static function resolveByName(?string $name): ?self
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        return static::firstOrCreate(
            ['office_name' => $name],
            ['office_abbr' => static::abbreviate($name), 'group_by' => 0, 'stat' => 1],
        );
    }

    /**
     * HRIS stores employees.emp_dept as the office id, so a numeric value is a direct
     * reference. Only a non-numeric value is treated as a name to look up or create.
     */
    public static function resolveFromHrisDepartment(mixed $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return static::whereKey((int) $value)->value('id');
        }

        return static::resolveByName($value)?->id;
    }

    public static function abbreviate(string $name): string
    {
        $noise = ['of', 'and', 'for', 'the', 'in', 'on', 'to', 'a'];
        $words = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = '';

        foreach ($words as $word) {
            if (in_array(strtolower($word), $noise, true)) {
                continue;
            }

            $letters .= strtoupper($word[0]);
        }

        return substr($letters !== '' ? $letters : strtoupper($name), 0, 20);
    }
}
