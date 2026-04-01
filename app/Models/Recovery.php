<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Recovery extends Model {

    protected $table = 'fact_recoveries';

    protected $fillable = [
        'period_id',
        'report_upload_id',
        'branch_id',
        'contract',
        'client_name',
        'normalized_client_name',
        'capital',
        'interest',
        'tax',
        'charges',
        'total_amount',
        'payment_date',
        'raw_payload',
    ];

    protected $casts = [
        'capital' => 'decimal:2',
        'interest' => 'decimal:2',
        'tax' => 'decimal:2',
        'charges' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'payment_date' => 'date',
        'raw_payload' => 'array',
    ];

    public function period(): BelongsTo {
        return $this->belongsTo(Period::class);
    }

    public function reportUpload(): BelongsTo {
        return $this->belongsTo(ReportUpload::class);
    }

    public function branch(): BelongsTo {
        return $this->belongsTo(Branch::class);
    }

}
