<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function record(string $event, ?User $user = null, ?Model $model = null, ?string $remarks = null, array $properties = []): void
    {
        AuditLog::create([
            'user_id' => $user?->id,
            'campus_id' => $properties['campus_id'] ?? $user?->campus_id,
            'event' => $event,
            'auditable_type' => $model ? $model::class : null,
            'auditable_id' => $model?->getKey(),
            'remarks' => $remarks,
            'properties' => $properties,
        ]);
    }
}
