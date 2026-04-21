<?php

namespace App\Jobs;

use App\Models\ReportUpload;
use App\Services\ReportAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportNoiNominaJob implements ShouldQueue {

    use Queueable;

    public function __construct(
        public int $reportUploadId
    ) {
    }

    public function handle(ReportAnalysisService $service): void {
        $upload = ReportUpload::query()->find($this->reportUploadId);
        if (!$upload) {
            return;
        }
        $service->analyze($upload);
    }

}
