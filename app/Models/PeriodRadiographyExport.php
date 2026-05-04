<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PeriodRadiographyExport extends Model { protected $fillable=['period_summary_id','export_path','template_version','exported_at','exported_by']; protected $casts=['exported_at'=>'datetime']; }
