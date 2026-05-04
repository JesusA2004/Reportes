<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodIncident extends Model
{
    protected $fillable = ['period_summary_id', 'type', 'severity', 'message', 'context'];

    protected $casts = ['context' => 'array'];

    public function periodSummary(): BelongsTo
    {
        return $this->belongsTo(PeriodSummary::class);
    }
}
