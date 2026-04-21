<?php

namespace App\Http\Controllers;

use App\Enums\MatchType;
use App\Models\DataSource;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\ReportUpload;
use Inertia\Inertia;
use Inertia\Response;

class ValidationController extends Controller
{
    public function index(): Response
    {
        $items = collect();

        /*
        |--------------------------------------------------------------------------
        | 1) Empleados sin sucursal asignada o sin match
        |--------------------------------------------------------------------------
        */
        $pendingAssignments = EmployeeBranchAssignment::query()
            ->with([
                'employee:id,full_name',
                'period:id,name,code,type,year,month,sequence,start_date,end_date',
            ])
            ->where(function ($query) {
                $query->whereNull('branch_id')
                    ->orWhere('match_type', MatchType::Unmatched->value);
            })
            ->latest()
            ->get();

        foreach ($pendingAssignments as $assignment) {
            $isUnmatched = $assignment->match_type?->value === MatchType::Unmatched->value;

            $items->push([
                'id' => 'assignment-' . $assignment->id,
                'type' => 'Asignación de sucursal',
                'title' => $isUnmatched
                    ? 'Empleado sin match de sucursal'
                    : 'Empleado sin sucursal asignada',
                'description' => $isUnmatched
                    ? 'No fue posible determinar automáticamente una sucursal para este empleado.'
                    : 'Este registro requiere revisión manual para definir la sucursal correcta.',
                'employee_name' => $assignment->employee?->full_name,
                'period_label' => $assignment->period?->label,
                'severity' => 'high',
                'status' => 'open',
                'updated_at' => optional($assignment->updated_at)->format('d/m/Y H:i'),
                'timestamp' => optional($assignment->updated_at)?->timestamp ?? 0,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 2) Archivos con error de procesamiento
        |--------------------------------------------------------------------------
        */
        $failedUploads = ReportUpload::query()
            ->with([
                'period:id,name,code,type,year,month,sequence,start_date,end_date',
                'dataSource:id,name',
            ])
            ->where('status', 'failed')
            ->latest()
            ->get();

        foreach ($failedUploads as $upload) {
            $items->push([
                'id' => 'upload-' . $upload->id,
                'type' => 'Carga de archivo',
                'title' => 'Archivo con error de procesamiento',
                'description' => $upload->dataSource?->name
                    ? "La fuente {$upload->dataSource->name} presentó error al procesarse."
                    : 'Un archivo presentó error al procesarse.',
                'employee_name' => null,
                'period_label' => $upload->period?->label,
                'severity' => 'medium',
                'status' => 'open',
                'updated_at' => optional($upload->updated_at)->format('d/m/Y H:i'),
                'timestamp' => optional($upload->updated_at)?->timestamp ?? 0,
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 3) Periodos con fuentes faltantes
        |--------------------------------------------------------------------------
        */
        $requiredSourcesCount = DataSource::query()
            ->where('is_active', true)
            ->count();

        $periods = Period::query()
            ->with('reportUploads:id,period_id,data_source_id')
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('sequence')
            ->get();

        foreach ($periods as $period) {
            $uploadedCount = $period->reportUploads
                ->pluck('data_source_id')
                ->filter()
                ->unique()
                ->count();

            $missingCount = max($requiredSourcesCount - $uploadedCount, 0);

            if ($missingCount > 0) {
                $items->push([
                    'id' => 'period-' . $period->id,
                    'type' => 'Periodo incompleto',
                    'title' => 'Periodo con fuentes faltantes',
                    'description' => "El periodo {$period->label} aún tiene {$missingCount} fuente(s) pendiente(s).",
                    'employee_name' => null,
                    'period_label' => $period->label,
                    'severity' => $missingCount >= 2 ? 'high' : 'medium',
                    'status' => 'open',
                    'updated_at' => optional($period->updated_at)->format('d/m/Y H:i'),
                    'timestamp' => optional($period->updated_at)?->timestamp ?? 0,
                ]);
            }
        }

        return Inertia::render('Validaciones/Index', [
            'validations' => $items
                ->sortByDesc('timestamp')
                ->map(function (array $item) {
                    unset($item['timestamp']);
                    return $item;
                })
                ->values(),
        ]);
    }
}
