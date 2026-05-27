<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodRadiographyRun extends Model
{
    protected $fillable = [
        'period_id',
        'period_summary_id',
        'status',
        'log',
        'metadata',
        'error_message',
        'started_at',
        'finished_at',
        'queued_at',
        'cancelled_at',
        'cancelled_by',
        'output_excel_path',
        'output_pdf_path',
        'created_by',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
        'queued_at'    => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata'     => 'array',
    ];
}
