<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodRadiographyRun extends Model
{
    protected $fillable = [
        'period_id',
        'period_summary_id',
        'report_type',
        'scope',
        'branch_id',
        'employee_id',
        'comparison_period_id',
        'status',
        'log',
        'metadata',
        'error_message',
        'started_at',
        'finished_at',
        'queued_at',
        'cancelled_at',
        'cancelled_by',
        'output_excel_path',
        'output_pdf_path',
        'created_by',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'finished_at'  => 'datetime',
        'queued_at'    => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata'     => 'array',
    ];

    public function exports(): HasMany
    {
        return $this->hasMany(PeriodRadiographyExport::class, 'run_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'period_id');
    }

    public function comparisonPeriod(): BelongsTo
    {
        return $this->belongsTo(Period::class, 'comparison_period_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * Tupla que identifica de forma única "qué reporte es este" — un simple y un
     * comparativo del mismo periodo tienen identidades distintas y por lo tanto
     * pueden coexistir sin pisarse.
     */
    public function identity(): array
    {
        return [
            'period_id'            => $this->period_id,
            'report_type'          => $this->report_type ?? 'simple',
            'scope'                => $this->scope ?? 'general',
            'comparison_period_id' => $this->comparison_period_id,
            'branch_id'            => $this->branch_id,
            'employee_id'          => $this->employee_id,
        ];
    }

    /**
     * Filtra runs que pertenecen EXACTAMENTE a la identidad dada (period_id + tipo +
     * alcance + sucursal/empleado/periodo de comparación) — nunca "el último run del
     * periodo sin importar de qué reporte era". Bug real corregido 2026-08-25: un
     * reporte GENERAL exitoso podía verse como fallido/desconocido en Etapa 5 si
     * DESPUÉS se generaba (con éxito o con error) un reporte por sucursal/gestor del
     * MISMO periodo — "el último run" pasaba a ser el scoped, no el general.
     *
     * report_type/scope tratan NULL como su default ('simple'/'general') porque runs
     * creados antes de que el dispatcher empezara a fijarlos explícitamente pueden
     * traerlos vacíos — ver ReportUploadController::generateRadiography().
     */
    public function scopeForIdentity($query, array $identity)
    {
        $reportType = $identity['report_type'] ?? 'simple';
        $scope      = $identity['scope'] ?? 'general';

        return $query
            ->where('period_id', $identity['period_id'])
            ->where(function ($q) use ($reportType) {
                $q->where('report_type', $reportType);
                if ($reportType === 'simple') {
                    $q->orWhereNull('report_type');
                }
            })
            ->where(function ($q) use ($scope) {
                $q->where('scope', $scope);
                if ($scope === 'general') {
                    $q->orWhereNull('scope');
                }
            })
            ->where('branch_id', $identity['branch_id'] ?? null)
            ->where('employee_id', $identity['employee_id'] ?? null)
            ->where('comparison_period_id', $identity['comparison_period_id'] ?? null);
    }

    /**
     * RESOLVEDOR ÚNICO DE ESTADO (auditoría 27-ago-2026, Problema 2/4/5).
     *
     * Antes existían dos nociones de "el run" para una misma identidad, usadas
     * inconsistentemente entre pantallas:
     *   - "el intento más reciente" (puede estar failed/running/cancelled)
     *   - "el último éxito" (el que realmente tiene Excel/PDF descargables)
     * Etapa 5 necesita la PRIMERA (para reflejar processing/failed en vivo).
     * Etapa 7 (exportación) y el histórico (MonthlyReportController) necesitan la
     * SEGUNDA — un intento de regeneración fallido NUNCA debe ocultar un éxito
     * anterior de la MISMA identidad. Este método es la única fuente que ambos
     * consumidores deben usar en vez de repetir la resolución en cada controlador.
     *
     * @return array{latest: ?self, latest_success: ?self}
     */
    public static function resolveForIdentity(array $identity): array
    {
        $latest = static::query()->forIdentity($identity)->latest('id')->first();

        $latestSuccess = ($latest && $latest->status === 'success' && $latest->output_excel_path && $latest->output_pdf_path)
            ? $latest
            : static::query()->forIdentity($identity)
                ->where('status', 'success')
                ->whereNotNull('output_excel_path')
                ->whereNotNull('output_pdf_path')
                ->latest('id')
                ->first();

        return ['latest' => $latest, 'latest_success' => $latestSuccess];
    }
}
