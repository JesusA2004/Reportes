<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugNominaSourcesCommand extends Command
{
    protected $signature   = 'reportes:debug-nomina-sources {period_id : ID del periodo mensual}';
    protected $description = 'Nómina: NOMINANUEVA vs NOMINAF por concepto, solapamiento de empleados, totales vs targets Abril 2026.';

    private const TARGET_NOMINA     = 1_075_509.70;
    private const TARGET_COMISIONES =   716_885.13;
    private const TARGET_BONOS      =   291_655.85;

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
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG NÓMINA SOURCES — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        // ── Uploads para cada fuente ──────────────────────────────────────────
        $uploads = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('ru.period_id', $dataIds)
            ->whereIn('ds.code', ['noi_nomina', 'noi_nomina_fiscal'])
            ->select('ru.id', 'ds.code', 'ru.original_name', 'ru.uploaded_at')
            ->orderBy('ds.code')
            ->get();

        $this->info('── Archivos NOI subidos ──');
        foreach ($uploads as $u) {
            $this->line(sprintf('  Upload #%d  %-25s  %-20s  subido: %s',
                $u->id, $u->code, $u->original_name ?? '—', substr($u->uploaded_at ?? '—', 0, 10)));
        }
        $this->line('');

        // ── 1. Por fuente: total y empleados únicos ───────────────────────────
        $this->info('── 1. Totales por fuente (todas las percepciones) ──');
        $bySource = DB::table('fact_noi_movements as n')
            ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereIn('ds.code', ['noi_nomina', 'noi_nomina_fiscal'])
            ->selectRaw("ds.code, ru.original_name, COUNT(DISTINCT n.employee_id) as empleados, COUNT(*) as filas, SUM(n.amount) as total")
            ->groupBy('ds.id', 'ds.code', 'ru.id', 'ru.original_name')
            ->orderByDesc('total')
            ->get();

        foreach ($bySource as $s) {
            $this->line(sprintf('  %-25s %-18s : %3d emps  %5d filas  $%s',
                $s->code, mb_substr($s->original_name ?? '—', 0, 18),
                $s->empleados, $s->filas, number_format((float)$s->total, 2)));
        }
        $this->line('');

        // ── 2. Conceptos clave por fuente ─────────────────────────────────────
        $this->info('── 2. Conceptos clave por fuente ──');

        $keyConceptExprs = [
            'Sueldo (P001)'     => "concept LIKE 'P001%'",
            'Comisiones (P002)' => "concept LIKE 'P002%'",
            'Bonos (P108/109/120/123)' => "concept REGEXP '^P(108|109|120|123)'",
            'Todas percepciones' => "LOWER(COALESCE(concept_type,'')) = 'percepcion'",
            'Todas deducciones'  => "LOWER(COALESCE(concept_type,'')) IN ('deduccion','descuento')",
        ];

        $sourceRows = [];
        foreach (['noi_nomina', 'noi_nomina_fiscal'] as $srcCode) {
            $srcUploadIds = DB::table('report_uploads as ru')
                ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
                ->whereIn('ru.period_id', $dataIds)
                ->where('ds.code', $srcCode)
                ->pluck('ru.id')
                ->all();

            if (empty($srcUploadIds)) {
                continue;
            }

            $row = ['Fuente' => $srcCode];
            foreach ($keyConceptExprs as $label => $expr) {
                $val = (float) DB::table('fact_noi_movements')
                    ->whereIn('period_id', $dataIds)
                    ->whereIn('report_upload_id', $srcUploadIds)
                    ->whereRaw($expr)
                    ->sum('amount');
                $row[$label] = '$' . number_format($val, 2);
            }
            $sourceRows[] = $row;
        }

        if (!empty($sourceRows)) {
            $headers = array_keys($sourceRows[0]);
            $this->table($headers, array_map('array_values', $sourceRows));
        }
        $this->line('');

        // ── 3. Solapamiento de empleados entre fuentes ────────────────────────
        $this->info('── 3. Solapamiento de employee_id entre NOMINANUEVA y NOMINAF ──');

        $nominaUploadIds = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('ru.period_id', $dataIds)
            ->where('ds.code', 'noi_nomina')
            ->pluck('ru.id')->all();

        $nomFiscalUploadIds = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('ru.period_id', $dataIds)
            ->where('ds.code', 'noi_nomina_fiscal')
            ->pluck('ru.id')->all();

        $empNomina = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('report_upload_id', $nominaUploadIds)
            ->whereNotNull('employee_id')
            ->distinct()->pluck('employee_id')->all();

        $empFiscal = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('report_upload_id', $nomFiscalUploadIds)
            ->whereNotNull('employee_id')
            ->distinct()->pluck('employee_id')->all();

        $solapados = array_intersect($empNomina, $empFiscal);
        $soloNomina = array_diff($empNomina, $empFiscal);
        $soloFiscal = array_diff($empFiscal, $empNomina);

        $this->line(sprintf('  NOMINANUEVA empleados  : %d', count($empNomina)));
        $this->line(sprintf('  NOMINAF empleados      : %d', count($empFiscal)));
        $this->line(sprintf('  Solapados (en ambos)   : %d', count($solapados)));
        $this->line(sprintf('  Solo en NOMINANUEVA    : %d', count($soloNomina)));
        $this->line(sprintf('  Solo en NOMINAF        : %d', count($soloFiscal)));
        $this->line('');

        if (!empty($solapados)) {
            $this->warn(sprintf('  ⚠ %d empleados aparecen en AMBAS fuentes — verificar si son duplicados.', count($solapados)));
            $solapSueldos = DB::table('fact_noi_movements as n')
                ->join('employees as e', 'n.employee_id', '=', 'e.id')
                ->whereIn('n.period_id', $dataIds)
                ->whereIn('n.employee_id', array_values($solapados))
                ->whereRaw("n.concept LIKE 'P001%'")
                ->selectRaw('e.full_name, e.employee_code, n.report_upload_id, SUM(n.amount) as total')
                ->groupBy('n.employee_id', 'e.full_name', 'e.employee_code', 'n.report_upload_id')
                ->orderBy('e.full_name')
                ->limit(30)
                ->get();

            $this->table(
                ['Empleado', 'Código', 'Upload ID', 'P001 Sueldo'],
                $solapSueldos->map(fn ($r) => [
                    mb_substr($r->full_name, 0, 35),
                    $r->employee_code,
                    $r->report_upload_id,
                    '$' . number_format((float)$r->total, 2),
                ])->all()
            );
            $this->line('');
        }

        // ── 4. Conceptos más importantes por fuente ───────────────────────────
        $this->info('── 4. Top conceptos (P001, P002, P1xx) detallados ──');
        $topConcepts = DB::table('fact_noi_movements as n')
            ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereIn('ds.code', ['noi_nomina', 'noi_nomina_fiscal'])
            ->whereRaw("n.concept REGEXP '^P(001|002|108|109|120|123)'")
            ->selectRaw("n.concept, n.concept_type, ds.code as fuente, COUNT(DISTINCT n.employee_id) as emps, SUM(n.amount) as total")
            ->groupBy('n.concept', 'n.concept_type', 'ds.id', 'ds.code')
            ->orderByDesc('total')
            ->get();

        $this->table(
            ['Concepto', 'Tipo', 'Fuente', 'Emps', 'Total'],
            $topConcepts->map(fn ($r) => [
                $r->concept,
                $r->concept_type,
                $r->fuente,
                $r->emps,
                '$' . number_format((float)$r->total, 2),
            ])->all()
        );
        $this->line('');

        // ── 5. Comparación vs targets ──────────────────────────────────────────
        $this->info('── 5. Totales combinados (NOMINANUEVA + NOMINAF) vs targets Abril 2026 ──');

        $nomina     = (float) DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)
            ->where('concept', 'P001 SUELDO')->where('concept_type', 'percepcion')->sum('amount');
        $comisiones = (float) DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)
            ->whereRaw("concept LIKE 'P002%'")->sum('amount');
        $bonos      = (float) DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)
            ->whereRaw("concept REGEXP '^P(108|109|120|123)'")->sum('amount');

        $this->printDiff('Nómina P001 SUELDO',         $nomina,     self::TARGET_NOMINA);
        $this->printDiff('Comisiones P002',             $comisiones, self::TARGET_COMISIONES);
        $this->printDiff('Bonos P108/P109/P120/P123',  $bonos,      self::TARGET_BONOS);
        $this->line('');

        // ── 5b. Distribución por sucursal via employee_branch_assignments ───────
        $this->info('── 5b. Nómina P001 por sucursal — employee_branch_assignments (directo, no proporcional) ──');

        $assignedBranches = DB::table('fact_noi_movements as n')
            ->join('employee_branch_assignments as eba', 'n.employee_id', '=', 'eba.employee_id')
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereRaw("n.concept LIKE 'P001%'")
            ->where('n.concept_type', 'percepcion')
            ->selectRaw("b.name as sucursal, COUNT(DISTINCT n.employee_id) as emps, SUM(n.amount) as total")
            ->groupBy('b.id', 'b.name')
            ->orderByDesc('total')
            ->get();

        $totalAsignado = (float) $assignedBranches->sum(fn ($r) => (float) $r->total);

        $unassigned = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', 'n.employee_id', '=', 'eba.employee_id')
            ->whereIn('n.period_id', $dataIds)
            ->whereRaw("n.concept LIKE 'P001%'")
            ->where('n.concept_type', 'percepcion')
            ->whereNull('eba.employee_id')
            ->selectRaw("COUNT(DISTINCT n.employee_id) as emps, SUM(n.amount) as total")
            ->first();

        $totalSinAsignar = (float) ($unassigned->total ?? 0);

        if ($assignedBranches->isEmpty()) {
            $this->warn('  Sin asignaciones en employee_branch_assignments — se usará distribución proporcional (fallback).');
        } else {
            foreach ($assignedBranches as $r) {
                $this->line(sprintf('  %-30s  %3d emps  $%s',
                    mb_substr($r->sucursal, 0, 30), $r->emps, number_format((float) $r->total, 2)));
            }
            $this->line(sprintf('  %-30s  SIN ASIGNAR:  $%s  (%d emps)',
                '', number_format($totalSinAsignar, 2), (int) ($unassigned->emps ?? 0)));
            $this->line(sprintf('  %-30s  TOTAL ASIGN:  $%s', '', number_format($totalAsignado, 2)));
        }
        $this->line('');

        // ── 6. Distribución por tipo de nómina (payroll_type) ────────────────
        $this->info('── 6. Por payroll_type en fact_noi_movements ──');
        $byType = DB::table('fact_noi_movements as n')
            ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereIn('ds.code', ['noi_nomina', 'noi_nomina_fiscal'])
            ->whereRaw("n.concept LIKE 'P001%'")
            ->selectRaw("COALESCE(n.payroll_type,'(sin tipo)') as payroll_type, ds.code, COUNT(DISTINCT n.employee_id) as emps, SUM(n.amount) as total")
            ->groupBy('n.payroll_type', 'ds.id', 'ds.code')
            ->orderByDesc('total')
            ->get();

        foreach ($byType as $r) {
            $this->line(sprintf('  %-30s  %-25s  %3d emps  $%s',
                mb_substr($r->payroll_type, 0, 30), $r->code, $r->emps, number_format((float)$r->total, 2)));
        }
        $this->line('');

        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }

    private function printDiff(string $label, float $calc, float $target): void
    {
        $diff  = $calc - $target;
        $pct   = $target > 0 ? abs($diff / $target) * 100 : 0;
        $color = $pct < 1 ? 'green' : ($pct < 5 ? 'yellow' : 'red');
        $this->line(sprintf("  %-38s  calc=\$%-13s  tgt=\$%-13s  <fg={$color}>dif=%+.2f (%.2f%%)</>",
            $label,
            number_format($calc, 2),
            number_format($target, 2),
            $diff, $pct));
    }
}
