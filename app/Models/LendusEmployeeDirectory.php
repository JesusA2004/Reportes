<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LendusEmployeeDirectory extends Model
{
    protected $table = 'lendus_employee_directory';

    protected $fillable = [
        'report_upload_id',
        'codigo',
        'nombre',
        'normalized_name',
        'puesto',
        'area',
        'promotor',
        'distribuidor',
        'estatus',
        'fecha_inicio',
        'inferred_branch_id',
        'inferred_branch_name',
        'is_operational',
        'raw_payload',
    ];

    protected $casts = [
        'fecha_inicio'   => 'date',
        'is_operational' => 'boolean',
        'raw_payload'    => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'inferred_branch_id');
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class, 'report_upload_id');
    }
}
