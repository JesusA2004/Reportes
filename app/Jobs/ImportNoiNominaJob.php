<?php

namespace App\Jobs;

use App\Services\Imports\NoiNominaImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportNoiNominaJob implements ShouldQueue {

    use Queueable;

    public function __construct(
        public int $reportUploadId
    ) {
    }

    public function handle(NoiNominaImportService $service): void {
        $service->handle($this->reportUploadId);
    }

}
