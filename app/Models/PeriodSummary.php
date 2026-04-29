<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PeriodSummary extends Model {
    protected $fillable = ['period_id','source_upload_ids','status','global_metrics','warnings','version','generated_at','generated_by','invalidated_at','invalidated_by','invalidated_reason'];
    protected $casts = ['source_upload_ids'=>'array','global_metrics'=>'array','warnings'=>'array','generated_at'=>'datetime','invalidated_at'=>'datetime'];
    public function period(){return $this->belongsTo(Period::class);}    
    public function branchSummaries(){return $this->hasMany(PeriodBranchSummary::class);}    
    public function corporateSummary(){return $this->hasOne(PeriodCorporateSummary::class);}    
    public function incidents(){return $this->hasMany(PeriodIncident::class);}    
}
