<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodRadiographyRun extends Model
{
    protected $fillable = [
        'period_id',
        'period_summary_id',
        'report_type',
        'scope',
        'branch_id',
        'employee_id',
        'comparison_period_id',
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

    public function exports(): HasMany
    {
        return $this->hasMany(PeriodRadiographyExport::class, 'run_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function comparisonPeriod(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'comparison_period_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Tupla que identifica de forma única "qué reporte es este" — un simple y un
     * comparativo del mismo periodo tienen identidades distintas y por lo tanto
     * pueden coexistir sin pisarse.
     */
    public function identity(): array
    {
        return [
            'period_id'            => $this->period_id,
            'report_type'          => $this->report_type ?? 'simple',
            'scope'                => $this->scope ?? 'general',
            'comparison_period_id' => $this->comparison_period_id,
            'branch_id'            => $this->branch_id,
            'employee_id'          => $this->employee_id,
        ];
    }
}
