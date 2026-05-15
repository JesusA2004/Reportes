<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use App\Services\EmployeeNameCanonicalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugNominaCommand extends Command
{
    protected $signature   = 'reportes:debug-nomina {period_id : ID del periodo mensual}';
    protected $description = 'Diagnóstico de nómina NOI: totales por tipo, por fuente, asignación por sucursal, sin asignar.';

    public function __construct(
        private readonly EmployeeNameCanonicalizer $canonicalizer,
        private readonly BranchResolverService     $resolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        $this->line('');
        $this->info("══════════════════════════════════════════════════════════════");
        $this->info("  DEBUG NÓMINA — {$period->label} (ID #{$periodId})");
        $this->info("══════════════════════════════════════════════════════════════");
        $this->line("  Data IDs: [" . implode(', ', $dataIds) . "]");
        $this->line('');

        // ── 1. Totales por concept_type ───────────────────────────────
        $this->info('── 1. Totales por concept_type ──');
        $byType = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->selectRaw("COALESCE(concept_type, '(sin tipo)') as tipo, COUNT(DISTINCT employee_id) as empleados, SUM(amount) as total")
            ->groupByRaw("COALESCE(concept_type, '(sin tipo)')")
            ->orderByDesc('total')
            ->get();

        $grandTotal = 0;
        foreach ($byType as $row) {
            $this->line(sprintf('  %-28s  %3d emps  $%s', $row->tipo, $row->empleados, number_format((float)$row->total, 2)));
            $grandTotal += (float)$row->total;
        }
        $this->line(sprintf('  %-28s           $%s', 'TOTAL', number_format($grandTotal, 2)));
        $this->line('');

        // ── 1b. Totales por concepto (código NOI P001, P002, D004…) ───
        $this->info('── 1b. Totales por concepto NOI (top 30 por monto absoluto) ──');
        $byConcept = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->selectRaw("concept, concept_type, SUM(amount) as total, COUNT(DISTINCT employee_id) as empleados")
            ->groupBy('concept', 'concept_type')
            ->orderByRaw('ABS(SUM(amount)) DESC')
            ->limit(30)
            ->get();

        $totPercep  = 0.0;
        $totDeducc  = 0.0;
        foreach ($byConcept as $r) {
            $isDed = str_starts_with(strtoupper(trim($r->concept ?? '')), 'D');
            $this->line(sprintf('  %-40s %s  emps=%d  $%s',
                mb_substr($r->concept ?? '—', 0, 40),
                str_pad($r->concept_type ?? '—', 12),
                $r->empleados,
                number_format((float)$r->total, 2)));
            if ($isDed) {
                $totDeducc += (float)$r->total;
            } else {
                $totPercep += (float)$r->total;
            }
        }
        $this->line(sprintf('  Percepciones (P###) approx: $%s', number_format($totPercep, 2)));
        $this->line(sprintf('  Deducciones (D###) approx:  $%s', number_format($totDeducc, 2)));
        $this->line(sprintf('  Neto approx:                $%s', number_format($totPercep - $totDeducc, 2)));
        $this->line('');

        // ── 2. Totales por fuente de datos ────────────────────────────
        $this->info('── 2. Totales por fuente (noi_nomina vs noi_nomina_fiscal) ──');
        $bySource = DB::table('fact_noi_movements as n')
            ->leftJoin('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->leftJoin('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->selectRaw("COALESCE(ds.code, 'sin_fuente') as fuente, COUNT(DISTINCT n.employee_id) as empleados, SUM(n.amount) as total")
            ->groupBy('ds.id', 'ds.code')
            ->orderByDesc('total')
            ->get();

        foreach ($bySource as $row) {
            $this->line(sprintf('  %-32s  %3d emps  $%s', $row->fuente, $row->empleados, number_format((float)$row->total, 2)));
        }
        $this->line('');

        // ── 3. Empleados con EBA por sucursal ─────────────────────────
        $this->info('── 3. Nómina asignada por sucursal (EBA) ──');
        $byBranch = DB::table('fact_noi_movements as n')
            ->join('employee_branch_assignments as eba', function ($j) use ($dataIds) {
                $j->on('eba.employee_id', '=', 'n.employee_id')
                  ->whereIn('eba.period_id', $dataIds);
            })
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('n.period_id', $dataIds)
            ->selectRaw('b.name as sucursal, SUM(n.amount) as total, COUNT(DISTINCT n.employee_id) as empleados')
            ->groupBy('b.name')
            ->orderByDesc('total')
            ->get();

        $totalAsignado = 0;
        foreach ($byBranch as $row) {
            $this->line(sprintf('  %-30s  %3d emps  $%s', $row->sucursal, $row->empleados, number_format((float)$row->total, 2)));
            $totalAsignado += (float)$row->total;
        }
        $this->line(sprintf('  %-30s           $%s', 'TOTAL ASIGNADO', number_format($totalAsignado, 2)));
        $this->line('');

        // ── 4. Sin asignar ────────────────────────────────────────────
        $ebaIds = DB::table('employee_branch_assignments')
            ->whereIn('period_id', $dataIds)
            ->pluck('employee_id')
            ->unique()
            ->all();

        $noiTotal = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->selectRaw('SUM(amount) as total, COUNT(DISTINCT employee_id) as empleados')
            ->first();

        $sinAsignadoTotal = (float)($noiTotal?->total ?? 0) - $totalAsignado;

        $this->info('── 4. Resumen asignación ──');
        $this->line(sprintf('  Total NOI            : %d emps  $%s', (int)($noiTotal?->empleados ?? 0), number_format((float)($noiTotal?->total ?? 0), 2)));
        $this->line(sprintf('  Asignados (con EBA)  : %d emps  $%s', $byBranch->sum('empleados'), number_format($totalAsignado, 2)));
        $this->line(sprintf('  SIN ASIGNAR          :          $%s', number_format($sinAsignadoTotal, 2)));
        $this->line('');

        // ── 5. Top SIN ASIGNAR ────────────────────────────────────────
        $noiAllIds = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id')
            ->all();

        $sinEbaIds = array_values(array_diff($noiAllIds, $ebaIds));

        if (!empty($sinEbaIds)) {
            $sinEba = DB::table('fact_noi_movements as n')
                ->join('employees as e', 'n.employee_id', '=', 'e.id')
                ->whereIn('n.period_id', $dataIds)
                ->whereIn('n.employee_id', $sinEbaIds)
                ->selectRaw('e.full_name, e.employee_code, SUM(n.amount) as total')
                ->groupBy('e.id', 'e.full_name', 'e.employee_code')
                ->orderByDesc('total')
                ->limit(30)
                ->get();

            $this->info(sprintf('── 5. Top empleados SIN ASIGNAR (%d total, mostrando máx 30) ──', count($sinEbaIds)));
            $this->table(
                ['Código', 'Nombre', 'Total'],
                $sinEba->map(fn ($r) => [
                    $r->employee_code ?? '—',
                    mb_substr($r->full_name, 0, 45),
                    '$' . number_format((float)$r->total, 2),
                ])->all()
            );
            $this->line('');
        }

        // ── 6. Referencia vs calculado ────────────────────────────────
        $this->info('── 6. Referencia vs calculado (targets Abril 2026) ──');

        // Nómina = P001 SUELDO
        $nomina = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("concept LIKE 'P001%'")
            ->sum('amount');
        // Comisiones = P002
        $comisiones = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("concept LIKE 'P002%'")
            ->sum('amount');
        // Bonos = P108 + P109 + P120 + P123
        $bonos = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("concept REGEXP '^P(108|109|120|123)'")
            ->sum('amount');
        // Total percepciones
        $percep = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("LOWER(COALESCE(concept_type,'')) = 'percepcion'")
            ->sum('amount');
        // Total deducciones
        $deduc = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("LOWER(COALESCE(concept_type,'')) IN ('deduccion','descuento')")
            ->sum('amount');

        $targets = [
            'Nómina (P001)'    => [1_075_509.70, $nomina],
            'Comisiones (P002)' => [716_885.13, $comisiones],
            'Bonos (P1xx)'     => [291_655.85, $bonos],
        ];

        foreach ($targets as $label => [$target, $calc]) {
            $diff = $calc - $target;
            $ok   = abs($diff) < 1.0 ? '<fg=green>✓</>' : '<fg=yellow>⚠</>';
            $this->line(sprintf('  %s %-22s calculado=$%-14s target=$%-14s dif=%s',
                $ok, $label,
                number_format($calc, 2),
                number_format($target, 2),
                number_format($diff, 2)));
        }
        $this->line(sprintf('  %-24s percepciones=$%s  deducciones=$%s  neto=$%s',
            'Total NOI:',
            number_format($percep, 2),
            number_format($deduc, 2),
            number_format($percep - $deduc, 2)));
        $this->line('');
        $this->line('  Targets Abril 2026: Nómina $1,075,509.70 | Comisiones $716,885.13 | Bonos $291,655.85');
        $this->line('  Total manual: $1,928,818.31 (NO FISCAL=$1,498,877.31 + FISCAL=$429,941.00)');
        $this->line('');

        $this->info("══════════════════════════════════════════════════════════════");
        $this->line('');

        return self::SUCCESS;
    }
}
