<?php

namespace App\Services;

use App\Enums\DataSourceCode;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\ReportAnalysisService;
use Illuminate\Validation\ValidationException;

class DatabaseUpdateService
{
    public function __construct(protected ReportAnalysisService $analysisService)
    {
    }

    public function updateForPeriod(Period $period): void
    {
        $required = [
            DataSourceCode::NoiNomina->value,
            DataSourceCode::LendusIngresosCobranza->value,
        ];

        $uploads = $period->reportUploads()->with('dataSource')->latest('id')->get();

        foreach ($required as $code) {
            $upload = $uploads->first(fn ($item) => $item->dataSource?->code === $code);
            if (!$upload) {
                throw ValidationException::withMessages([
                    'period' => 'Debes cargar NOI Nómina y Lendus Ingresos Cobranza antes de actualizar la BD.',
                ]);
            }

            $this->analysisService->analyze($upload);
        }

        PeriodSummary::query()->updateOrCreate(
            ['period_id' => $period->id],
            [
                'status' => 'database_updated',
                'warnings' => [],
            ],
        );
    }
}
