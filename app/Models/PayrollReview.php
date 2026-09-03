<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollReview extends Model
{
    protected $fillable = [
        'payroll_batch_id',
        'payroll_line_id',
        'reviewed_by',
        'action',
        'remarks',
    ];

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
