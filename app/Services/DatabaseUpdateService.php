<?php

namespace App\Services;

use App\Enums\DataSourceCode;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\PeriodIncident;
use App\Models\PeriodSummary;
use App\Services\Imports\LendusIngresosCobranzaImportService;
use App\Services\Imports\NoiNominaImportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DatabaseUpdateService
{
    public function __construct(
        protected NoiNominaImportService $noiNominaImportService,
        protected LendusIngresosCobranzaImportService $lendusIngresosCobranzaImportService,
    )
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

            if (!$upload->stored_path || !Storage::disk('public')->exists($upload->stored_path)) {
                throw ValidationException::withMessages([
                    'period' => "El archivo de {$upload->dataSource?->name} no existe físicamente en storage.",
                ]);
            }
        }

        $noiUpload = $uploads->first(fn ($item) => $item->dataSource?->code === DataSourceCode::NoiNomina->value);
        $cobranzaUpload = $uploads->first(fn ($item) => $item->dataSource?->code === DataSourceCode::LendusIngresosCobranza->value);

        $noiResult = $this->noiNominaImportService->scanForDatabaseUpdate($noiUpload);
        $cobranzaResult = $this->lendusIngresosCobranzaImportService->scanForDatabaseUpdate($cobranzaUpload);

        $summary = PeriodSummary::query()->updateOrCreate(
            ['period_id' => $period->id],
            [
                'status' => 'database_updated',
                'warnings' => [
                    'db_update' => [
                        'employees_detected' => $noiResult['employees_detected'] ?? 0,
                        'promoters_detected' => $cobranzaResult['promoters_detected'] ?? 0,
                        'branches_detected' => $cobranzaResult['branches_detected'] ?? 0,
                    ],
                ],
            ],
        );

        PeriodIncident::query()->where('period_summary_id', $summary->id)->where('type', 'like', 'db_update.%')->delete();
        foreach (array_merge($noiResult['incidents'] ?? [], $cobranzaResult['incidents'] ?? []) as $incident) {
            PeriodIncident::query()->create([
                'period_summary_id' => $summary->id,
                'type' => 'db_update.' . ($incident['type'] ?? 'unknown'),
                'severity' => $incident['severity'] ?? 'high',
                'message' => $incident['message'] ?? 'Incidencia detectada durante actualización de BD.',
                'context' => $incident['context'] ?? null,
            ]);
        }
    }
}
