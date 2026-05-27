<?php

namespace App\Services;

use App\Enums\DataSourceCode;
use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\PeriodIncident;
use App\Models\PeriodSummary;
use App\Services\EmployeeBranchAutoMatchService;
use App\Services\Imports\LendusEmployeeDirectoryImportService;
use App\Services\Imports\LendusIngresosCobranzaImportService;
use App\Services\Imports\NoiNominaImportService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DatabaseUpdateService
{
    public function __construct(
        protected NoiNominaImportService $noiNominaImportService,
        protected LendusIngresosCobranzaImportService $lendusIngresosCobranzaImportService,
        protected LendusEmployeeDirectoryImportService $lendusEmployeeDirectoryImportService,
        protected EmployeeBranchAutoMatchService $employeeBranchAutoMatchService,
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
        $fiscalUpload   = $uploads->first(fn ($item) => $item->dataSource?->code === DataSourceCode::NoiNominaFiscal->value);

        $this->progress($run, 'Leyendo NOI Nómina…', 20, [], 'noi_nomina', basename($noiUpload->stored_path ?? ''));
        $this->checkCancelled($run);
        $noiResult = $this->noiNominaImportService->scanForDatabaseUpdate($noiUpload);

        // Scan fiscal NOI if available — cross-matches against already-loaded employees
        if ($fiscalUpload && $fiscalUpload->stored_path && Storage::disk('public')->exists($fiscalUpload->stored_path)) {
            $this->progress($run, 'Leyendo NOI Nómina Fiscal…', 36, [], 'noi_nomina_fiscal', basename($fiscalUpload->stored_path));
            $this->checkCancelled($run);
            $fiscalResult = $this->noiNominaImportService->scanForDatabaseUpdate($fiscalUpload);
            $noiResult['employees_detected'] += $fiscalResult['employees_detected'] ?? 0;
        }

        $this->progress($run, 'Leyendo Lendus Ingresos Cobranza…', 50, [], 'lendus_ingresos_cobranza', basename($cobranzaUpload->stored_path ?? ''));
        $this->checkCancelled($run);
        $cobranzaResult = $this->lendusIngresosCobranzaImportService->scanForDatabaseUpdate($cobranzaUpload, $run, 50, 65);

        // Optional: import Lendus employee directory if a file has been uploaded
        $lendusEmpleadosUpload = $uploads->first(fn ($item) => $item->dataSource?->code === DataSourceCode::LendusEmpleados->value);
        if (
            $lendusEmpleadosUpload &&
            $lendusEmpleadosUpload->stored_path &&
            Storage::disk('public')->exists($lendusEmpleadosUpload->stored_path)
        ) {
            $this->progress($run, 'Importando directorio de empleados Lendus…', 68, [], 'lendus_empleados', basename($lendusEmpleadosUpload->stored_path));
            $this->checkCancelled($run);
            $this->lendusEmployeeDirectoryImportService->handle($lendusEmpleadosUpload);
        }

        // Assign branches to NOI employees from cobranza, placements, Lendus directory, etc.
        $this->progress($run, 'Asignando sucursales automáticamente…', 76);
        $this->checkCancelled($run);
        $this->employeeBranchAutoMatchService->handle($period->id);

        $this->progress($run, 'Guardando resumen operativo y registrando incidencias…', 88);
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
                        'persons_detected'  => $noiResult['employees_detected'] ?? 0,
                        'records_loaded'    => ($noiResult['rows_read'] ?? 0) + ($cobranzaResult['records_loaded'] ?? $cobranzaResult['cobranza_rows_read'] ?? 0),
                        'records_excluded'  => ($noiResult['rows_skipped'] ?? 0) + ($cobranzaResult['records_excluded'] ?? $cobranzaResult['cobranza_rows_skipped'] ?? 0),
                        'branches_included' => 12,
                        'branches_excluded' => 2,
                        'pending_locations' => $cobranzaResult['pending_locations'] ?? 0,
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

        $allIncidents = array_merge($noiResult['incidents'] ?? [], $cobranzaResult['incidents'] ?? []);
        $criticalCount = count(array_filter($allIncidents, fn ($i) => in_array($i['severity'] ?? 'high', ['high', 'critical'], true)));
        $warningCount  = count(array_filter($allIncidents, fn ($i) => ($i['severity'] ?? '') === 'warning'));

        $stats = [
            'persons_detected'  => $noiResult['employees_detected'] ?? 0,
            'records_loaded'    => ($noiResult['rows_read'] ?? 0) + ($cobranzaResult['records_loaded'] ?? $cobranzaResult['cobranza_rows_read'] ?? 0),
            'records_excluded'  => ($noiResult['rows_skipped'] ?? 0) + ($cobranzaResult['records_excluded'] ?? $cobranzaResult['cobranza_rows_skipped'] ?? 0),
            'branches_included' => 12,
            'branches_excluded' => 2,
            'pending_locations' => $cobranzaResult['pending_locations'] ?? 0,
            'critical_incidents' => $criticalCount,
            'warnings'          => $warningCount,
        ];

        $this->progress($run, 'Actualización completada.', 100, $stats);
    }

    private function progress(
        ?PeriodDatabaseUpdateRun $run,
        string $step,
        int $percent,
        array $stats = [],
        ?string $currentSource = null,
        ?string $currentFile = null,
    ): void {
        if (!$run) return;

        $meta                     = $run->metadata ?? [];
        $meta['current_step']     = $step;
        $meta['progress_percent'] = $percent;
        if ($currentSource !== null) $meta['current_source'] = $currentSource;
        if ($currentFile   !== null) $meta['current_file']   = $currentFile;
        if (!empty($stats))         $meta['stats']           = $stats;

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
