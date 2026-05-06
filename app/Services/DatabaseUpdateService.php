<?php

namespace App\Services;

use App\Enums\DataSourceCode;
use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
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
    ) {}

    public function updateForPeriod(Period $period, ?PeriodDatabaseUpdateRun $run = null): void
    {
        $required = [
            DataSourceCode::NoiNomina->value,
            DataSourceCode::LendusIngresosCobranza->value,
        ];

        $this->progress($run, 'Validando fuentes obligatorias…', 5);
        $this->checkCancelled($run);

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

        $noiUpload      = $uploads->first(fn ($item) => $item->dataSource?->code === DataSourceCode::NoiNomina->value);
        $cobranzaUpload = $uploads->first(fn ($item) => $item->dataSource?->code === DataSourceCode::LendusIngresosCobranza->value);

        $this->progress($run, 'Leyendo NOI Nómina…', 25);
        $this->checkCancelled($run);
        $noiResult = $this->noiNominaImportService->scanForDatabaseUpdate($noiUpload);

        $this->progress($run, 'Leyendo Lendus Ingresos Cobranza…', 55);
        $this->checkCancelled($run);
        $cobranzaResult = $this->lendusIngresosCobranzaImportService->scanForDatabaseUpdate($cobranzaUpload, $run, 55, 78);

        $this->progress($run, 'Guardando resumen operativo y registrando incidencias…', 80);
        $this->checkCancelled($run);

        $summary = PeriodSummary::query()->updateOrCreate(
            ['period_id' => $period->id],
            [
                'status'             => 'database_updated',
                'invalidated_at'     => null,
                'invalidated_by'     => null,
                'invalidated_reason' => null,
                'warnings'           => [
                    'db_update' => [
                        'employees_detected' => $noiResult['employees_detected'] ?? 0,
                        'promoters_detected' => $cobranzaResult['promoters_detected'] ?? 0,
                        'branches_detected'  => $cobranzaResult['branches_detected'] ?? 0,
                    ],
                ],
            ],
        );

        PeriodIncident::query()
            ->where('period_summary_id', $summary->id)
            ->where('type', 'like', 'db_update.%')
            ->delete();

        foreach (array_merge($noiResult['incidents'] ?? [], $cobranzaResult['incidents'] ?? []) as $incident) {
            PeriodIncident::query()->create([
                'period_summary_id' => $summary->id,
                'type'              => 'db_update.' . ($incident['type'] ?? 'unknown'),
                'severity'          => $incident['severity'] ?? 'high',
                'message'           => $incident['message'] ?? 'Incidencia detectada durante actualización de BD.',
                'context'           => $incident['context'] ?? null,
            ]);
        }

        $stats = [
            'employees_detected' => $noiResult['employees_detected'] ?? 0,
            'promoters_detected' => $cobranzaResult['promoters_detected'] ?? 0,
            'branches_detected'  => $cobranzaResult['branches_detected'] ?? 0,
            'incidents_created'  => count(array_merge($noiResult['incidents'] ?? [], $cobranzaResult['incidents'] ?? [])),
        ];

        $this->progress($run, 'Actualización completada.', 100, $stats);
    }

    private function progress(?PeriodDatabaseUpdateRun $run, string $step, int $percent, array $stats = []): void
    {
        if (!$run) return;

        $meta                    = $run->metadata ?? [];
        $meta['current_step']    = $step;
        $meta['progress_percent'] = $percent;
        if (!empty($stats)) $meta['stats'] = $stats;

        $run->update(['log' => $step, 'metadata' => $meta]);
    }

    private function checkCancelled(?PeriodDatabaseUpdateRun $run): void
    {
        if (!$run) return;
        $run->refresh();
        if (!in_array($run->status, ['running', 'queued'], true)) {
            throw new \RuntimeException('El proceso fue cancelado antes de completarse.');
        }
    }
}
