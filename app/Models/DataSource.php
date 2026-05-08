<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataSource extends Model {

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'is_required_for_bd',
        'is_required_for_report',
    ];

    protected $casts = [
        'is_active'              => 'boolean',
        'is_required_for_bd'     => 'boolean',
        'is_required_for_report' => 'boolean',
    ];

    public function reportUploads(): HasMany {
        return $this->hasMany(ReportUpload::class);
    }

}
