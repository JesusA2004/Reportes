<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FactImss extends Model {

    protected $table = 'fact_imss';

    protected $fillable = [
        'period_id',
        'branch_id',
        'branch_name',
        'collaborators_count',
        'monthly_fee_per_employee',
        'amount',
        'included_in_report',
        'excluded_reason',
        'source',
    ];

    protected $casts = [
        'included_in_report'       => 'boolean',
        'monthly_fee_per_employee' => 'float',
        'amount'                   => 'float',
    ];

    public function period(): BelongsTo {
        return $this->belongsTo(Period::class);
    }
}
