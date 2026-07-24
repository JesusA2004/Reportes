<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PeriodRadiographyExport extends Model {
    protected $fillable=['period_summary_id','run_id','export_path','file_type','template_version','metadata','exported_at','exported_by'];
    protected $casts=['metadata'=>'array','exported_at'=>'datetime'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(PeriodRadiographyRun::class, 'run_id');
    }
}
