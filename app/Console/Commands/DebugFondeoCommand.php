<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugFondeoCommand extends Command
{
    protected $signature   = 'reportes:debug-fondeo {period_id : ID del periodo mensual}';
    protected $description = 'Fondeo: detalle fila a fila de Préstamos Intersucursales por fuente, sucursal y concepto.';

    private const TARGET      = 449_425.00;
    private const FONDEO_CATS = ['Préstamos Intersucursales'];

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
        $this->info("  DEBUG FONDEO — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('  Target fondeo Abril 2026: $' . number_format(self::TARGET, 2));
        $this->line('');

        // ── 1. Total por fuente ───────────────────────────────────────────────
        $this->info('── 1. Total por fuente ──');
        $bySource = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('e.category', self::FONDEO_CATS)
            ->selectRaw("ds.code, ds.name as source_name, COUNT(*) as filas, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('ds.id', 'ds.code', 'ds.name')
            ->orderByDesc('total')
            ->get();

        if ($bySource->isEmpty()) {
            $this->warn('  Sin registros de fondeo en fact_expenses.');
        } else {
            $grandTotal = 0.0;
            foreach ($bySource as $s) {
                $this->line(sprintf('  %-25s : %4d filas  $%s', $s->code, $s->filas, number_format((float)$s->total, 2)));
                $grandTotal += (float)$s->total;
            }
            $this->line(sprintf('  %-25s :       TOTAL $%s', '', number_format($grandTotal, 2)));
        }
        $this->line('');

        // ── 2. Por sucursal y fuente ──────────────────────────────────────────
        $this->info('── 2. Por sucursal × fuente ──');
        $bySucFuente = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->join('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('e.category', self::FONDEO_CATS)
            ->selectRaw("b.name as sucursal, ds.code as fuente, COUNT(*) as filas, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('b.id', 'b.name', 'ds.id', 'ds.code')
            ->orderByDesc('total')
            ->get();

        if ($bySucFuente->isEmpty()) {
            $this->line('  (ningún registro)');
        } else {
            $this->table(
                ['Sucursal', 'Fuente', 'Filas', 'Total'],
                $bySucFuente->map(fn ($r) => [
                    mb_substr($r->sucursal, 0, 30),
                    $r->fuente,
                    $r->filas,
                    '$' . number_format((float)$r->total, 2),
                ])->all()
            );
        }
        $this->line('');

        // ── 3. Detalle fila a fila — solo Lendus PDF (fuente usada en calculador) ──
        $this->info('── 3. Fila a fila — gastos_lendus PDF (fuente activa en calculador) ──');
        $this->line('  gastos_lendus_excel NO se usa en el calculador — es duplicado del PDF.');
        $this->line('  Monto en calculador = $582,625.00 (suma de las filas abajo).');
        $this->line('  Target = $449,425.00. Diferencia = +$133,200.00 (exceso).');
        $this->line('  Pendiente: el usuario debe indicar qué filas excluir.');
        $this->line('');

        $lendusSourceId = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');

        $filasPDF = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->leftJoin('employees as emp', 'e.employee_id', '=', 'emp.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('e.category', self::FONDEO_CATS)
            ->where('ru.data_source_id', $lendusSourceId)
            ->selectRaw("
                COALESCE(b.name,'(sin sucursal)') as sucursal,
                COALESCE(emp.full_name,'—') as empleado,
                e.expense_date,
                e.category,
                e.concept,
                e.observations,
                COALESCE(NULLIF(e.paid_amount,0), e.amount) as total,
                e.id
            ")
            ->orderBy('b.name')
            ->orderByDesc('e.amount')
            ->get();

        $pdfTotal = $filasPDF->sum(fn ($r) => (float) $r->total);

        if ($filasPDF->isEmpty()) {
            $this->line('  (ningún registro en gastos_lendus PDF)');
        } else {
            $this->line(sprintf('  %-4s  %-25s  %-35s  %-12s  %-35s  %12s',
                '#', 'Sucursal', 'Concepto', 'Fecha', 'Observación', 'Monto'));
            $this->line('  ' . str_repeat('─', 135));
            $i = 1;
            foreach ($filasPDF as $r) {
                $this->line(sprintf('  %-4d  %-25s  %-35s  %-12s  %-35s  $%s',
                    $i++,
                    mb_substr($r->sucursal, 0, 25),
                    mb_substr($r->concept ?? '—', 0, 35),
                    $r->expense_date ?? '—',
                    mb_substr($r->observations ?? '—', 0, 35),
                    number_format((float) $r->total, 2)
                ));
            }
            $this->line('  ' . str_repeat('─', 135));
            $this->line(sprintf('  %-4s  %-25s  %-35s  %-12s  %-35s  $%s',
                'TOT', '', '', '', 'TOTAL LENDUS PDF:', number_format($pdfTotal, 2)));
        }
        $this->line('');

        // ── 3b. gastos_lendus_excel — solo para referencia, NO se usa ─────────
        $this->info('── 3b. gastos_lendus_excel — SOLO REFERENCIA (no usado en calculador) ──');
        $excelSourceId = DB::table('data_sources')->where('code', 'gastos_lendus_excel')->value('id');

        $filasExcel = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('e.category', self::FONDEO_CATS)
            ->where('ru.data_source_id', $excelSourceId)
            ->selectRaw("
                COALESCE(b.name,'(sin sucursal)') as sucursal,
                e.expense_date,
                e.concept,
                e.observations,
                COALESCE(NULLIF(e.paid_amount,0), e.amount) as total
            ")
            ->orderByDesc('e.amount')
            ->get();

        $excelTotal = $filasExcel->sum(fn ($r) => (float) $r->total);
        $excelFondeo = $filasExcel->filter(fn ($r) => str_contains(strtoupper($r->concept ?? ''), 'FONDEO'))
            ->sum(fn ($r) => (float) $r->total);
        $excelExcedentes = $filasExcel->filter(fn ($r) => str_contains(strtoupper($r->concept ?? ''), 'EXCEDENTE'))
            ->sum(fn ($r) => (float) $r->total);

        $this->line(sprintf('  Total Excel (categoria Préstamos Intersucursales): $%s', number_format($excelTotal, 2)));
        $this->line(sprintf('  De los cuales concept FONDEO A SUCURSAL: $%s', number_format($excelFondeo, 2)));
        $this->line(sprintf('  De los cuales concept EXCEDENTES:         $%s', number_format($excelExcedentes, 2)));
        $this->line('  <fg=yellow>NOTA: gastos_lendus_excel es duplicado del PDF con más detalle pero</>');
        $this->line('  <fg=yellow>      también mezcla EXCEDENTES bajo categoría Préstamos Intersucursales.</>');
        $this->line('  <fg=yellow>      El calculador usa SOLO gastos_lendus PDF para evitar duplicación.</>');
        $this->line('');

        // ── 4. Comparación vs target ──────────────────────────────────────────
        $this->info('── 4. Comparación vs target ──');
        $calcTotal = $bySource->sum(fn ($s) => (float)$s->total);
        $diff  = $calcTotal - self::TARGET;
        $pct   = self::TARGET > 0 ? abs($diff / self::TARGET) * 100 : 0;
        $color = $pct < 1 ? 'green' : ($pct < 10 ? 'yellow' : 'red');
        $this->line(sprintf('  Calculado : $%s', number_format($calcTotal, 2)));
        $this->line(sprintf('  Target    : $%s', number_format(self::TARGET, 2)));
        $this->line(sprintf("  Diferencia: <fg={$color}>%+.2f (%.2f%%)</>", $diff, $pct));
        $this->line('');

        if (abs($diff) > 1000) {
            $this->warn('  ⚠ Diferencia significativa. Revisar:');
            $this->line('    A) ¿Hay entradas de fondeo en ERP que deben excluirse?');
            $this->line('    B) ¿El Excel manual incluye "Préstamos Intersucursales" de alguna otra categoría?');
            $this->line('    C) ¿Corporativo tiene fondeo que no debe contar en el global?');

            // Check corporativo fondeo
            $corpFondeo = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
                ->join('branches as b', 'e.branch_id', '=', 'b.id')
                ->whereIn('e.period_id', $dataIds)
                ->whereIn('e.category', self::FONDEO_CATS)
                ->whereRaw("UPPER(b.name) LIKE '%CORPORAT%'")
                ->selectRaw("SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->value('total');

            if ((float)($corpFondeo ?? 0) > 0) {
                $this->line(sprintf('    → Corporativo tiene fondeo: $%s — ¿debe excluirse del GLOBAL?', number_format((float)$corpFondeo, 2)));
            }
        }
        $this->line('');

        // ── 5. Categorías alternativas que podrían ser fondeo ─────────────────
        $this->info('── 5. Otras categorías con "fondeo" o "intersucursal" en nombre ──');
        $altCats = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereRaw("LOWER(COALESCE(e.category,'')) REGEXP 'fondeo|intersucursal|prestamo'")
            ->whereNotIn('e.category', self::FONDEO_CATS)
            ->selectRaw("ds.code, e.category, e.concept, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total, COUNT(*) as filas")
            ->groupBy('ds.id', 'ds.code', 'e.category', 'e.concept')
            ->orderByDesc('total')
            ->get();

        if ($altCats->isEmpty()) {
            $this->line('  Ninguna categoría alternativa encontrada.');
        } else {
            $this->table(
                ['Fuente', 'Categoría', 'Concepto', 'Total', 'Filas'],
                $altCats->map(fn ($r) => [
                    $r->code,
                    mb_substr($r->category ?? '—', 0, 30),
                    mb_substr($r->concept ?? '—', 0, 35),
                    '$' . number_format((float)$r->total, 2),
                    $r->filas,
                ])->all()
            );
        }
        $this->line('');

        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }
}
