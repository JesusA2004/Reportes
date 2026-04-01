<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NoiMovement extends Model {

    protected $table = 'fact_noi_movements';

    protected $fillable = [
        'period_id',
        'employee_id',
        'report_upload_id',
        'concept',
        'concept_type',
        'amount',
        'quantity',
        'payroll_type',
        'movement_date',
        'raw_row_hash',
        'raw_payload',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'quantity' => 'decimal:2',
        'movement_date' => 'date',
        'raw_payload' => 'array',
    ];

    public function period(): BelongsTo {
        return $this->belongsTo(Period::class);
    }

    public function employee(): BelongsTo {
        return $this->belongsTo(Employee::class);
    }

    public function reportUpload(): BelongsTo {
        return $this->belongsTo(ReportUpload::class);
    }

}
