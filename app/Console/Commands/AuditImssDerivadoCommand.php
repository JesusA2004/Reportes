<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use App\Services\ImssFromNoiFiscalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditImssDerivadoCommand extends Command
{
    protected $signature = 'reportes:audit-imss-derivado
                                {period_id}
                                {--detail : listar colaboradores por sucursal}
                                {--rebuild : re-derivar antes de auditar}';

    protected $description = 'Auditoría IMSS: compara legacy validado (archivo, fact_expenses) contra derivado de NOI Fiscal (fact_imss). El legacy manda mientras exista.';

    public function handle(ImssFromNoiFiscalService $service, BranchResolverService $branchResolver): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA IMSS — {$period->label} (ID {$period->id})");
        $this->info('  Derivado: colaboradores únicos en NOI Fiscal por sucursal × $3,500.00');
        $this->info('════════════════════════════════════════════════════════════');

        if ($this->option('rebuild')) {
            $res = $service->deriveForPeriod($period);
            $this->line('  Derivado recalculado: ' . json_encode($res));
        }

        $imssSourceId = DB::table('data_sources')->where('code', 'imss')->value('id');
        $legacyRaw = $imssSourceId
            ? DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
                ->where('e.period_id', $period->id)
                ->where('ru.data_source_id', $imssSourceId)
                ->where('e.category', 'IMSS')
                ->selectRaw("b.name as branch_name, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('b.name')
                ->get()
            : collect();

        $legacyBySucursal = [];
        foreach ($legacyRaw as $row) {
            $real = $row->branch_name ? $branchResolver->resolveRealBranchFromRoute($row->branch_name) : null;
            $key = $real ?: ($row->branch_name ?? 'SIN SUCURSAL');
            $legacyBySucursal[$key] = ($legacyBySucursal[$key] ?? 0.0) + (float) $row->total;
            if ($real && !$branchResolver->isSheetBranch($real)) {
                $legacyBySucursal[$key . ' (excluida)'] = $legacyBySucursal[$key];
                unset($legacyBySucursal[$key]);
            }
        }

        $derivedRows = DB::table('fact_imss')
            ->where('period_id', $period->id)
            ->orderByDesc('included_in_report')
            ->orderBy('branch_name')
            ->get();

        $usaLegacy = !empty($legacyBySucursal);

        if ($derivedRows->isEmpty() && !$usaLegacy) {
            $this->warn('  Sin datos legacy ni derivados para este periodo. Ejecuta con --rebuild.');
            return self::SUCCESS;
        }

        $derivedBySucursal = [];
        foreach ($derivedRows as $r) {
            $derivedBySucursal[$r->branch_name] = [
                'amount' => (float) $r->amount,
                'included' => (bool) $r->included_in_report,
                'reason' => $r->excluded_reason,
                'collabs' => (int) $r->collaborators_count,
            ];
        }

        $sucursales = collect(array_keys($legacyBySucursal))->merge(array_keys($derivedBySucursal))->unique()->sort()->values();

        $tableRows = [];
        foreach ($sucursales as $suc) {
            $l = $legacyBySucursal[$suc] ?? null;
            $d = $derivedBySucursal[$suc] ?? null;
            $diff = ($d['amount'] ?? 0.0) - ($l ?? 0.0);
            $accion = $usaLegacy ? 'Usa LEGACY (validado)' : 'Usa DERIVADO (sin archivo)';
            $tableRows[] = [
                $suc,
                $l !== null ? '$' . number_format($l, 2) : '—',
                $d !== null ? '$' . number_format($d['amount'], 2) : '—',
                ($l !== null && $d !== null) ? '$' . number_format($diff, 2) : '—',
                $accion,
            ];
        }

        $this->table(['Sucursal', 'IMSS validado', 'IMSS derivado', 'Diferencia', 'Acción aplicada'], $tableRows);

        $legacyTotal = array_sum(array_filter($legacyBySucursal, fn ($k) => !str_ends_with($k, '(excluida)'), ARRAY_FILTER_USE_KEY));
        $derivedTotal = (float) $derivedRows->where('included_in_report', true)->sum('amount');

        $activo = $usaLegacy ? $legacyTotal : $derivedTotal;

        $this->line('');
        $this->info('════ RESULTADO QUE VE EL REPORTE (' . ($usaLegacy ? 'LEGACY validado' : 'DERIVADO NOI') . ') ════');
        $this->line('  IMSS incluido en reporte: $' . number_format($activo, 2));

        if ($usaLegacy && $derivedRows->isNotEmpty()) {
            $matches = abs($legacyTotal - $derivedTotal) <= 0.01;
            $this->line('');
            $this->line('  Comparación LEGACY vs DERIVADO (informativa, el derivado NO sustituye mientras exista el archivo):');
            $this->line('    Legacy   -> $' . number_format($legacyTotal, 2));
            $this->line('    Derivado -> $' . number_format($derivedTotal, 2) . ' (diferencia: $' . number_format($derivedTotal - $legacyTotal, 2) . ')');
            $this->line($matches ? '    Estado: COINCIDE ✓' : '    Estado: NO coincide — revisar sucursales con diferencia arriba (--detail para lista de empleados)');
        }

        if (!$usaLegacy) {
            $this->line('');
            $this->warn('  No hay archivo legacy para este periodo — se está usando el valor DERIVADO como fuente del reporte.');
        }

        if ($this->option('detail')) {
            $branchRows = DB::table('period_employee_rosters')
                ->where('period_id', $period->id)
                ->where('is_active_for_period', true)
                ->where('appears_in_nomina_fiscal', true)
                ->orderBy('branch_name')
                ->get();

            foreach ($branchRows->groupBy(fn ($r) => $r->branch_name ?? 'SIN SUCURSAL') as $branchName => $group) {
                $this->line('');
                $this->info("  {$branchName} ({$group->count()}) — colaboradores NOI Fiscal (derivado)");
                foreach ($group as $g) {
                    $this->line("    · {$g->nombre_normalizado}");
                }
            }
        }

        return self::SUCCESS;
    }
}
