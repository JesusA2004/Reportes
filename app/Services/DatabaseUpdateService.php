<?php

namespace App\Services;

use App\Enums\DataSourceCode;
use App\Models\Period;
use App\Models\PeriodIncident;
use App\Models\PeriodSummary;
use App\Models\ReportUpload;
use Illuminate\Support\Facades\DB;

class DatabaseUpdateService
{
    public function updateForPeriod(Period $period, ?int $userId = null): array
    {
        $required = [DataSourceCode::NoiNomina->value, DataSourceCode::LendusIngresosCobranza->value];
        $uploads = ReportUpload::query()->with('dataSource:id,code,name')->where('period_id', $period->id)->get();

        $missing = [];
        foreach ($required as $code) {
            $hasProcessed = $uploads->contains(fn ($u) => $u->dataSource?->code === $code && (($u->status?->value ?? $u->status) === 'processed'));
            if (!$hasProcessed) {
                $missing[] = $code;
            }
        }

        if (!empty($missing)) {
            return ['ok' => false, 'missing' => $missing, 'message' => 'No se puede actualizar la BD. Faltan archivos obligatorios: ' . implode(', ', $missing) . '.'];
        }

        return DB::transaction(function () use ($period, $userId, $uploads) {
            $summary = PeriodSummary::query()->updateOrCreate(['period_id' => $period->id], [
                'status' => 'db_updated',
                'generated_by' => $userId,
                'generated_at' => now(),
                'invalidated_at' => null,
                'invalidated_by' => null,
                'invalidated_reason' => null,
                'source_upload_ids' => $uploads->pluck('id')->values()->all(),
                'warnings' => ['BD operativa actualizada.'],
            ]);

            PeriodIncident::query()->where('period_summary_id', $summary->id)->where('type', 'like', 'db_%')->delete();

            // Incidencias mínimas de cruce para flujo
            $hasCobranza = $uploads->contains(fn ($u) => $u->dataSource?->code === DataSourceCode::LendusIngresosCobranza->value);
            if ($hasCobranza) {
                PeriodIncident::query()->create([
                    'period_summary_id' => $summary->id,
                    'type' => 'db_revision_match',
                    'severity' => 'warning',
                    'message' => 'Revisa promotores sin sucursal asignada antes de generar radiografía.',
                    'context' => ['critical' => true, 'resolved' => false],
                ]);
            }

            return ['ok' => true, 'summary_id' => $summary->id, 'message' => 'BD actualizada correctamente para el periodo.'];
        });
    }
}
