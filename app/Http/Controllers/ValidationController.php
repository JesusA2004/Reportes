<?php

namespace App\Http\Controllers;

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
        | 1) Empleados sin sucursal asignada
        |--------------------------------------------------------------------------
        */
        $pendingAssignments = EmployeeBranchAssignment::query()
            ->with([
                'employee:id,full_name',
                'period:id,year,month,code',
            ])
            ->whereNull('branch_id')
            ->latest()
            ->get();

        foreach ($pendingAssignments as $assignment) {
            $items->push([
                'id' => 'assignment-' . $assignment->id,
                'type' => 'Asignación de sucursal',
                'title' => 'Empleado sin sucursal asignada',
                'description' => 'Este registro requiere revisión manual para definir la sucursal correcta.',
                'employee_name' => $assignment->employee?->full_name,
                'period_label' => $assignment->period?->label,
                'severity' => 'high',
                'status' => 'open',
                'updated_at' => optional($assignment->updated_at)->format('d/m/Y H:i'),
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | 2) Archivos con error de procesamiento
        |--------------------------------------------------------------------------
        */
        $failedUploads = ReportUpload::query()
            ->with([
                'period:id,year,month,code',
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
                ]);
            }
        }

        return Inertia::render('Validaciones/Index', [
            'validations' => $items
                ->sortByDesc('updated_at')
                ->values(),
        ]);
    }
}
