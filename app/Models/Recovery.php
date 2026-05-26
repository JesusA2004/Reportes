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
        'promoter_name',
        'product_name',
        'operation',
        'concept',
        'transaction',
        'capital',
        'interest',
        'tax',
        'charges',
        'charges_due',
        'excedente',
        'total_amount',
        'days_past_due',
        'payment_date',
        'is_savehearts',
        'savehearts_crece_share',
        'raw_payload',
    ];

    protected $casts = [
        'capital'                 => 'decimal:2',
        'interest'                => 'decimal:2',
        'tax'                     => 'decimal:2',
        'charges'                 => 'decimal:2',
        'charges_due'             => 'decimal:2',
        'excedente'               => 'decimal:2',
        'total_amount'            => 'decimal:2',
        'days_past_due'           => 'integer',
        'payment_date'            => 'date',
        'is_savehearts'           => 'boolean',
        'savehearts_crece_share'  => 'decimal:2',
        'raw_payload'             => 'array',
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
